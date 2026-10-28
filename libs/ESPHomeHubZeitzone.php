<?php

declare(strict_types=1);

require_once __DIR__ . '/ESPHomeHubProtokoll.php';

// ===========================================================================
// Zeitzone für die GetTimeResponse.
//
// Seit ESPHome 2026.9 liest das Gerät die Zeitzone nur noch aus
// parsed_timezone (Feld 3); ohne sie bleibt es auf der Zeitzone, mit der es
// gebaut wurde (oder UTC). Nachgebaut nach aioesphomeapi timezone.py und
// posix_tz.py (MIT, Port von ESPHomes posix_tz.cpp):
//   IANA-Name → POSIX-String (vorletzte Zeile der TZif-Datei) → ParsedTimezone.
// Ohne zoneinfo-Dateien (Symcon unter Windows) leiten wir den POSIX-String
// aus PHPs Zeitzonen-Übergängen des laufenden Jahres ab.
// ===========================================================================

final class ESPHomeHubZeitzone
{
    /** POSIX-TZ-String der Zeitzone (z. B. „CET-1CEST,M3.5.0,M10.5.0/3"), '' wenn unbekannt. */
    public static function posix(string $iana): string
    {
        if ($iana === '') {
            return '';
        }
        foreach (['/usr/share/zoneinfo/', '/usr/lib/zoneinfo/', '/usr/share/lib/zoneinfo/'] as $ordner) {
            $datei = $ordner . $iana;
            if (strpos($iana, '..') === false && is_file($datei)) {
                $inhalt = (string)@file_get_contents($datei);
                $zeilen = explode("\n", $inhalt);
                if (substr($inhalt, 0, 4) === 'TZif' && count($zeilen) >= 2) {
                    return $zeilen[count($zeilen) - 2];
                }
            }
        }
        return self::posixAusPhp($iana);
    }

    /** Ersatz ohne TZif-Dateien: Regeln aus den Übergängen des laufenden Jahres. */
    public static function posixAusPhp(string $iana, ?int $jahr = null): string
    {
        try {
            $tz = new DateTimeZone($iana);
        } catch (Exception $e) {
            return '';
        }
        $jahr ??= (int)date('Y');
        $anfang = gmmktime(0, 0, 0, 1, 1, $jahr);
        $uebergaenge = $tz->getTransitions($anfang, gmmktime(0, 0, 0, 12, 31, $jahr));
        $std = null;
        $dst = null;
        $beginn = null;
        $ende = null;
        foreach ($uebergaenge as $i => $u) {
            if ($u['isdst']) {
                $dst = $u;
                if ($i > 0) {
                    $beginn = $u;
                }
            } else {
                $std = $u;
                if ($i > 0) {
                    $ende = $u;
                }
            }
        }
        $name = fn (array $u) => preg_match('/^[A-Za-z]{3,}$/', $u['abbr']) ? $u['abbr'] : '<' . $u['abbr'] . '>';
        $versatz = function (int $sekunden): string {
            $s = -$sekunden;   // POSIX: positiv = westlich von UTC
            $v = $s < 0 ? '-' : '';
            $s = abs($s);
            $t = (string)intdiv($s, 3600);
            if ($s % 3600 !== 0) {
                $t .= ':' . sprintf('%02d', intdiv($s % 3600, 60));
                if ($s % 60 !== 0) {
                    $t .= ':' . sprintf('%02d', $s % 60);
                }
            }
            return $v . $t;
        };
        $std ??= $uebergaenge[0];
        $out = $name($std) . $versatz((int)$std['offset']);
        if ($dst === null || $beginn === null || $ende === null) {
            return $out;
        }
        // Regel Mm.w.d/zeit, Zeit in der vor dem Übergang geltenden Ortszeit
        $regel = function (array $u, int $vorher): string {
            $lokal = (int)$u['ts'] + $vorher;
            $tag = (int)gmdate('j', $lokal);
            $tageImMonat = (int)gmdate('t', $lokal);
            $woche = $tag + 7 > $tageImMonat ? 5 : intdiv($tag - 1, 7) + 1;
            $zeit = $lokal - gmmktime(0, 0, 0, (int)gmdate('n', $lokal), $tag, (int)gmdate('Y', $lokal));
            $r = 'M' . gmdate('n', $lokal) . '.' . $woche . '.' . gmdate('w', $lokal);
            if ($zeit !== 7200) {
                $r .= '/' . intdiv($zeit, 3600) . ($zeit % 3600 !== 0 ? ':' . sprintf('%02d', intdiv($zeit % 3600, 60)) : '');
            }
            return $r;
        };
        $out .= $name($dst);
        if ((int)$dst['offset'] !== (int)$std['offset'] + 3600) {
            $out .= $versatz((int)$dst['offset']);
        }
        return $out . ',' . $regel($beginn, (int)$std['offset']) . ',' . $regel($ende, (int)$dst['offset']);
    }

