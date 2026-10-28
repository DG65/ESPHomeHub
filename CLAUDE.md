# ESPHomeHub — Hinweise für die Arbeit an diesem Repository

## Einordnung

- **Eigenständiges DG65-Modul** (wie IRHub/RoboHub/WarnHub), weder NRG-Stack noch DG65-Toolkit —
  Dietmars Entscheidung 28.10.2026. `library.json→name` = „ESPHomeHub", Repo `DG65/ESPHomeHub`.
  Übernimmt alle handwerklichen Verbund-Konventionen aus `/Users/dietmar/Nextcloud/Claude/SUITE.md`.
- **Bluetooth-Proxy wird ein eigenes Modul** (Dietmar 28.10.2026), das als Kind am ESPHome-Gerät hängt.
- Module/Präfixe: `ESPHomeHubGeraet` (ESPH, Elternteil Client Socket). Reserviert:
  Konfigurator-GUID `{D3B32407-FD69-4647-8915-4DE17C9D5C07}`, Präfix ESPHC (nie denselben Präfix wie das
  Gerät, sonst „Cannot redeclare function"). Klassen/Präfixe/GUIDs eingefroren, sobald eine Instanz existiert.
- Machbarkeit und Prototyp: `../ESPHome-Machbarkeit.md`, Befund des Wegwerf-Prototyps
  `../ESPHome-Prototyp/BEFUND.md`.

## Protokoll — belegte Fakten (gemessen 28.10.2026, ESPHome 2026.10.1, Host-Plattform)

- Referenz **esphome/aioesphomeapi** (MIT). Noise byte-genau gegen `noiseprotocol` 0.3.1 geprüft
  (`.tools/fixture-noise.json`, neu erzeugen mit `.tools/fixture-erzeugen.py`).
- Verschlüsselt: Client schickt leeren Rahmen `01 00 00` + Rahmen `00`+Nachricht 1 (48 Byte). Gerät:
  ServerHello `01`+Name\0+MAC\0, dann `00`+Nachricht 2 bzw. `01`+„Handshake MAC failure".
  Danach Rahmen `01`+2 Byte Länge, Inhalt verschlüsselt: 2 Byte Typ + 2 Byte Länge + Protobuf.
- **Keine AuthenticationRequest** mehr nötig; nach HelloResponse gleich DeviceInfo usw.
- **`object_id` (Feld 1) kommt leer** → Entitäten über `key` (fixed32) identifizieren (Stufe 2).
- Gerät pingt **fest alle 60 s**, auch wenn es laufend Zustände schickt. Nach DisconnectRequest auf
  DisconnectResponse warten, sonst „Reading failed CONNECTION_CLOSED" im Geräte-Log.
- `supported_color_modes` kommt gepackt; Floats sind 32 Bit (22,089 statt 22,1).
- `client_info` aus HelloRequest erscheint im Geräte-Log → wir senden „Symcon <Instanzname>".
- **Client Socket verbindet selbst neu** (live 28.10.2026, Symcon 9.0): Gerät weg → Status 200, Gerät wieder da → nach ~37 s von selbst 102. Die Wache stößt im Fehlerzustand alle 30 s zusätzlich an (schneller, harmlos).

## Bauweise

- `libs/ESPHomeHubSitzung.php` ist reines PHP ohne Symcon (Bytes rein, Bytes + Ereignisse raus),
  Zustand als JSON im Puffer `Sitzung`, weil Symcon das Objekt je Aufruf neu anlegt. Zugriffe unter
  Semaphore `ESPH_<id>` (Nonce-Zähler dürfen nie doppelt benutzt werden).
- Client Socket: Bytes als UTF-8-kodiertes ISO-8859-1 (Muster RoboHub). Umstellen per
  `IPS_RunScriptText` (Muster RoboHub/Tibber), Ergebnis über IM_CHANGESTATUS.
- Schlüssel bewusst als **Property** (PasswordTextBox), nicht Attribut: Attribute gehen beim
  Neu-Registrieren verloren (SUITE.md Stolperstein 5), der Schlüssel wird bei jeder Verbindung gebraucht.
  Abweichung von der Credentials-Regel (Attribut) — Dietmar am 28.10.2026 vorgelegt.
- Schlüssel-/Verschlüsselungsfehler → `Gesperrt`, Socket zu, bis zur nächsten Übernahme.

## Offen / am echten Gerät prüfen

- Kein echter ESP32/ESP8266 getestet, nur Host-Plattform auf dem Mac.
- Modul noch nie in Symcon geladen (kein Repo). Erst nach Freigabe: Repo anlegen, beta pushen,
  über die Modulverwaltung installieren, gegen das Host-Gerät testen (Mac nur über seine VPN-Tunneladresse erreichbar).
- Parallelbetrieb mit Home Assistant (mehrere Clients je Gerät).

## Prüfen vor jedem Push

`php .tools/test-esphomehub.php` (ohne Symcon, ohne Netz). Mit echten Geräten:
`ESPHOMEHUB_LIVE="host:6053:<schlüssel>,host:6054:" php .tools/test-esphomehub.php`.
Bei jedem Live-Fund den Fall dort ergänzen und per Mutation zeigen, dass die Prüfung rot wird.
