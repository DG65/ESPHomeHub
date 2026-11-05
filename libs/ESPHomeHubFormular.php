<?php

declare(strict_types=1);

require_once __DIR__ . '/ESPHomeHubText.php';

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
            '• First release: connect ESPHome devices directly through their own interface (Native API), without MQTT and without Home Assistant.',
            '• Encrypted connection with the key from the device YAML, keep-alive and automatic reconnect.',
            '• Device info (name, version, model, MAC) and a variable "Connected" per device.',
        ],
        '0.1.1' => [
            '• If the Client Socket is missing, the ESPHome device creates and connects it by itself.',
        ],
        '0.3.0' => [
            '• 🔍 New: ESPHome configurator — finds the devices on the network and creates them with one click; the column "Key" shows which device needs one.',
            '• Devices behind VLAN or VPN boundaries can be entered manually in the configurator.',
        ],
        '0.4.0' => [
            '• 🪟 New: choose the form layout yourself — in the ESPHome device, the panel "Form: layout" sets which sections are expanded, collapsed or hidden when the form opens and in which order they appear.',
        ],
        '0.5.0' => [
            '• 🌐 New: English user interface — ESPHomeHub follows the language of Symcon.',
        ],
        '0.6.0' => [
            '• ❄️ New for climate devices: fan, swing, preset and humidity as variables, whenever the device offers them.',
        ],
        '0.2.0' => [
            '• 🧩 Sensors, switches, lights, covers, fans, locks, climate and more become variables automatically — switchable ones can be operated directly.',
            '• A variable only takes a new value once the device confirms it.',
            '• Panel "Entities" shows everything the device reports, with its current value.',
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
        $items[] = ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => $this->esphomehubPrefix() . '_AckPurposeIntro($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true, 'caption' => '👋  What is this module for?', 'items' => $items];
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
            $items[] = ['type' => 'Label', 'caption' => ESPHomeHubText::t('Version %s:', $version)];
            foreach ($zeilen as $zeile) {
                $items[] = ['type' => 'Label', 'caption' => $zeile];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => $this->esphomehubPrefix() . '_AckNews($id);'];
        $keys = array_keys($offen);
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true, 'caption' => ESPHomeHubText::t('🆕  New up to version %s', end($keys)), 'items' => $items];
    }

    protected function formDoku(): array
    {
        $lib = $this->esphomehubBibliothek();
        $ver = isset($lib['Version'])
            ? ESPHomeHubText::t('ℹ️ ESPHomeHub version %s (build %s)', $lib['Version'], $lib['Build'] ?? '?')
            : 'ℹ️ ESPHomeHub';
        $items = [['type' => 'Label', 'caption' => $ver]];
        foreach ($this->esphomehubDoku() as $zeile) {
            $items[] = ['type' => 'Label', 'caption' => $zeile];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Manual on GitHub', 'onClick' => "echo '" . self::$ESPHOMEHUB_REPO_URL . "';", 'link' => true];
        return ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '📖  Documentation & help', 'items' => $items];
    }

    protected function formRueckmeldung(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        // Forum-Thread seit 0.3.5; GitHub bleibt als zweiter Weg für Fehlerberichte.
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Feedback',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 ESPHomeHub is new. Does your device work? Is something missing? Feel free to post in the ESPHomeHub thread in the Symcon forum — with ESPHome version and chip (ESP32, ESP8266 …) from the panel "Device".'],
                ['type' => 'Button', 'caption' => 'Go to the forum thread', 'onClick' => "echo '" . self::$ESPHOMEHUB_FORUM_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Report a bug on GitHub', 'onClick' => "echo '" . self::$ESPHOMEHUB_ISSUES_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => $this->esphomehubPrefix() . '_AckForumHint($id);'],
            ],
        ];
    }

    /** Wortlaut verbundweit identisch (SUITE.md Formular-Optik Punkt 5, Variante A). */
    protected function formLizenz(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  About this module',
            'items' => [
                ['type' => 'Label', 'caption' => 'Born out of real enthusiasm for my own setup — and quite a few evenings of typing. Still: hobby software or not, this is intellectual property and real work went into it.'],
                ['type' => 'Label', 'caption' => 'License: PolyForm Noncommercial 1.0.0 — free for private and non-commercial use; commercial use requires a separate license from the rights holder.'],
                ['type' => 'Button', 'caption' => 'View license text', 'onClick' => "echo '" . self::$ESPHOMEHUB_LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Commercial use or questions about the license? Just get in touch: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'You like the module and would like to leave something anyway? I am happy about a small donation — completely voluntary, nothing required in return.'],
                ['type' => 'Button', 'caption' => '☕  Donate via PayPal', 'onClick' => "echo '" . self::$ESPHOMEHUB_PAYPAL_URL . "';", 'link' => true],
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
        return (string)json_encode(['elements' => $this->formUebersetzen($elements), 'actions' => $this->formUebersetzen($aktionen),
            'status' => $this->formUebersetzen($status)]);
    }

    /** Übersetzt alle Überschriften im Formular (Quelltexte englisch, Deutsch in locale.json). */
    private function formUebersetzen(array $teil): array
    {
        foreach ($teil as $k => $v) {
            if (is_array($v)) {
                $teil[$k] = $this->formUebersetzen($v);
            } elseif ($k === 'caption' && is_string($v)) {
                $teil[$k] = ESPHomeHubText::t($v);
            }
        }
        return $teil;
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
        return trim((string)preg_replace('/\s*\(.*$/u', '', ESPHomeHubText::t((string)($panel['caption'] ?? ''))));
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
            ? [['type' => 'Label', 'caption' => ESPHomeHubText::t('🙈 Hidden: %s — to show again, clear the "Hidden" checkbox. Hidden sections keep working, only the display is missing.', implode(', ', $versteckt))]]
            : [];
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => ESPHomeHubText::t('🪟  Form: layout') . ($versteckt ? ' ' . ESPHomeHubText::t('(%s hidden)', count($versteckt)) : ''),
            'items' => array_merge($versteckZeile, [
                ['type' => 'Label', 'caption' => 'Here you decide which sections are expanded when the form opens and in which order they appear. Symcon does not remember expanding and collapsing, so this setting applies every time the form opens. It takes effect after "Apply" the next time the form is opened.'],
                ['type' => 'Label', 'caption' => 'No checkbox means "default": as the module intends (e.g. "Connection" expanded as long as no address is entered, then "Device"). The position is a number; with equal numbers the default order applies. "Hidden" removes a section from the display without it losing anything: its settings are kept and keep working. To show it again, clear the checkbox or click "Reset to default". If several checkboxes are set, "Hidden" wins over "Collapsed", which wins over "Expanded". The sections at the top (purpose, news, documentation) and at the bottom (feedback, about this module) stay where they are and cannot be hidden.'],
                [
                    'type' => 'List', 'name' => 'FormLayout', 'caption' => 'Sections', 'rowCount' => count($zeilen), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false,
                    'values' => $zeilen,
                    'columns' => [
                        ['caption' => 'Section', 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                        ['caption' => 'Key', 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                        ['caption' => 'Expanded', 'name' => 'Opened', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Collapsed', 'name' => 'Closed', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => '🙈 Hidden', 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Position', 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                    ],
                ],
                ['type' => 'Button', 'caption' => '↩️ Reset to default', 'onClick' => 'echo ' . $this->esphomehubPrefix() . '_ResetFormLayout($id);'],
            ]),
        ];
    }
}
