# Changelog

## 0.3.4 (28.10.2026)

- Keine Warnung mehr im Meldungsfenster, wenn der Client Socket kurz nach dem Löschen des Geräts noch eine Statusänderung meldet.

## 0.3.3 (28.10.2026)

- Übernehmen ohne Änderung, Symcon-Start und Modul-Update bauen die Verbindung nicht mehr zwangsweise neu auf. Steht der Socket schon, beginnt nur die API-Sitzung neu; geschlossen und geöffnet wird er nur bei neuer Adresse, neuem Port oder neuem Schlüssel.

## 0.3.2 (28.10.2026)

- Zeitanfrage des Geräts mit Zeitzone und Sommerzeit-Regeln beantwortet (`parsed_timezone`, seit ESPHome 2026.9 nötig). Vorher zeigte ein Gerät die Zeitzone seines Build-Rechners, oft UTC. Funktioniert auch ohne zoneinfo-Dateien (Symcon unter Windows).

## 0.3.1 (28.10.2026)

- Über eine Hand-Adresse angelegte Geräte bekommen nach dem ersten Verbinden den Anzeigenamen des Geräts statt der IP-Adresse (ein selbst vergebener Name bleibt).
- Konfigurator: Hand-Adressen zeigen MAC, Version und Chip aus der zugehörigen Instanz.

## 0.3.0 (28.10.2026)

- Neu: ESPHome-Konfigurator. Findet ESPHome-Geräte im Netz (mDNS über Symcons DNS-SD-Instanz) und legt sie samt Client Socket in einem Schritt an.
- Zeigt je Gerät Anzeigename, Adresse, MAC, ESPHome-Version, Chip und ob ein Schlüssel nötig ist; erkennt vorhandene Instanzen an der MAC, auch nach einem IP-Wechsel.
- Adressen von Hand für Geräte, die die Suche nicht erreicht (VLAN, VPN, Docker ohne Host-Netz).

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