    // ------------------------------------------------------------------ POSIX zerlegen (wie posix_tz.py)

    /** @return array|null ['std', 'dst', 'start' => Regel, 'ende' => Regel] oder null bei ungültigem String */
    public static function zerlegen(string $s): ?array
    {
        if ($s === '') {
            return null;
        }
        try {
            $leer = ['zeit' => 0, 'tag' => 0, 'typ' => 0, 'monat' => 0, 'woche' => 0, 'wochentag' => 0];
            $r = ['std' => 0, 'dst' => 0, 'start' => $leer, 'ende' => $leer];
            $p = self::name($s, 0);
            if ($p >= strlen($s) || !(ctype_digit($s[$p]) || $s[$p] === '+' || $s[$p] === '-')) {
                return null;
            }
            [$r['std'], $p] = self::versatz($s, $p);
            if ($p >= strlen($s)) {
                return $r;
            }
            if ($s[$p] === ',') {
                return null;
            }
            if (!ctype_alpha($s[$p]) && $s[$p] !== '<') {
                return $r;
            }
            $p = self::name($s, $p);
            if ($p < strlen($s) && $s[$p] !== ',' && (ctype_digit($s[$p]) || $s[$p] === '+' || $s[$p] === '-')) {
                [$r['dst'], $p] = self::versatz($s, $p);
            } else {
                $r['dst'] = $r['std'] - 3600;
            }
            if ($p >= strlen($s) || $s[$p] !== ',') {
                return $r;
            }
            [$r['start'], $p] = self::regel($s, $p + 1);
            if ($p >= strlen($s) || $s[$p] !== ',') {
                return null;
            }
            [$r['ende'], $p] = self::regel($s, $p + 1);
            return $r;
        } catch (InvalidArgumentException $e) {
            return null;
        }
    }

    private static function name(string $s, int $p): int
    {
        if (($s[$p] ?? '') === '<') {
            $z = strpos($s, '>', $p);
            if ($z === false) {
                throw new InvalidArgumentException('offene spitze Klammer');
            }
            return $z + 1;
        }
        $a = $p;
        while ($p < strlen($s) && ctype_alpha($s[$p])) {
            $p++;
        }
        if ($p - $a < 3) {
            throw new InvalidArgumentException('Name zu kurz');
        }
        return $p;
    }

    private static function zahl(string $s, int $p): array
    {
        $a = $p;
        $v = 0;
        while ($p < strlen($s) && ctype_digit($s[$p])) {
            $v = $v * 10 + (int)$s[$p];
            $p++;
        }
        if ($p === $a) {
            throw new InvalidArgumentException('Ziffer erwartet');
        }
        return [$v, $p];
    }

    private static function versatz(string $s, int $p): array
    {
        $vz = 1;
        if (($s[$p] ?? '') === '-') {
            $vz = -1;
            $p++;
        } elseif (($s[$p] ?? '') === '+') {
            $p++;
        }
        [$h, $p] = self::zahl($s, $p);
        $m = 0;
        $sek = 0;
        if (($s[$p] ?? '') === ':') {
            [$m, $p] = self::zahl($s, $p + 1);
            if (($s[$p] ?? '') === ':') {
                [$sek, $p] = self::zahl($s, $p + 1);
            }
        }
        return [$vz * ($h * 3600 + $m * 60 + $sek), $p];
    }

