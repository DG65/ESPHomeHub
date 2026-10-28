<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ESPHomeHubSitzung.php';
require_once __DIR__ . '/../libs/ESPHomeHubFormular.php';

// ===========================================================================
// ESPHomeHub Gerät — eine Instanz je ESPHome-Gerät (ESP32, ESP8266, …).
//
// Elternteil ist ein Client Socket (TCP 6053). Steht die Verbindung, baut die
// Instanz die API-Sitzung auf (Noise-Handshake mit dem Schlüssel aus der
// Geräte-YAML oder unverschlüsselt), liest die Geräteinfo und beantwortet die
// Pings, die das Gerät alle 60 s schickt. Der Sitzungszustand (Schlüssel und
// Nonce-Zähler) liegt im Puffer, weil Symcon das Objekt je Aufruf neu anlegt;
// alle Zugriffe darauf laufen unter einer Semaphore.
//
// Ohne verbundenen Client startet ein ESPHome-Gerät nach reboot_timeout
// (Standard 15 min) neu — deshalb verbindet die Wache zügig wieder.
//
// Stufe 1: Verbindung, Geräteinfo, Lebenszeichen. Entitäten folgen in Stufe 2.
// ===========================================================================

class ESPHomeHubGeraet extends IPSModule
{
    use ESPHomeHubFormular;

    private const CLIENT_SOCKET = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    private const DATA_ZUM_IO = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';

    private const STATUS_SCHLUESSEL_FALSCH = 201;
    private const STATUS_VERSCHLUESSELUNG = 202;
    private const STATUS_UNERREICHBAR = 203;

