# Changelog

## 0.2.2 (28.10.2026)

- Darstellungen werden inhaltlich verglichen: Symcon gibt sie umgeformt zurück (ganze Zahlen ohne Nachkomma, OPTIONS neu sortiert), bisher hätte jede Neuverbindung Zahlen-, Klima- und Auswahlvariablen neu geschrieben.
- Grenzen und Schrittweiten aus dem Gerät als glatte Dezimalzahl (0,1 statt 0,100000001).

## 0.2.1 (28.10.2026)

- Knopf und Fahrbefehle aktualisieren ihre Variable bei jedem Druck, auch bei gleichem Wert — Ereignisse „bei Aktualisierung" sehen so jeden Druck.

## 0.2.0 (28.10.2026)

- Entitäten werden zu Variablen: Sensor, Binärsensor, Textsensor, Schalter, Knopf, Zahl, Auswahl, Text, Licht (an/aus, Helligkeit, Farbe, Farbtemperatur), Abdeckung/Rollladen und Ventil (Position, Öffnen/Stopp/Schließen), Lüfter (Stufe), Schloss (verriegeln, öffnen), Klima (Betriebsart, Solltemperatur, Ist-Temperatur, Tätigkeit), Ereignis.
- Schaltbare Variablen über die Visualisierung oder `RequestAction`; die Variable übernimmt den Wert mit der Rückmeldung des Geräts. Klare Fehlermeldung bei ungültigem Wert oder getrennter Verbindung.
- Darstellungen passend zur Entität (Einheit, Nachkommastellen, Grenzen, Klartext je device_class); Zeitabfrage des Geräts wird beantwortet.
- Panel „Entitäten" mit allen Entitäten und aktuellen Werten; nicht unterstützte Typen werden genannt.

## 0.1.1 (28.10.2026)

- Fehlt der Client Socket (z. B. Instanz per Skript angelegt), legt das Gerät ihn nach spätestens 30 s selbst an, benennt und verbindet ihn.
- Gerätename im ESPHome-Protokoll an der Zeichengrenze auf 31 Byte gekürzt (das Gerät schnitt sonst mitten im Umlaut ab).

## 0.1.0 (28.10.2026)

- Erste Stufe: ESPHome-Gerät über die Native API (TCP 6053) mit Client Socket als Elternteil.
- Verschlüsselung Noise_NNpsk0_25519_ChaChaPoly_SHA256 mit dem Schlüssel aus der Geräte-YAML; Geräte ohne Verschlüsselung ebenfalls.
- Geräteinfo (Name, MAC, ESPHome-Version, Modell, Hersteller), Variable „Verbunden", Lebenszeichen beantworten, Wiederverbinden nach Trennung oder 150 s Stille.
- Klare Meldung bei falschem Schlüssel und bei Gerät mit/ohne Verschlüsselung; dann keine Dauerversuche.
