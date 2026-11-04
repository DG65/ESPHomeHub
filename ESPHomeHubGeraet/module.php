<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ESPHomeHubSitzung.php';
require_once __DIR__ . '/../libs/ESPHomeHubEntitaeten.php';
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
// Entitäten (Sensoren, Schalter, Lichter …) beschreibt das Gerät nach jeder
// Verbindung selbst; daraus entstehen Variablen mit passender Darstellung.
// Zustände kommen per Abo, Schalten über RequestAction → *CommandRequest. Die
// Variable übernimmt den neuen Wert erst mit der Rückmeldung des Geräts.
// Verschwundene Entitäten behalten ihre Variable (Archiv), sie wird nicht gelöscht.
// ===========================================================================

class ESPHomeHubGeraet extends IPSModule
{
    use ESPHomeHubFormular;

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        // Quelltexte sind englisch; die Bibliotheken übersetzen über die locale.json dieses Moduls.
        ESPHomeHubText::setzen(fn (string $text): string => $this->Translate($text));
    }


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
        $this->RegisterPropertyString('FormLayout', '[]');

        $this->RegisterAttributeString('Geraet', '{}');
        $this->RegisterAttributeString('Verbindung', '{}');
        $this->RegisterAttributeString('Entitaeten', '{}');
        $this->RegisterAttributeString('NichtUnterstuetzt', '[]');
        $this->RegisterAttributeBoolean('Gesperrt', false);
        $this->RegisterAttributeString('Angewendet', '');
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

        $this->MaintainVariable('Verbunden', ESPHomeHubText::t('Connected'), VARIABLETYPE_BOOLEAN, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => (string)json_encode([
                ['Value' => false, 'Caption' => ESPHomeHubText::t('Disconnected'), 'IconActive' => false, 'IconValue' => '', 'ColorActive' => true, 'ColorValue' => 0xE53935],
                ['Value' => true, 'Caption' => ESPHomeHubText::t('Connected'), 'IconActive' => false, 'IconValue' => '', 'ColorActive' => true, 'ColorValue' => 0x43A047],
            ]),
        ], 1, true);

        // Jede Übernahme ist ein neuer Versuch, auch nach einem Schlüsselfehler.
        $this->WriteAttributeBoolean('Gesperrt', false);
        $this->elternBeobachten();

        $schluessel = trim($this->ReadPropertyString('Schluessel'));
        // Den Socket nur neu aufbauen, wenn sich Adresse, Port oder Schlüssel geändert haben. ApplyChanges
        // läuft auch bei jedem Symcon-Start und Modul-Update; ein Zwangs-Schließen/Öffnen ist dort unnötig.
        // Live 28.10.2026: Symcon brach 2 s nach mehrfachem Schließen/Öffnen innerhalb einer Sekunde ab
        // (SIGABRT, Ursache offen) — seitdem so wenig Socket-Wechsel wie möglich.
        $kennung = $this->host() . '|' . $this->ReadPropertyInteger('Port') . '|' . hash('sha256', $schluessel);
        $unveraendert = $kennung === $this->ReadAttributeString('Angewendet');
        $this->WriteAttributeString('Angewendet', $kennung);
        $eltern = $this->eltern();
        $elternAktiv = $eltern > 0 && IPS_GetInstance($eltern)['InstanceStatus'] === IS_ACTIVE
            && IPS_GetProperty($eltern, 'Host') === $this->host() && (int)IPS_GetProperty($eltern, 'Port') === $this->ReadPropertyInteger('Port');
        $sitzung = ESPHomeHubSitzung::import($this->GetBuffer('Sitzung'));
        if ($this->host() !== '' && $unveraendert && $elternAktiv) {
            $this->SetTimerInterval('Wache', self::WACHE_S * 1000);
            $this->socketBenennen();
            if ($sitzung === null || !$sitzung->bereit()) {
                // Socket steht schon: nur die API-Sitzung neu beginnen, ohne Schließen/Öffnen.
                $this->sitzungVerwerfen();
                $this->sitzungStarten();
            }
            return;
        }
        $this->sitzungVerwerfen();
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
            $this->verbindungMerken(['zustand' => 'fehler', 'text' => ESPHomeHubText::t('The key is not a valid ESPHome key (Base64, 32 bytes).')]);
            return;
        }
        $this->SetTimerInterval('Wache', self::WACHE_S * 1000);
        $this->socketBenennen();
        $this->verbindungMerken(['zustand' => 'verbinde']);
        // Geänderte Adresse/Schlüssel: neu verbinden, damit es sofort greift. Sonst nur öffnen, falls zu.
        $this->elternEinstellen(true, !$unveraendert);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        // Meldungen können noch eintreffen, wenn die Instanz gerade gelöscht wurde.
        if (!IPS_InstanceExists($this->InstanceID)) {
            return;
        }
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
                    $this->verbindungMerken(['zustand' => 'getrennt', 'text' => ESPHomeHubText::t('Device not reachable.')]);
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
            // Per Skript angelegte Instanzen bekommen über RequireParent keinen Socket (live 28.10.2026,
            // wie RoboHub). Erst in der Wache, damit die Konsole beim Anlegen ihren eigenen einhängen kann.
            $this->socketAnlegen();
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
            $this->LogMessage(ESPHomeHubText::t('No answer from the device for %s s — reconnecting.', time() - $letzter), KL_WARNING);
            $this->elternEinstellen(true, true);
        }
    }

    /** Formular-Knopf: Verbindung trennen und neu aufbauen. */
    public function Neuverbinden(): void
    {
        if ($this->host() === '') {
            echo ESPHomeHubText::t('⛔ First enter an IP address or host name and apply.');
            return;
        }
        $this->WriteAttributeBoolean('Gesperrt', false);
        $this->verbindungMerken(['zustand' => 'verbinde']);
        $this->elternEinstellen(true, true);
        echo ESPHomeHubText::t('🔄 Reconnecting to %s … see panel "Device" for the result (reopen the form).', $this->host());
    }

    /** Geräteinfo als JSON (Name, MAC, ESPHome-Version, Modell …); leer, solange nie verbunden. */
    public function GetDeviceInfo(): string
    {
        return $this->ReadAttributeString('Geraet');
    }

    /** Entitäten des Geräts als JSON, Schlüssel = Variablen-Ident der Hauptvariable. */
    public function GetEntities(): string
    {
        $out = [];
        foreach ($this->entitaeten() as $e) {
            $out[ESPHomeHubEntitaeten::ident((int)$e['key'])] = $e;
        }
        return (string)json_encode($out);
    }

    /** Variable schalten: Wert an das Gerät senden; die Variable folgt der Rückmeldung. */
    public function RequestAction($Ident, $Value)
    {
        $ziel = ESPHomeHubEntitaeten::identLesen((string)$Ident);
        $e = $ziel === null ? null : ($this->entitaeten()[$ziel[0]] ?? null);
        if ($e === null) {
            throw new Exception(ESPHomeHubText::t('Unknown variable %s — the device does not know this entity (any more).', $Ident));
        }
        try {
            [$typ, $daten, $sofort] = ESPHomeHubEntitaeten::befehl($e, $ziel[1], $Value);
        } catch (InvalidArgumentException $ex) {
            throw new Exception($ex->getMessage());
        }
        if (!$this->nachrichtSenden($typ, $daten)) {
            throw new Exception(ESPHomeHubText::t('"%s" not sent: device is not connected.', $e['name'] ?: IPS_GetName($this->InstanceID)));
        }
        $this->SendDebug('Befehl', $Ident . ' = ' . json_encode($Value), 0);
        // Knöpfe und Fahrbefehle haben keinen Zustand, den das Gerät zurückmeldet. Immer setzen,
        // auch bei gleichem Wert: der neue Zeitstempel ist für Ereignisse „bei Aktualisierung" der Druck.
        if ($sofort !== null && @$this->GetIDForIdent((string)$Ident)) {
            $this->SetValue((string)$Ident, $sofort);
        }
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
                // Vom Konfigurator über eine Hand-Adresse angelegt: Instanz heißt noch wie die Adresse.
                $anzeige = (string)($info['anzeigename'] ?: $info['name']);
                if ($anzeige !== '' && IPS_GetName($this->InstanceID) === $this->host()) {
                    IPS_SetName($this->InstanceID, $anzeige);
                }
                if ($vorher === 'getrennt') {
                    $this->LogMessage(ESPHomeHubText::t('Connected again to %s.', $info['anzeigename'] ?: $info['name']), KL_MESSAGE);
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
                $this->verbindungMerken(['zustand' => 'getrennt', 'text' => ESPHomeHubText::t('The device closed the connection (restart or update).')]);
                return;
            case 'entitaetenFertig':
                $this->entitaetenUebernehmen();
                return;
            case 'nachricht':
                $this->nachricht((int)$e[1], (string)$e[2]);
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
            $this->LogMessage(ESPHomeHubText::t('Protocol error, reconnecting: %s', $text), KL_WARNING);
            $this->elternEinstellen(true, true);
            return;
        }
        // Schlüssel- und Verschlüsselungsfehler heilen nicht von selbst: Socket zu, bis der Nutzer etwas ändert.
        $hinweis = match ($art) {
            'schluessel'       => $text . ' ' . ESPHomeHubText::t('Enter the key from "api: encryption: key" of the device YAML.'),
            'unverschluesselt' => $text . ' ' . ESPHomeHubText::t('Leave the key empty or turn on encryption in the device.'),
            default            => $text . ' ' . ESPHomeHubText::t('Enter the key from the device YAML.'),
        };
        if (($server['name'] ?? '') !== '') {
            $hinweis .= ' ' . ESPHomeHubText::t('(device reports as "%s")', $server['name']);
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
            // Das Gerät kürzt client_info auf 31 Byte, auch mitten im Umlaut (live 28.10.2026) — selbst an der Zeichengrenze kürzen.
            [$sitzung, $bytes] = ESPHomeHubSitzung::starten($psk, mb_strcut('Symcon ' . IPS_GetName($this->InstanceID), 0, 31, 'UTF-8'));
            $sitzung->setzeZeitzone(ESPHomeHubZeitzone::posix(date_default_timezone_get()));
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

    /** Socket mit Standardnamen (Konsole: „Client Socket", per Skript: leer) sprechend benennen. */
    private function socketBenennen(): void
    {
        $eltern = $this->eltern();
        if ($eltern > 0 && in_array(IPS_GetName($eltern), ['', 'Client Socket', 'Client-Socket'], true)) {
            IPS_SetName($eltern, ESPHomeHubText::t('ESPHome connection (%s)', IPS_GetName($this->InstanceID)));
        }
    }

    /** Fehlenden Client Socket anlegen, verbinden und einstellen (Muster RoboHub). */
    private function socketAnlegen(): void
    {
        $id = @IPS_CreateInstance(self::CLIENT_SOCKET);
        if (!$id) {
            $this->verbindungMerken(['zustand' => 'fehler', 'text' => ESPHomeHubText::t('Could not create the Client Socket.')]);
            return;
        }
        // Inzwischen doch verbunden (z. B. von der Konsole)? Dann den eigenen wieder entfernen.
        if ($this->eltern() !== 0 || !@IPS_ConnectInstance($this->InstanceID, $id)) {
            @IPS_DeleteInstance($id);
            return;
        }
        $this->LogMessage(ESPHomeHubText::t('Client Socket #%s created and connected.', $id), KL_MESSAGE);
        $this->socketBenennen();
        $this->elternBeobachten();
        $this->verbindungMerken(['zustand' => 'verbinde']);
        $this->elternEinstellen(true);
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

    // ------------------------------------------------------------------ Entitäten

    private ?array $entitaetenCache = null;

    private function entitaeten(): array
    {
        if ($this->entitaetenCache === null) {
            $roh = json_decode($this->ReadAttributeString('Entitaeten'), true) ?: [];
            $this->entitaetenCache = [];
            foreach ($roh as $e) {
                $this->entitaetenCache[(int)$e['key']] = $e;
            }
        }
        return $this->entitaetenCache;
    }

    private function nachricht(int $typ, string $daten): void
    {
        if (ESPHomeHubEntitaeten::istListe($typ)) {
            $neu = json_decode($this->GetBuffer('EntitaetenNeu'), true) ?: ['liste' => [], 'fehlt' => []];
            $e = ESPHomeHubEntitaeten::beschreiben($typ, $daten);
            if ($e === null) {
                $neu['fehlt'][] = ESPHomeHubEntitaeten::NICHT_UNTERSTUETZT[$typ] ?? ESPHomeHubText::t('Type %s', $typ);
            } else {
                $neu['liste'][] = $e;
            }
            $this->SetBuffer('EntitaetenNeu', (string)json_encode($neu));
            return;
        }
        $art = ESPHomeHubEntitaeten::zustandsTyp($typ);
        if ($art === null) {
            $this->SendDebug('Nachricht', 'Typ ' . $typ . ', ' . strlen($daten) . ' Byte (nicht ausgewertet)', 0);
            return;
        }
        try {
            $key = ESPHomeHubProtokoll::fixed32(ESPHomeHubProtokoll::zerlegen($daten), 1);
            $e = $this->entitaeten()[$key] ?? null;
            if ($e === null || $e['typ'] !== $art) {
                return;
            }
            foreach (ESPHomeHubEntitaeten::zustand($typ, $daten, $e) as $ident => $wert) {
                $this->wertSetzen($ident, $wert);
            }
        } catch (RuntimeException $ex) {
            $this->SendDebug('Zustand', 'nicht lesbar: ' . $ex->getMessage(), 0);
        }
    }

    /** Vollständige Liste da: merken und Variablen anlegen bzw. anpassen. */
    private function entitaetenUebernehmen(): void
    {
        $neu = json_decode($this->GetBuffer('EntitaetenNeu'), true) ?: ['liste' => [], 'fehlt' => []];
        $this->SetBuffer('EntitaetenNeu', '');
        $this->WriteAttributeString('Entitaeten', (string)json_encode($neu['liste']));
        $this->WriteAttributeString('NichtUnterstuetzt', (string)json_encode(array_values(array_unique($neu['fehlt']))));
        $this->entitaetenCache = null;
        $g = json_decode($this->ReadAttributeString('Geraet'), true) ?: [];
        $geraet = (string)(($g['anzeigename'] ?? '') ?: ($g['name'] ?? IPS_GetName($this->InstanceID)));
        $pos = 10;
        $anzahl = 0;
        foreach ($neu['liste'] as $e) {
            foreach (ESPHomeHubEntitaeten::variablen($e, $geraet) as $i => $v) {
                $this->variableSicherstellen($v, $pos + $i);
                $anzahl++;
            }
            $pos += 10;
        }
        $this->SendDebug('Entitäten', count($neu['liste']) . ' Entitäten, ' . $anzahl . ' Variablen' . ($neu['fehlt'] ? ', nicht unterstützt: ' . implode(', ', $neu['fehlt']) : ''), 0);
    }

    /**
     * Variable anlegen oder anpassen. Darstellung nur bei Abweichung neu schreiben:
     * MaintainVariable setzt sie sonst bei jedem Aufruf neu (Update-Sturm, ips-module-pitfalls 9).
     * Position nur bei Neuanlage (SUITE.md 9m), Name eines Bestands nie überschreiben.
     */
    private function variableSicherstellen(array $v, int $pos): void
    {
        $id = @$this->GetIDForIdent($v['ident']);
        $anders = $id === false;
        if (!$anders) {
            $ist = IPS_GetVariablePresentation($id);
            foreach ($v['pres'] as $k => $wert) {
                if (!array_key_exists($k, $ist) || !self::gleich($ist[$k], $wert)) {
                    $anders = true;
                    break;
                }
            }
            $anders = $anders || IPS_GetVariable($id)['VariableType'] !== $v['typ'];
        }
        if ($anders) {
            $this->MaintainVariable($v['ident'], $v['name'], $v['typ'], $v['pres'], $id === false ? $pos : 0, true);
            if ($id !== false) {
                IPS_SetPosition($this->GetIDForIdent($v['ident']), IPS_GetObject($id)['ObjectPosition']);
            }
        }
        if ($v['aktion']) {
            $this->EnableAction($v['ident']);
        }
    }

    /**
     * Darstellungswert inhaltlich vergleichen. Symcon gibt ihn umgeformt zurück (live 28.10.2026):
     * ganze Kommazahlen als int (10 statt 10.0), OPTIONS neu serialisiert (Schlüssel sortiert,
     * Umlaute unmaskiert), float32-Schritte ungerundet. Ein Textvergleich schriebe sonst bei jeder
     * Verbindung alle Variablen neu.
     */
    private static function gleich($ist, $soll): bool
    {
        if (is_string($ist) && is_string($soll) && $ist !== $soll && in_array(substr(ltrim($soll), 0, 1), ['[', '{'], true)) {
            $a = json_decode($ist, true);
            $b = json_decode($soll, true);
            return is_array($a) && is_array($b) && self::gleich($a, $b);
        }
        if (is_array($ist) && is_array($soll)) {
            if (count($ist) !== count($soll)) {
                return false;
            }
            foreach ($soll as $k => $w) {
                if (!array_key_exists($k, $ist) || !self::gleich($ist[$k], $w)) {
                    return false;
                }
            }
            return true;
        }
        if ((is_int($ist) || is_float($ist)) && (is_int($soll) || is_float($soll))) {
            return abs($ist - $soll) < 1e-6;
        }
        return $ist === $soll;
    }

    private function wertSetzen(string $ident, $wert): void
    {
        if (!@$this->GetIDForIdent($ident)) {
            return;
        }
        if ($this->GetValue($ident) !== $wert) {
            $this->SetValue($ident, $wert);
        }
    }

    /** Eine Nachricht in der laufenden Sitzung senden (Nonce-Zähler unter Semaphore fortschreiben). */
    private function nachrichtSenden(int $typ, string $daten): bool
    {
        if (!$this->sperren()) {
            return false;
        }
        try {
            $sitzung = ESPHomeHubSitzung::import($this->GetBuffer('Sitzung'));
            if ($sitzung === null || !$sitzung->bereit() || !$this->HasActiveParent()) {
                return false;
            }
            $bytes = $sitzung->senden($typ, $daten);
            $this->SetBuffer('Sitzung', $sitzung->export());
            $this->rohSenden($bytes);
            return true;
        } finally {
            $this->entsperren();
        }
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
            'Connects an ESPHome device directly through its own interface (Native API) — without an MQTT server and without Home Assistant.',
            'Enter the address and the key from the device YAML and apply: the instance keeps the connection, answers the keep-alive messages of the device and reconnects by itself after a restart.',
        ];
    }

    protected function esphomehubDoku(): array
    {
        return [
            'The device needs the section "api:" in its YAML. Since ESPHome 2026.1 there is only the key under "encryption: key" (Base64, 44 characters), no password any more.',
            'The port is 6053 unless another one is set under "api:" in the YAML.',
            'Without a connected client, an ESPHome device restarts after "reboot_timeout" (default 15 minutes). If Home Assistant runs in parallel, the device may hold both connections at the same time.',
            'Every entity of the device becomes variables below this instance, switchable ones with an action. The ident is the key of the entity (e + 8 hex characters, partial variables with a suffix such as _h for brightness) and stays the same as long as the id in the YAML stays the same.',
            'If an entity is removed from the YAML, its variable and its archive remain and can be deleted manually.',
        ];
    }

    public function GetConfigurationForm()
    {
        return $this->esphomehubFormular($this->fachpanels(), [], $this->formStatus());
    }

    /** Setzt Aufklapp-Zustand und Reihenfolge der Fachpanels im offenen Formular auf den Standard (gilt nach „Übernehmen"). */
    public function ResetFormLayout(): string
    {
        $this->UpdateFormField('FormLayout', 'values', json_encode($this->formLayoutStandard($this->fachpanels()), JSON_UNESCAPED_UNICODE));
        return ESPHomeHubText::t('ℹ️ Reset to default. Click "Apply" below to save.');
    }

    /** Fachpanels in Standardreihenfolge; die Schlüssel sind stabil (FormLayout), die Überschriften dürfen sich ändern. */
    private function fachpanels(): array
    {
        return ['verbindung' => $this->formVerbindung(), 'geraet' => $this->formGeraet(), 'entitaeten' => $this->formEntitaeten()];
    }

    private function formVerbindung(): array
    {
        $schluessel = trim($this->ReadPropertyString('Schluessel')) !== '';
        return [
            'type' => 'ExpansionPanel', 'expanded' => $this->host() === '', 'caption' => '🔌  Connection',
            'items' => [
                ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'IP address or host name (e.g. 192.168.1.50 or livingroom.local)'],
                ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'Port', 'minimum' => 1, 'maximum' => 65535],
                ['type' => 'PasswordTextBox', 'name' => 'Schluessel', 'caption' => 'Key from "api: encryption: key"'],
                ['type' => 'Label', 'caption' => $schluessel
                    ? '🔑 Key stored — the connection is encrypted.'
                    : 'ℹ️ No key: only for devices without "encryption:" in the YAML. New ESPHome projects always have a key.'],
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
            $items[] = ['type' => 'Label', 'caption' => ESPHomeHubText::t('ESPHome %s · built %s · MAC %s', $g['version'], $g['kompiliert'], $g['mac'])];
            if (($g['projekt'] ?? '') !== '') {
                $items[] = ['type' => 'Label', 'caption' => ESPHomeHubText::t('Project: %s %s', $g['projekt'], $g['projektVersion'])];
            }
            if ((int)($g['bluetoothProxy'] ?? 0) !== 0) {
                $items[] = ['type' => 'Label', 'caption' => '📡 The device is a Bluetooth proxy (a separate module for it is coming).'];
            }
            if ((int)($g['webserverPort'] ?? 0) !== 0) {
                $items[] = ['type' => 'Label', 'caption' => ESPHomeHubText::t('🌐 Own web interface on port %s.', $g['webserverPort'])];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => '🔄 Reconnect', 'onClick' => 'ESPH_Neuverbinden($id);'];
        return ['type' => 'ExpansionPanel', 'expanded' => $this->host() !== '', 'caption' => '📟  Device', 'items' => $items];
    }

    private function formEntitaeten(): array
    {
        $zeilen = [];
        foreach ($this->entitaeten() as $e) {
            $ident = ESPHomeHubEntitaeten::ident((int)$e['key']);
            $id = @$this->GetIDForIdent($ident);
            $zeilen[] = [
                'typ'   => ESPHomeHubText::t(ESPHomeHubEntitaeten::TYP_NAMEN[$e['typ']] ?? $e['typ']),
                'name'  => $e['name'] !== '' ? $e['name'] : ESPHomeHubText::t('(device name)'),
                'wert'  => $id ? GetValueFormatted($id) : '—',
                'ident' => $ident,
            ];
        }
        $items = [];
        if (count($zeilen) === 0) {
            $items[] = ['type' => 'Label', 'caption' => 'ℹ️ No entities yet — they arrive with the first connection.'];
        } else {
            $items[] = ['type' => 'Label', 'caption' => ESPHomeHubText::t('✅ %s entities. The variables are below this instance; switchable ones can be operated directly or set via RequestAction.', count($zeilen))];
            $items[] = ['type' => 'List', 'name' => 'EntitaetenListe', 'rowCount' => min(15, count($zeilen)), 'add' => false, 'delete' => false,
                'columns' => [
                    ['caption' => 'Type', 'name' => 'typ', 'width' => '130px'],
                    ['caption' => 'Name', 'name' => 'name', 'width' => 'auto'],
                    ['caption' => 'Value', 'name' => 'wert', 'width' => '160px'],
                    ['caption' => 'Ident', 'name' => 'ident', 'width' => '130px'],
                ],
                'values' => $zeilen];
        }
        $fehlt = json_decode($this->ReadAttributeString('NichtUnterstuetzt'), true) ?: [];
        if (count($fehlt) > 0) {
            $items[] = ['type' => 'Label', 'caption' => ESPHomeHubText::t('⚠️ Not supported yet and therefore skipped: %s.', implode(', ', $fehlt))];
        }
        return ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🧩  Entities', 'items' => $items];
    }

    private function statuszeile(array $v): string
    {
        $seit = isset($v['seit']) ? ' ' . ESPHomeHubText::t('(since %s)', date('d.m.Y H:i:s', (int)$v['seit'])) : '';
        switch ($v['zustand'] ?? 'aus') {
            case 'verbunden':
                $ping = isset($v['ping']) ? ESPHomeHubText::t(', last keep-alive %s', date('H:i:s', (int)$v['ping'])) : '';
                return ESPHomeHubText::t('✅ Connected') . ' ' . (($v['verschluesselt'] ?? false) ? ESPHomeHubText::t('(encrypted)') : ESPHomeHubText::t('(unencrypted)')) . $seit . $ping . '.';
            case 'verbinde':
                return ESPHomeHubText::t('ℹ️ Connecting …') . $seit;
            case 'getrennt':
                return ESPHomeHubText::t('⚠️ Disconnected') . $seit . (($v['text'] ?? '') !== '' ? ': ' . $v['text'] : '.') . ' ' . ESPHomeHubText::t('Next attempt every %s s.', self::WACHE_S);
            case 'fehler':
                return '⛔ ' . ($v['text'] ?? ESPHomeHubText::t('Error')) . $seit;
            default:
                return ESPHomeHubText::t('ℹ️ No address entered yet.');
        }
    }

    private function formStatus(): array
    {
        return [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Connected'],
            ['code' => 104, 'icon' => 'inactive', 'caption' => 'Ready for setup: enter an address'],
            ['code' => self::STATUS_SCHLUESSEL_FALSCH, 'icon' => 'error', 'caption' => 'The key does not match the device'],
            ['code' => self::STATUS_VERSCHLUESSELUNG, 'icon' => 'error', 'caption' => 'Encryption does not match (device with/without key)'],
            ['code' => self::STATUS_UNERREICHBAR, 'icon' => 'error', 'caption' => 'Device not reachable, retrying'],
        ];
    }
}
