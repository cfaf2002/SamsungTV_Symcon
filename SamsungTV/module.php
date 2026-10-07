<?php

declare(strict_types=1);

/**
 * Samsung TV – IP-Symcon-Modul für Samsung-Fernseher mit Tizen (ab 2016)
 *
 * @author    Armin Frohwerk
 * @copyright 2026 Armin Frohwerk
 * @license   MIT – siehe Datei LICENSE im Hauptverzeichnis
 *
 * SPDX-License-Identifier: MIT
 */

require_once __DIR__ . '/../libs/SamsungApiTrait.php';
require_once __DIR__ . '/../libs/SamsungTileTrait.php';

/**
 * Steuert einen Samsung-Fernseher über seine lokale WebSocket-Schnittstelle
 * (samsung.remote.control). Die Verbindung hält ein Symcon-WebSocket-Client als
 * übergeordnete Instanz; er wird nur aktiv geschaltet, solange der Fernseher an ist.
 */
class SamsungTV extends IPSModuleStrict
{
    use SamsungApiTrait;
    use SamsungTileTrait;

    // Instanz „SmartThings Gerät“ aus der Bibliothek SmartThings (optional, für den App-Start)
    private const STH_DEVICE_GUID = '{0E3FCD01-8B22-4987-88AE-26B272A7EA7D}';

    // Symcon-I/O „WebSocket Client“ und Datenfluss vom Typ „Simple“
    private const WSC_GUID = '{D68FD31F-0E90-7019-F16C-1949BD3079EF}';
    private const TX_GUID = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';

    // Name, unter dem Symcon am Fernseher erscheint (Geräteverwaltung)
    private const CLIENT_NAME = 'Symcon';

    // Kopplung
    private const PAIR_UNKNOWN = 0;
    private const PAIR_WAITING = 1;
    private const PAIR_DENIED = 2;
    private const PAIR_OK = 3;

    // Quellen: Wert der Variable => Taste
    private const SOURCES = [
        0 => ['TV', 'KEY_TV'],
        1 => ['HDMI 1', 'KEY_HDMI1'],
        2 => ['HDMI 2', 'KEY_HDMI2'],
        3 => ['HDMI 3', 'KEY_HDMI3'],
        4 => ['HDMI 4', 'KEY_HDMI4'],
    ];

    // Fernbedienung (Variable „Remote“): Wert => [Beschriftung, Taste, Symbol]
    private const REMOTE = [
        1  => ['Home', 'KEY_HOME', 'house'],
        2  => ['Back', 'KEY_RETURN', 'arrow-left'],
        3  => ['Up', 'KEY_UP', 'chevron-up'],
        4  => ['Down', 'KEY_DOWN', 'chevron-down'],
        5  => ['Left', 'KEY_LEFT', 'chevron-left'],
        6  => ['Right', 'KEY_RIGHT', 'chevron-right'],
        7  => ['OK', 'KEY_ENTER', 'circle-dot'],
        8  => ['Play', 'KEY_PLAY', 'play'],
        9  => ['Pause', 'KEY_PAUSE', 'pause'],
        10 => ['Stop', 'KEY_STOP', 'stop'],
        11 => ['Rewind', 'KEY_REWIND', 'backward'],
        12 => ['Fast forward', 'KEY_FF', 'forward'],
        13 => ['Channel +', 'KEY_CHUP', 'chevrons-up'],
        14 => ['Channel −', 'KEY_CHDOWN', 'chevrons-down'],
        15 => ['Volume +', 'KEY_VOLUP', 'volume-high'],
        16 => ['Volume −', 'KEY_VOLDOWN', 'volume-low'],
        17 => ['Mute', 'KEY_MUTE', 'volume-xmark'],
        18 => ['Menu', 'KEY_MENU', 'bars'],
        19 => ['Guide', 'KEY_GUIDE', 'table-list'],
        20 => ['Info', 'KEY_INFO', 'circle-info'],
        21 => ['Exit', 'KEY_EXIT', 'xmark'],
        22 => ['Source', 'KEY_SOURCE', 'right-to-bracket'],
    ];

    // Bekannte App-IDs für die Grundeinstellung (lassen sich vom Fernseher abrufen)
    private const DEFAULT_APPS = [
        ['Name' => 'Netflix', 'AppID' => '3201907018807'],
        ['Name' => 'YouTube', 'AppID' => '111299001912'],
        ['Name' => 'Prime Video', 'AppID' => '3201910019365'],
        ['Name' => 'Disney+', 'AppID' => '3201901017640'],
        ['Name' => 'Browser', 'AppID' => 'org.tizen.browser'],
    ];

    public function Create(): void
    {
        // Never delete this line!
        parent::Create();

        // Verbindung
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Connection', 0);
        $this->RegisterPropertyString('MAC', '');
        $this->RegisterPropertyString('Broadcast', '255.255.255.255');
        $this->RegisterPropertyInteger('PollInterval', 10);

        // Funktionen
        $this->RegisterPropertyInteger('PowerOffMode', 0);
        $this->RegisterPropertyBoolean('UseUpnpVolume', true);
        $this->RegisterPropertyInteger('VolumeStep', 0);
        $this->RegisterPropertyBoolean('ShowSource', true);
        $this->RegisterPropertyBoolean('ShowRemote', true);
        $this->RegisterPropertyString('Apps', json_encode(self::DEFAULT_APPS));
        $this->RegisterPropertyInteger('SmartThingsInstance', 0);

        // Kachel
        $this->RegisterPropertyBoolean('UseTile', true);
        $this->RegisterPropertyInteger('TileTheme', 0);
        $this->RegisterPropertyBoolean('TileShowSources', true);
        $this->RegisterPropertyBoolean('TileShowApps', true);
        $this->RegisterPropertyBoolean('TileShowMedia', true);

        $this->RegisterAttributeString('Token', '');
        $this->RegisterAttributeInteger('Pairing', self::PAIR_UNKNOWN);
        $this->RegisterAttributeString('DeviceInfo', '{}');
        $this->RegisterAttributeBoolean('TvOn', false);
        $this->RegisterAttributeBoolean('Reachable', false);
        $this->RegisterAttributeInteger('FailCount', 0);
        $this->RegisterAttributeString('PowerTarget', '');
        $this->RegisterAttributeInteger('PowerTargetUntil', 0);
        $this->RegisterAttributeString('InstalledApps', '');
        $this->RegisterAttributeString('AppMethod', '');
        $this->RegisterAttributeInteger('AppStatusFailures', 0);
        $this->RegisterAttributeString('TileIconsKey', '');
        $this->RegisterAttributeString('TileData', '{}');

        $this->RegisterTimer('Poll', 0, 'SAMTV_Poll($_IPS[\'TARGET\']);');
    }