    private static function regel(string $s, int $p): array
    {
        $r = ['zeit' => 0, 'tag' => 0, 'typ' => 0, 'monat' => 0, 'woche' => 0, 'wochentag' => 0];
        $c = $s[$p] ?? '';
        if ($c === 'M' || $c === 'm') {
            $r['typ'] = 1;
            [$r['monat'], $p] = self::zahl($s, $p + 1);
            if ($r['monat'] < 1 || $r['monat'] > 12 || ($s[$p] ?? '') !== '.') {
                throw new InvalidArgumentException('Monat');
            }
            [$r['woche'], $p] = self::zahl($s, $p + 1);
            if ($r['woche'] < 1 || $r['woche'] > 5 || ($s[$p] ?? '') !== '.') {
                throw new InvalidArgumentException('Woche');
            }
            [$r['wochentag'], $p] = self::zahl($s, $p + 1);
            if ($r['wochentag'] > 6) {
                throw new InvalidArgumentException('Wochentag');
            }
        } elseif ($c === 'J' || $c === 'j') {
            $r['typ'] = 2;
            [$r['tag'], $p] = self::zahl($s, $p + 1);
            if ($r['tag'] < 1 || $r['tag'] > 365) {
                throw new InvalidArgumentException('Julianischer Tag');
            }
        } elseif (ctype_digit($c)) {
            $r['typ'] = 3;
            [$r['tag'], $p] = self::zahl($s, $p);
            if ($r['tag'] > 365) {
                throw new InvalidArgumentException('Tag des Jahres');
            }
        } else {
            throw new InvalidArgumentException('Regel erwartet');
        }
        $r['zeit'] = 7200;
        if (($s[$p] ?? '') === '/') {
            [$r['zeit'], $p] = self::versatz($s, $p + 1);
        }
        return [$r, $p];
    }

    // ------------------------------------------------------------------ Protobuf

    /** sint32: ZigZag-kodiertes varint. */
    private static function feldSint(int $feld, int $wert): string
    {
        return $wert === 0 ? '' : ESPHomeHubProtokoll::varint($feld << 3) . ESPHomeHubProtokoll::varint(($wert << 1) ^ ($wert >> 63));
    }

    private static function feldNachricht(int $feld, string $daten): string
    {
        return ESPHomeHubProtokoll::varint(($feld << 3) | 2) . ESPHomeHubProtokoll::varint(strlen($daten)) . $daten;
    }

    private static function regelBytes(array $r): string
    {
        $P = ESPHomeHubProtokoll::class;
        return self::feldSint(1, $r['zeit']) . $P::feldZahl(2, $r['tag']) . $P::feldZahl(3, $r['typ'])
            . $P::feldZahl(4, $r['monat']) . $P::feldZahl(5, $r['woche']) . $P::feldZahl(6, $r['wochentag']);
    }

    /** GetTimeResponse wie aioesphomeapi: Uhrzeit, alter Textwert, parsed_timezone (sofern lesbar). */
    public static function getTimeResponse(int $epoche, string $posix): string
    {
        $P = ESPHomeHubProtokoll::class;
        $out = $P::feldFixed32(1, $epoche) . $P::feldText(2, $posix);
        $z = self::zerlegen($posix);
        if ($z !== null) {
            // Untermeldungen setzt aioesphomeapi immer (CopyFrom), auch wenn sie leer sind.
            $out .= self::feldNachricht(3, self::feldSint(1, $z['std']) . self::feldSint(2, $z['dst'])
                . self::feldNachricht(3, self::regelBytes($z['start'])) . self::feldNachricht(4, self::regelBytes($z['ende'])));
        }
        return $out;
    }
}
