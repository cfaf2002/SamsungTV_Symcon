<?php

declare(strict_types=1);

/*
 * Nachgebauter Samsung-Fernseher für die Tests (Router für den eingebauten PHP-Server).
 * Port 8001: Geräteinfo /api/v2/ · Port 9197: UPnP RenderingControl.
 * Zustand (PowerState, Lautstärke, Stumm) liegt in der Datei aus der Umgebungsvariable TV_STATE.
 *
 * SPDX-License-Identifier: MIT
 */

$file = (string) getenv('TV_STATE');
$state = json_decode((string) @file_get_contents($file), true) ?: ['power' => 'on', 'volume' => 17, 'mute' => false];
$save = static function () use ($file, &$state): void {
    file_put_contents($file, json_encode($state));
};

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if ($uri === '/api/v2/' && $method === 'GET') {
    header('Content-Type: application/json');
    echo json_encode([
        'id'      => 'uuid:test',
        'name'    => '[TV] Samsung Q80B',
        'device'  => [
            'name'             => '75&quot; Neo QLED',
            'modelName'        => 'QE55Q80BAT',
            'wifiMac'          => 'aa:bb:cc:dd:ee:ff',
            'networkType'      => 'wired',
            'OS'               => 'Tizen',
            'TokenAuthSupport' => 'true',
            'FrameTVSupport'   => 'false',
            'PowerState'       => $state['power'],
        ],
        'type'    => 'Samsung SmartTV',
        'version' => '2.0.25',
    ]);
    return true;
}

if (str_starts_with((string) $uri, '/api/v2/applications/') && $method === 'POST') {
    $state['launched'] = basename((string) $uri);
    $save();
    echo 'true';
    return true;
}

if ($uri === '/upnp/control/RenderingControl1' && $method === 'POST') {
    $body = (string) file_get_contents('php://input');
    $action = preg_match('/#(\w+)"?$/', (string) ($_SERVER['HTTP_SOAPACTION'] ?? ''), $m) ? $m[1] : '';
    $reply = '';
    switch ($action) {
        case 'GetVolume':
            $reply = '<CurrentVolume>' . $state['volume'] . '</CurrentVolume>';
            break;
        case 'GetMute':
            $reply = '<CurrentMute>' . ($state['mute'] ? 1 : 0) . '</CurrentMute>';
            break;
        case 'SetVolume':
            preg_match('/<DesiredVolume>(\d+)<\/DesiredVolume>/', $body, $v);
            $state['volume'] = (int) ($v[1] ?? 0);
            $save();
            break;
        case 'SetMute':
            preg_match('/<DesiredMute>([01])<\/DesiredMute>/', $body, $v);
            $state['mute'] = ($v[1] ?? '0') === '1';
            $save();
            break;
        default:
            http_response_code(500);
            echo '<s:Fault/>';
            return true;
    }
    header('Content-Type: text/xml');
    echo '<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><u:' . $action
        . 'Response xmlns:u="urn:schemas-upnp-org:service:RenderingControl:1">' . $reply . '</u:' . $action . 'Response></s:Body></s:Envelope>';
    return true;
}

http_response_code(404);
return true;