    /**
     * Übergeordnete Instanz: ein eigener WebSocket Client (legt Symcon beim Anlegen mit an)
     */
    public function GetCompatibleParents(): string
    {
        return (string) json_encode([
            'type'      => 'require',
            'moduleIDs' => [self::WSC_GUID],
        ]);
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        $this->WatchParent();

        $this->SetVisualizationType($this->ReadPropertyBoolean('UseTile') ? 1 : 0);
        $this->MaintainVariables();

        // Neue Adresse: Gerät, Kopplung und Zustand neu ermitteln
        $host = $this->Host();
        $info = $this->DeviceInfo();
        if (($info['host'] ?? '') !== $host) {
            $this->WriteAttributeString('DeviceInfo', '{}');
            $this->WriteAttributeString('Token', '');
            $this->WriteAttributeInteger('Pairing', self::PAIR_UNKNOWN);
            $this->WriteAttributeString('InstalledApps', '');
            $this->WriteAttributeString('AppMethod', '');
        }
        $this->WriteAttributeInteger('FailCount', 0);
        $this->WriteAttributeInteger('AppStatusFailures', 0);

        if ($host === '') {
            $this->SetTimerInterval('Poll', 0);
            $this->WriteAttributeBoolean('TvOn', false);
            $this->ConfigureParent();
            $this->SetStatus(104);
            $this->PushTile();
            return;
        }

        $this->SetStatus($this->StatusFromPairing());
        $this->ConfigureParent();
        $this->ScheduleNext();

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->Poll();
        } else {
            $this->PushTile();
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                if ($this->Host() !== '') {
                    $this->Poll();
                }
                break;
            case FM_CONNECT:
            case FM_DISCONNECT:
                $this->WatchParent();
                break;
            case IM_CHANGESTATUS:
                // WebSocket verbunden oder getrennt: Kachel sofort nachziehen
                $this->SendDebug('WebSocket', $this->HasActiveParent() ? 'connected' : 'disconnected', 0);
                $this->PushTile();
                break;
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Power':
                $this->SetPower((bool) $Value);
                break;
            case 'Volume':
                $this->SetVolume((int) $Value);
                break;
            case 'Mute':
                $this->SetMute((bool) $Value);
                break;
            case 'Source':
                $this->SelectSourceValue((int) $Value);
                break;
            case 'App':
                $this->LaunchAppValue((int) $Value);
                break;
            case 'Remote':
                if (isset(self::REMOTE[(int) $Value])) {
                    $this->SendKey(self::REMOTE[(int) $Value][1]);
                }
                break;
            case 'Key':
                // Taste aus der Kachel
                $this->SendKey((string) $Value);
                break;
            case 'VolumeStep':
                // +1/-1 aus der Kachel: mit UPnP in festen Schritten, sonst als Taste
                $this->StepVolume((int) $Value);
                break;
            case 'Refresh':
                $this->Update();
                break;
            case 'LoadApps':
                $this->LoadAppsIntoForm();
                break;
            case 'ResetPairing':
                $this->ResetPairing();
                echo $this->Translate('Pairing reset. Please allow access on the TV when it asks.');
                break;
            case 'TestWakeOnLan':
                echo $this->WakeUp()
                    ? $this->Translate('Wake-on-LAN packet sent.')
                    : $this->Translate('Wake-on-LAN could not be sent. Please check the MAC address.');
                break;
            default:
                throw new Exception('Invalid ident: ' . $Ident);
        }
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $info = $this->DeviceInfo();
        if ($this->Host() !== '' && ($info['model'] ?? '') !== '') {
            $caption = sprintf(
                $this->Translate('Found: %s (%s) · Token authentication: %s · MAC (Wi-Fi): %s'),
                $info['name'] !== '' ? $info['name'] : 'Samsung TV',
                $info['model'],
                $info['tokenAuth'] ? $this->Translate('yes') : $this->Translate('no'),
                $info['mac'] !== '' ? $info['mac'] : '–'
            );
            $this->InjectProperty($form['elements'], 'DeviceLabel', 'caption', $caption);
            $this->InjectProperty($form['elements'], 'DeviceLabel', 'visible', true);
        }
        $lines = [];
        foreach ($this->Apps() as $app) {
            if ($app['Icon'] > 0) {
                [$uri, $note] = $this->AppIcon($app['Icon']);
                $lines[] = $app['Name'] . ': ' . ($uri !== '' ? '✓ ' : '✗ ') . $this->Translate($note);
            }
        }
        if ($lines !== []) {
            $this->InjectProperty($form['elements'], 'IconInfo', 'caption', $this->Translate('Icons') . ' – ' . implode(' · ', $lines));
            $this->InjectProperty($form['elements'], 'IconInfo', 'visible', true);
        }
        $pairing = $this->ReadAttributeInteger('Pairing');
        $this->InjectProperty($form['elements'], 'PairingLabel', 'caption', $this->Translate(match ($pairing) {
            self::PAIR_OK      => 'Paired – Symcon is allowed on the TV.',
            self::PAIR_DENIED  => 'Access was denied on the TV. Allow “Symcon” on the TV under Settings → General → External Device Manager → Device Connection Manager → Device List, then use “Pair again”.',
            self::PAIR_WAITING => 'Waiting for confirmation – please allow “Symcon” on the TV.',
            default            => 'Not paired yet. When the TV is on, it asks once whether Symcon may control it.',
        }));
        return (string) json_encode($form);
    }

    public function GetConfigurationForParent(): string
    {
        return (string) json_encode($this->ParentConfiguration());
    }

    /**
     * Empfängt Nachrichten des Fernsehers vom WebSocket-Client (Buffer HEX-kodiert).
     */
    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        $buffer = (string) ($data['Buffer'] ?? '');
        if ($buffer !== '' && strlen($buffer) % 2 === 0 && ctype_xdigit($buffer)) {
            $buffer = (string) hex2bin($buffer);
        }
        // Rückfall für ältere Symcon-Versionen: Klartext
        if ($buffer === '') {
            return '';
        }

        // Große Antworten (App-Liste) können in mehreren Stücken kommen
        $pending = $this->GetBuffer('RX') . $buffer;
        $message = json_decode($pending, true);
        if (!is_array($message)) {
            $this->SetBuffer('RX', strlen($pending) < 262144 ? $pending : '');
            return '';
        }
        $this->SetBuffer('RX', '');
        $this->HandleMessage($message);
        return '';
    }

    // ------------------------------------------------------------------
    // Öffentliche Befehle (SAMTV_…)
    // ------------------------------------------------------------------

    /**
     * Fragt den Zustand sofort ab (Fernseher an/aus, Lautstärke).
     */
    public function Update(): bool
    {
        $this->Poll();
        return $this->ReadAttributeBoolean('Reachable');
    }

    /**
     * Timer: Zustand abfragen. Läuft im eingestellten Takt, nach einem Schaltbefehl kurz alle 2 Sekunden.
     */
    public function Poll(): void
    {
        $host = $this->Host();
        if ($host === '') {
            return;
        }
        $info = $this->FetchDeviceInfo($host);
        if ($info !== null) {
            $info['host'] = $host;
            $this->WriteAttributeString('DeviceInfo', (string) json_encode($info));
            $this->WriteAttributeInteger('FailCount', 0);
            $this->WriteAttributeBoolean('Reachable', true);
            $on = $info['powerState'] === 'on';
        } else {
            $this->SendDebug('Poll', 'not reachable: ' . $this->apiError, 0);
            $this->WriteAttributeBoolean('Reachable', false);
            $fails = $this->ReadAttributeInteger('FailCount') + 1;
            $this->WriteAttributeInteger('FailCount', $fails);
            // Erst nach zwei Fehlversuchen als aus werten (WLAN-Aussetzer)
            $on = $fails < 2 && $this->ReadAttributeBoolean('TvOn');
        }

        if ($on !== $this->ReadAttributeBoolean('TvOn')) {
            $this->SendDebug('Power', $on ? 'on' : 'off', 0);
            $this->WriteAttributeBoolean('TvOn', $on);
        }
        if ($on) {
            $this->SetBuffer('PowerKey', '');
        }
        $this->SetValueIfChanged('Power', $on);

        if ($on && $this->ReadPropertyBoolean('UseUpnpVolume')) {
            $this->ReadVolume();
        }
        $this->DetectRunningApp($on);

        // Schaltziel erreicht oder abgelaufen
        $target = $this->ReadAttributeString('PowerTarget');
        if ($target !== '' && (($target === 'on') === $on || time() > $this->ReadAttributeInteger('PowerTargetUntil'))) {
            $this->WriteAttributeString('PowerTarget', '');
            $this->SetBuffer('PowerKey', '');
        }
        // WebSocket-Client passend schalten (ändert nur, wenn nötig; auch nach Abschalten von Hand)
        $this->ConfigureParent();
        $this->ScheduleNext();
        $this->PushTile();
    }

    public function SetPower(bool $Value): bool
    {
        return $Value ? $this->PowerOn() : $this->PowerOff();
    }

    /**
     * Schaltet ein: Wake-on-LAN (braucht „Mit Mobilgerät einschalten“ am Fernseher).
     */
    public function PowerOn(): bool
    {
        if ($this->ReadAttributeBoolean('TvOn')) {
            return true;
        }
        $this->SetPowerTarget('on');
        // Immer Wake-on-LAN – für Fernseher, die im Standby nicht mehr im Netz antworten
        $wol = $this->WakeUp();
        // Neuere Modelle bleiben im Netzwerk-Standby erreichbar und reagieren dann zuverlässiger auf die
        // Ein/Aus-Taste über den WebSocket als auf Wake-on-LAN. Gesendet wird sie, sobald die Verbindung steht.
        $standby = $this->ReadAttributeBoolean('Reachable') && ($this->DeviceInfo()['powerState'] ?? '') === 'standby';
        if ($standby) {
            $this->SetBuffer('PowerKey', '1');
            $this->ConfigureParent();
            $this->SendDebug('PowerOn', 'network standby: connecting to send KEY_POWER', 0);
        }
        if (!$wol && !$standby) {
            $this->SendDebug('PowerOn', 'Wake-on-LAN failed: ' . $this->apiError, 0);
        }
        return $wol || $standby;
    }

    /**
     * Schaltet aus: Ein/Aus-Taste, bei „The Frame“ auf Wunsch lang gedrückt (ganz aus statt Kunstmodus).
     */
    public function PowerOff(): bool
    {
        if (!$this->ReadAttributeBoolean('TvOn')) {
            return true;
        }
        $this->SetPowerTarget('off');
        if ($this->ReadPropertyInteger('PowerOffMode') === 1) {
            return $this->HoldKey('KEY_POWER', 3000);
        }
        return $this->SendKey('KEY_POWER');
    }

    /**
     * Sendet eine Taste der Fernbedienung, z. B. KEY_VOLUP, KEY_HOME, KEY_HDMI1.
     */
    public function SendKey(string $Key): bool
    {
        $key = $this->NormalizeKey($Key);
        if ($key === '') {
            $this->SendDebug('SendKey', 'invalid key: ' . $Key, 0);
            return false;
        }
        $ok = $this->SendRemote('Click', $key);
        // Lautstärke nach Tastendruck nachlesen, damit Variable und Kachel stimmen
        if ($ok && in_array($key, ['KEY_VOLUP', 'KEY_VOLDOWN', 'KEY_MUTE'], true) && $this->ReadPropertyBoolean('UseUpnpVolume')) {
            IPS_Sleep(150);
            $this->ReadVolume();
            $this->PushTile();
        }
        return $ok;
    }

    /**
     * Sendet mehrere Tasten nacheinander, getrennt durch Komma (z. B. "KEY_HOME,KEY_RIGHT,KEY_ENTER").
     */
    public function SendKeys(string $Keys, int $DelayMs): bool
    {
        $ok = true;
        $list = array_values(array_filter(array_map('trim', explode(',', $Keys)), static fn (string $k): bool => $k !== ''));
        foreach ($list as $i => $key) {
            if ($i > 0) {
                IPS_Sleep(max(50, min(5000, $DelayMs)));
            }
            $ok = $this->SendKey($key) && $ok;
        }
        return $ok && $list !== [];
    }

    /**
     * Hält eine Taste gedrückt (höchstens 5 Sekunden).
     */
    public function HoldKey(string $Key, int $Milliseconds): bool
    {
        $key = $this->NormalizeKey($Key);
        if ($key === '' || !$this->SendRemote('Press', $key)) {
            return false;
        }
        IPS_Sleep(max(100, min(5000, $Milliseconds)));
        return $this->SendRemote('Release', $key);
    }

    /**
     * Wählt eine Quelle: TV, HDMI1 … HDMI4 (oder direkt eine Taste wie KEY_HDMI2).
     */
    public function SelectSource(string $Source): bool
    {
        $source = strtoupper((string) preg_replace('/\s+/', '', $Source));
        foreach (self::SOURCES as $value => [$label, $key]) {
            if ($source === strtoupper(str_replace(' ', '', $label)) || $source === $key) {
                return $this->SelectSourceValue($value);
            }
        }
        return $this->SendKey($source);
    }

    /**
     * Startet eine App über ihre ID (z. B. 3201907018807 für Netflix).
     */
    public function LaunchApp(string $AppID): bool
    {
        $appID = trim($AppID);
        if ($appID === '' || !preg_match('/^[A-Za-z0-9._-]{3,64}$/', $appID)) {
            return false;
        }
        $appID = $this->InstalledAppID($appID, '');

        // Weg, der bei diesem Fernseher zuletzt funktioniert hat, zuerst (spart Wartezeit)
        if ($this->ReadAttributeString('AppMethod') === 'rest' && $this->LaunchAppRestDirect($appID)) {
            return true;
        }

        // 1. Über SmartThings (zuverlässig bei neueren Modellen, die lokal keine Apps mehr starten)
        if ($this->LaunchAppSmartThings($appID)) {
            return true;
        }

        // 2. Über den WebSocket; der Fernseher antwortet mit ed.apps.launch (200 = gestartet)
        if ($this->HasActiveParent()) {
            $type = $this->AppType($appID);
            $this->SetBuffer('PendingApp', $appID . '|' . time());
            $sent = $this->Send([
                'method' => 'ms.channel.emit',
                'params' => [
                    'event' => 'ed.apps.launch',
                    'to'    => 'host',
                    'data'  => ['appId' => $appID, 'action_type' => $type, 'metaTag' => ''],
                ],
            ]);
            $this->SendDebug('App', 'launch ' . $appID . ' (' . $type . ')', 0);
            if ($sent) {
                // kurz auf die Antwort warten (kommt über ReceiveData in einem anderen Thread)
                for ($i = 0; $i < 15 && $this->GetBuffer('PendingApp') !== ''; $i++) {
                    IPS_Sleep(100);
                }
                $answer = $this->GetBuffer('LaunchAnswer');
                $this->SetBuffer('LaunchAnswer', '');
                if ($this->GetBuffer('PendingApp') === '' && $answer === '200') {
                    $this->RememberAppMethod('websocket');
                    return true;
                }
                $this->SetBuffer('PendingApp', '');
                $this->SendDebug('App', $answer === '' ? 'no answer from the TV' : 'rejected: ' . $answer, 0);
            }
        }

        // 3. Über REST
        $ok = $this->LaunchAppRestDirect($appID);
        if ($ok) {
            $this->RememberAppMethod('rest');
        }
        return $ok;
    }

    private function LaunchAppRestDirect(string $appID): bool
    {
        $host = $this->Host();
        $ok = $host !== '' && $this->ReadAttributeBoolean('TvOn') && $this->LaunchAppRest($host, $appID);
        $this->SendDebug('App', 'launch ' . $appID . ' via REST: ' . ($ok ? 'OK' : $this->apiError), 0);
        if (!$ok && $this->ReadAttributeString('AppMethod') === 'rest') {
            // hat nicht mehr geklappt: beim nächsten Mal wieder alle Wege versuchen
            $this->RememberAppMethod('');
        }
        return $ok;
    }

    private function RememberAppMethod(string $method): void
    {
        if ($this->ReadAttributeString('AppMethod') !== $method) {
            $this->WriteAttributeString('AppMethod', $method);
            $this->SendDebug('App', 'method for this TV: ' . ($method !== '' ? $method : 'unknown'), 0);
        }
    }

    /**
     * Startet die App über die verknüpfte Instanz „SmartThings Gerät“ (Fähigkeit custom.launchapp).
     */
    private function LaunchAppSmartThings(string $appID): bool
    {
        $instance = $this->ReadPropertyInteger('SmartThingsInstance');
        if ($instance <= 0 || !IPS_InstanceExists($instance) || IPS_GetInstance($instance)['ModuleInfo']['ModuleID'] !== self::STH_DEVICE_GUID
            || !function_exists('STH_SendCommand')) {
            return false;
        }
        $ok = (bool) @STH_SendCommand($instance, 'main', 'custom.launchapp', 'launchApp', (string) json_encode([$appID]));
        $this->SendDebug('App', 'launch ' . $appID . ' via SmartThings: ' . ($ok ? 'OK' : 'failed'), 0);
        if ($ok) {
            $this->RememberAppMethod('smartthings');
        }
        return $ok;
    }

    /**
     * Passt eine App-ID an die App-Liste des Fernsehers an: Ist die ID dort nicht vorhanden, wird die App
     * mit gleichem Namen genommen (App-IDs unterscheiden sich je nach Baujahr und Land).
     */
    private function InstalledAppID(string $appID, string $name): string
    {
        $installed = json_decode($this->GetInstalledApps(), true) ?: [];
        if ($installed === [] || in_array($appID, array_column($installed, 'appId'), true)) {
            return $appID;
        }
        if ($name === '') {
            foreach ($this->Apps() as $app) {
                if ($app['AppID'] === $appID) {
                    $name = $app['Name'];
                }
            }
        }
        $wanted = mb_strtolower(trim($name));
        if ($wanted === '') {
            return $appID;
        }
        foreach ($installed as $app) {
            if (mb_strtolower(trim((string) $app['name'])) === $wanted) {
                $this->SendDebug('App', $name . ': ' . $appID . ' → ' . $app['appId'] . ' (ID of this TV)', 0);
                return (string) $app['appId'];
            }
        }
        return $appID;
    }

    /**
     * Öffnet eine Webseite im Browser des Fernsehers.
     */
    public function OpenBrowser(string $URL): bool
    {
        if (!preg_match('#^https?://#i', $URL) || filter_var($URL, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        return $this->Send([
            'method' => 'ms.channel.emit',
            'params' => [
                'event' => 'ed.apps.launch',
                'to'    => 'host',
                'data'  => ['appId' => 'org.tizen.browser', 'action_type' => 'NATIVE_LAUNCH', 'metaTag' => $URL],
            ],
        ]);
    }

    /**
     * Schreibt Text in ein geöffnetes Eingabefeld am Fernseher (Bildschirmtastatur muss offen sein).
     */
    public function SendText(string $Text): bool
    {
        if ($Text === '' || mb_strlen($Text) > 500) {
            return false;
        }
        return $this->Send([
            'method' => 'ms.remote.control',
            'params' => [
                'Cmd'          => base64_encode($Text),
                'DataOfCmd'    => 'base64',
                'TypeOfRemote' => 'SendInputString',
            ],
        ]);
    }

    /**
     * Setzt die Lautstärke (0–100) über UPnP.
     */
    public function SetVolume(int $Volume): bool
    {
        $host = $this->Host();
        if ($host === '' || !$this->ReadPropertyBoolean('UseUpnpVolume') || !$this->ReadAttributeBoolean('TvOn')) {
            return false;
        }
        $volume = max(0, min(100, $Volume));
        if (!$this->UpnpSetVolume($host, $volume)) {
            $this->SendDebug('SetVolume', 'failed: ' . $this->apiError, 0);
            return false;
        }
        $this->SetValueIfChanged('Volume', $volume);
        $this->PushTile();
        return true;
    }

    /**
     * Schaltet den Ton stumm oder wieder an (UPnP; ohne UPnP über die Stummtaste).
     */
    public function SetMute(bool $Mute): bool
    {
        $host = $this->Host();
        if ($host === '' || !$this->ReadAttributeBoolean('TvOn')) {
            return false;
        }
        if (!$this->ReadPropertyBoolean('UseUpnpVolume')) {
            return $this->SendKey('KEY_MUTE');
        }
        if (!$this->UpnpSetMute($host, $Mute)) {
            $this->SendDebug('SetMute', 'failed: ' . $this->apiError, 0);
            return false;
        }
        $this->SetValueIfChanged('Mute', $Mute);
        $this->PushTile();
        return true;
    }

    /**
     * Fragt die installierten Apps beim Fernseher an. Die Antwort kommt kurz danach;
     * abrufbar mit SAMTV_GetInstalledApps.
     */
    public function RequestInstalledApps(): bool
    {
        return $this->Send([
            'method' => 'ms.channel.emit',
            'params' => ['event' => 'ed.installedApp.get', 'to' => 'host'],
        ]);
    }

    /**
     * Liefert die zuletzt vom Fernseher gemeldeten Apps als JSON: [{"name":…, "appId":…, "type":…}].
     */
    public function GetInstalledApps(): string
    {
        $apps = $this->ReadAttributeString('InstalledApps');
        return $apps !== '' ? $apps : '[]';
    }

    /**
     * Liefert die zuletzt gelesene Geräteinfo als JSON (Name, Modell, MAC, Netzwerk …).
     */
    public function GetDeviceInfo(): string
    {
        return (string) json_encode($this->DeviceInfo());
    }

    /**
     * Löscht den Token und koppelt neu (der Fernseher fragt erneut nach).
     */
    public function ResetPairing(): bool
    {
        $this->WriteAttributeString('Token', '');
        $this->WriteAttributeInteger('Pairing', self::PAIR_UNKNOWN);
        $this->SetStatus($this->Host() === '' ? 104 : 102);
        // Verbindung einmal trennen, damit die Anfrage neu gestellt wird
        $this->ConfigureParent(false);
        $this->ConfigureParent();
        $this->PushTile();
        return true;
    }

    // ------------------------------------------------------------------
    // Nachrichten des Fernsehers
    // ------------------------------------------------------------------

    private function HandleMessage(array $message): void
    {
        $event = (string) ($message['event'] ?? '');
        $data = is_array($message['data'] ?? null) ? $message['data'] : [];
        $this->SendDebug('Event', $event, 0);

        switch ($event) {
            case 'ms.channel.connect':
                $token = (string) ($data['token'] ?? '');
                if ($token !== '' && preg_match('/^[0-9A-Za-z]{1,64}$/', $token) && $token !== $this->ReadAttributeString('Token')) {
                    // Gilt ab der nächsten Verbindung; die laufende ist bereits erlaubt
                    $this->WriteAttributeString('Token', $token);
                    $this->SendDebug('Pairing', 'token received', 0);
                }
                $this->WriteAttributeInteger('Pairing', self::PAIR_OK);
                $this->SetStatus(102);
                // Einschalten aus dem Netzwerk-Standby: jetzt die Ein/Aus-Taste senden
                if ($this->GetBuffer('PowerKey') === '1' && !$this->ReadAttributeBoolean('TvOn')) {
                    $this->SetBuffer('PowerKey', '');
                    $this->SendRemote('Click', 'KEY_POWER');
                    $this->SendDebug('PowerOn', 'KEY_POWER sent after connect', 0);
                }
                // Manche Modelle (ab 2021) antworten nicht – deshalb höchstens einmal pro Stunde fragen
                if ($this->ReadAttributeString('InstalledApps') === '' && time() - (int) $this->GetBuffer('AppListAsked') > 3600) {
                    $this->SetBuffer('AppListAsked', (string) time());
                    $this->RequestInstalledApps();
                }
                break;

            case 'ms.channel.unauthorized':
                // Abgelehnt: nicht weiter verbinden, bis neu gekoppelt wird
                $this->WriteAttributeInteger('Pairing', self::PAIR_DENIED);
                $this->SetStatus(202);
                $this->ConfigureParent();
                break;

            case 'ms.channel.timeOut':
                $this->WriteAttributeInteger('Pairing', self::PAIR_WAITING);
                $this->SetStatus(201);
                break;

            case 'ed.installedApp.get':
                $list = is_array($data['data'] ?? null) ? $data['data'] : [];
                $apps = [];
                foreach ($list as $app) {
                    if (!is_array($app) || !isset($app['appId'])) {
                        continue;
                    }
                    $apps[] = [
                        'name'  => mb_substr((string) ($app['name'] ?? $app['appId']), 0, 60),
                        'appId' => (string) $app['appId'],
                        'type'  => (int) ($app['app_type'] ?? 2),
                    ];
                }
                usort($apps, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
                $this->WriteAttributeString('InstalledApps', (string) json_encode($apps));
                $this->SendDebug('Apps', count($apps) . ' apps', 0);
                break;

            case 'ed.apps.launch':
                // Antwort auf den App-Start: 200 = gestartet, sonst über REST nochmals versuchen
                // LaunchApp wartet darauf und versucht sonst REST
                $result = $message['data'] ?? null;
                $this->SendDebug('App', 'launch answer: ' . json_encode($result), 0);
                $this->SetBuffer('LaunchAnswer', is_scalar($result) ? (string) $result : (string) json_encode($result));
                $this->SetBuffer('PendingApp', '');
                break;

            case 'ms.error':
                $this->SendDebug('Error', (string) ($data['message'] ?? json_encode($data)), 0);
                break;
        }
        $this->PushTile();
    }

    // ------------------------------------------------------------------
    // Senden
    // ------------------------------------------------------------------

    private function SendRemote(string $cmd, string $key): bool
    {
        return $this->Send([
            'method' => 'ms.remote.control',
            'params' => [
                'Cmd'          => $cmd,
                'DataOfCmd'    => $key,
                'Option'       => 'false',
                'TypeOfRemote' => 'SendRemoteKey',
            ],
        ]);
    }

    /**
     * Schickt eine Nachricht über den WebSocket-Client (Buffer HEX-kodiert, IPSModuleStrict).
     */
    private function Send(array $payload): bool
    {
        if (!$this->HasActiveParent()) {
            $this->SendDebug('Send', 'no connection to the TV (off or not paired)', 0);
            return false;
        }
        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $this->SendDebug('Send', $json, 0);
        try {
            $this->SendDataToParent((string) json_encode(['DataID' => self::TX_GUID, 'Buffer' => bin2hex($json)]));
        } catch (Throwable $e) {
            $this->SendDebug('Send', 'failed: ' . $e->getMessage(), 0);
            return false;
        }
        return true;
    }

    private function NormalizeKey(string $key): string
    {
        $key = strtoupper(trim($key));
        if ($key !== '' && !str_starts_with($key, 'KEY_')) {
            $key = 'KEY_' . $key;
        }
        return preg_match('/^KEY_[A-Z0-9_]{1,40}$/', $key) ? $key : '';
    }

    private function SelectSourceValue(int $value): bool
    {
        if (!isset(self::SOURCES[$value])) {
            return false;
        }
        $ok = $this->SendKey(self::SOURCES[$value][1]);
        if ($ok) {
            $this->SetValueIfChanged('Source', $value);
            $this->PushTile();
        }
        return $ok;
    }

    private function LaunchAppValue(int $value): bool
    {
        $apps = $this->Apps();
        if (!isset($apps[$value - 1])) {
            return false;
        }
        $ok = $this->LaunchApp($apps[$value - 1]['AppID']);
        if ($ok) {
            $this->SetValueIfChanged('App', $value);
            $this->PushTile();
        }
        return $ok;
    }

    /**
     * Fragt per REST ab, welche Favoriten-App sichtbar läuft (GET /api/v2/applications/{id}).
     * Liefert der Fernseher dafür nie eine Antwort, wird es nach drei Versuchen abgeschaltet.
     */
    private function DetectRunningApp(bool $on): void
    {
        if (!$this->VariableExists('App')) {
            return;
        }
        if (!$on) {
            $this->SetValueIfChanged('App', 0);
            return;
        }
        $failures = $this->ReadAttributeInteger('AppStatusFailures');
        if ($failures >= 3) {
            return; // vom Fernseher nicht unterstützt
        }
        $host = $this->Host();
        $running = 0;
        $answered = false;
        foreach ($this->Apps() as $i => $app) {
            $raw = $this->HttpRequest('GET', 'http://' . $host . ':8001/api/v2/applications/' . rawurlencode($app['AppID']), '', [], 500, 1500);
            if ($raw === null) {
                // 404 = App nicht installiert; das ist eine Antwort
                $answered = $answered || $this->apiError === 'HTTP 404';
                continue;
            }
            $answered = true;
            $state = json_decode($raw, true);
            if (is_array($state) && ($state['visible'] ?? false) === true) {
                $running = $i + 1;
                break;
            }
        }
        if (!$answered) {
            $this->WriteAttributeInteger('AppStatusFailures', $failures + 1);
            $this->SendDebug('App', 'running app not available (' . ($failures + 1) . '/3)', 0);
            return;
        }
        if ($failures !== 0) {
            $this->WriteAttributeInteger('AppStatusFailures', 0);
        }
        if ($running !== (int) $this->GetValue('App')) {
            $this->SendDebug('App', 'running: ' . ($running > 0 ? $this->Apps()[$running - 1]['Name'] : 'none'), 0);
        }
        $this->SetValueIfChanged('App', $running);
    }

    private function StepVolume(int $direction): void
    {
        $step = $this->ReadPropertyInteger('VolumeStep');
        if ($step > 0 && $this->ReadPropertyBoolean('UseUpnpVolume') && $this->VariableExists('Volume')) {
            $this->SetVolume((int) $this->GetValue('Volume') + ($direction > 0 ? $step : -$step));
            return;
        }
        $this->SendKey($direction > 0 ? 'KEY_VOLUP' : 'KEY_VOLDOWN');
    }

    private function ReadVolume(): void
    {
        $host = $this->Host();
        $volume = $this->UpnpGetVolume($host);
        if ($volume !== null) {
            $this->SetValueIfChanged('Volume', $volume);
        }
        $mute = $this->UpnpGetMute($host);
        if ($mute !== null) {
            $this->SetValueIfChanged('Mute', $mute);
        }
    }

    /**
     * Wake-on-LAN an die eingetragene MAC, sonst an die vom Fernseher gemeldete WLAN-MAC.
     */
    private function WakeUp(): bool
    {
        $mac = trim($this->ReadPropertyString('MAC'));
        if ($mac === '') {
            $mac = (string) ($this->DeviceInfo()['mac'] ?? '');
        }
        $ok = $this->SendWakeOnLan($mac, trim($this->ReadPropertyString('Broadcast')), $this->Host());
        $this->SendDebug('WakeOnLan', $ok ? 'sent' : 'failed: ' . $this->apiError, 0);
        return $ok;
    }

    private function SetPowerTarget(string $target): void
    {
        $this->WriteAttributeString('PowerTarget', $target);
        $this->WriteAttributeInteger('PowerTargetUntil', time() + 40);
        $this->ScheduleNext();
        $this->PushTile();
    }

    private function AppType(string $appID): string
    {
        if (str_starts_with($appID, 'org.tizen.')) {
            return 'NATIVE_LAUNCH';
        }
        foreach (json_decode($this->GetInstalledApps(), true) ?: [] as $app) {
            if (($app['appId'] ?? '') === $appID) {
                return ((int) ($app['type'] ?? 2)) === 4 ? 'NATIVE_LAUNCH' : 'DEEP_LINK';
            }
        }
        return 'DEEP_LINK';
    }

    /**
     * Holt die App-Liste vom Fernseher und trägt neue Apps in die Liste des Formulars ein.
     */
    private function LoadAppsIntoForm(): void
    {
        if (!$this->RequestInstalledApps()) {
            echo $this->Translate('The TV must be on and paired to load its apps.');
            return;
        }
        $before = $this->ReadAttributeString('InstalledApps');
        $this->WriteAttributeString('InstalledApps', '');
        for ($i = 0; $i < 25 && $this->ReadAttributeString('InstalledApps') === ''; $i++) {
            IPS_Sleep(200);
        }
        $installed = json_decode($this->ReadAttributeString('InstalledApps'), true);
        if (!is_array($installed) || $installed === []) {
            $this->WriteAttributeString('InstalledApps', $before);
            echo $this->Translate('The TV did not send an app list.');
            return;
        }
        $rows = $this->Apps();
        $known = array_column($rows, 'AppID');
        foreach ($installed as $app) {
            if (!in_array($app['appId'], $known, true)) {
                $rows[] = ['Name' => $app['name'], 'AppID' => $app['appId']];
            }
        }
        $this->UpdateFormField('Apps', 'values', (string) json_encode($rows));
        echo sprintf($this->Translate('%d apps found. Remove the ones you do not need and apply the changes.'), count($installed));
    }

    // ------------------------------------------------------------------
    // WebSocket-Client (übergeordnete Instanz)
    // ------------------------------------------------------------------

    private function ParentConfiguration(?bool $active = null): array
    {
        $host = $this->Host();
        // Verbunden, solange der Fernseher an ist – und kurz beim Einschalten aus dem Netzwerk-Standby
        $waking = $this->ReadAttributeString('PowerTarget') === 'on' && $this->GetBuffer('PowerKey') === '1';
        $active ??= $host !== '' && ($this->ReadAttributeBoolean('TvOn') || $waking) && $this->ReadAttributeInteger('Pairing') !== self::PAIR_DENIED;
        return [
            'URL'               => $host === '' ? '' : $this->SocketUrl($host),
            // Der Fernseher nutzt ein selbst ausgestelltes Zertifikat
            'VerifyCertificate' => false,
            'Active'            => $active,
        ];
    }

    /**
     * Setzt Adresse und Aktiv-Schalter des WebSocket-Clients – nur, wenn sich etwas ändert.
     */
    private function ConfigureParent(?bool $active = null): void
    {
        $parent = $this->ParentID();
        if ($parent === 0 || IPS_GetInstance($parent)['ModuleInfo']['ModuleID'] !== self::WSC_GUID) {
            return;
        }
        $changed = false;
        foreach ($this->ParentConfiguration($active) as $name => $value) {
            try {
                if (IPS_GetProperty($parent, $name) !== $value) {
                    IPS_SetProperty($parent, $name, $value);
                    $changed = true;
                }
            } catch (Throwable $e) {
                $this->SendDebug('Parent', $name . ': ' . $e->getMessage(), 0);
            }
        }
        if ($changed) {
            IPS_ApplyChanges($parent);
        }
    }

    private function SocketUrl(string $host): string
    {
        $info = $this->DeviceInfo();
        $mode = $this->ReadPropertyInteger('Connection');
        // Automatisch: verschlüsselt (8002), außer das Gerät meldet keine Token-Anmeldung (2016/2017)
        $secure = $mode === 1 || ($mode === 0 && ($info['tokenAuth'] ?? true) !== false);
        $url = ($secure ? 'wss://' : 'ws://') . $host . ':' . ($secure ? 8002 : 8001)
            . '/api/v2/channels/samsung.remote.control?name=' . base64_encode(self::CLIENT_NAME);
        $token = $this->ReadAttributeString('Token');
        if ($secure && $token !== '') {
            $url .= '&token=' . rawurlencode($token);
        }
        return $url;
    }

    private function ParentID(): int
    {
        return (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
    }

    private function ParentIsActive(): bool
    {
        $parent = $this->ParentID();
        try {
            return $parent > 0 && IPS_GetProperty($parent, 'Active') === true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function WatchParent(): void
    {
        $parent = $this->ParentID();
        $last = (int) $this->GetBuffer('Parent');
        if ($last > 0 && $last !== $parent) {
            $this->UnregisterMessage($last, IM_CHANGESTATUS);
        }
        if ($parent > 0) {
            $this->RegisterMessage($parent, IM_CHANGESTATUS);
        }
        $this->SetBuffer('Parent', (string) $parent);
    }

    // ------------------------------------------------------------------
    // Variablen und Darstellungen
    // ------------------------------------------------------------------

    private function MaintainVariables(): void
    {
        $this->MaintainVariable('Power', $this->Translate('Power'), VARIABLETYPE_BOOLEAN, [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON_TRUE'    => 'tv',
            'ICON_FALSE'   => 'power-off',
            'USAGE_TYPE'   => 0,
        ], 10, true);
        $this->EnableAction('Power');

        $upnp = $this->ReadPropertyBoolean('UseUpnpVolume');
        $this->MaintainVariable('Volume', $this->Translate('Volume'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON'         => 'volume',
            'MIN'          => 0,
            'MAX'          => 100,
            'STEP_SIZE'    => 1,
            'SUFFIX'       => '',
            'USAGE_TYPE'   => 0,
        ], 20, $upnp);
        $this->MaintainVariable('Mute', $this->Translate('Mute'), VARIABLETYPE_BOOLEAN, [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON_TRUE'    => 'volume-xmark',
            'ICON_FALSE'   => 'volume',
            'USAGE_TYPE'   => 0,
        ], 21, $upnp);
        if ($upnp) {
            $this->EnableAction('Volume');
            $this->EnableAction('Mute');
        }

        $showSource = $this->ReadPropertyBoolean('ShowSource');
        $sources = [];
        foreach (self::SOURCES as $value => [$label, $key]) {
            $sources[] = ['Value' => $value, 'Caption' => $label, 'IconActive' => true, 'IconValue' => $value === 0 ? 'tv' : 'plug', 'Color' => -1];
        }
        $this->MaintainVariable('Source', $this->Translate('Source'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => 'right-to-bracket',
            'LAYOUT'       => 1,
            'DISPLAY'      => 2,
            'OPTIONS'      => json_encode($sources),
        ], 30, $showSource);
        if ($showSource) {
            $this->EnableAction('Source');
        }

        $apps = $this->Apps();
        $options = [['Value' => 0, 'Caption' => $this->Translate('No app'), 'IconActive' => true, 'IconValue' => 'tv', 'Color' => -1]];
        foreach ($apps as $i => $app) {
            $options[] = ['Value' => $i + 1, 'Caption' => $app['Name'], 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        $this->MaintainVariable('App', $this->Translate('App'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => 'grid-2',
            'LAYOUT'       => 1,
            'DISPLAY'      => 2,
            'OPTIONS'      => json_encode($options),
        ], 40, $apps !== []);
        if ($apps !== []) {
            $this->EnableAction('App');
        }

        $showRemote = $this->ReadPropertyBoolean('ShowRemote');
        $keys = [];
        foreach (self::REMOTE as $value => [$label, $key, $icon]) {
            $keys[] = ['Value' => $value, 'Caption' => $this->Translate($label), 'IconActive' => true, 'IconValue' => $icon, 'Color' => -1];
        }
        $this->MaintainVariable('Remote', $this->Translate('Remote control'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => 'tv-retro',
            'LAYOUT'       => 1,
            'DISPLAY'      => 2,
            'OPTIONS'      => json_encode($keys),
        ], 50, $showRemote);
        if ($showRemote) {
            $this->EnableAction('Remote');
        }
    }

    // ------------------------------------------------------------------
    // Hilfsfunktionen
    // ------------------------------------------------------------------

    private function Host(): string
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            return '';
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }
        // Rechnername (z. B. samsung-tv.fritz.box), keine Pfade oder Ports
        return preg_match('/^(?=.{1,253}$)[A-Za-z0-9]([A-Za-z0-9-]{0,62}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,62}[A-Za-z0-9])?)*$/', $host) ? $host : '';
    }

    private function DeviceInfo(): array
    {
        return json_decode($this->ReadAttributeString('DeviceInfo'), true) ?: [];
    }

    /**
     * Apps aus der Liste im Formular (bereinigt).
     *
     * @return array<int, array{Name: string, AppID: string}>
     */
    private function Apps(): array
    {
        $rows = json_decode($this->ReadPropertyString('Apps'), true);
        $apps = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $id = trim((string) ($row['AppID'] ?? ''));
            $name = trim((string) ($row['Name'] ?? ''));
            if ($id !== '' && preg_match('/^[A-Za-z0-9._-]{3,64}$/', $id)) {
                $apps[] = ['Name' => $name !== '' ? $name : $id, 'AppID' => $id, 'Icon' => max(0, (int) ($row['Icon'] ?? 0))];
            }
        }
        return $apps;
    }

    /**
     * Icons der Apps als Data-URI (Medienobjekte aus der App-Liste), Schlüssel = Wert der Variable App.
     */
    private function AppIcons(): array
    {
        $icons = [];
        foreach ($this->Apps() as $i => $app) {
            [$uri] = $this->AppIcon($app['Icon']);
            if ($uri !== '') {
                $icons[(string) ($i + 1)] = $uri;
            }
        }
        return $icons;
    }

    /**
     * Liefert [Data-URI, Hinweis] für ein Medienobjekt. Die Bildart wird am Inhalt erkannt; große Bilder
     * werden auf 128 × 128 px verkleinert (zwischengespeichert, bis sich das Bild ändert).
     */
    private function AppIcon(int $media): array
    {
        if ($media <= 0) {
            return ['', 'no image'];
        }
        if (!IPS_MediaExists($media)) {
            return ['', 'media object not found'];
        }
        $info = IPS_GetMedia($media);
        $content = preg_replace('/\s+/', '', (string) @IPS_GetMediaContent($media));
        $raw = $content === '' ? false : base64_decode((string) $content, true);
        if ($raw === false || $raw === '') {
            return ['', 'media object is empty'];
        }
        $crc = md5($raw);
        $cache = json_decode($this->GetBuffer('IconCache'), true) ?: [];
        if (($cache[$media]['crc'] ?? '') === $crc) {
            return [(string) $cache[$media]['uri'], (string) $cache[$media]['note']];
        }

        $uri = '';
        $note = '';
        if (preg_match('/^\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*<svg[\s>]/is', $raw)) {
            if (strlen($raw) > 200000) {
                $note = 'SVG larger than 200 KB';
            } else {
                $uri = 'data:image/svg+xml;base64,' . base64_encode($raw);
                $note = 'SVG';
            }
        } else {
            $size = @getimagesizefromstring($raw);
            $types = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_GIF => 'image/gif', IMAGETYPE_WEBP => 'image/webp'];
            if ($size === false || !isset($types[$size[2]])) {
                $note = 'not an image (PNG, JPG, GIF, WebP or SVG)';
            } else {
                $kind = strtoupper(substr($types[$size[2]], 6));
                $small = '';
                if (($size[0] > 192 || $size[1] > 192 || strlen($raw) > 60000) && function_exists('imagecreatefromstring')) {
                    $small = $this->ScaleIcon($raw, 128);
                }
                if ($small !== '') {
                    $uri = 'data:image/png;base64,' . base64_encode($small);
                    $note = $kind . ' ' . $size[0] . '×' . $size[1] . ' → 128×128';
                } elseif (strlen($raw) <= 1000000) {
                    // Verkleinern nicht möglich (z. B. WebP ohne Unterstützung in PHP): Original nehmen –
                    // die Visualisierung zeigt es trotzdem an
                    $uri = 'data:' . $types[$size[2]] . ';base64,' . base64_encode($raw);
                    $note = $kind . ' ' . $size[0] . '×' . $size[1] . ($size[0] > 192 || $size[1] > 192 ? ' (' . $this->Translate('original size') . ')' : '');
                } else {
                    $note = $kind . ' ' . $size[0] . '×' . $size[1] . ': ' . $this->Translate('larger than 1 MB – please use a smaller image');
                }
            }
        }
        $cache[$media] = ['crc' => $crc, 'uri' => $uri, 'note' => $note];
        $this->SetBuffer('IconCache', (string) json_encode($cache));
        $this->SendDebug('Icon', '#' . $media . ' (' . (string) ($info['MediaFile'] ?? '') . '): ' . $note, 0);
        return [$uri, $note];
    }

    /**
     * Verkleinert ein Bild quadratisch (mittiger Ausschnitt) und liefert PNG mit Transparenz.
     */
    private function ScaleIcon(string $raw, int $edge): string
    {
        $src = @imagecreatefromstring($raw);
        if ($src === false) {
            return '';
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $dst = imagecreatetruecolor($edge, $edge);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), $edge, $edge, $side, $side);
        ob_start();
        imagepng($dst, null, 9);
        return (string) ob_get_clean();
    }

    /**
     * Fingerabdruck der Icons: ändert sich, wenn ein Bild ausgetauscht oder neu zugeordnet wird.
     */
    private function AppIconsKey(): string
    {
        $parts = [];
        foreach ($this->Apps() as $i => $app) {
            $media = $app['Icon'];
            $updated = $media > 0 && IPS_MediaExists($media) ? (string) (IPS_GetMedia($media)['MediaUpdated'] ?? 0) . ':' . (string) (IPS_GetMedia($media)['MediaCRC'] ?? '') . ':' . (string) (IPS_GetMedia($media)['MediaSize'] ?? '') : '';
            $parts[] = ($i + 1) . '=' . $media . '@' . $updated;
        }
        return md5(implode('|', $parts));
    }

    private function StatusFromPairing(): int
    {
        return match ($this->ReadAttributeInteger('Pairing')) {
            self::PAIR_DENIED  => 202,
            self::PAIR_WAITING => 201,
            default            => 102,
        };
    }

    private function ScheduleNext(): void
    {
        if ($this->Host() === '') {
            $this->SetTimerInterval('Poll', 0);
            return;
        }
        // Nach einem Schaltbefehl kurz alle 2 Sekunden, damit der neue Zustand schnell sichtbar ist
        $fast = $this->ReadAttributeString('PowerTarget') !== '' && time() <= $this->ReadAttributeInteger('PowerTargetUntil');
        $interval = $fast ? 2 : max(5, $this->ReadPropertyInteger('PollInterval'));
        $this->SetTimerInterval('Poll', $interval * 1000);
    }

    private function VariableExists(string $ident): bool
    {
        return @$this->GetIDForIdent($ident) !== false;
    }

    private function SetValueIfChanged(string $ident, mixed $value): void
    {
        if ($this->VariableExists($ident) && $this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    private function InjectProperty(array &$elements, string $name, string $key, mixed $value): void
    {
        foreach ($elements as &$element) {
            if (($element['name'] ?? '') === $name) {
                $element[$key] = $value;
            }
            if (isset($element['items']) && is_array($element['items'])) {
                $this->InjectProperty($element['items'], $name, $key, $value);
            }
        }
    }
}
