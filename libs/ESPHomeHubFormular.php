<?php

declare(strict_types=1);

// ===========================================================================
// Gemeinsame Formular-Bausteine aller ESPHomeHub-Module nach der DG65-Formular-
// Konvention: „Wozu dieses Modul?" → „Neu bis Version" → „Dokumentation &
// Hilfe" → Fachpanels → Rückmeldungs-Hinweis → „Über dieses Modul".
//
// Trait mit Modul-Präfix im Namen: Klassen- und Trait-Namen sind in Symcon
// prozessweit global. Jedes Modul liefert über esphomehubPrefix() seinen Präfix,
// damit die Ack-Knöpfe die richtige globale Funktion aufrufen.
// ===========================================================================

trait ESPHomeHubFormular
{
    // „Was ist neu": Schlüssel = Version ohne Beta-/Build-Suffix.
    private static $ESPHOMEHUB_NEWS_VERSIONS = [
        '0.1.0' => [
            '• Erstes Release: ESPHome-Geräte direkt über ihre eigene Schnittstelle (Native API) anbinden, ohne MQTT und ohne Home Assistant.',
            '• Verschlüsselte Verbindung mit dem Schlüssel aus der Geräte-YAML, Lebenszeichen und automatisches Wiederverbinden.',
            '• Geräteinfo (Name, Version, Modell, MAC) und eine Variable „Verbunden" je Gerät.',
        ],
        '0.1.1' => [
            '• Fehlt der Client Socket, legt das ESPHome-Gerät ihn selbst an und verbindet ihn.',
        ],
        '0.3.0' => [
            '• 🔍 Neu: ESPHome-Konfigurator — findet die Geräte im Netz und legt sie mit einem Klick an, die Spalte „Schlüssel" zeigt, welches Gerät einen braucht.',
            '• Geräte hinter VLAN- oder VPN-Grenzen lassen sich im Konfigurator von Hand eintragen.',
        ],
        '0.2.0' => [
            '• 🧩 Sensoren, Schalter, Lichter, Rollläden, Lüfter, Schlösser, Klima und mehr werden automatisch zu Variablen — schaltbare direkt bedienbar.',
            '• Die Variable übernimmt einen neuen Wert erst, wenn das Gerät ihn bestätigt.',
            '• Panel „Entitäten" zeigt alles, was das Gerät meldet, mit aktuellem Wert.',
        ],
    ];

    private static $ESPHOMEHUB_REPO_URL     = 'https://github.com/DG65/ESPHomeHub';
    private static $ESPHOMEHUB_ISSUES_URL   = 'https://github.com/DG65/ESPHomeHub/issues';
    private static $ESPHOMEHUB_LICENSE_URL  = 'https://github.com/DG65/ESPHomeHub/blob/beta/LICENSE';
    private static $ESPHOMEHUB_PAYPAL_URL   = 'https://paypal.me/DietmarGureth';
    private static $ESPHOMEHUB_LIBRARY_GUID = '{E431F148-7264-4C2A-83AD-C3FA1CDE96B8}';

    abstract protected function esphomehubPrefix(): string;

    /** Zweck-Text (2–3 Sätze) je Modul. */
    abstract protected function esphomehubZweck(): array;

    /** Inhalt des Doku-Panels je Modul (ohne Versionszeile). */
    abstract protected function esphomehubDoku(): array;

