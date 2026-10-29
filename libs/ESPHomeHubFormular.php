<?php

declare(strict_types=1);

// ===========================================================================
// Gemeinsame Formular-Bausteine aller ESPHomeHub-Module nach der DG65-Formular-
// Konvention: „Wozu dieses Modul?" → „Neu bis Version" → „Dokumentation &
// Hilfe" → Fachpanels → „Formular: Aufbau" (ab drei Fachpanels, SUITE.md 3a) →
// Rückmeldungs-Hinweis → „Über dieses Modul".
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
        '0.4.0' => [
            '• 🪟 Neu: Formular-Aufbau selbst wählen — im ESPHome-Gerät legst du im Panel „Formular: Aufbau" fest, welche Bereiche beim Öffnen aufgeklappt, zugeklappt oder ausgeblendet sind und in welcher Reihenfolge sie stehen.',
        ],
        '0.2.0' => [
            '• 🧩 Sensoren, Schalter, Lichter, Rollläden, Lüfter, Schlösser, Klima und mehr werden automatisch zu Variablen — schaltbare direkt bedienbar.',
            '• Die Variable übernimmt einen neuen Wert erst, wenn das Gerät ihn bestätigt.',
            '• Panel „Entitäten" zeigt alles, was das Gerät meldet, mit aktuellem Wert.',
        ],
    ];

    private static $ESPHOMEHUB_REPO_URL     = 'https://github.com/DG65/ESPHomeHub';
    private static $ESPHOMEHUB_ISSUES_URL   = 'https://github.com/DG65/ESPHomeHub/issues';
    private static $ESPHOMEHUB_FORUM_URL    = 'https://community.symcon.de/t/beta-modul-esphomehub-esphome-geraete-direkt-in-ip-symcon-ohne-mqtt-und-ohne-home-assistant/144807';
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
        // Forum-Thread seit 0.3.5; GitHub bleibt als zweiter Weg für Fehlerberichte.
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Rückmeldungen',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 ESPHomeHub ist neu. Läuft dein Gerät? Fehlt etwas? Schreib es gern in den ESPHomeHub-Thread im Symcon-Forum — mit ESPHome-Version und Chip (ESP32, ESP8266 …) aus dem Panel „Gerät".'],
                ['type' => 'Button', 'caption' => 'Zum Forum-Thread', 'onClick' => "echo '" . self::$ESPHOMEHUB_FORUM_URL . "';", 'link' => true],
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

    /**
     * Baut das komplette Formular aus Rahmen + Fachpanels. Sind die Fachpanels mit Schlüsseln
     * versehen (Schlüssel => Panel), wählt der Nutzer ihren Aufbau im Panel „Formular: Aufbau";
     * das Modul braucht dann die Property „FormLayout" und eine öffentliche ResetFormLayout().
     */
    protected function esphomehubFormular(array $fachpanels, array $aktionen, array $status): string
    {
        $aufbau = [];
        if (!array_is_list($fachpanels)) {
            $layout = $this->formLayout(array_keys($fachpanels));
            $mitte = [];
            foreach ($layout as $key => $l) {
                $panel = $fachpanels[$key];
                if ($l['open'] === 'open') {
                    $panel['expanded'] = true;
                } elseif ($l['open'] === 'closed') {
                    $panel['expanded'] = false;
                } elseif ($l['open'] === 'hidden') {
                    // Nicht weglassen: die Felder bleiben im Formular, gespeicherte Werte bleiben beim „Übernehmen" erhalten
                    $panel['visible'] = false;
                }
                $mitte[] = $panel;
            }
            $aufbau = [$this->formAufbau($layout, $fachpanels)];
            $fachpanels = $mitte;
        }
        $elements = array_values(array_filter(array_merge(
            [$this->formZweck(), $this->formNeu(), $this->formDoku()],
            $fachpanels,
            $aufbau,
            [$this->formRueckmeldung(), $this->formLizenz()]
        )));
        return (string)json_encode(['elements' => $elements, 'actions' => $aktionen, 'status' => $status]);
    }

    /**
     * Reihenfolge und Aufklapp-Zustand der Fachpanels aus der Einstellung „FormLayout" (SUITE.md 3a,
     * nach Batteriewächter). Unbekannte oder fehlende Einträge fallen auf Standard und Standardposition zurück.
     *
     * @param string[] $keys Schlüssel der Fachpanels in Standardreihenfolge
     * @return array Schlüssel => ['open' => 'std'|'open'|'closed'|'hidden', 'pos' => int, 'idx' => int], sortiert
     */
    protected function formLayout(array $keys): array
    {
        $gespeichert = json_decode($this->ReadPropertyString('FormLayout'), true);
        $nach = [];
        foreach (is_array($gespeichert) ? $gespeichert : [] as $zeile) {
            if (is_array($zeile) && isset($zeile['Key'])) {
                $nach[(string)$zeile['Key']] = $zeile;
            }
        }
        $out = [];
        foreach (array_values($keys) as $i => $k) {
            $r = $nach[$k] ?? [];
            // Vorrang bei Widerspruch: ausgeblendet vor zugeklappt vor aufgeklappt, kein Haken = Standard
            $open = !empty($r['Hidden']) ? 'hidden' : (!empty($r['Closed']) ? 'closed' : (!empty($r['Opened']) ? 'open' : 'std'));
            $out[$k] = ['open' => $open, 'pos' => isset($r['Pos']) ? max(1, min(99, (int)$r['Pos'])) : $i + 1, 'idx' => $i];
        }
        uasort($out, fn ($a, $b) => [$a['pos'], $a['idx']] <=> [$b['pos'], $b['idx']]);
        return $out;
    }

    /** Standardzeilen der Liste „FormLayout" (für ResetFormLayout). */
    protected function formLayoutStandard(array $fachpanels): array
    {
        $zeilen = [];
        foreach (array_keys($fachpanels) as $i => $k) {
            $zeilen[] = ['Key' => $k, 'Panel' => $this->formPanelName($fachpanels[$k]), 'Opened' => false, 'Closed' => false, 'Hidden' => false, 'Pos' => $i + 1];
        }
        return $zeilen;
    }

    private function formPanelName(array $panel): string
    {
        return trim((string)preg_replace('/\s*\(.*$/u', '', (string)($panel['caption'] ?? '')));
    }

    /** Panel „Formular: Aufbau": je Fachpanel Aufklapp-Zustand beim Öffnen und Position. */
    private function formAufbau(array $layout, array $fachpanels): array
    {
        $zeilen = [];
        $versteckt = [];
        foreach ($layout as $key => $l) {
            $name = $this->formPanelName($fachpanels[$key]);
            $zeilen[] = ['Key' => $key, 'Panel' => $name, 'Opened' => $l['open'] === 'open', 'Closed' => $l['open'] === 'closed',
                'Hidden' => $l['open'] === 'hidden', 'Pos' => $l['pos']];
            if ($l['open'] === 'hidden') {
                $versteckt[] = trim((string)preg_replace('/^[^\p{L}\p{N}]+/u', '', $name));
            }
        }
        $versteckZeile = $versteckt
            ? [['type' => 'Label', 'caption' => '🙈 Ausgeblendet: ' . implode(', ', $versteckt) . ' — zum Wiederzeigen den Haken bei „Ausgeblendet" entfernen. Ausgeblendete Bereiche arbeiten weiter, nur die Anzeige fehlt.']]
            : [];
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🪟  Formular: Aufbau' . ($versteckt ? ' (' . count($versteckt) . ' ausgeblendet)' : ''),
            'items' => array_merge($versteckZeile, [
                ['type' => 'Label', 'caption' => 'Hier legst du fest, welche Bereiche beim Öffnen des Formulars aufgeklappt sind und in welcher Reihenfolge sie stehen. Symcon merkt sich das Auf- und Zuklappen nicht selbst, deshalb gilt die Einstellung hier jedes Mal beim Öffnen. Sie wirkt nach „Übernehmen" beim nächsten Öffnen des Formulars.'],
                ['type' => 'Label', 'caption' => 'Kein Haken heißt „Standard": wie das Modul es vorsieht (z. B. „Verbindung" aufgeklappt, solange keine Adresse eingetragen ist, danach „Gerät"). Die Position ist eine Zahl; bei gleicher Zahl bleibt die Standardreihenfolge. „Ausgeblendet" nimmt einen Bereich aus der Anzeige, ohne dass er etwas verliert: seine Einstellungen bleiben erhalten und wirken weiter. Zum Wiederzeigen den Haken entfernen oder „Auf Standard zurücksetzen" klicken. Sind mehrere Haken gesetzt, gilt „Ausgeblendet" vor „Zugeklappt" vor „Aufgeklappt". Die Bereiche oben (Zweck, Neu, Dokumentation) und unten (Rückmeldungen, Über dieses Modul) bleiben, wo sie sind, und lassen sich nicht ausblenden.'],
                [
                    'type' => 'List', 'name' => 'FormLayout', 'caption' => 'Bereiche', 'rowCount' => count($zeilen), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false,
                    'values' => $zeilen,
                    'columns' => [
                        ['caption' => 'Bereich', 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                        ['caption' => 'Schlüssel', 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                        ['caption' => 'Aufgeklappt', 'name' => 'Opened', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Zugeklappt', 'name' => 'Closed', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => '🙈 Ausgeblendet', 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Position', 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                    ],
                ],
                ['type' => 'Button', 'caption' => '↩️ Auf Standard zurücksetzen', 'onClick' => 'echo ' . $this->esphomehubPrefix() . '_ResetFormLayout($id);'],
            ]),
        ];
    }
}