    private const WACHE_S = 30;
    private const AUFBAU_MAX_S = 20;       // Handshake bis DeviceInfo, sonst neu verbinden
    private const STILLE_MAX_S = 150;      // Gerät pingt alle 60 s; nach 2,5 Pings ohne Lebenszeichen neu verbinden

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 6053);
        // Bewusst Property, nicht Attribut: Attribute gehen beim Neu-Registrieren in der
        // Modulverwaltung verloren (SUITE.md Stolperstein 5), der Schlüssel wird aber bei
        // JEDER Verbindung gebraucht. Im Formular als PasswordTextBox verdeckt.
        $this->RegisterPropertyString('Schluessel', '');

        $this->RegisterAttributeString('Geraet', '{}');
        $this->RegisterAttributeString('Verbindung', '{}');
        $this->RegisterAttributeBoolean('Gesperrt', false);
        $this->RegisterAttributeInteger('Eltern', 0);
        $this->registerFormAttributes();

        $this->RequireParent(self::CLIENT_SOCKET);
        $this->RegisterTimer('Wache', 0, 'ESPH_Wache($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
            return;
        }

        $this->MaintainVariable('Verbunden', 'Verbunden', VARIABLETYPE_BOOLEAN, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => (string)json_encode([
                ['Value' => false, 'Caption' => 'Getrennt', 'IconActive' => false, 'IconValue' => '', 'ColorActive' => true, 'ColorValue' => 0xE53935],
                ['Value' => true, 'Caption' => 'Verbunden', 'IconActive' => false, 'IconValue' => '', 'ColorActive' => true, 'ColorValue' => 0x43A047],
            ]),
        ], 1, true);

        // Jede Übernahme ist ein neuer Versuch, auch nach einem Schlüsselfehler.
        $this->WriteAttributeBoolean('Gesperrt', false);
        $this->sitzungVerwerfen();
        $this->elternBeobachten();

        $schluessel = trim($this->ReadPropertyString('Schluessel'));
        if ($this->host() === '') {
            $this->SetStatus(104);
            $this->SetTimerInterval('Wache', 0);
            $this->elternEinstellen(false);
            $this->verbindungMerken(['zustand' => 'aus']);
            return;
        }
        if ($schluessel !== '' && ESPHomeHubNoise::schluesselLesen($schluessel) === null) {
            $this->SetStatus(self::STATUS_SCHLUESSEL_FALSCH);
            $this->SetTimerInterval('Wache', 0);
            $this->elternEinstellen(false);
            $this->verbindungMerken(['zustand' => 'fehler', 'text' => 'Der Schlüssel ist kein gültiger ESPHome-Schlüssel (Base64, 32 Byte).']);
            return;
        }
        $this->SetTimerInterval('Wache', self::WACHE_S * 1000);
        $this->socketBenennen();
        $this->verbindungMerken(['zustand' => 'verbinde']);
        // Neu verbinden, damit eine geänderte Adresse oder ein neuer Schlüssel sofort greift.
        $this->elternEinstellen(true, true);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && ($Data[0] ?? null) === KR_READY) {
            $this->UnregisterMessage(0, IPS_KERNELMESSAGE);
            $this->ApplyChanges();
            return;
        }
        if ($Message === IM_CHANGESTATUS && $SenderID === $this->ReadAttributeInteger('Eltern')) {
            if ((int)($Data[0] ?? 0) === IS_ACTIVE) {
                $this->sitzungStarten();
            } else {
                $this->sitzungVerwerfen();
                if ((int)($Data[0] ?? 0) >= IS_EBASE && !$this->ReadAttributeBoolean('Gesperrt') && $this->host() !== '') {
                    $this->SetStatus(self::STATUS_UNERREICHBAR);
                    $this->verbindungMerken(['zustand' => 'getrennt', 'text' => 'Gerät nicht erreichbar.']);
                }
            }
        }
    }

    // ------------------------------------------------------------------ öffentliche Funktionen

    /** Timer: Verbindung prüfen, hängende oder stumme Sitzungen neu aufbauen. */
    public function Wache(): void
    {
        if ($this->host() === '' || $this->ReadAttributeBoolean('Gesperrt')) {
            return;
        }
        $eltern = $this->eltern();
        if ($eltern === 0) {
            $this->verbindungMerken(['zustand' => 'fehler', 'text' => 'Kein Client Socket verbunden.']);
            return;
        }
        $v = $this->verbindung();
        if (IPS_GetInstance($eltern)['InstanceStatus'] !== IS_ACTIVE) {
            // Client Socket im Fehlerzustand: selbst neu anstoßen (Gerät war z. B. kurz offline).
            $this->elternEinstellen(true, true);
            return;
        }
        $sitzung = $this->GetBuffer('Sitzung');
        $seit = (int)($v['seit'] ?? 0);
        if ($sitzung === '' || ($v['zustand'] ?? '') !== 'verbunden') {
            if (time() - $seit > self::AUFBAU_MAX_S) {
                $this->SendDebug('Wache', 'Sitzung kommt nicht zustande — verbinde neu', 0);
                $this->elternEinstellen(true, true);
            }
            return;
        }
        $letzter = (int)$this->GetBuffer('LetzterEmpfang');
        if ($letzter > 0 && time() - $letzter > self::STILLE_MAX_S) {
            $this->LogMessage('Keine Antwort mehr vom Gerät seit ' . (time() - $letzter) . ' s — verbinde neu.', KL_WARNING);
            $this->elternEinstellen(true, true);
        }
    }

    /** Formular-Knopf: Verbindung trennen und neu aufbauen. */
    public function Neuverbinden(): void
    {
        if ($this->host() === '') {
            echo '⛔ Erst eine IP-Adresse oder einen Hostnamen eintragen und übernehmen.';
            return;
        }
        $this->WriteAttributeBoolean('Gesperrt', false);
        $this->verbindungMerken(['zustand' => 'verbinde']);
        $this->elternEinstellen(true, true);
        echo '🔄 Verbinde neu mit ' . $this->host() . ' … Ergebnis siehe Panel „Gerät" (Formular neu öffnen).';
    }

    /** Geräteinfo als JSON (Name, MAC, ESPHome-Version, Modell …); leer, solange nie verbunden. */
    public function GetDeviceInfo(): string
    {
        return $this->ReadAttributeString('Geraet');
    }

    // ------------------------------------------------------------------ Daten vom Client Socket

    public function ReceiveData($JSONString)
    {
        $paket = json_decode((string)$JSONString, true);
        if (!is_array($paket) || !isset($paket['Buffer'])) {
            return '';
        }
        // Client Socket liefert Bytes als UTF-8-kodiertes ISO-8859-1 (Verbund-Muster RoboHub/Fernwirk101).
        $bytes = mb_convert_encoding((string)$paket['Buffer'], 'ISO-8859-1', 'UTF-8');
        if (!$this->sperren()) {
            $this->SendDebug('Empfang', 'Semaphore belegt — ' . strlen($bytes) . ' Byte verworfen', 0);
            return '';
        }
        try {
            $sitzung = ESPHomeHubSitzung::import($this->GetBuffer('Sitzung'));
            if ($sitzung === null) {
                $this->SendDebug('Empfang', strlen($bytes) . ' Byte ohne Sitzung ignoriert', 0);
                return '';
            }
            $ergebnis = $sitzung->empfangen($bytes);
            if ($ergebnis['senden'] !== '') {
                $this->rohSenden($ergebnis['senden']);
            }
            $this->SetBuffer('Sitzung', $sitzung->phase() === ESPHomeHubSitzung::AUS ? '' : $sitzung->export());
            $this->SetBuffer('LetzterEmpfang', (string)time());
            $server = $sitzung->server();
        } finally {
            $this->entsperren();
        }
        foreach ($ergebnis['ereignisse'] as $e) {
            $this->ereignis($e, $server);
        }
        return '';
    }

    private function ereignis(array $e, array $server): void
    {
        switch ($e[0]) {
            case 'hello':
                $this->SendDebug('Hello', json_encode($e[1]), 0);
                return;
            case 'geraet':
                $info = $e[1] + ['zuletzt' => time()];
                $this->WriteAttributeString('Geraet', (string)json_encode($info));
                $vorher = $this->verbindung()['zustand'] ?? '';
                $this->verbindungMerken(['zustand' => 'verbunden', 'text' => '', 'verschluesselt' => trim($this->ReadPropertyString('Schluessel')) !== '']);
                $this->verbundenSetzen(true);
                $this->SetStatus(IS_ACTIVE);
                if ($vorher === 'getrennt') {
                    $this->LogMessage('Wieder verbunden mit ' . ($info['anzeigename'] ?: $info['name']) . '.', KL_MESSAGE);
                }
                $this->SendDebug('Gerät', json_encode($info), 0);
                return;
            case 'ping':
                $this->verbindungMerken(['ping' => time()]);
                $this->SendDebug('Ping', 'beantwortet', 0);
                return;
            case 'getrennt':
                // Gerät trennt selbst (Neustart, OTA-Update) — die Wache verbindet wieder.
                $this->verbundenSetzen(false);
                $this->verbindungMerken(['zustand' => 'getrennt', 'text' => 'Gerät hat die Verbindung beendet (Neustart oder Update).']);
                return;
            case 'nachricht':
                $this->SendDebug('Nachricht', 'Typ ' . $e[1] . ', ' . strlen($e[2]) . ' Byte (noch nicht ausgewertet)', 0);
                return;
            case 'fehler':
                $this->fehler((string)$e[1], (string)$e[2], $server);
                return;
        }
    }

    private function fehler(string $art, string $text, array $server): void
    {
        $this->verbundenSetzen(false);
        if ($art === 'protokoll') {
            // Kein Dauerzustand: die Wache baut die Sitzung neu auf.
            $this->verbindungMerken(['zustand' => 'getrennt', 'text' => 'Protokollfehler: ' . $text]);
            $this->LogMessage('Protokollfehler, verbinde neu: ' . $text, KL_WARNING);
            $this->elternEinstellen(true, true);
            return;
        }
        // Schlüssel- und Verschlüsselungsfehler heilen nicht von selbst: Socket zu, bis der Nutzer etwas ändert.
        $hinweis = match ($art) {
            'schluessel'       => $text . ' Den Schlüssel aus „api: encryption: key" der Geräte-YAML eintragen.',
            'unverschluesselt' => $text . ' Schlüssel leer lassen oder im Gerät die Verschlüsselung einschalten.',
            default            => $text . ' Den Schlüssel aus der Geräte-YAML eintragen.',
        };
        if (($server['name'] ?? '') !== '') {
            $hinweis .= ' (Gerät meldet sich als „' . $server['name'] . '")';
        }
        $this->WriteAttributeBoolean('Gesperrt', true);
        $this->SetStatus($art === 'schluessel' ? self::STATUS_SCHLUESSEL_FALSCH : self::STATUS_VERSCHLUESSELUNG);
        $this->verbindungMerken(['zustand' => 'fehler', 'text' => $hinweis]);
        $this->LogMessage($hinweis, KL_WARNING);
        $this->elternEinstellen(false);
    }

    // ------------------------------------------------------------------ Sitzung

    private function sitzungStarten(): void
    {
        if ($this->host() === '' || $this->ReadAttributeBoolean('Gesperrt')) {
            return;
        }
        $schluessel = trim($this->ReadPropertyString('Schluessel'));
        $psk = $schluessel === '' ? null : ESPHomeHubNoise::schluesselLesen($schluessel);
        if ($schluessel !== '' && $psk === null) {
            return;
        }
        if (!$this->sperren()) {
            return;
        }
        try {
            [$sitzung, $bytes] = ESPHomeHubSitzung::starten($psk, 'Symcon ' . IPS_GetName($this->InstanceID));
            $this->SetBuffer('Sitzung', $sitzung->export());
            $this->SetBuffer('LetzterEmpfang', '0');
            $this->rohSenden($bytes);
        } finally {
            $this->entsperren();
        }
        $this->verbindungMerken(['zustand' => 'verbinde', 'text' => '']);
        $this->SendDebug('Sitzung', $psk === null ? 'unverschlüsselt gestartet' : 'Noise-Handshake gestartet', 0);
    }

    private function sitzungVerwerfen(): void
    {
        $this->SetBuffer('Sitzung', '');
        $this->verbundenSetzen(false);
    }

    private function rohSenden(string $bytes): void
    {
        if (!$this->HasActiveParent()) {
            return;
        }
        $this->SendDataToParent((string)json_encode([
            'DataID' => self::DATA_ZUM_IO,
            'Buffer' => mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1'),
        ]));
    }

    private function sperren(): bool
    {
        return IPS_SemaphoreEnter('ESPH_' . $this->InstanceID, 3000);
    }

    private function entsperren(): void
    {
        IPS_SemaphoreLeave('ESPH_' . $this->InstanceID);
    }

    // ------------------------------------------------------------------ Client Socket

    private function eltern(): int
    {
        return (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
    }

    private function elternBeobachten(): void
    {
        $eltern = $this->eltern();
        $vorher = $this->ReadAttributeInteger('Eltern');
        if ($vorher > 0 && $vorher !== $eltern) {
            $this->UnregisterMessage($vorher, IM_CHANGESTATUS);
        }
        $this->WriteAttributeInteger('Eltern', $eltern);
        if ($eltern > 0) {
            $this->RegisterMessage($eltern, IM_CHANGESTATUS);
        }
    }

    public function GetConfigurationForParent()
    {
        return (string)json_encode([
            'Host' => $this->host(),
            'Port' => $this->ReadPropertyInteger('Port'),
            'Open' => $this->host() !== '' && !$this->ReadAttributeBoolean('Gesperrt'),
        ]);
    }

    /**
     * Client Socket auf Adresse/Port stellen und öffnen bzw. schließen.
     * Per IPS_RunScriptText (Muster RoboHub/Tibber), damit das ApplyChanges des
     * Sockets nicht im eigenen Aufruf hängt; IM_CHANGESTATUS meldet das Ergebnis.
     */
    private function elternEinstellen(bool $offen, bool $neu = false): void
    {
        $eltern = $this->eltern();
        if ($eltern === 0 || IPS_GetInstance($eltern)['ModuleInfo']['ModuleID'] !== self::CLIENT_SOCKET) {
            return;
        }
        $soll = ['Host' => $this->host(), 'Port' => $this->ReadPropertyInteger('Port'), 'Open' => $offen];
        $anders = false;
        foreach ($soll as $k => $wert) {
            if (IPS_GetProperty($eltern, $k) != $wert) {
                $anders = true;
            }
        }
        $skript = '';
        if ($offen && $neu && !$anders) {
            $skript .= 'IPS_SetProperty(' . $eltern . ', "Open", false); IPS_ApplyChanges(' . $eltern . ');';
        }
        if ($anders || $skript !== '') {
            $skript .= 'IPS_SetConfiguration(' . $eltern . ', ' . var_export((string)json_encode($soll), true) . '); IPS_ApplyChanges(' . $eltern . ');';
            IPS_RunScriptText($skript);
        }
    }

    /** Vom Konsolen-Dialog angelegten Socket sprechend benennen. */
    private function socketBenennen(): void
    {
        $eltern = $this->eltern();
        if ($eltern > 0 && in_array(IPS_GetName($eltern), ['Client Socket', 'Client-Socket'], true)) {
            IPS_SetName($eltern, 'ESPHome-Verbindung (' . IPS_GetName($this->InstanceID) . ')');
        }
    }

    // ------------------------------------------------------------------ Hilfen

    private function host(): string
    {
        return trim($this->ReadPropertyString('Host'));
    }

    private function verbindung(): array
    {
        return json_decode($this->ReadAttributeString('Verbindung'), true) ?: [];
    }

    private function verbindungMerken(array $neu): void
    {
        $v = $this->verbindung();
        if (isset($neu['zustand']) && ($v['zustand'] ?? '') !== $neu['zustand']) {
            $neu['seit'] = time();
        }
        $this->WriteAttributeString('Verbindung', (string)json_encode(array_merge($v, $neu)));
    }

    private function verbundenSetzen(bool $an): void
    {
        if (@$this->GetIDForIdent('Verbunden') && $this->GetValue('Verbunden') !== $an) {
            $this->SetValue('Verbunden', $an);
        }
    }

    // ------------------------------------------------------------------ Formular

    protected function esphomehubPrefix(): string
    {
        return 'ESPH';
    }

    protected function esphomehubZweck(): array
    {
        return [
            'Bindet ein ESPHome-Gerät direkt über seine eigene Schnittstelle (Native API) an — ohne MQTT-Server und ohne Home Assistant.',
            'Adresse und Schlüssel aus der Geräte-YAML eintragen, übernehmen: die Instanz hält die Verbindung, beantwortet die Lebenszeichen des Geräts und verbindet nach einem Neustart selbst wieder.',
        ];
    }

    protected function esphomehubDoku(): array
    {
        return [
            'Das Gerät braucht in seiner YAML den Abschnitt „api:". Seit ESPHome 2026.1 gibt es dort nur noch den Schlüssel unter „encryption: key" (Base64, 44 Zeichen), kein Passwort mehr.',
            'Port ist 6053, solange in der YAML unter „api:" kein anderer steht.',
            'Ohne verbundenen Client startet ein ESPHome-Gerät nach „reboot_timeout" (Standard 15 Minuten) neu. Läuft parallel Home Assistant, darf das Gerät beide Verbindungen gleichzeitig halten.',
            'Diese Version stellt die Verbindung her und zeigt die Geräteinfo. Sensoren, Schalter und Co. als Variablen folgen in der nächsten Version.',
        ];
    }

    public function GetConfigurationForm()
    {
        return $this->esphomehubFormular([$this->formVerbindung(), $this->formGeraet()], [], $this->formStatus());
    }

    private function formVerbindung(): array
    {
        $schluessel = trim($this->ReadPropertyString('Schluessel')) !== '';
        return [
            'type' => 'ExpansionPanel', 'expanded' => $this->host() === '', 'caption' => '🔌  Verbindung',
            'items' => [
                ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'IP-Adresse oder Hostname (z. B. 192.168.1.50 oder wohnzimmer.local)'],
                ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'Port', 'minimum' => 1, 'maximum' => 65535],
                ['type' => 'PasswordTextBox', 'name' => 'Schluessel', 'caption' => 'Schlüssel aus „api: encryption: key"'],
                ['type' => 'Label', 'caption' => $schluessel
                    ? '🔑 Schlüssel hinterlegt — die Verbindung ist verschlüsselt.'
                    : 'ℹ️ Kein Schlüssel: nur für Geräte ohne „encryption:" in der YAML. Neue ESPHome-Projekte haben immer einen Schlüssel.'],
            ],
        ];
    }

    private function formGeraet(): array
    {
        $v = $this->verbindung();
        $g = json_decode($this->ReadAttributeString('Geraet'), true) ?: [];
        $items = [['type' => 'Label', 'caption' => $this->statuszeile($v)]];
        if (($g['name'] ?? '') !== '') {
            $items[] = ['type' => 'Label', 'caption' => '📟 ' . ($g['anzeigename'] ?: $g['name']) . ' (' . $g['name'] . ')'
                . ($g['hersteller'] !== '' ? ' · ' . $g['hersteller'] : '') . ' · ' . $g['modell']];
            $items[] = ['type' => 'Label', 'caption' => 'ESPHome ' . $g['version'] . ' · erstellt ' . $g['kompiliert'] . ' · MAC ' . $g['mac']];
            if (($g['projekt'] ?? '') !== '') {
                $items[] = ['type' => 'Label', 'caption' => 'Projekt: ' . $g['projekt'] . ' ' . $g['projektVersion']];
            }
            if ((int)($g['bluetoothProxy'] ?? 0) !== 0) {
                $items[] = ['type' => 'Label', 'caption' => '📡 Das Gerät ist ein Bluetooth-Proxy (wird in einer späteren Version unterstützt).'];
            }
            if ((int)($g['webserverPort'] ?? 0) !== 0) {
                $items[] = ['type' => 'Label', 'caption' => '🌐 Eigene Weboberfläche auf Port ' . $g['webserverPort'] . '.'];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => '🔄 Neu verbinden', 'onClick' => 'ESPH_Neuverbinden($id);'];
        return ['type' => 'ExpansionPanel', 'expanded' => $this->host() !== '', 'caption' => '📟  Gerät', 'items' => $items];
    }

    private function statuszeile(array $v): string
    {
        $seit = isset($v['seit']) ? ' (seit ' . date('d.m.Y H:i:s', (int)$v['seit']) . ' Uhr)' : '';
        switch ($v['zustand'] ?? 'aus') {
            case 'verbunden':
                $ping = isset($v['ping']) ? ', letztes Lebenszeichen ' . date('H:i:s', (int)$v['ping']) . ' Uhr' : '';
                return '✅ Verbunden' . (($v['verschluesselt'] ?? false) ? ' (verschlüsselt)' : ' (unverschlüsselt)') . $seit . $ping . '.';
            case 'verbinde':
                return 'ℹ️ Verbindung wird aufgebaut …' . $seit;
            case 'getrennt':
                return '⚠️ Getrennt' . $seit . (($v['text'] ?? '') !== '' ? ': ' . $v['text'] : '.') . ' Neuer Versuch alle ' . self::WACHE_S . ' s.';
            case 'fehler':
                return '⛔ ' . ($v['text'] ?? 'Fehler') . $seit;
            default:
                return 'ℹ️ Noch keine Adresse eingetragen.';
        }
    }

    private function formStatus(): array
    {
        return [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Verbunden'],
            ['code' => 104, 'icon' => 'inactive', 'caption' => 'Bereit zur Einrichtung: Adresse eintragen'],
            ['code' => self::STATUS_SCHLUESSEL_FALSCH, 'icon' => 'error', 'caption' => 'Der Schlüssel passt nicht zum Gerät'],
            ['code' => self::STATUS_VERSCHLUESSELUNG, 'icon' => 'error', 'caption' => 'Verschlüsselung passt nicht (Gerät mit/ohne Schlüssel)'],
            ['code' => self::STATUS_UNERREICHBAR, 'icon' => 'error', 'caption' => 'Gerät nicht erreichbar, neuer Versuch läuft'],
        ];
    }
}