    protected function registerFormAttributes(): void
    {
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('ForumHintGone', false);
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    public function AckNews(): void
    {
        // Gespeichert wird die tatsächlich INSTALLIERTE Version (SUITE.md Formular-Optik Punkt 1).
        $this->WriteAttributeString('SeenNews', $this->esphomehubInstallierteVersion());
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function AckForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
    }

    protected static function esphomehubBasisVersion(string $version): string
    {
        return (string)preg_replace('/-.*$/', '', $version);
    }

    protected function esphomehubBibliothek(): array
    {
        $lib = function_exists('IPS_GetLibrary') ? @IPS_GetLibrary(self::$ESPHOMEHUB_LIBRARY_GUID) : false;
        return is_array($lib) ? $lib : [];
    }

    protected function esphomehubInstallierteVersion(): string
    {
        $lib = $this->esphomehubBibliothek();
        if (!empty($lib['Version'])) {
            return self::esphomehubBasisVersion((string)$lib['Version']);
        }
        $keys = array_keys(self::$ESPHOMEHUB_NEWS_VERSIONS);
        usort($keys, 'version_compare');
        return (string)end($keys);
    }

    protected function formZweck(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        $items = [];
        foreach ($this->esphomehubZweck() as $zeile) {
            $items[] = ['type' => 'Label', 'caption' => $zeile];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => $this->esphomehubPrefix() . '_AckPurposeIntro($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true, 'caption' => '👋  Wozu dieses Modul?', 'items' => $items];
    }

    protected function formNeu(): ?array
    {
        $gesehen = $this->ReadAttributeString('SeenNews');
        if ($gesehen === '') {
            $gesehen = '0';
        }
        $offen = [];
        foreach (self::$ESPHOMEHUB_NEWS_VERSIONS as $version => $zeilen) {
            if (version_compare((string)$version, $gesehen, '>')) {
                $offen[(string)$version] = $zeilen;
            }
        }
        if (count($offen) === 0) {
            return null;
        }
        uksort($offen, 'version_compare');
        $items = [];
        foreach ($offen as $version => $zeilen) {
            $items[] = ['type' => 'Label', 'caption' => 'Version ' . $version . ':'];
            foreach ($zeilen as $zeile) {
                $items[] = ['type' => 'Label', 'caption' => $zeile];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => $this->esphomehubPrefix() . '_AckNews($id);'];
        $keys = array_keys($offen);
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true, 'caption' => '🆕  Neu bis Version ' . end($keys), 'items' => $items];
    }

    protected function formDoku(): array
    {
        $lib = $this->esphomehubBibliothek();
        $ver = isset($lib['Version'])
            ? 'ℹ️ ESPHomeHub Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')'
            : 'ℹ️ ESPHomeHub';
        $items = [['type' => 'Label', 'caption' => $ver]];
        foreach ($this->esphomehubDoku() as $zeile) {
            $items[] = ['type' => 'Label', 'caption' => $zeile];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Anleitung auf GitHub', 'onClick' => "echo '" . self::$ESPHOMEHUB_REPO_URL . "';", 'link' => true];
        return ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '📖  Dokumentation & Hilfe', 'items' => $items];
    }

    protected function formRueckmeldung(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        // Noch kein Forum-Thread: Rückmeldungen vorerst über GitHub.
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Rückmeldungen',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 ESPHomeHub ist neu. Läuft dein Gerät? Fehlt etwas? Schreib es gern als Meldung auf GitHub — mit ESPHome-Version und Chip (ESP32, ESP8266 …) aus dem Panel „Gerät".'],
                ['type' => 'Button', 'caption' => 'Fehler auf GitHub melden', 'onClick' => "echo '" . self::$ESPHOMEHUB_ISSUES_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => $this->esphomehubPrefix() . '_AckForumHint($id);'],
            ],
        ];
    }

    /** Wortlaut verbundweit identisch (SUITE.md Formular-Optik Punkt 5, Variante A). */
    protected function formLizenz(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  Über dieses Modul',
            'items' => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . self::$ESPHOMEHUB_LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo '" . self::$ESPHOMEHUB_PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    /** Baut das komplette Formular aus Rahmen + Fachpanels. */
    protected function esphomehubFormular(array $fachpanels, array $aktionen, array $status): string
    {
        $elements = array_values(array_filter(array_merge(
            [$this->formZweck(), $this->formNeu(), $this->formDoku()],
            $fachpanels,
            [$this->formRueckmeldung(), $this->formLizenz()]
        )));
        return (string)json_encode(['elements' => $elements, 'actions' => $aktionen, 'status' => $status]);
    }
}
