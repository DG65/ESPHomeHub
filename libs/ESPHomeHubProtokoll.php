<?php

declare(strict_types=1);

// ===========================================================================
// ESPHome Native API: Rahmen und Protobuf, von Hand.
//
// Nachrichtennummern und Felder nach aioesphomeapi/api.proto (MIT). Es wird
// nur gelesen, was das Modul braucht; unbekannte Felder und Nachrichtentypen
// überspringt der Decoder (ESPHome ergänzt monatlich neue).
//
// Rahmen unverschlüsselt: 0x00 + varint Länge + varint Typ + Nutzdaten.
// Rahmen verschlüsselt:   0x01 + 2 Byte Länge (big-endian) + Noise-Daten;
//   entschlüsselt: 2 Byte Typ + 2 Byte Länge + Nutzdaten.
// ===========================================================================

final class ESPHomeHubProtokoll
{
    // Nachrichtentypen (option (id) in api.proto)
    public const HELLO_REQUEST = 1;
    public const HELLO_RESPONSE = 2;
    public const DISCONNECT_REQUEST = 5;
    public const DISCONNECT_RESPONSE = 6;
    public const PING_REQUEST = 7;
    public const PING_RESPONSE = 8;
    public const DEVICE_INFO_REQUEST = 9;
    public const DEVICE_INFO_RESPONSE = 10;
    public const LIST_ENTITIES_REQUEST = 11;
    public const LIST_ENTITIES_DONE = 19;
    public const SUBSCRIBE_STATES_REQUEST = 20;
    public const GET_TIME_REQUEST = 36;
    public const GET_TIME_RESPONSE = 37;

    // API-Version, die wir anbieten (aioesphomeapi 2026.10: 1.19 — Gerät antwortet mit seiner).
    public const API_MAJOR = 1;
    public const API_MINOR = 14;

    /** Ergebnis von rahmenLesen(): kein vollständiger Rahmen im Puffer. */
    public const UNVOLLSTAENDIG = null;

    // ------------------------------------------------------------------ varint

    public static function varint(int $v): string
    {
        $o = '';
        do {
            $b = $v & 0x7F;
            $v = ($v >> 7) & 0x01FFFFFFFFFFFFFF;   // logisch schieben, auch für negative int32 (10 Byte)
            $o .= chr($v !== 0 ? $b | 0x80 : $b);
        } while ($v !== 0);
        return $o;
    }

    /** Liest ein varint ab $p; null, wenn der Puffer mitten im varint endet. */
    public static function varintLesen(string $s, int &$p): ?int
    {
        $v = 0;
        $schieben = 0;
        $len = strlen($s);
        do {
            if ($p >= $len || $schieben > 63) {
                return null;
            }
            $b = ord($s[$p++]);
            $v |= ($b & 0x7F) << $schieben;
            $schieben += 7;
        } while (($b & 0x80) !== 0);
        return $v;
    }

    // ------------------------------------------------------------------ Protobuf schreiben

    public static function feldZahl(int $feld, int $wert): string
    {
        return $wert === 0 ? '' : self::varint($feld << 3) . self::varint($wert);
    }

    public static function feldBool(int $feld, bool $wert): string
    {
        return $wert ? self::varint($feld << 3) . "\x01" : '';
    }

    public static function feldText(int $feld, string $wert): string
    {
        return $wert === '' ? '' : self::varint(($feld << 3) | 2) . self::varint(strlen($wert)) . $wert;
    }

    public static function feldFixed32(int $feld, int $wert): string
    {
        return self::varint(($feld << 3) | 5) . pack('V', $wert);
    }

    /** Wie Protobuf (proto3): 0.0 wird weggelassen, der Empfänger liest dann ohnehin 0. */
    public static function feldFloat(int $feld, float $wert): string
    {
        return $wert == 0.0 ? '' : self::varint(($feld << 3) | 5) . pack('g', $wert);
    }

    // ------------------------------------------------------------------ Protobuf lesen

