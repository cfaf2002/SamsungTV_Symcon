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
 * Kachel für die Kachel-Visualisierung (HTML-SDK): eine Fernbedienung.
 * Die Kachel bekommt nur Daten (JSON) und baut alles mit textContent auf – kein HTML aus Variablen.
 */
trait SamsungTileTrait
{
    /**
     * Liefert das HTML der Kachel (wird von der Visualisierung einmal geladen).
     */
    public function GetVisualizationTile(): string
    {
        $html = (string) file_get_contents(__DIR__ . '/../SamsungTV/tile.html');
        $data = $this->TileData();
        // JSON_HEX_* verhindert, dass Werte wie "</script>" das Skript der Kachel beenden
        $json = (string) json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return str_replace('/*INITIAL_DATA*/null', $json, $html);
    }

    /**
     * Sendet die Kacheldaten, wenn sich etwas geändert hat.
     */
    private function PushTile(): void
    {
        $data = $this->TileData();
        $json = (string) json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if ($json === $this->ReadAttributeString('TileData')) {
            return; // unverändert: nichts an die Visualisierung schicken
        }
        $this->WriteAttributeString('TileData', $json);
        if ($this->ReadPropertyBoolean('UseTile')) {
            $this->UpdateVisualizationValue($json);
        }
    }

    private function TileData(): array
    {
        $info = $this->DeviceInfo();
        $host = $this->Host();
        $upnp = $this->ReadPropertyBoolean('UseUpnpVolume');
        $on = $this->ReadAttributeBoolean('TvOn');
        $target = $this->ReadAttributeString('PowerTarget');
        $pairing = $this->ReadAttributeInteger('Pairing');

        $error = '';
        if ($host === '') {
            $error = $this->Translate('Please enter the IP address of the TV');
        } elseif ($pairing === self::PAIR_DENIED) {
            $error = $this->Translate('Access denied on the TV');
        }

        $sources = [];
        if ($this->ReadPropertyBoolean('TileShowSources')) {
            foreach (self::SOURCES as $value => [$label, $key]) {
                $sources[] = ['v' => $value, 'label' => $label];
            }
        }
        $apps = [];
        if ($this->ReadPropertyBoolean('TileShowApps')) {
            foreach ($this->Apps() as $i => $app) {
                $apps[] = ['v' => $i + 1, 'label' => $app['Name']];
            }
        }

        return [
            'theme'     => $this->ReadPropertyInteger('TileTheme'),
            'name'      => ($info['name'] ?? '') !== '' ? $info['name'] : 'Samsung TV',
            'model'     => (string) ($info['model'] ?? ''),
            'on'        => $on,
            'pending'   => $target,
            'connected' => $on && $this->HasActiveParent(),
            'pairing'   => $pairing,
            'error'     => $error,
            'upnp'      => $upnp && $this->VariableExists('Volume'),
            'volume'    => $upnp && $this->VariableExists('Volume') ? (int) $this->GetValue('Volume') : null,
            'mute'      => $upnp && $this->VariableExists('Mute') ? (bool) $this->GetValue('Mute') : null,
            'sources'   => $sources,
            'source'    => $this->VariableExists('Source') ? (int) $this->GetValue('Source') : -1,
            'apps'      => $apps,
            'app'       => $this->VariableExists('App') ? (int) $this->GetValue('App') : 0,
            'media'     => $this->ReadPropertyBoolean('TileShowMedia'),
        ];
    }
}
