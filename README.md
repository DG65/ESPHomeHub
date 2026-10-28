# ESPHomeHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul-0.3.2-informational)
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

**Stand 0.3.0:** Konfigurator mit Gerätesuche, Verbindung mit Verschlüsselung, Lebenszeichen,
Wiederverbinden, Geräteinfo und alle gängigen Entitäten als Variablen. Der Bluetooth-Proxy wird
ein eigenes Modul.

## Aufbau

| Instanz | Aufgabe |
|---|---|
| **ESPHome-Konfigurator** | findet ESPHome-Geräte im Netz (mDNS) und legt sie samt Verbindung an |
| **ESPHome-Gerät** | ein ESPHome-Gerät im Netz: Verbindung, Verschlüsselung, Lebenszeichen, Geräteinfo, Entitäten als Variablen |
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
2. In Symcon eine Instanz **ESPHome-Konfigurator** anlegen. Er zeigt alle ESPHome-Geräte im Netz,
   mit Anzeigename, Adresse, MAC, Version, Chip und ob ein Schlüssel nötig ist.
3. Gerät auswählen, „Erstellen": ESPHome-Gerät und Client Socket entstehen in einem Schritt.
4. In der neuen Instanz den Schlüssel eintragen, übernehmen. Im Panel „Gerät" steht danach
   „✅ Verbunden", im Panel „Entitäten" alles, was das Gerät kann.

Ohne Konfigurator geht es auch von Hand: Instanz **ESPHome-Gerät** anlegen, IP-Adresse oder
Hostnamen (z. B. `wohnzimmer.local`) und Schlüssel eintragen.

Die Suche läuft über mDNS und reicht nur im selben Netzsegment. Geräte hinter VLAN-Grenzen, VPN
oder in Docker ohne Host-Netz trägt man im Konfigurator unter „Adressen von Hand" ein. Vorhandene
Instanzen erkennt der Konfigurator an der MAC-Adresse, auch wenn sich die IP geändert hat.

Läuft Home Assistant parallel, darf das Gerät beide Verbindungen gleichzeitig halten.

## Entitäten

Das Gerät beschreibt nach jeder Verbindung selbst, was es kann. Daraus entstehen Variablen unter
der Instanz, mit passender Darstellung (Einheit, Nachkommastellen, Grenzen, Klartext):

| ESPHome | Variablen |
|---|---|
| sensor | Zahl mit Einheit |
| binary_sensor | Ja/Nein mit Klartext je Geräteklasse (Offen/Geschlossen, Bewegung, …) |
| text_sensor, event | Text (beim Ereignis: das zuletzt ausgelöste) |
| switch | Schalter |
| button | Knopf „Auslösen" |
| number | Schieberegler bzw. Eingabefeld mit Grenzen |
| select | Auswahl mit den Optionen des Geräts |
| text | Eingabefeld |
| light | An/Aus, Helligkeit, Farbe, Farbtemperatur (je nach Licht) |
| cover, valve | Position in % (sonst Offen/Geschlossen), Öffnen/Stopp/Schließen, Bewegung |
| fan | An/Aus, Stufe |
| lock | Verriegelt, Öffnen (falls unterstützt), Zustand |
| climate | Betriebsart, Solltemperatur (bzw. unten/oben), Ist-Temperatur, Tätigkeit |

Noch nicht unterstützt (im Panel „Entitäten" genannt): Kamera, Medienplayer, Alarmanlage, Sirene,
Datum/Uhrzeit, Update, Warmwasserbereiter, Infrarot/Funk.

Schaltbare Variablen lassen sich in der Visualisierung bedienen oder per Skript setzen. Die
Variable übernimmt den Wert erst, wenn das Gerät ihn bestätigt; bei ungültigem Wert oder
getrennter Verbindung gibt es eine Fehlermeldung.

```php
RequestAction($variablenID, true);               // z. B. Variable „Relais" einschalten
IPS_RequestAction($id, 'e6d53a046_h', 40);       // Licht auf 40 % (Ident aus dem Panel „Entitäten")
```

Der Ident einer Variable ist der `key` der Entität (`e` + 8 Hex-Zeichen, Teilvariablen mit Endung).
Er bleibt gleich, solange die `id` in der Geräte-YAML gleich bleibt. Entfernt man eine Entität,
bleibt ihre Variable samt Archiv stehen und kann von Hand gelöscht werden.

## Verhalten

- Das Gerät schickt alle 60 Sekunden ein Lebenszeichen, ESPHomeHub antwortet.
- Kommt 150 Sekunden nichts, oder steht die Verbindung nicht, verbindet ESPHomeHub neu.
  Wichtig, weil ein ESPHome-Gerät ohne verbundenen Client nach `reboot_timeout`
  (Standard 15 Minuten) neu startet.
- Ein falscher Schlüssel heilt nicht von selbst: ESPHomeHub trennt, zeigt den Grund und
  versucht es erst nach einer Änderung wieder.
- Die Variable **Verbunden** eignet sich als Auslöser, z. B. für eine Meldung, wenn ein Gerät
  ausfällt.
- Fragt das Gerät nach der Uhrzeit (`time: platform: homeassistant`), antwortet ESPHomeHub mit
  Uhrzeit und der Zeitzone von Symcon, einschließlich Sommerzeit-Regeln.

| Status | Bedeutung |
|---|---|
| 102 | verbunden |
| 104 | noch keine Adresse eingetragen |
| 201 | Schlüssel passt nicht zum Gerät (oder ist kein gültiger Schlüssel) |
| 202 | Verschlüsselung passt nicht: Gerät verlangt einen Schlüssel bzw. hat keinen |
| 203 | Gerät nicht erreichbar, neuer Versuch alle 30 Sekunden |

```php
ESPH_GetDeviceInfo($id);   // JSON: name, mac, version, modell, anzeigename, hersteller, …
ESPH_GetEntities($id);     // JSON: alle Entitäten mit Typ, Name, Fähigkeiten; Schlüssel = Ident
ESPHC_GetDevices($id);     // Konfigurator: gefundene Geräte als JSON
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
  `api.proto`, Ablauf der Verschlüsselung aus `_frame_helper/noise.py`. Der Prüfstand vergleicht
  jede Entitätsbeschreibung, jeden Zustand und jeden Befehl mit den Protobuf-Klassen dieser
  Bibliothek.
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
