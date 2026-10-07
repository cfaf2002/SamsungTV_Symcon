<?php

declare(strict_types=1);

/**
 * Ladetest mit den offiziellen Symcon-Stubs (https://github.com/symcon/SymconStubs).
 *
 * Lädt die Bibliothek wie Symcon, legt die Instanz samt WebSocket-Client an, öffnet Formular und Kachel
 * und spielt mit einem nachgebauten Fernseher (tests/fixtures/tv.php auf Port 8001 und 9197) durch:
 * Ein/Aus, Lautstärke über UPnP, Kopplung mit Token, Tasten, Apps und den HEX-kodierten Datenfluss.
 *
 * Aufruf: php tests/stubs.php <Pfad zu SymconStubs>
 *
 * SPDX-License-Identifier: MIT
 */

$stubs = $argv[1] ?? __DIR__ . '/../../SymconStubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, 'SymconStubs nicht gefunden: ' . $stubs . PHP_EOL);
    exit(2);
}

set_error_handler(static function (int $no, string $str): bool {
    return $no === E_DEPRECATED || $no === E_USER_DEPRECATED || str_contains($str, 'could not be found');
});

$tmp = sys_get_temp_dir() . '/samtv-test-' . getmypid();
@mkdir($tmp . '/stubs', 0777, true);
@mkdir($tmp . '/wsc/WebSocketClient', 0777, true);

// Die Stubs verlangen für Timer eine Testuhr (getTime). In eine Kopie die normale Uhrzeit eintragen.
foreach (glob($stubs . '/*.php') as $file) {
    $code = (string) file_get_contents($file);
    if (basename($file) === 'ModuleStrictStubs.php') {
        $code = str_replace(
            "throw new Exception('getTime needs to be implemented by module under test');\n    }\n}",
            "return time();\n    }\n}",
            $code
        );
    }
    file_put_contents($tmp . '/stubs/' . basename($file), $code);
}

// Nachbau des Symcon-I/O „WebSocket Client“ (nicht Teil der Stubs): merkt sich gesendete Pakete
file_put_contents($tmp . '/wsc/library.json', json_encode([
    'id' => '{6E1B6E1B-0000-4000-8000-00000000AA01}', 'author' => 'Test', 'name' => 'WSC Test', 'url' => '',
    'version' => '1.0', 'build' => 1, 'date' => 0,
]));
file_put_contents($tmp . '/wsc/WebSocketClient/module.json', json_encode([
    'id' => '{D68FD31F-0E90-7019-F16C-1949BD3079EF}', 'name' => 'WebSocket Client', 'type' => 1, 'vendor' => 'Symcon GmbH',
    'aliases' => [], 'parentRequirements' => [], 'childRequirements' => ['{018EF6B5-AB94-40C6-AA53-46943E824ACF}'],
    'implemented' => ['{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}'], 'prefix' => 'WSC',
]));
file_put_contents($tmp . '/wsc/WebSocketClient/module.php', <<<'PHP'
<?php
declare(strict_types=1);
class WebSocketClient extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('URL', '');
        $this->RegisterPropertyBoolean('VerifyCertificate', true);
        $this->RegisterPropertyString('Headers', '[]');
        $this->RegisterPropertyBoolean('Active', false);
    }
    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus($this->ReadPropertyBoolean('Active') && $this->ReadPropertyString('URL') !== '' ? 102 : 104);
    }
    public function ForwardData($JSONString)
    {
        $GLOBALS['wscSent'][] = json_decode($JSONString, true);
        return '';
    }
    public function Push(string $Text)
    {
        $this->SendDataToChildren(json_encode(['DataID' => '{018EF6B5-AB94-40C6-AA53-46943E824ACF}', 'Buffer' => bin2hex($Text)]));
    }
}
PHP);

