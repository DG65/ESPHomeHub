<?php

declare(strict_types=1);

require_once __DIR__ . '/ESPHomeHubNoise.php';
require_once __DIR__ . '/ESPHomeHubProtokoll.php';
require_once __DIR__ . '/ESPHomeHubZeitzone.php';

// ===========================================================================
// Eine API-Sitzung mit einem ESPHome-Gerät, ohne Symcon und ohne Netz.
//
// Bytes rein (empfangen), Bytes raus (Feld 'senden') plus Ereignisse. Der
// gesamte Zustand ist exportierbar, weil Symcon das Modulobjekt bei jedem
// ReceiveData neu anlegt. Ablauf: Verbindung steht → starten() → ServerHello
// → Handshake → HelloRequest → HelloResponse → DeviceInfoRequest → bereit.
// PingRequest des Geräts (alle 60 s) beantwortet die Sitzung selbst.
//
// Nach DeviceInfo fordert die Sitzung die Entitäten an, nach ListEntitiesDone
// abonniert sie die Zustände; GetTimeRequest beantwortet sie mit der Uhrzeit.
//
// Ereignisse: ['hello', info], ['geraet', info], ['entitaetenFertig'], ['ping'], ['getrennt'],
//   ['nachricht', typ, daten] für alles, was die Sitzung nicht selbst kennt,
//   ['fehler', art, text] mit art = schluessel | unverschluesselt |
//   verschluesselt | protokoll. Nach einem Fehler ist die Sitzung beendet.
// ===========================================================================

final class ESPHomeHubSitzung
{
    public const AUS = 'aus';
    public const SERVER_HELLO = 'serverHello';
    public const HANDSHAKE = 'handshake';
    public const HELLO = 'hello';
    public const BEREIT = 'bereit';

    private string $phase = self::AUS;
    private bool $verschluesselt = false;
    private string $puffer = '';
    private ?ESPHomeHubNoise $noise = null;
    private ?ESPHomeHubCipher $tx = null;
    private ?ESPHomeHubCipher $rx = null;
    private string $clientInfo = '';
    private string $zeitzone = '';
    private array $server = [];

    /**
     * Neue Sitzung; $schluessel = 32 Byte oder null (unverschlüsselt).
     * @return array{0:self,1:string} [sitzung, zu sendende Bytes]
     */
    public static function starten(?string $schluessel, string $clientInfo, ?string $ephemer = null): array
    {
        $s = new self();
        $s->clientInfo = $clientInfo;
        if ($schluessel === null) {
            $s->phase = self::HELLO;
            return [$s, ESPHomeHubProtokoll::klartextRahmen(ESPHomeHubProtokoll::HELLO_REQUEST, ESPHomeHubProtokoll::helloRequest($clientInfo))];
        }
        $s->verschluesselt = true;
        $s->noise = ESPHomeHubNoise::starten($ephemer);
        $s->phase = self::SERVER_HELLO;
        // Leerer ClientHello-Rahmen, dann gleich der Handshake (0x00 = „kein Fehler“ + Nachricht 1).
        $bytes = ESPHomeHubProtokoll::noiseRahmen('') . ESPHomeHubProtokoll::noiseRahmen("\x00" . $s->noise->nachricht1($schluessel));
        return [$s, $bytes];
    }

    /** POSIX-Zeitzone für Zeitanfragen des Geräts (ESPHomeHubZeitzone::posix). */
    public function setzeZeitzone(string $posix): void
    {
        $this->zeitzone = $posix;
    }

    public function phase(): string
    {
        return $this->phase;
    }

    public function bereit(): bool
    {
        return $this->phase === self::BEREIT;
    }

    public function verschluesselt(): bool
    {
        return $this->verschluesselt;
    }

    /** Name und MAC aus dem ServerHello (nur verschlüsselt, vor dem Handshake). */
    public function server(): array
    {
        return $this->server;
    }

    /** Eine Nachricht an das Gerät verpacken (erst nach dem Handshake). */
    public function senden(int $typ, string $daten = ''): string
    {
        if ($this->phase === self::AUS || $this->phase === self::SERVER_HELLO || $this->phase === self::HANDSHAKE) {
            throw new RuntimeException('Sitzung noch nicht aufgebaut');
        }
        if (!$this->verschluesselt) {
            return ESPHomeHubProtokoll::klartextRahmen($typ, $daten);
        }
        return ESPHomeHubProtokoll::noiseRahmen($this->tx->verschluesseln('', ESPHomeHubProtokoll::noiseInhalt($typ, $daten)));
    }

    /** @return array{senden:string,ereignisse:array} */
    public function empfangen(string $bytes): array
    {
        $this->puffer .= $bytes;
        $raus = '';
        $ereignisse = [];
        while ($this->phase !== self::AUS) {
            try {
                $rahmen = ESPHomeHubProtokoll::rahmenLesen($this->puffer);
            } catch (RuntimeException $e) {
                $ereignisse[] = $this->abbrechen('protokoll', $e->getMessage());
                break;
            }
            if ($rahmen === null) {
                break;
            }
            if ($rahmen[0] === 'klartext' && $this->verschluesselt) {
                $ereignisse[] = $this->abbrechen('unverschluesselt', 'Das Gerät spricht unverschlüsselt, hier ist aber ein Schlüssel eingetragen.');
                break;
            }
            if ($rahmen[0] === 'noise' && !$this->verschluesselt) {
                $ereignisse[] = $this->abbrechen('verschluesselt', 'Das Gerät verlangt einen Schlüssel (api: encryption: key in der Geräte-YAML).');
                break;
            }
            try {
                if ($rahmen[0] === 'klartext') {
                    $this->nachricht($rahmen[1], $rahmen[2], $raus, $ereignisse);
                } else {
                    $this->noiseRahmen($rahmen[1], $raus, $ereignisse);
                }
            } catch (ESPHomeHubSchluesselFehler $e) {
                $ereignisse[] = $this->abbrechen('schluessel', 'Der Schlüssel passt nicht zum Gerät.');
            } catch (RuntimeException $e) {
                $ereignisse[] = $this->abbrechen('protokoll', $e->getMessage());
            }
        }
        return ['senden' => $raus, 'ereignisse' => $ereignisse];
    }

