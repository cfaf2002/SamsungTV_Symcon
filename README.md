# Samsung TV für IP-Symcon

[![IP-Symcon ab 8.1](https://img.shields.io/badge/IP--Symcon-ab_8.1-0b6fb3.svg)](https://www.symcon.de)
[![Optimiert für Symcon 9.0](https://img.shields.io/badge/optimiert_f%C3%BCr-Symcon_9.0-0b6fb3.svg)](https://www.symcon.de/de/service/dokumentation/installation/migrationen/v81-v90-q1-2026/)
[![Modul-Version 1.0 (Build 6)](https://img.shields.io/badge/Modul--Version-1.0_(Build_6)-informational.svg)](library.json)
[![Tests](https://github.com/cfaf2002/SamsungTV_Symcon/actions/workflows/tests.yml/badge.svg)](https://github.com/cfaf2002/SamsungTV_Symcon/actions/workflows/tests.yml)
[![PHP 8.3 und 8.5](https://img.shields.io/badge/PHP-8.3_%7C_8.5-777bb4.svg?logo=php&logoColor=white)](https://www.php.net)
[![SDK: IPSModuleStrict](https://img.shields.io/badge/SDK-IPSModuleStrict-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/)
[![Variablen: Darstellungen](https://img.shields.io/badge/Variablen-Darstellungen-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/darstellungen/)
[![Kachel-Visualisierung: HTML-SDK](https://img.shields.io/badge/Kachel--Visualisierung-HTML--SDK-orange.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/html-sdk/)
[![Farbschema: Symcon-Design, Dunkel, Hell](https://img.shields.io/badge/Farbschema-Symcon--Design_%7C_Dunkel_%7C_Hell-blueviolet.svg)](STYLEGUIDE.md)
![Sprachen: Deutsch | Englisch](https://img.shields.io/badge/Sprachen-Deutsch_%7C_Englisch-blueviolet.svg)
[![Lizenz: MIT](https://img.shields.io/badge/Lizenz-MIT-green.svg)](LICENSE)
![Schnittstelle: lokal, ohne Cloud](https://img.shields.io/badge/Schnittstelle-lokal_(WebSocket%2C_UPnP)-lightgrey.svg)

IP-Symcon-Modul zum Steuern von Samsung-Fernsehern mit Tizen (Baujahr 2016 und neuer) im Heimnetz: Ein- und Ausschalten, Tasten der Fernbedienung, Lautstärke, Quellen und Apps – mit eigener Kachel als Fernbedienung.

> Kein offizielles Produkt von Samsung. Das Modul nutzt die lokale Schnittstelle, über die auch die SmartThings-App den Fernseher bedient. Samsung kann sie mit Software-Updates ändern.

Autor: Armin Frohwerk · Lizenz: MIT

## Inhalt

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen und Technik](#2-voraussetzungen-und-technik)
3. [Installation](#3-installation)
4. [Einrichtung](#4-einrichtung)
5. [Kachel](#5-kachel)
6. [Variablen und Darstellungen](#6-variablen-und-darstellungen)
7. [PHP-Befehle](#7-php-befehle)
8. [Sicherheit und Geschwindigkeit](#8-sicherheit-und-geschwindigkeit)
9. [Entwicklung und Tests](#9-entwicklung-und-tests)
10. [Changelog](#10-changelog)
11. [Lizenz](#11-lizenz)

## 1. Funktionsumfang

- Einschalten per Wake-on-LAN und – bei Fernsehern im Netzwerk-Standby (die meisten ab 2018) – über die Ein/Aus-Taste per WebSocket; Ausschalten über die Ein/Aus-Taste – bei „The Frame“ wahlweise lang gedrückt (ganz aus statt Kunstmodus)
- Zustand an/aus/Standby aus der Geräteinfo des Fernsehers, mit schneller Abfrage alle 2 Sekunden direkt nach einem Schaltbefehl
- Alle Tasten der Fernbedienung (`KEY_…`), auch als Tastenfolge oder gedrückt gehalten
- Lautstärke 0–100 und Stummschaltung über UPnP lesen und setzen (abschaltbar; ohne UPnP über die Tasten)
- Quellen TV und HDMI 1–4
- Apps starten (Favoritenliste im Formular, App-Liste lässt sich vom Fernseher laden), Webseite im Browser des Fernsehers öffnen, Text in Eingabefelder schreiben
- Kopplung mit Token: Der Fernseher fragt einmal, ob „Symcon“ ihn steuern darf; der Token wird gespeichert
- Eigene Kachel als Fernbedienung mit Steuerkreuz, Lautstärke, Programm, Medientasten, Quellen und Apps; kleine Kacheln zeigen nur Ein/Aus und Lautstärke
- Moderne Symcon-Darstellungen statt Variablenprofilen, Basisklasse IPSModuleStrict
- Deutsch und Englisch (Formular, Variablen, Meldungen und Kachel)
- Automatische Tests mit nachgebautem Fernseher im GitHub-Workflow

## 2. Voraussetzungen und Technik

- IP-Symcon ab 8.1, optimiert für 9.0
- Samsung-Fernseher mit Tizen ab 2016 (Serien K, M, N, Q, R, T, A, B, C, D … sowie The Frame) im selben Netz
- Feste IP-Adresse des Fernsehers (im Router reservieren)
- Für das Einschalten am Fernseher: **Einstellungen → Allgemein → Netzwerk → Experteneinstellungen → „Mit Mobilgerät einschalten“** aktivieren (Bezeichnung je nach Baujahr leicht anders)

| Weg | Port | Wofür |
| :-- | :-- | :-- |
| WebSocket `samsung.remote.control` | 8002 (wss, ab 2018) bzw. 8001 (ws, 2016/2017) | Tasten, Apps, Text, Kopplung |
| REST `/api/v2/` | 8001 (http) | Geräteinfo, Zustand an/Standby, App-Start als Rückfall |
| UPnP RenderingControl | 9197 (http) | Lautstärke und Stumm |
| Wake-on-LAN | UDP 9 | Einschalten |

Die WebSocket-Verbindung hält ein Symcon-**WebSocket Client** als übergeordnete Instanz. Das Modul legt ihn selbst an, trägt die Adresse ein und schaltet ihn nur aktiv, solange der Fernseher an ist – so gibt es keine Fehlermeldungen, während der Fernseher aus ist.

Die SmartThings-Cloud wird bewusst nicht genutzt: Sie liefert zwar die aktive Quelle, verlangt aber Zugangsdaten, die Samsung inzwischen nur noch 24 Stunden gültig ausstellt.

## 3. Installation

Im Symcon-Konsolenfenster unter **Kerninstanzen → Module Control** die Adresse hinzufügen:

```
https://github.com/cfaf2002/SamsungTV_Symcon
```

Danach eine Instanz **Samsung TV** anlegen.

## 4. Einrichtung

1. **IP-Adresse** des Fernsehers eintragen und übernehmen. Ist der Fernseher an, zeigt das Formular das gefundene Modell.
2. Am Fernseher erscheint die Frage, ob **„Symcon“** zugreifen darf → **Zulassen**. Danach steht im Formular „Gekoppelt“.
3. **MAC-Adresse** eintragen, wenn der Fernseher per **Netzwerkkabel** angeschlossen ist. Leer bleibt sie bei WLAN – dann nimmt das Modul die vom Fernseher gemeldete WLAN-MAC. Mit „Wake-on-LAN senden“ lässt sich das Einschalten prüfen.
4. Unter **Funktionen** die Apps zusammenstellen: „Apps vom Fernseher laden“ trägt alle installierten Apps ein; nicht benötigte entfernen und übernehmen.
5. **Modelle ab 2021** starten Apps über die lokale Schnittstelle oft nicht mehr und liefern auch keine App-Liste. Ist der Fernseher in SmartThings eingebunden (Modul [SmartThings](https://github.com/cfaf2002/Smartthings_Symcon)), unter **„Apps über SmartThings starten“** dessen Instanz „SmartThings Gerät“ wählen – dann startet das Modul Apps zuerst darüber. Ohne SmartThings versucht es den WebSocket und, wenn der Fernseher nicht antwortet, REST.

| Einstellung | Bedeutung |
| :-- | :-- |
| Verbindung | Automatisch (empfohlen), verschlüsselt 8002 oder unverschlüsselt 8001 |
| Neu koppeln | Löscht den Token; der Fernseher fragt erneut |
| Zustand prüfen alle | Takt der Abfrage an/aus, Standard 10 Sekunden |
| Ausschalten | Taste drücken oder lang halten (The Frame) |
| Lautstärke über UPnP | Schieberegler und Stumm mit echtem Wert; bei Soundbar über ARC/eARC ggf. abschalten |
| Lautstärketasten der Kachel | Schrittweite über UPnP; 0 = wie die Fernbedienung |

Wurde der Zugriff am Fernseher abgelehnt (Status 202), unter **Einstellungen → Allgemein → Externe Geräteverwaltung → Geräteverbindungs-Manager → Geräteliste** „Symcon“ erlauben und im Formular **Neu koppeln** wählen.

## 5. Kachel

Die Kachel ist eine Fernbedienung: oben bleibt Platz für Titel und Symbole der Symcon-App, darunter Zustand und Ein/Aus-Taste, Steuerkreuz mit OK, Zurück, Home und Menü, Wippen für Lautstärke und Programm, Stummtaste, Lautstärkeregler (mit UPnP), Medientasten sowie Leisten für Quellen und Apps. Pfeile und Lautstärke wiederholen sich, solange sie gedrückt bleiben.

- **Farbschema der Kachel:** Symcon-Design (Farben der Visualisierung), Dunkel oder Hell
- Quellen, Apps sowie Medien- und Programmtasten lassen sich einzeln ausblenden
- Kleine Kacheln (bis ca. 230 px Höhe) zeigen nur Ein/Aus und Lautstärke

## 6. Variablen und Darstellungen

| Ident | Name | Typ | Darstellung |
| :-- | :-- | :-- | :-- |
| `Power` | Ein/Aus | Boolean | Schalter |
| `Volume` | Lautstärke | Integer | Schieberegler 0–100 (mit UPnP) |
| `Mute` | Stumm | Boolean | Schalter (mit UPnP) |
| `Source` | Quelle | Integer | Aufzählung TV, HDMI 1–4 |
| `App` | App | Integer | Aufzählung aus der Favoritenliste |
| `Remote` | Fernbedienung | Integer | Aufzählung mit den wichtigsten Tasten |

Die Quelle zeigt die zuletzt gewählte Quelle; der Fernseher meldet über die lokale Schnittstelle nicht zurück, welche Quelle gerade läuft.

## 7. PHP-Befehle

| Befehl | Beschreibung |
| :-- | :-- |
| `SAMTV_PowerOn(int $id): bool` | Einschalten (Wake-on-LAN) |
| `SAMTV_PowerOff(int $id): bool` | Ausschalten |
| `SAMTV_SetPower(int $id, bool $on): bool` | Ein- oder ausschalten |
| `SAMTV_SendKey(int $id, string $key): bool` | Taste senden, z. B. `KEY_HOME`, `KEY_VOLUP`, `KEY_HDMI2` (Präfix `KEY_` darf fehlen) |
| `SAMTV_SendKeys(int $id, string $keys, int $delayMs): bool` | Tastenfolge, z. B. `"KEY_HOME,KEY_RIGHT,KEY_ENTER"` |
| `SAMTV_HoldKey(int $id, string $key, int $ms): bool` | Taste gedrückt halten (höchstens 5 s) |
| `SAMTV_SelectSource(int $id, string $source): bool` | `TV`, `HDMI1` … `HDMI4` |
| `SAMTV_SetVolume(int $id, int $volume): bool` | Lautstärke 0–100 (UPnP) |
| `SAMTV_SetMute(int $id, bool $mute): bool` | Stumm an/aus |
| `SAMTV_LaunchApp(int $id, string $appId): bool` | App starten, z. B. `3201907018807` (Netflix) |
| `SAMTV_OpenBrowser(int $id, string $url): bool` | Webseite im Browser des Fernsehers öffnen |
| `SAMTV_SendText(int $id, string $text): bool` | Text in ein geöffnetes Eingabefeld schreiben |
| `SAMTV_RequestInstalledApps(int $id): bool` | App-Liste beim Fernseher anfragen |
| `SAMTV_GetInstalledApps(int $id): string` | Zuletzt gemeldete Apps als JSON |
| `SAMTV_GetDeviceInfo(int $id): string` | Geräteinfo als JSON (Name, Modell, MAC …) |
| `SAMTV_Update(int $id): bool` | Zustand sofort abfragen |
| `SAMTV_ResetPairing(int $id): bool` | Token löschen und neu koppeln |

Beispiel – Filmabend:

```php
SAMTV_PowerOn(12345);
IPS_Sleep(8000);
SAMTV_SelectSource(12345, 'HDMI2');
SAMTV_SetVolume(12345, 18);
```

## 8. Sicherheit und Geschwindigkeit

- Alles bleibt im Heimnetz; es gibt keine Cloud und keine Zugangsdaten. Der Fernseher bietet für Geräteinfo und Lautstärke nur HTTP an und nutzt für den WebSocket ein selbst ausgestelltes Zertifikat. Die Zertifikatsprüfung ist deshalb – nur für diese Verbindung – abgeschaltet.
- Der Token des Fernsehers liegt in einem Attribut und steht in der Adresse des WebSocket-Clients; ins Debug wird er nicht geschrieben.
- Tasten, Quellen, App-IDs und Adressen werden vor dem Senden geprüft; die Kachel setzt alle Texte per `textContent`, Kacheldaten werden mit `JSON_HEX_*` eingebettet.
- Tasten gehen über die bestehende WebSocket-Verbindung ohne neuen Verbindungsaufbau. Abfragen haben kurze Zeitlimits (Verbindung 0,8 s), damit ein ausgeschalteter Fernseher Symcon nicht aufhält. Als „aus“ gilt der Fernseher erst nach zwei Fehlversuchen.
- Variablen werden nur bei Änderung geschrieben, die Kachel nur bei geänderten Daten aktualisiert; die Animation beim Schalten ruht, solange die Kachel nicht sichtbar ist.
- Datenfluss zum WebSocket-Client HEX-kodiert, wie es IPSModuleStrict verlangt.

## 9. Entwicklung und Tests

```
php tests/structure.php
git clone --depth 1 https://github.com/symcon/SymconStubs.git ../SymconStubs
php tests/stubs.php ../SymconStubs
```

`tests/stubs.php` startet einen nachgebauten Fernseher (`tests/fixtures/tv.php`, Ports 8001 und 9197) und einen nachgebauten WebSocket-Client und prüft Ein/Aus, Standby, Lautstärke, Kopplung mit Token, Ablehnung, Tasten, Apps, Kachel und den HEX-kodierten Datenfluss. Der Workflow führt alles mit PHP 8.3 und 8.5 aus.

## 10. Changelog

| Version | Build | Datum | Beschreibung |
| :-- | --: | :-- | :-- |
| 1.0 | 6 | 07.10.2026 | App-Start wahlweise über die SmartThings-Instanz des Fernsehers (nötig bei Modellen ab 2021); ohne Antwort des Fernsehers Start über REST; App-Liste höchstens stündlich angefragt |
| 1.0 | 5 | 07.10.2026 | App-Start: Antwort des Fernsehers wird ausgewertet, bei Ablehnung Start über REST; App-IDs der Favoriten werden über den Namen an die App-Liste des Fernsehers angepasst |
| 1.0 | 4 | 07.10.2026 | Kachel lässt oben Platz für Titel und Symbole der Symcon-App (keine Überlagerung mehr), eigener Name entfällt |
| 1.0 | 3 | 07.10.2026 | Einschalten aus dem Netzwerk-Standby: kurz verbinden und Ein/Aus-Taste senden (zusätzlich zu Wake-on-LAN); Wake-on-LAN auch an den Broadcast des Fernseher-Netzes |
| 1.0 | 2 | 07.10.2026 | Gerätename ohne HTML-Kodierung (z. B. 75&quot; → 75″) |
| 1.0 | 1 | 06.10.2026 | Erste Version: WebSocket-Steuerung mit Kopplung, Wake-on-LAN, Lautstärke über UPnP, Quellen, Apps, Kachel als Fernbedienung |

## 11. Lizenz

MIT – siehe [LICENSE](LICENSE). Copyright (c) 2026 Armin Frohwerk.
