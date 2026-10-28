# Changelog

## 0.1.1 (28.10.2026)

- Fehlt der Client Socket (z. B. Instanz per Skript angelegt), legt das Gerät ihn nach spätestens 30 s selbst an, benennt und verbindet ihn.
- Gerätename im ESPHome-Protokoll an der Zeichengrenze auf 31 Byte gekürzt (das Gerät schnitt sonst mitten im Umlaut ab).

## 0.1.0 (28.10.2026)

- Erste Stufe: ESPHome-Gerät über die Native API (TCP 6053) mit Client Socket als Elternteil.
- Verschlüsselung Noise_NNpsk0_25519_ChaChaPoly_SHA256 mit dem Schlüssel aus der Geräte-YAML; Geräte ohne Verschlüsselung ebenfalls.
- Geräteinfo (Name, MAC, ESPHome-Version, Modell, Hersteller), Variable „Verbunden", Lebenszeichen beantworten, Wiederverbinden nach Trennung oder 150 s Stille.
- Klare Meldung bei falschem Schlüssel und bei Gerät mit/ohne Verschlüsselung; dann keine Dauerversuche.
