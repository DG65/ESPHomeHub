# ESPHomeHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul-0.1.1-informational)
![Symcon Version](https://img.shields.io/badge/Symcon-9.0%2B-informational)
![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-orange)
[![Check Style](https://github.com/DG65/ESPHomeHub/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/ESPHomeHub/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Spenden-blue?logo=paypal)](https://paypal.me/DietmarGureth)

[ESPHome](https://esphome.io)-Geräte direkt in IP-Symcon — über ihre eigene Schnittstelle
(Native API), verschlüsselt, ohne MQTT-Server und ohne Home Assistant.

## Was tut dieses Modul?

ESPHome macht aus einem ESP32 oder ESP8266 mit ein paar Zeilen YAML einen Sensor, ein Relais,
ein Licht oder einen Bluetooth-Proxy. Bisher ging das in Symcon nur über MQTT als Umweg.
ESPHomeHub spricht das Gerät so an wie Home Assistant: eine TCP-Verbindung je Gerät, das Gerät
beschreibt sich selbst, Änderungen kommen sofort.

**Stand 0.1.0 (erste Stufe):** Verbindung mit Verschlüsselung, Lebenszeichen, Wiederverbinden
und Geräteinfo. Sensoren, Schalter, Lichter usw. als Variablen folgen in der nächsten Version,
danach ein Konfigurator, der die Geräte im Netz findet.

## Aufbau

| Instanz | Aufgabe |
|---|---|
| **ESPHome-Gerät** | ein ESPHome-Gerät im Netz: Verbindung, Verschlüsselung, Lebenszeichen, Geräteinfo |
| Client Socket | darunter, legt Symcon beim Anlegen mit an (TCP zum Gerät) |

## Einrichtung

1. Das Gerät braucht in seiner YAML die API, am besten mit Schlüssel:
   ```yaml
   api:
     encryption:
       key: "…44 Zeichen Base64…"
   ```
   Neue ESPHome-Projekte haben den Schlüssel schon. Ein Passwort („password:") gibt es seit
   ESPHome 2026.1 nicht mehr.
2. In Symcon eine Instanz **ESPHome-Gerät** anlegen, den angebotenen Client Socket übernehmen.
3. IP-Adresse oder Hostnamen (z. B. `wohnzimmer.local`) und den Schlüssel eintragen,
   übernehmen. Im Panel „Gerät" steht danach „✅ Verbunden" mit Name, ESPHome-Version und Chip.

Läuft Home Assistant parallel, darf das Gerät beide Verbindungen gleichzeitig halten.

## Verhalten

- Das Gerät schickt alle 60 Sekunden ein Lebenszeichen, ESPHomeHub antwortet.
- Kommt 150 Sekunden nichts, oder steht die Verbindung nicht, verbindet ESPHomeHub neu.
  Wichtig, weil ein ESPHome-Gerät ohne verbundenen Client nach `reboot_timeout`
  (Standard 15 Minuten) neu startet.
- Ein falscher Schlüssel heilt nicht von selbst: ESPHomeHub trennt, zeigt den Grund und
  versucht es erst nach einer Änderung wieder.
- Die Variable **Verbunden** eignet sich als Auslöser, z. B. für eine Meldung, wenn ein Gerät
  ausfällt.

| Status | Bedeutung |
|---|---|
| 102 | verbunden |
| 104 | noch keine Adresse eingetragen |
| 201 | Schlüssel passt nicht zum Gerät (oder ist kein gültiger Schlüssel) |
| 202 | Verschlüsselung passt nicht: Gerät verlangt einen Schlüssel bzw. hat keinen |
| 203 | Gerät nicht erreichbar, neuer Versuch alle 30 Sekunden |

```php
ESPH_GetDeviceInfo($id);   // JSON: name, mac, version, modell, anzeigename, hersteller, …
ESPH_Neuverbinden($id);
```

## Zum Schlüssel

Der Schlüssel steht als verdecktes Feld in den Einstellungen der Instanz. Symcon verschlüsselt
gespeicherte Einstellungen nicht. „Sicher" heißt hier: nicht im Formular lesbar und nicht im
Protokoll. Als Einstellung, nicht als internes Attribut, damit er ein Neu-Laden des Moduls in
der Modulverwaltung übersteht.

## Fragen und Rückmeldungen

Als [GitHub-Issue](https://github.com/DG65/ESPHomeHub/issues).

## Grundlagen und Dank

- Protokoll nach [aioesphomeapi](https://github.com/esphome/aioesphomeapi) (MIT), der
  Bibliothek, mit der Home Assistant ESPHome-Geräte anspricht. Nachrichtennummern aus deren
  `api.proto`, Ablauf der Verschlüsselung aus `_frame_helper/noise.py`.
- Verschlüsselung `Noise_NNpsk0_25519_ChaChaPoly_SHA256` nach der
  [Noise-Spezifikation](https://noiseprotocol.org/noise.html), in PHP mit sodium nachgebaut
  und byte-genau gegen die Python-Bibliothek
  [noiseprotocol](https://github.com/plizonczyk/noiseprotocol) (MIT) geprüft. Hinweise zu den
  Lizenzen in [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
- ESPHome ist ein Projekt der Open Home Foundation. ESPHomeHub ist kein offizielles
  ESPHome-Projekt.

## Lizenz

PolyForm Noncommercial 1.0.0 — private und nicht-kommerzielle Nutzung ist frei, gewerbliche
Nutzung erfordert eine gesonderte Lizenz vom Rechteinhaber (DG65). Spenden sind willkommen:
[paypal.me/DietmarGureth](https://paypal.me/DietmarGureth).