// Nachgebauter Fernseher
$stateFile = $tmp . '/tv.json';
file_put_contents($stateFile, json_encode(['power' => 'on', 'volume' => 17, 'mute' => false]));
$servers = [];
foreach ([8001, 9197] as $port) {
    $servers[] = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fixtures/tv.php'],
        [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
        $pipes,
        null,
        ['TV_STATE' => $stateFile]
    );
}
register_shutdown_function(static function () use ($tmp, $servers): void {
    foreach ($servers as $server) {
        if (is_resource($server)) {
            proc_terminate($server);
        }
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});
// warten, bis beide Server antworten
for ($i = 0; $i < 50; $i++) {
    $a = @fsockopen('127.0.0.1', 8001, $e, $s, 0.1);
    $b = @fsockopen('127.0.0.1', 9197, $e, $s, 0.1);
    if ($a && $b) {
        break;
    }
    usleep(100000);
}

require $tmp . '/stubs/autoload.php';

\IPS\Kernel::reset();
\IPS\ModuleLoader::loadLibrary($tmp . '/wsc/library.json');
\IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');

$failed = 0;
function ok(bool $condition, string $message): void
{
    global $failed;
    echo ($condition ? '  ✓ ' : '  ✗ ') . $message . PHP_EOL;
    if (!$condition) {
        $failed++;
    }
}

function tv(array $change): void
{
    global $stateFile;
    $state = json_decode((string) file_get_contents($stateFile), true);
    file_put_contents($stateFile, json_encode(array_merge($state, $change)));
}

function tvState(): array
{
    global $stateFile;
    return json_decode((string) file_get_contents($stateFile), true);
}

function attr(int $id, string $name): mixed
{
    $module = \IPS\InstanceManager::getInstanceInterface($id);
    foreach (['ReadAttributeString', 'ReadAttributeInteger', 'ReadAttributeBoolean'] as $fn) {
        try {
            $m = new ReflectionMethod($module, $fn);
            $v = $m->invoke($module, $name);
            if (($fn === 'ReadAttributeString' && is_string($v)) || ($fn === 'ReadAttributeInteger' && is_int($v)) || ($fn === 'ReadAttributeBoolean' && is_bool($v))) {
                return $v;
            }
        } catch (Throwable $e) {
        }
    }
    return null;
}

/** Letzte an den Fernseher gesendete Nachricht (aus HEX dekodiert) */
function lastSent(): ?array
{
    $packet = end($GLOBALS['wscSent']);
    if (!is_array($packet)) {
        return null;
    }
    $hex = (string) $packet['Buffer'];
    if (!ctype_xdigit($hex) || strlen($hex) % 2 !== 0) {
        return ['notHex' => $hex];
    }
    return json_decode((string) hex2bin($hex), true);
}

function push(int $parent, string $text): void
{
    \IPS\InstanceManager::getInstanceInterface($parent)->Push($text);
}

$GLOBALS['wscSent'] = [];

echo 'Samsung TV' . PHP_EOL;
try {
    $id = IPS_CreateInstance('{0D4C6AE0-8BE5-420E-92C6-9ACD54D1B01D}');
    ok($id > 0, 'Instanz angelegt');
    $compatible = json_decode(\IPS\InstanceManager::getInstanceInterface($id)->GetCompatibleParents(), true);
    ok(($compatible['type'] ?? '') === 'require' && ($compatible['moduleIDs'] ?? []) === ['{D68FD31F-0E90-7019-F16C-1949BD3079EF}'], 'Verlangt einen WebSocket Client als übergeordnete Instanz');
    // Symcon legt den WebSocket Client beim Anlegen an (die Stubs nicht): hier von Hand
    $parent = IPS_CreateInstance('{D68FD31F-0E90-7019-F16C-1949BD3079EF}');
    IPS_ConnectInstance($id, $parent);
    IPS_ApplyChanges($parent);
    IPS_ApplyChanges($id);
    ok(IPS_GetInstance($id)['InstanceStatus'] === 104, 'Ohne Adresse Status 104');
    $form = json_decode(IPS_GetConfigurationForm($id), true);
    ok(is_array($form) && isset($form['elements']), 'Formular ist gültiges JSON');
    $tile = SAMTV_GetVisualizationTile($id);
    ok(str_contains($tile, 'window.handleMessage') && !str_contains($tile, '/*INITIAL_DATA*/'), 'Kachel mit Startdaten');
    ok(SAMTV_SendKey($id, 'KEY_HOME') === false, 'Ohne Verbindung: Taste wird abgelehnt');

    // Einrichten: Fernseher ist an
    IPS_SetProperty($id, 'Host', '127.0.0.1');
    IPS_ApplyChanges($id);
    ok(IPS_GetInstance($id)['InstanceStatus'] === 102, 'Mit Adresse Status 102');
    foreach (['Power', 'Volume', 'Mute', 'Source', 'App', 'Remote'] as $ident) {
        ok(@IPS_GetObjectIDByIdent($ident, $id) !== false, 'Variable ' . $ident);
    }
    ok(GetValue(IPS_GetObjectIDByIdent('Power', $id)) === true, 'Fernseher an erkannt');
    ok(GetValue(IPS_GetObjectIDByIdent('Volume', $id)) === 17, 'Lautstärke über UPnP gelesen');
    ok(IPS_GetProperty($parent, 'Active') === true, 'WebSocket-Client aktiv geschaltet');
    ok(IPS_GetProperty($parent, 'VerifyCertificate') === false, 'Zertifikatsprüfung für das Gerätezertifikat aus');
    ok(IPS_GetProperty($parent, 'URL') === 'wss://127.0.0.1:8002/api/v2/channels/samsung.remote.control?name=U3ltY29u', 'URL verschlüsselt mit Name, ohne Token');
    ok(json_decode(SAMTV_GetDeviceInfo($id), true)['model'] === 'QE55Q80BAT', 'Geräteinfo gelesen');
    ok(str_contains((string) json_encode(json_decode(IPS_GetConfigurationForm($id), true)), 'QE55Q80BAT'), 'Formular zeigt das gefundene Gerät');

    // Kopplung: Fernseher schickt den Token (in zwei Stücken, HEX-kodiert)
    $connect = json_encode(['event' => 'ms.channel.connect', 'data' => ['id' => 'x', 'token' => '12345678', 'clients' => []]]);
    push($parent, substr($connect, 0, 20));
    push($parent, substr($connect, 20));
    ok(attr($id, 'Token') === '12345678', 'Token gespeichert (Nachricht in zwei Stücken)');
    ok(attr($id, 'Pairing') === 3, 'Kopplung erfolgreich');
    ok((lastSent()['params']['event'] ?? '') === 'ed.installedApp.get', 'App-Liste nach der Kopplung angefragt');

    // Tasten
    ok(SAMTV_SendKey($id, 'home') === true, 'Taste ohne KEY_-Präfix');
    $sent = end($GLOBALS['wscSent']);
    ok(ctype_xdigit((string) $sent['Buffer']) && $sent['DataID'] === '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}', 'Buffer HEX-kodiert (IPSModuleStrict)');
    ok((lastSent()['params']['DataOfCmd'] ?? '') === 'KEY_HOME' && lastSent()['params']['Cmd'] === 'Click', 'KEY_HOME als Klick gesendet');
    ok(SAMTV_SendKey($id, 'rm -rf /') === false, 'Ungültige Taste abgelehnt');
    IPS_RequestAction($id, 'Remote', 3);
    ok((lastSent()['params']['DataOfCmd'] ?? '') === 'KEY_UP', 'Variable Fernbedienung: Hoch');
    IPS_RequestAction($id, 'Source', 2);
    ok((lastSent()['params']['DataOfCmd'] ?? '') === 'KEY_HDMI2' && GetValue(IPS_GetObjectIDByIdent('Source', $id)) === 2, 'Quelle HDMI 2');
    ok(SAMTV_SelectSource($id, 'hdmi 1') === true && lastSent()['params']['DataOfCmd'] === 'KEY_HDMI1', 'SAMTV_SelectSource');
    $count = count($GLOBALS['wscSent']);
    ok(SAMTV_SendKeys($id, 'KEY_HOME, KEY_RIGHT,KEY_ENTER', 50) === true && count($GLOBALS['wscSent']) === $count + 3, 'SAMTV_SendKeys');

    // App-Liste
    $apps = json_encode(['event' => 'ed.installedApp.get', 'data' => ['data' => [
        ['appId' => '3201907018807', 'app_type' => 2, 'name' => 'Netflix'],
        ['appId' => 'org.tizen.browser', 'app_type' => 4, 'name' => 'Internet</script>'],
    ]]]);
    push($parent, $apps);
    ok(count(json_decode(SAMTV_GetInstalledApps($id), true)) === 2, 'Installierte Apps empfangen');
    ok(SAMTV_LaunchApp($id, '3201907018807') === true && lastSent()['params']['data']['action_type'] === 'DEEP_LINK', 'App starten (DEEP_LINK)');
    IPS_RequestAction($id, 'App', 5);
    ok(lastSent()['params']['data']['appId'] === 'org.tizen.browser' && lastSent()['params']['data']['action_type'] === 'NATIVE_LAUNCH', 'App aus der Variable (Browser, NATIVE_LAUNCH)');
    ok(SAMTV_OpenBrowser($id, 'https://www.symcon.de') === true && lastSent()['params']['data']['metaTag'] === 'https://www.symcon.de', 'Browser mit Adresse');
    ok(SAMTV_OpenBrowser($id, 'javascript:alert(1)') === false, 'Ungültige Adresse abgelehnt');
    ok(SAMTV_SendText($id, 'Tagesschau') === true && base64_decode(lastSent()['params']['Cmd']) === 'Tagesschau', 'Text senden');

    // Lautstärke
    ok(SAMTV_SetVolume($id, 30) === true && tvState()['volume'] === 30 && GetValue(IPS_GetObjectIDByIdent('Volume', $id)) === 30, 'Lautstärke 30 über UPnP');
    IPS_RequestAction($id, 'Mute', true);
    ok(tvState()['mute'] === true && GetValue(IPS_GetObjectIDByIdent('Mute', $id)) === true, 'Stumm über UPnP');
    IPS_SetProperty($id, 'VolumeStep', 5);
    IPS_ApplyChanges($id);
    IPS_RequestAction($id, 'VolumeStep', 1);
    ok(tvState()['volume'] === 35, 'Lautstärketaste der Kachel in 5er-Schritten');

    // Kachel
    foreach ([0, 1, 2] as $theme) {
        IPS_SetProperty($id, 'TileTheme', $theme);
        IPS_ApplyChanges($id);
        ok(str_contains(SAMTV_GetVisualizationTile($id), '"theme":' . $theme), 'Kachel mit Farbschema ' . $theme);
    }
    ok(!str_contains(SAMTV_GetVisualizationTile($id), '</script>","'), 'App-Namen in der Kachel sicher eingebettet');

    // Aus- und Einschalten
    ok(SAMTV_PowerOff($id) === true && lastSent()['params']['DataOfCmd'] === 'KEY_POWER', 'Ausschalten mit KEY_POWER');
    ok(attr($id, 'PowerTarget') === 'off', 'Schaltziel aus gemerkt (schnelle Abfrage)');
    tv(['power' => 'standby']);
    SAMTV_Update($id);
    ok(GetValue(IPS_GetObjectIDByIdent('Power', $id)) === false && attr($id, 'PowerTarget') === '', 'Standby erkannt, Schaltziel erreicht');
    ok(IPS_GetProperty($parent, 'Active') === false, 'WebSocket-Client im Standby aus');
    ok(SAMTV_SendKey($id, 'KEY_HOME') === false, 'Im Standby keine Tasten');
    IPS_SetProperty($id, 'MAC', 'AA-BB-CC-DD-EE-FF');
    IPS_SetProperty($id, 'Broadcast', '127.255.255.255');
    IPS_ApplyChanges($id);
    ok(SAMTV_PowerOn($id) === true, 'Einschalten per Wake-on-LAN');
    tv(['power' => 'on']);
    SAMTV_Update($id);
    ok(GetValue(IPS_GetObjectIDByIdent('Power', $id)) === true, 'Wieder an');
    ok(str_ends_with((string) IPS_GetProperty($parent, 'URL'), '&token=12345678'), 'Neue Verbindung mit Token');

    // Magic Packet
    $magic = new ReflectionMethod('SamsungTV', 'MagicPacket');
    $packet = $magic->invoke(null, 'aa:bb:cc:dd:ee:ff');
    ok(strlen((string) $packet) === 102 && str_starts_with((string) $packet, str_repeat("\xFF", 6)) && substr((string) $packet, 6, 6) === "\xAA\xBB\xCC\xDD\xEE\xFF", 'Magic Packet');
    ok($magic->invoke(null, 'kein Wert') === null, 'Ungültige MAC erkannt');

    // Ablehnung, Zeitüberschreitung, neu koppeln
    push($parent, (string) json_encode(['event' => 'ms.channel.timeOut']));
    ok(IPS_GetInstance($id)['InstanceStatus'] === 201, 'Zeitüberschreitung: Status 201');
    push($parent, (string) json_encode(['event' => 'ms.channel.unauthorized']));
    ok(IPS_GetInstance($id)['InstanceStatus'] === 202, 'Abgelehnt: Status 202');
    ok(IPS_GetProperty($parent, 'Active') === false, 'Abgelehnt: keine weiteren Verbindungsversuche');
    SAMTV_Update($id);
    ok(IPS_GetProperty($parent, 'Active') === false, 'Abgelehnt bleibt aus, auch wenn der Fernseher an ist');
    ok(SAMTV_ResetPairing($id) === true && attr($id, 'Token') === '' && IPS_GetProperty($parent, 'Active') === true, 'Neu koppeln');
    ok(IPS_GetInstance($id)['InstanceStatus'] === 102, 'Neu koppeln: Status 102');

    // Klartext-Rückfall (ältere Symcon-Versionen)
    \IPS\InstanceManager::getInstanceInterface($id)->ReceiveData(json_encode(['DataID' => '{018EF6B5-AB94-40C6-AA53-46943E824ACF}', 'Buffer' => '{"event":"ms.channel.connect","data":{"token":"99"}}']));
    ok(attr($id, 'Token') === '99', 'Klartext-Rückfall');

    // Fernseher nicht erreichbar: erst nach zwei Fehlversuchen aus
    IPS_SetProperty($id, 'Host', '127.0.0.2');
    IPS_ApplyChanges($id);
    ok(GetValue(IPS_GetObjectIDByIdent('Power', $id)) === true, 'Ein Fehlversuch: bleibt an (WLAN-Aussetzer)');
    SAMTV_Update($id);
    ok(GetValue(IPS_GetObjectIDByIdent('Power', $id)) === false, 'Nicht erreichbar: aus');
    ok(attr($id, 'Token') === '', 'Neue Adresse: Kopplung zurückgesetzt');

    // UPnP abgeschaltet: Variablen entfernt
    IPS_SetProperty($id, 'UseUpnpVolume', false);
    IPS_SetProperty($id, 'ShowRemote', false);
    IPS_ApplyChanges($id);
    ok(@IPS_GetObjectIDByIdent('Volume', $id) === false && @IPS_GetObjectIDByIdent('Remote', $id) === false, 'Abgeschaltete Variablen entfernt');

    // Alle öffentlichen Funktionen mit Typen
    $missing = [];
    foreach ((new ReflectionClass('SamsungTV'))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== 'SamsungTV' && !str_starts_with($method->getFileName() ?: '', realpath(__DIR__ . '/..'))) {
            continue;
        }
        if (!$method->hasReturnType()) {
            $missing[] = $method->getName() . '()';
        }
        foreach ($method->getParameters() as $p) {
            if (!$p->hasType()) {
                $missing[] = $method->getName() . ' $' . $p->getName();
            }
        }
    }
    ok($missing === [], 'Öffentliche Funktionen vollständig typisiert' . ($missing ? ': ' . implode(', ', $missing) : ''));
} catch (Throwable $e) {
    ok(false, get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

echo PHP_EOL . ($failed === 0 ? 'Ladetest bestanden.' : $failed . ' Prüfung(en) fehlgeschlagen.') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