    /**
     * Zerlegt eine Nachricht in [feldnummer => [[wiretyp, rohwert], ...]].
     * @throws RuntimeException bei kaputten Daten
     */
    public static function zerlegen(string $s): array
    {
        $out = [];
        $p = 0;
        $len = strlen($s);
        while ($p < $len) {
            $tag = self::varintLesen($s, $p);
            if ($tag === null) {
                throw new RuntimeException('Protobuf: abgeschnittener Feldkopf');
            }
            $feld = $tag >> 3;
            $wt = $tag & 7;
            switch ($wt) {
                case 0:
                    $v = self::varintLesen($s, $p);
                    break;
                case 1:
                    $v = $p + 8 <= $len ? substr($s, $p, 8) : null;
                    $p += 8;
                    break;
                case 2:
                    $l = self::varintLesen($s, $p);
                    $v = ($l !== null && $p + $l <= $len) ? substr($s, $p, $l) : null;
                    $p += (int)$l;
                    break;
                case 5:
                    $v = $p + 4 <= $len ? substr($s, $p, 4) : null;
                    $p += 4;
                    break;
                default:
                    throw new RuntimeException('Protobuf: unbekannter Wiretyp ' . $wt);
            }
            if ($v === null) {
                throw new RuntimeException('Protobuf: abgeschnittenes Feld ' . $feld);
            }
            $out[$feld][] = [$wt, $v];
        }
        return $out;
    }

    public static function text(array $m, int $feld): string
    {
        return isset($m[$feld]) ? (string)$m[$feld][0][1] : '';
    }

    public static function zahl(array $m, int $feld): int
    {
        return isset($m[$feld]) && $m[$feld][0][0] === 0 ? (int)$m[$feld][0][1] : 0;
    }

    /** int32-Felder (z. B. accuracy_decimals) kommen negativ als 64-Bit-varint. */
    public static function zahl32(array $m, int $feld): int
    {
        $v = self::zahl($m, $feld) & 0xFFFFFFFF;
        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }

    public static function bool(array $m, int $feld): bool
    {
        return self::zahl($m, $feld) !== 0;
    }

    public static function fixed32(array $m, int $feld): int
    {
        return isset($m[$feld]) && $m[$feld][0][0] === 5 ? (int)unpack('V', $m[$feld][0][1])[1] : 0;
    }

    public static function float(array $m, int $feld): float
    {
        return isset($m[$feld]) && $m[$feld][0][0] === 5 ? (float)unpack('g', $m[$feld][0][1])[1] : 0.0;
    }

    /** float32 als glatte Dezimalzahl (0.1 statt 0.10000000149…), für Grenzen und Schrittweiten. */
    public static function floatGlatt(array $m, int $feld): float
    {
        $w = self::float($m, $feld);
        return is_finite($w) ? (float)sprintf('%.7g', $w) : 0.0;
    }

    /** @return string[] */
    public static function texte(array $m, int $feld): array
    {
        return array_map(fn ($e) => (string)$e[1], $m[$feld] ?? []);
    }

