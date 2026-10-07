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

/**
 * Lokale Schnittstellen des Fernsehers ohne WebSocket:
 * Geräteinfo (REST, Port 8001), Lautstärke (UPnP RenderingControl, Port 9197),
 * App-Start als Rückfall (REST) und Wake-on-LAN.
 *
 * Alles bleibt im Heimnetz. Der Fernseher bietet hier nur HTTP an (bzw. auf 8002 ein
 * selbst ausgestelltes Zertifikat); es werden keine Zugangsdaten übertragen.
 */
trait SamsungApiTrait
{
    /** Letzter Fehler einer Anfrage (für Debug und Formular) */
    private string $apiError = '';

    /**
     * Fragt die Geräteinfo ab (GET http://<tv>:8001/api/v2/).
     * Liefert null, wenn der Fernseher nicht erreichbar ist.
     */
    private function FetchDeviceInfo(string $host): ?array
    {
        $response = $this->HttpRequest('GET', 'http://' . $host . ':8001/api/v2/', '', [], 800, 2500);
        if ($response === null) {
            return null;
        }
        $json = json_decode($response, true);
        if (!is_array($json)) {
            $this->apiError = 'invalid response';
            return null;
        }
        $device = is_array($json['device'] ?? null) ? $json['device'] : [];
        $bool = static fn (mixed $v): bool => $v === true || $v === 'true';
        return [
            // Der Fernseher schickt den Namen HTML-kodiert (z. B. 75&quot; Neo QLED)
            'name'       => html_entity_decode((string) ($device['name'] ?? $json['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'model'      => (string) ($device['modelName'] ?? ''),
            'mac'        => (string) ($device['wifiMac'] ?? ''),
            'network'    => (string) ($device['networkType'] ?? ''),
            'os'         => (string) ($device['OS'] ?? ''),
            'tokenAuth'  => $bool($device['TokenAuthSupport'] ?? false),
            'frame'      => $bool($device['FrameTVSupport'] ?? false),
            // Neuere Modelle melden "on" oder "standby"; ältere melden nichts und sind dann an
            'powerState' => (string) ($device['PowerState'] ?? 'on'),
        ];
    }

    /**
     * Startet eine App über REST (Rückfall, wenn keine WebSocket-Verbindung besteht).
     */
    private function LaunchAppRest(string $host, string $appID): bool
    {
        return $this->HttpRequest('POST', 'http://' . $host . ':8001/api/v2/applications/' . rawurlencode($appID), '', [], 1000, 4000) !== null;
    }

    // ------------------------------------------------------------------
    // Lautstärke über UPnP (RenderingControl)
    // ------------------------------------------------------------------

    private function UpnpGetVolume(string $host): ?int
    {
        $xml = $this->UpnpCall($host, 'GetVolume', '<Channel>Master</Channel>');
        if ($xml === null || !preg_match('/<CurrentVolume>\s*(\d+)\s*<\/CurrentVolume>/', $xml, $m)) {
            return null;
        }
        return max(0, min(100, (int) $m[1]));
    }

    private function UpnpGetMute(string $host): ?bool
    {
        $xml = $this->UpnpCall($host, 'GetMute', '<Channel>Master</Channel>');
        if ($xml === null || !preg_match('/<CurrentMute>\s*([01])\s*<\/CurrentMute>/', $xml, $m)) {
            return null;
        }
        return $m[1] === '1';
    }

    private function UpnpSetVolume(string $host, int $volume): bool
    {
        $volume = max(0, min(100, $volume));
        return $this->UpnpCall($host, 'SetVolume', '<Channel>Master</Channel><DesiredVolume>' . $volume . '</DesiredVolume>') !== null;
    }

    private function UpnpSetMute(string $host, bool $mute): bool
    {
        return $this->UpnpCall($host, 'SetMute', '<Channel>Master</Channel><DesiredMute>' . ($mute ? '1' : '0') . '</DesiredMute>') !== null;
    }

    private function UpnpCall(string $host, string $action, string $arguments): ?string
    {
        $service = 'urn:schemas-upnp-org:service:RenderingControl:1';
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
            . '<s:Body><u:' . $action . ' xmlns:u="' . $service . '"><InstanceID>0</InstanceID>' . $arguments . '</u:' . $action . '></s:Body></s:Envelope>';
        $response = $this->HttpRequest('POST', 'http://' . $host . ':9197/upnp/control/RenderingControl1', $body, [
            'Content-Type: text/xml; charset="utf-8"',
            'SOAPACTION: "' . $service . '#' . $action . '"',
        ], 800, 2000);
        if ($response !== null && str_contains($response, 's:Fault')) {
            $this->apiError = 'UPnP fault';
            return null;
        }
        return $response;
    }

    // ------------------------------------------------------------------
    // Wake-on-LAN
    // ------------------------------------------------------------------

    /**
     * Baut das Magic Packet: 6 × 0xFF und 16 × die MAC-Adresse.
     */
    private static function MagicPacket(string $mac): ?string
    {
        $hex = strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', $mac));
        if (strlen($hex) !== 12 || $hex === '000000000000') {
            return null;
        }
        return str_repeat("\xFF", 6) . str_repeat((string) hex2bin($hex), 16);
    }

    private function SendWakeOnLan(string $mac, string $broadcast, string $host): bool
    {
        $packet = self::MagicPacket($mac);
        if ($packet === null) {
            $this->apiError = 'invalid MAC address';
            return false;
        }
        // Broadcast aus dem Formular, Broadcast des Netzes des Fernsehers (x.y.z.255) und direkt an den Fernseher
        $subnet = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? (string) preg_replace('/\.\d+$/', '.255', $host) : '';
        $targets = array_unique(array_filter([$broadcast, $subnet, $host], static fn (string $t): bool => filter_var($t, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));
        $sent = false;
        foreach ($targets as $target) {
            $context = stream_context_create(['socket' => ['so_broadcast' => true]]);
            $socket = @stream_socket_client('udp://' . $target . ':9', $errno, $errstr, 1, STREAM_CLIENT_CONNECT, $context);
            if ($socket === false) {
                $this->apiError = $errstr;
                continue;
            }
            // dreimal senden: UDP kann verloren gehen
            for ($i = 0; $i < 3; $i++) {
                $sent = @fwrite($socket, $packet) === strlen($packet) || $sent;
            }
            fclose($socket);
        }
        return $sent;
    }

    // ------------------------------------------------------------------
    // HTTP
    // ------------------------------------------------------------------

    private function HttpRequest(string $method, string $url, string $body, array $headers, int $connectMs, int $timeoutMs): ?string
    {
        $this->apiError = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST     => $method,
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_CONNECTTIMEOUT_MS => $connectMs,
            CURLOPT_TIMEOUT_MS        => $timeoutMs,
            CURLOPT_HTTPHEADER        => $headers,
            CURLOPT_FOLLOWLOCATION    => false,
            CURLOPT_PROTOCOLS         => CURLPROTO_HTTP,
            CURLOPT_NOSIGNAL          => true,
        ]);
        if ($body !== '' || $method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            $this->apiError = curl_error($ch);
            return null;
        }
        if ($code < 200 || $code >= 300) {
            $this->apiError = 'HTTP ' . $code;
            return null;
        }
        return (string) $response;
    }
}