    private function noiseRahmen(string $daten, string &$raus, array &$ereignisse): void
    {
        switch ($this->phase) {
            case self::SERVER_HELLO:
                $this->server = ESPHomeHubProtokoll::serverHello($daten);
                $this->phase = self::HANDSHAKE;
                return;
            case self::HANDSHAKE:
                if ($daten === '') {
                    throw new RuntimeException('Leere Handshake-Antwort');
                }
                if ($daten[0] !== "\x00") {
                    $grund = substr($daten, 1);
                    if ($grund === 'Handshake MAC failure') {
                        throw new ESPHomeHubSchluesselFehler($grund);
                    }
                    throw new RuntimeException('Gerät lehnt den Handshake ab: ' . $grund);
                }
                [$this->tx, $this->rx] = $this->noise->nachricht2(substr($daten, 1));
                $this->noise = null;
                $this->phase = self::HELLO;
                $raus .= $this->senden(ESPHomeHubProtokoll::HELLO_REQUEST, ESPHomeHubProtokoll::helloRequest($this->clientInfo));
                return;
            default:
                [$typ, $inhalt] = ESPHomeHubProtokoll::noiseInhaltLesen($this->rx->entschluesseln('', $daten));
                $this->nachricht($typ, $inhalt, $raus, $ereignisse);
        }
    }

    private function nachricht(int $typ, string $daten, string &$raus, array &$ereignisse): void
    {
        switch ($typ) {
            case ESPHomeHubProtokoll::HELLO_RESPONSE:
                $ereignisse[] = ['hello', ESPHomeHubProtokoll::helloResponse($daten)];
                $raus .= $this->senden(ESPHomeHubProtokoll::DEVICE_INFO_REQUEST);
                return;
            case ESPHomeHubProtokoll::DEVICE_INFO_RESPONSE:
                $this->phase = self::BEREIT;
                $ereignisse[] = ['geraet', ESPHomeHubProtokoll::deviceInfo($daten)];
                $raus .= $this->senden(ESPHomeHubProtokoll::LIST_ENTITIES_REQUEST);
                return;
            case ESPHomeHubProtokoll::LIST_ENTITIES_DONE:
                // Erst nach der vollständigen Liste abonnieren: Zustände ohne bekannte Entität wären wertlos.
                $raus .= $this->senden(ESPHomeHubProtokoll::SUBSCRIBE_STATES_REQUEST);
                $ereignisse[] = ['entitaetenFertig'];
                return;
            case ESPHomeHubProtokoll::GET_TIME_REQUEST:
                // Geräte mit „time: platform: homeassistant" fragen den API-Client nach der Uhrzeit.
                // Seit ESPHome 2026.9 nur mit parsed_timezone in der richtigen Zeitzone.
                $raus .= $this->senden(ESPHomeHubProtokoll::GET_TIME_RESPONSE, ESPHomeHubZeitzone::getTimeResponse(time(), $this->zeitzone));
                return;
            case ESPHomeHubProtokoll::PING_REQUEST:
                $raus .= $this->senden(ESPHomeHubProtokoll::PING_RESPONSE);
                $ereignisse[] = ['ping'];
                return;
            case ESPHomeHubProtokoll::DISCONNECT_REQUEST:
                $raus .= $this->senden(ESPHomeHubProtokoll::DISCONNECT_RESPONSE);
                $this->phase = self::AUS;
                $ereignisse[] = ['getrennt'];
                return;
            case ESPHomeHubProtokoll::PING_RESPONSE:
            case ESPHomeHubProtokoll::DISCONNECT_RESPONSE:
                return;
        }
        $ereignisse[] = ['nachricht', $typ, $daten];
    }

    private function abbrechen(string $art, string $text): array
    {
        $this->phase = self::AUS;
        $this->puffer = '';
        return ['fehler', $art, $text];
    }

    public function export(): string
    {
        return (string)json_encode([
            'phase'  => $this->phase,
            'v'      => $this->verschluesselt,
            'puffer' => base64_encode($this->puffer),
            'noise'  => $this->noise?->export(),
            'tx'     => $this->tx?->export(),
            'rx'     => $this->rx?->export(),
            'client' => $this->clientInfo,
            'tz'     => $this->zeitzone,
            'server' => $this->server,
        ]);
    }

    public static function import(string $json): ?self
    {
        $d = json_decode($json, true);
        if (!is_array($d) || !isset($d['phase'])) {
            return null;
        }
        $s = new self();
        $s->phase = (string)$d['phase'];
        $s->verschluesselt = (bool)$d['v'];
        $s->puffer = (string)base64_decode((string)$d['puffer']);
        $s->noise = is_array($d['noise']) ? ESPHomeHubNoise::import($d['noise']) : null;
        $s->tx = is_array($d['tx']) ? ESPHomeHubCipher::import($d['tx']) : null;
        $s->rx = is_array($d['rx']) ? ESPHomeHubCipher::import($d['rx']) : null;
        $s->clientInfo = (string)$d['client'];
        $s->zeitzone = (string)($d['tz'] ?? '');
        $s->server = (array)$d['server'];
        return $s;
    }
}