    /** Wiederholte Zahlen, gepackt (Wiretyp 2) oder einzeln. @return int[] */
    public static function zahlen(array $m, int $feld): array
    {
        $out = [];
        foreach ($m[$feld] ?? [] as [$wt, $v]) {
            if ($wt === 0) {
                $out[] = (int)$v;
                continue;
            }
            if ($wt === 2) {
                $p = 0;
                while ($p < strlen($v)) {
                    $z = self::varintLesen($v, $p);
                    if ($z === null) {
                        break;
                    }
                    $out[] = $z;
                }
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ Rahmen

    /** Verschlüsselter Rahmen um bereits verschlüsselte Daten. */
    public static function noiseRahmen(string $daten): string
    {
        return "\x01" . pack('n', strlen($daten)) . $daten;
    }

    /** Klartext vor dem Verschlüsseln: 2 Byte Typ + 2 Byte Länge + Daten. */
    public static function noiseInhalt(int $typ, string $daten): string
    {
        return pack('nn', $typ, strlen($daten)) . $daten;
    }

    /** @return array{0:int,1:string} [typ, daten] */
    public static function noiseInhaltLesen(string $klartext): array
    {
        if (strlen($klartext) < 4) {
            throw new RuntimeException('Entschlüsselte Nachricht zu kurz (' . strlen($klartext) . ' Byte)');
        }
        return [(int)unpack('n', $klartext)[1], substr($klartext, 4)];
    }

    public static function klartextRahmen(int $typ, string $daten): string
    {
        return "\x00" . self::varint(strlen($daten)) . self::varint($typ) . $daten;
    }

    /**
     * Nimmt einen vollständigen Rahmen vorn aus dem Puffer.
     * Verschlüsselt: ['noise', daten]. Unverschlüsselt: ['klartext', typ, daten].
     * null = noch nicht vollständig (Puffer bleibt unverändert).
     * @throws RuntimeException bei unbekanntem Präambel-Byte
     */
    public static function rahmenLesen(string &$puffer): ?array
    {
        if ($puffer === '') {
            return null;
        }
        $praeambel = ord($puffer[0]);
        if ($praeambel === 0x01) {
            if (strlen($puffer) < 3) {
                return null;
            }
            $len = (ord($puffer[1]) << 8) | ord($puffer[2]);
            if (strlen($puffer) < 3 + $len) {
                return null;
            }
            $daten = substr($puffer, 3, $len);
            $puffer = (string)substr($puffer, 3 + $len);
            return ['noise', $daten];
        }
        if ($praeambel === 0x00) {
            $p = 1;
            $len = self::varintLesen($puffer, $p);
            if ($len === null) {
                return null;
            }
            $typ = self::varintLesen($puffer, $p);
            if ($typ === null || strlen($puffer) < $p + $len) {
                return null;
            }
            $daten = substr($puffer, $p, $len);
            $puffer = (string)substr($puffer, $p + $len);
            return ['klartext', $typ, $daten];
        }
        throw new RuntimeException(sprintf('Unbekanntes Präambel-Byte 0x%02X', $praeambel));
    }

    // ------------------------------------------------------------------ Nachrichten

    public static function helloRequest(string $clientInfo): string
    {
        return self::feldText(1, $clientInfo) . self::feldZahl(2, self::API_MAJOR) . self::feldZahl(3, self::API_MINOR);
    }

    public static function helloResponse(string $d): array
    {
        $m = self::zerlegen($d);
        return [
            'api'    => self::zahl($m, 1) . '.' . self::zahl($m, 2),
            'server' => self::text($m, 3),
            'name'   => self::text($m, 4),
        ];
    }

    public static function deviceInfo(string $d): array
    {
        $m = self::zerlegen($d);
        return [
            'name'          => self::text($m, 2),
            'mac'           => self::text($m, 3),
            'version'       => self::text($m, 4),
            'kompiliert'    => self::text($m, 5),
            'modell'        => self::text($m, 6),
            'projekt'       => self::text($m, 8),
            'projektVersion' => self::text($m, 9),
            'webserverPort' => self::zahl($m, 10),
            'bluetoothProxy' => self::zahl($m, 15),
            'hersteller'    => self::text($m, 12),
            'anzeigename'   => self::text($m, 13),
            'bereich'       => self::text($m, 16),
            'bluetoothMac'  => self::text($m, 18),
        ];
    }

    /** ServerHello (vor dem Handshake): 0x01 Protokoll + Name\0 + MAC\0 (beides optional). */
    public static function serverHello(string $d): array
    {
        if ($d === '' || ord($d[0]) !== 0x01) {
            throw new RuntimeException('Gerät bietet ein unbekanntes Verschlüsselungsverfahren an');
        }
        $teile = explode("\0", substr($d, 1));
        return ['name' => (string)($teile[0] ?? ''), 'mac' => (string)($teile[1] ?? '')];
    }
}
