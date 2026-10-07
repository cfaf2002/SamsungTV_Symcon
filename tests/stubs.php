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
    if (basename($file) === 'GlobalStubs.php') {
        // Medienobjekte sind in den Stubs nur leere Hüllen: Datei und Inhalt im Speicher nachbilden
        $code = str_replace(
            [
                'function IPS_GetMedia(int $MediaID)
{
    return [];',
                'function IPS_GetMediaContent(int $MediaID)
{
    return \'\';',
                'function IPS_SetMediaContent(int $MediaID, string $Content)
{
    return true;',
                'function IPS_SetMediaFile(int $MediaID, string $FilePath, bool $FileMustExists)
{
    return true;',
            ],
            [
                'function IPS_GetMedia(int $MediaID)
{
    return [\'MediaID\' => $MediaID, \'MediaType\' => 1, \'MediaFile\' => $GLOBALS[\'testMedia\'][$MediaID][\'file\'] ?? \'\', \'MediaCRC\' => md5($GLOBALS[\'testMedia\'][$MediaID][\'content\'] ?? \'\'), \'MediaUpdated\' => 0];',
                'function IPS_GetMediaContent(int $MediaID)
{
    return $GLOBALS[\'testMedia\'][$MediaID][\'content\'] ?? \'\';',
                'function IPS_SetMediaContent(int $MediaID, string $Content)
{
    $GLOBALS[\'testMedia\'][$MediaID][\'content\'] = $Content;
    return true;',
                'function IPS_SetMediaFile(int $MediaID, string $FilePath, bool $FileMustExists)
{
    $GLOBALS[\'testMedia\'][$MediaID][\'file\'] = $FilePath;
    return true;',
            ],
            $code,
            $replaced
        );
        if ($replaced !== 4) {
            fwrite(STDERR, 'Medien-Stubs nicht gefunden (' . $replaced . '/4)' . PHP_EOL);
        }
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
        $packet = json_decode($JSONString, true);
        $GLOBALS['wscSent'][] = $packet;
        // Fernseher beantwortet den App-Start (wenn der Test eine Antwort vorgibt)
        $message = json_decode((string) hex2bin((string) $packet['Buffer']), true);
        if (($message['params']['event'] ?? '') === 'ed.apps.launch' && ($GLOBALS['tvAppAnswer'] ?? null) !== null) {
            $this->Push((string) json_encode(['event' => 'ed.apps.launch', 'data' => $GLOBALS['tvAppAnswer']]));
        }
        return '';
    }
    public function Push(string $Text)
    {
        $this->SendDataToChildren(json_encode(['DataID' => '{018EF6B5-AB94-40C6-AA53-46943E824ACF}', 'Buffer' => bin2hex($Text)]));
    }
}
PHP);

// Nachbau der Instanz „SmartThings Gerät“ (Bibliothek SmartThings): merkt sich Befehle
@mkdir($tmp . '/sth/SmartThingsGeraet', 0777, true);
file_put_contents($tmp . '/sth/library.json', json_encode([
    'id' => '{6E1B6E1B-0000-4000-8000-00000000AA02}', 'author' => 'Test', 'name' => 'STH Test', 'url' => '',
    'version' => '1.0', 'build' => 1, 'date' => 0,
]));
file_put_contents($tmp . '/sth/SmartThingsGeraet/module.json', json_encode([
    'id' => '{0E3FCD01-8B22-4987-88AE-26B272A7EA7D}', 'name' => 'SmartThingsGeraet', 'type' => 3, 'vendor' => 'Samsung SmartThings',
    'aliases' => [], 'parentRequirements' => [], 'childRequirements' => [], 'implemented' => [], 'prefix' => 'STH',
]));
file_put_contents($tmp . '/sth/SmartThingsGeraet/module.php', <<<'PHP'
<?php
declare(strict_types=1);
class SmartThingsGeraet extends IPSModule
{
    public function SendCommand(string $Component, string $Capability, string $Command, string $Arguments)
    {
        $GLOBALS['sthCommands'][] = [$Component, $Capability, $Command, json_decode($Arguments, true)];
        return $GLOBALS['sthOk'] ?? true;
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
\IPS\ModuleLoader::loadLibrary($tmp . '/sth/library.json');
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

// Instanz samt Variablen und Medien löschen, wie Symcon es beim Löschen im Objektbaum tut
function deleteInstance(int $id): void
{
    foreach (IPS_GetChildrenIDs($id) as $child) {
        match (IPS_GetObject($child)['ObjectType']) {
            2 => IPS_DeleteVariable($child),
            3 => IPS_DeleteScript($child, true),
            5 => IPS_DeleteMedia($child, true),
            default => IPS_DeleteInstance($child),
        };
    }
    IPS_DeleteInstance($id);
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

/** Gemerkten Weg für den App-Start vergessen (jeder Test prüft seinen Weg einzeln) */
function forgetAppMethod(int $id): void
{
    $module = \IPS\InstanceManager::getInstanceInterface($id);
    (new ReflectionMethod($module, 'WriteAttributeString'))->invoke($module, 'AppMethod', '');
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
    ok(($compatible['type'] ?? '') === 'connect' && ($compatible['moduleIDs'] ?? []) === ['{D68FD31F-0E90-7019-F16C-1949BD3079EF}'], 'Passt zu einem WebSocket Client als übergeordnete Instanz');
    // Ein schon verbundener WebSocket Client (Instanz aus älterer Version) wird übernommen
    $parent = IPS_CreateInstance('{D68FD31F-0E90-7019-F16C-1949BD3079EF}');
    IPS_ConnectInstance($id, $parent);
    IPS_ApplyChanges($parent);
    IPS_ApplyChanges($id);
    ok(attr($id, 'Socket') === $parent, 'Verbundenen WebSocket Client übernommen');
    ok(IPS_GetInstance($id)['ConnectionID'] === 0, 'Ohne Adresse getrennt (kein Fehler im Objektbaum)');
    // Fehlt er, legt das Modul selbst einen an
    $other = IPS_CreateInstance('{0D4C6AE0-8BE5-420E-92C6-9ACD54D1B01D}');
    IPS_ApplyChanges($other);
    $socket = (int) attr($other, 'Socket');
    ok($socket > 0 && $socket !== $parent && IPS_GetInstance($socket)['ModuleInfo']['ModuleID'] === '{D68FD31F-0E90-7019-F16C-1949BD3079EF}', 'Eigenen WebSocket Client angelegt');
    IPS_ApplyChanges($other);
    ok((int) attr($other, 'Socket') === $socket, 'Kein zweiter WebSocket Client bei erneutem Übernehmen');
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
    ok(IPS_GetProperty($parent, 'Active') === true && IPS_GetInstance($id)['ConnectionID'] === $parent, 'WebSocket-Client aktiv geschaltet und verbunden');
    ok(IPS_GetProperty($parent, 'VerifyCertificate') === false, 'Zertifikatsprüfung für das Gerätezertifikat aus');
    ok(IPS_GetProperty($parent, 'URL') === 'wss://127.0.0.1:8002/api/v2/channels/samsung.remote.control?name=U3ltY29u', 'URL verschlüsselt mit Name, ohne Token');
    ok(json_decode(SAMTV_GetDeviceInfo($id), true)['model'] === 'QE55Q80BAT', 'Geräteinfo gelesen');
    ok(json_decode(SAMTV_GetDeviceInfo($id), true)['name'] === '75" Neo QLED', 'HTML-kodierter Name zurückgewandelt');
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
    forgetAppMethod($id);
    IPS_RequestAction($id, 'App', 5);
    ok(lastSent()['params']['data']['appId'] === 'org.tizen.browser' && lastSent()['params']['data']['action_type'] === 'NATIVE_LAUNCH', 'App aus der Variable (Browser, NATIVE_LAUNCH)');
    // Antwort des Fernsehers auf den App-Start
    forgetAppMethod($id);
    tv(['launched' => '']);
    $GLOBALS['tvAppAnswer'] = 200;
    ok(SAMTV_LaunchApp($id, '3201907018807') === true && (tvState()['launched'] ?? '') === '', 'App gestartet (Antwort 200): kein zweiter Weg');
    $GLOBALS['tvAppAnswer'] = 404;
    ok(SAMTV_LaunchApp($id, '3201907018807') === true && (tvState()['launched'] ?? '') === '3201907018807', 'App abgelehnt (404): Start über REST');
    tv(['launched' => '']);
    $GLOBALS['tvAppAnswer'] = null;
    $started = microtime(true);
    ok(SAMTV_LaunchApp($id, '3201907018807') === true && (tvState()['launched'] ?? '') === '3201907018807', 'Keine Antwort (neuere Modelle): Start über REST');
    ok(microtime(true) - $started < 3, 'Wartet höchstens kurz auf die Antwort');
    // gemerkter Weg: direkt REST, ohne Warten
    tv(['launched' => '']);
    $count = count($GLOBALS['wscSent']);
    $started = microtime(true);
    ok(SAMTV_LaunchApp($id, '3201907018807') === true && (tvState()['launched'] ?? '') === '3201907018807' && count($GLOBALS['wscSent']) === $count, 'Gemerkter Weg REST: direkt, ohne WebSocket');
    ok(microtime(true) - $started < 1, 'Gemerkter Weg ohne Wartezeit');
    forgetAppMethod($id);
    // App-Start über die SmartThings-Instanz
    $sth = IPS_CreateInstance('{0E3FCD01-8B22-4987-88AE-26B272A7EA7D}');
    IPS_SetProperty($id, 'SmartThingsInstance', $sth);
    IPS_ApplyChanges($id);
    $count = count($GLOBALS['wscSent']);
    ok(SAMTV_LaunchApp($id, '3201907018807') === true && end($GLOBALS['sthCommands']) === ['main', 'custom.launchapp', 'launchApp', ['3201907018807']], 'App-Start über SmartThings (custom.launchapp)');
    ok(count($GLOBALS['wscSent']) === $count, 'Mit SmartThings kein zusätzlicher lokaler Start');
    $GLOBALS['sthOk'] = false;
    ok(SAMTV_LaunchApp($id, '3201907018807') === true && count($GLOBALS['wscSent']) === $count + 1, 'SmartThings abgelehnt: lokaler Start als Rückfall');
    $GLOBALS['sthOk'] = true;
    IPS_SetProperty($id, 'SmartThingsInstance', 0);
    IPS_ApplyChanges($id);
    // Favorit mit einer ID, die es auf diesem Fernseher nicht gibt
    forgetAppMethod($id);
    IPS_SetProperty($id, 'Apps', json_encode([['Name' => 'Netflix', 'AppID' => '11101200001']]));
    IPS_ApplyChanges($id);
    IPS_RequestAction($id, 'App', 1);
    ok(lastSent()['params']['data']['appId'] === '3201907018807', 'Fremde App-ID über den Namen auf die ID des Fernsehers umgestellt');
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

    // App-Icons und laufende App
    $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
    $media = IPS_CreateMedia(1);
    IPS_SetMediaFile($media, 'netflix.png', false);
    IPS_SetMediaContent($media, $png);
    IPS_SetProperty($id, 'Apps', json_encode([
        ['Name' => 'Netflix', 'AppID' => '3201907018807', 'Icon' => $media],
        ['Name' => 'Disney+', 'AppID' => '3201901017640', 'Icon' => 0],
        ['Name' => 'Nicht da', 'AppID' => '9999999999999', 'Icon' => 0],
    ]));
    IPS_ApplyChanges($id);
    $tileHtml = SAMTV_GetVisualizationTile($id);
    ok(str_contains($tileHtml, 'data:image\/png;base64,' . str_replace('/', '\/', $png)), 'Icon als Data-URI in der Kachel');
    ok(!str_contains(attr($id, 'TileData'), 'base64'), 'Icons nicht in jeder Aktualisierung');
    tv(['launched' => '3201901017640']);
    SAMTV_Update($id);
    ok(GetValue(IPS_GetObjectIDByIdent('App', $id)) === 2, 'Laufende App erkannt (Disney+)');
    ok(json_decode(attr($id, 'TileData'), true)['running'] === 2, 'Kachel zeigt die laufende App');
    tv(['launched' => '']);
    SAMTV_Update($id);
    ok(GetValue(IPS_GetObjectIDByIdent('App', $id)) === 0, 'Keine App sichtbar: App = 0');
    $key = attr($id, 'TileIconsKey');
    IPS_SetMediaContent($media, base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>'));
    IPS_SetMediaFile($media, 'netflix.svg', false);
    tv(['launched' => '3201907018807']);
    SAMTV_Update($id);
    ok(attr($id, 'TileIconsKey') !== $key, 'Geändertes Icon wird an die Kachel geschickt');
    ok(str_contains(SAMTV_GetVisualizationTile($id), 'data:image\/svg+xml;base64,'), 'SVG-Icon mit passendem Typ');
    // großes Bild wird verkleinert
    $big = imagecreatetruecolor(400, 300);
    imagefill($big, 0, 0, imagecolorallocate($big, 200, 30, 30));
    ob_start();
    imagepng($big);
    $bigPng = (string) ob_get_clean();
    IPS_SetMediaFile($media, 'gross.png', false);
    IPS_SetMediaContent($media, base64_encode($bigPng));
    $module = \IPS\InstanceManager::getInstanceInterface($id);
    [$uri, $note] = (new ReflectionMethod($module, 'AppIcon'))->invoke($module, $media);
    $small = str_starts_with($uri, 'data:image/png;base64,') ? @getimagesizefromstring((string) base64_decode(substr($uri, 22))) : false;
    ok($small !== false && $small[0] === 128 && $small[1] === 128, 'Großes Bild (400×300) auf 128×128 verkleinert (' . $note . ')');
    ok(str_contains((string) json_encode(json_decode(IPS_GetConfigurationForm($id), true), JSON_UNESCAPED_UNICODE), '400×300'), 'Formular zeigt den Zustand der Icons');
    // Bild, das PHP nicht einlesen kann (z. B. WebP ohne Unterstützung): Original statt nichts
    $broken = substr($bigPng, 0, 64) . str_repeat("\x00", 70000);
    IPS_SetMediaContent($media, base64_encode($broken));
    [$uri, $note] = (new ReflectionMethod($module, 'AppIcon'))->invoke($module, $media);
    ok(str_starts_with($uri, 'data:image/png;base64,') && strlen($uri) > 90000, 'Nicht verkleinerbares Bild im Original übernommen (' . $note . ')');
    tv(['noAppStatus' => true]);
    SAMTV_Update($id);
    SAMTV_Update($id);
    SAMTV_Update($id);
    ok(attr($id, 'AppStatusFailures') === 3, 'Kein App-Status vom Fernseher: nach drei Versuchen abgeschaltet');
    tv(['noAppStatus' => false, 'launched' => '']);

    // Aus- und Einschalten
    ok(SAMTV_PowerOff($id) === true && lastSent()['params']['DataOfCmd'] === 'KEY_POWER', 'Ausschalten mit KEY_POWER');
    ok(attr($id, 'PowerTarget') === 'off', 'Schaltziel aus gemerkt (schnelle Abfrage)');
    tv(['power' => 'standby']);
    SAMTV_Update($id);
    ok(GetValue(IPS_GetObjectIDByIdent('Power', $id)) === false && attr($id, 'PowerTarget') === '', 'Standby erkannt, Schaltziel erreicht');
    ok(IPS_GetProperty($parent, 'Active') === false, 'WebSocket-Client im Standby aus');
    ok(IPS_GetInstance($id)['ConnectionID'] === 0 && attr($id, 'Socket') === $parent, 'Im Standby getrennt, WebSocket Client gemerkt');
    $notice = static function (int $id): bool {
        foreach (json_decode(IPS_GetConfigurationForm($id), true)['elements'] as $e) {
            if (($e['name'] ?? '') === 'StandbyNotice') {
                return (bool) ($e['visible'] ?? false);
            }
        }
        return false;
    };
    ok($notice($id), 'Formular: Hinweis „kein Fehler“ im Standby sichtbar');
    ok(SAMTV_SendKey($id, 'KEY_HOME') === false, 'Im Standby keine Tasten');
    IPS_SetProperty($id, 'MAC', 'AA-BB-CC-DD-EE-FF');
    IPS_SetProperty($id, 'Broadcast', '127.255.255.255');
    IPS_ApplyChanges($id);
    ok(SAMTV_PowerOn($id) === true, 'Einschalten: Wake-on-LAN gesendet');
    ok(IPS_GetProperty($parent, 'Active') === true && IPS_GetInstance($id)['ConnectionID'] === $parent, 'Netzwerk-Standby: WebSocket-Client zum Einschalten verbunden');
    SAMTV_Update($id);
    ok(IPS_GetProperty($parent, 'Active') === true, 'Abfrage im Standby trennt die Verbindung beim Einschalten nicht');
    $count = count($GLOBALS['wscSent']);
    push($parent, (string) json_encode(['event' => 'ms.channel.connect', 'data' => ['id' => 'x']]));
    ok(count($GLOBALS['wscSent']) > $count && (lastSent()['params']['DataOfCmd'] ?? '') === 'KEY_POWER', 'Nach dem Verbinden die Ein/Aus-Taste gesendet');
    $count = count($GLOBALS['wscSent']);
    push($parent, (string) json_encode(['event' => 'ms.channel.connect', 'data' => ['id' => 'x']]));
    ok(count($GLOBALS['wscSent']) === $count, 'Ein/Aus-Taste nur einmal (sonst ginge er wieder aus)');
    tv(['power' => 'on']);
    SAMTV_Update($id);
    ok(GetValue(IPS_GetObjectIDByIdent('Power', $id)) === true, 'Wieder an');
    ok(!$notice($id), 'Formular: Hinweis bei eingeschaltetem Fernseher ausgeblendet');
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
    IPS_RequestAction($id, 'Poll', 0);
    ok(GetValue(IPS_GetObjectIDByIdent('Power', $id)) === false, 'Timer-Abfrage über RequestAction (keine öffentliche Funktion)');
    SAMTV_Update($id);
    ok(GetValue(IPS_GetObjectIDByIdent('Power', $id)) === false, 'Nicht erreichbar: aus');
    ok(attr($id, 'Token') === '', 'Neue Adresse: Kopplung zurückgesetzt');

    // UPnP abgeschaltet: Variablen entfernt
    IPS_SetProperty($id, 'UseUpnpVolume', false);
    IPS_SetProperty($id, 'ShowRemote', false);
    IPS_ApplyChanges($id);
    ok(@IPS_GetObjectIDByIdent('Volume', $id) === false && @IPS_GetObjectIDByIdent('Remote', $id) === false, 'Abgeschaltete Variablen entfernt');

    // Löschen der Instanz: eigenen WebSocket Client mit entfernen (erkannt am Ident, nicht an Attributen)
    $wsc = '{D68FD31F-0E90-7019-F16C-1949BD3079EF}';
    ok(IPS_GetObject($socket)['ObjectIdent'] === 'SAMTV_SOCKET_' . $other && IPS_GetObject($parent)['ObjectIdent'] === 'SAMTV_SOCKET_' . $id, 'WebSocket Client als eigener gekennzeichnet');
    $module = \IPS\InstanceManager::getInstanceInterface($other);
    $module->Destroy();
    ok(IPS_InstanceExists($socket), 'Destroy beim Modul-Update (Instanz existiert noch): WebSocket Client bleibt');
    deleteInstance($other);
    $module->Destroy();
    ok(!IPS_InstanceExists($socket), 'Instanz gelöscht: eigener WebSocket Client entfernt');
    $third = IPS_CreateInstance('{0D4C6AE0-8BE5-420E-92C6-9ACD54D1B01D}');
    IPS_ApplyChanges($third);
    $thirdSocket = (int) attr($third, 'Socket');
    $foreign = IPS_CreateInstance('{0D4C6AE0-8BE5-420E-92C6-9ACD54D1B01D}');
    IPS_ConnectInstance($foreign, $thirdSocket);
    $userSocket = IPS_CreateInstance($wsc);
    $module = \IPS\InstanceManager::getInstanceInterface($third);
    deleteInstance($third);
    $module->Destroy();
    ok(IPS_InstanceExists($thirdSocket), 'Hängt eine andere Instanz daran: WebSocket Client bleibt');
    IPS_DisconnectInstance($foreign);
    IPS_ApplyChanges($id);
    ok(!IPS_InstanceExists($thirdSocket), 'Verwaister eigener WebSocket Client beim Übernehmen nachträglich entfernt');
    ok(IPS_InstanceExists($userSocket) && IPS_InstanceExists($parent), 'Fremde und benutzte WebSocket Clients bleiben unberührt');

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
