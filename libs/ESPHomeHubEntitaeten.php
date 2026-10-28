<?php

declare(strict_types=1);

require_once __DIR__ . '/ESPHomeHubProtokoll.php';

// ===========================================================================
// ESPHome-Entitäten ↔ Symcon-Variablen, ohne Symcon.
//
// beschreiben(): ListEntities*Response → Entität (Typ, key, Name, Fähigkeiten)
// variablen():   Entität → Variablen (Ident, Name, Typ, Darstellung, Aktion)
// zustand():     *StateResponse → [key, [ident => wert]]
// befehl():      Ident + Wert → [*CommandRequest-Typ, Nutzdaten]
//
// Feldnummern nach aioesphomeapi/api.proto (MIT). object_id kommt seit
// ESPHome 2026 leer — die Entität heißt nach ihrem key (fixed32, stabil,
// solange sich die object_id in der YAML nicht ändert). Ident: 'e' + key als
// 8 Hex-Zeichen, Teilvariablen mit Endung (_h Helligkeit, _f Farbe, …).
// ===========================================================================

final class ESPHomeHubEntitaeten
{
    public const LISTE_FERTIG = 19;
    public const LISTE_ANFORDERN = 11;
    public const ZUSTAENDE_ABONNIEREN = 20;

    /** ListEntities-Typ => [Entitätstyp, StateResponse-Typ, CommandRequest-Typ] (0 = gibt es nicht) */
    public const TYPEN = [
        12  => ['binary_sensor', 21, 0],
        13  => ['cover', 22, 30],
        14  => ['fan', 23, 31],
        15  => ['light', 24, 32],
        16  => ['sensor', 25, 0],
        17  => ['switch', 26, 33],
        18  => ['text_sensor', 27, 0],
        46  => ['climate', 47, 48],
        49  => ['number', 50, 51],
        52  => ['select', 53, 54],
        58  => ['lock', 59, 60],
        61  => ['button', 0, 62],
        97  => ['text', 98, 99],
        107 => ['event', 108, 0],
        109 => ['valve', 110, 111],
    ];

    /** Weitere ListEntities-Typen, die ESPHome kennt, wir aber (noch) nicht abbilden. */
    public const NICHT_UNTERSTUETZT = [
        43 => 'camera', 41 => 'services', 55 => 'siren', 63 => 'media_player', 94 => 'alarm_control_panel',
        100 => 'date', 103 => 'time', 112 => 'datetime', 116 => 'update', 132 => 'water_heater', 135 => 'infrared', 148 => 'radio_frequency',
    ];

    public const TYP_NAMEN = [
        'binary_sensor' => 'Binärsensor', 'cover' => 'Abdeckung', 'fan' => 'Lüfter', 'light' => 'Licht',
        'sensor' => 'Sensor', 'switch' => 'Schalter', 'text_sensor' => 'Textsensor', 'climate' => 'Klima',
        'number' => 'Zahl', 'select' => 'Auswahl', 'lock' => 'Schloss', 'button' => 'Knopf', 'text' => 'Text',
        'event' => 'Ereignis', 'valve' => 'Ventil',
    ];

    private const KLIMA_MODI = [0 => 'Aus', 1 => 'Heizen/Kühlen', 2 => 'Kühlen', 3 => 'Heizen', 4 => 'Nur Lüften', 5 => 'Entfeuchten', 6 => 'Automatik'];
    private const KLIMA_AKTION = [0 => 'Aus', 2 => 'Kühlt', 3 => 'Heizt', 4 => 'Leerlauf', 5 => 'Entfeuchtet', 6 => 'Lüftet', 7 => 'Taut ab'];
    private const SCHLOSS = [0 => 'Unbekannt', 1 => 'Verriegelt', 2 => 'Entriegelt', 3 => 'Blockiert', 4 => 'Verriegelt …', 5 => 'Entriegelt …', 6 => 'Öffnet …', 7 => 'Offen'];
    private const BEWEGUNG = [0 => 'Steht', 1 => 'Öffnet', 2 => 'Schließt'];

    /** Klartext für binary_sensor je device_class: [aus, an] */
    private const BINAER = [
        'door' => ['Geschlossen', 'Offen'], 'window' => ['Geschlossen', 'Offen'], 'garage_door' => ['Geschlossen', 'Offen'],
        'opening' => ['Geschlossen', 'Offen'], 'lock' => ['Verriegelt', 'Entriegelt'], 'motion' => ['Keine Bewegung', 'Bewegung'],
        'occupancy' => ['Frei', 'Belegt'], 'presence' => ['Abwesend', 'Anwesend'], 'moisture' => ['Trocken', 'Nass'],
        'smoke' => ['Kein Rauch', 'Rauch'], 'gas' => ['Kein Gas', 'Gas'], 'problem' => ['OK', 'Problem'],
        'connectivity' => ['Getrennt', 'Verbunden'], 'battery' => ['Normal', 'Schwach'], 'power' => ['Aus', 'An'],
        'plug' => ['Ausgesteckt', 'Eingesteckt'], 'running' => ['Läuft nicht', 'Läuft'], 'vibration' => ['Ruhig', 'Vibration'],
        'light' => ['Dunkel', 'Hell'], 'heat' => ['Normal', 'Heiß'], 'cold' => ['Normal', 'Kalt'], 'tamper' => ['OK', 'Manipuliert'],
        'safety' => ['Sicher', 'Unsicher'], 'sound' => ['Ruhig', 'Geräusch'], 'update' => ['Aktuell', 'Update verfügbar'],
    ];

    // ------------------------------------------------------------------ Beschreibung

    public static function istListe(int $typ): bool
    {
        return isset(self::TYPEN[$typ]) || isset(self::NICHT_UNTERSTUETZT[$typ]);
    }

    /** @return array|null Entität, oder null für unbekannte/nicht unterstützte Typen */
    public static function beschreiben(int $listTyp, string $daten): ?array
    {
        if (!isset(self::TYPEN[$listTyp])) {
            return null;
        }
        $P = ESPHomeHubProtokoll::class;
        $m = $P::zerlegen($daten);
        $typ = self::TYPEN[$listTyp][0];
        $e = ['typ' => $typ, 'key' => $P::fixed32($m, 2), 'name' => $P::text($m, 3), 'objekt' => $P::text($m, 1)];
        switch ($typ) {
            case 'sensor':
                $e += ['einheit' => $P::text($m, 6), 'nachkomma' => max(0, $P::zahl32($m, 7)), 'klasse' => $P::text($m, 9)];
                break;
            case 'binary_sensor':
                $e += ['klasse' => $P::text($m, 5)];
                break;
            case 'cover':
                $e += ['position' => $P::bool($m, 6), 'stopp' => $P::bool($m, 12), 'klasse' => $P::text($m, 8)];
                break;
            case 'valve':
                $e += ['position' => $P::bool($m, 10), 'stopp' => $P::bool($m, 11), 'klasse' => $P::text($m, 8)];
                break;
            case 'fan':
                $e += ['stufen' => $P::bool($m, 6) ? max(0, $P::zahl32($m, 8)) : 0];
                break;
            case 'light':
                $modi = $P::zahlen($m, 12);
                $faehig = 0;
                foreach ($modi as $mod) {
                    $faehig |= $mod;
                }
                $e += [
                    'helligkeit' => ($faehig & 2) !== 0,
                    'rgb'        => ($faehig & 32) !== 0,
                    'farbtemp'   => ($faehig & 8) !== 0,
                    'minMired'   => $P::float($m, 9),
                    'maxMired'   => $P::float($m, 10),
                ];
                break;
            case 'climate':
                $flags = $P::zahl($m, 27);
                $e += [
                    'modi'     => $P::zahlen($m, 7),
                    'ist'      => ($flags & 1) !== 0 || $P::bool($m, 5),
                    'zweiPunkt' => ($flags & 6) !== 0 || $P::bool($m, 6),
                    'aktion'   => ($flags & 32) !== 0 || $P::bool($m, 12),
                    'min'      => $P::float($m, 8),
                    'max'      => $P::float($m, 9),
                    'schritt'  => $P::float($m, 10),
                ];
                break;
            case 'number':
                $e += ['min' => $P::float($m, 6), 'max' => $P::float($m, 7), 'schritt' => $P::float($m, 8), 'einheit' => $P::text($m, 11), 'eingabe' => $P::zahl($m, 12) === 1];
                break;
            case 'select':
                $e += ['optionen' => $P::texte($m, 6)];
                break;
            case 'lock':
                $e += ['oeffnen' => $P::bool($m, 9), 'code' => $P::bool($m, 10)];
                break;
            case 'event':
                $e += ['ereignisse' => $P::texte($m, 9)];
                break;
        }
        return $e;
    }

    public static function ident(int $key, string $teil = ''): string
    {
        return 'e' . sprintf('%08x', $key) . ($teil === '' ? '' : '_' . $teil);
    }

    /** @return array{0:int,1:string}|null [key, Teil] aus einem Ident */
    public static function identLesen(string $ident): ?array
    {
        if (!preg_match('/^e([0-9a-f]{8})(?:_([a-z]))?$/', $ident, $t)) {
            return null;
        }
        return [(int)hexdec($t[1]), $t[2] ?? ''];
    }

    // ------------------------------------------------------------------ Variablen

    /**
     * Variablen einer Entität. $geraet = Anzeigename des Geräts (für Entitäten ohne eigenen Namen).
     * @return array[] je ['ident', 'name', 'typ', 'pres', 'aktion']
     */
    public static function variablen(array $e, string $geraet): array
    {
        $name = $e['name'] !== '' ? $e['name'] : $geraet;
        $k = (int)$e['key'];
        $anzeige = fn (array $p = []) => ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION] + $p;
        $v = fn (string $teil, string $n, int $typ, array $pres, bool $aktion) => ['ident' => self::ident($k, $teil), 'name' => $n, 'typ' => $typ, 'pres' => $pres, 'aktion' => $aktion];
        $prozent = ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'MIN' => 0, 'MAX' => 100, 'STEP_SIZE' => 1, 'SUFFIX' => ' %'];

        switch ($e['typ']) {
            case 'sensor':
                return [$v('', $name, VARIABLETYPE_FLOAT, $anzeige(['DIGITS' => (int)$e['nachkomma']] + ($e['einheit'] !== '' ? ['SUFFIX' => ' ' . $e['einheit']] : [])), false)];
            case 'binary_sensor':
                [$aus, $an] = self::BINAER[$e['klasse']] ?? ['Aus', 'An'];
                return [$v('', $name, VARIABLETYPE_BOOLEAN, $anzeige(['OPTIONS' => self::wertanzeige([[false, $aus], [true, $an]])]), false)];
            case 'text_sensor':
                return [$v('', $name, VARIABLETYPE_STRING, $anzeige(), false)];
            case 'event':
                return [$v('', $name, VARIABLETYPE_STRING, $anzeige(), false)];
            case 'switch':
                return [$v('', $name, VARIABLETYPE_BOOLEAN, ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], true)];
            case 'button':
                return [$v('', $name, VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => self::aufzaehlung([0 => 'Auslösen'])], true)];
            case 'number':
                $einheit = $e['einheit'] !== '' ? ' ' . $e['einheit'] : '';
                if ($e['eingabe']) {
                    return [$v('', $name, VARIABLETYPE_FLOAT, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_INPUT] + ($einheit !== '' ? ['SUFFIX' => $einheit] : []), true)];
                }
                return [$v('', $name, VARIABLETYPE_FLOAT, ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'MIN' => (float)$e['min'], 'MAX' => (float)$e['max'],
                    'STEP_SIZE' => (float)($e['schritt'] ?: 1), 'DIGITS' => self::stellen((float)$e['schritt'])] + ($einheit !== '' ? ['SUFFIX' => $einheit] : []), true)];
            case 'select':
                return [$v('', $name, VARIABLETYPE_STRING, ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => self::aufzaehlung(array_combine($e['optionen'], $e['optionen']) ?: [])], true)];
            case 'text':
                return [$v('', $name, VARIABLETYPE_STRING, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_INPUT], true)];
            case 'light':
                $out = [$v('', $name, VARIABLETYPE_BOOLEAN, ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], true)];
                if ($e['helligkeit']) {
                    $out[] = $v('h', $name . ' Helligkeit', VARIABLETYPE_INTEGER, $prozent, true);
                }
                if ($e['rgb']) {
                    $out[] = $v('f', $name . ' Farbe', VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_COLOR, 'ENCODING' => 0], true);
                }
                if ($e['farbtemp'] && $e['minMired'] > 0 && $e['maxMired'] > 0) {
                    $out[] = $v('k', $name . ' Farbtemperatur', VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                        'MIN' => (int)round(1e6 / $e['maxMired']), 'MAX' => (int)round(1e6 / $e['minMired']), 'STEP_SIZE' => 50, 'SUFFIX' => ' K'], true);
                }
                return $out;
            case 'cover':
            case 'valve':
                $out = [];
                if ($e['position']) {
                    $out[] = $v('', $name . ' (offen)', VARIABLETYPE_INTEGER, $prozent, true);
                } else {
                    $out[] = $v('', $name, VARIABLETYPE_BOOLEAN, $anzeige(['OPTIONS' => self::wertanzeige([[false, 'Geschlossen'], [true, 'Offen']])]), false);
                }
                $out[] = $v('s', $name . ' Steuerung', VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                    'OPTIONS' => self::aufzaehlung($e['stopp'] ? [0 => 'Öffnen', 1 => 'Stopp', 2 => 'Schließen'] : [0 => 'Öffnen', 2 => 'Schließen'])], true);
                $out[] = $v('z', $name . ' Bewegung', VARIABLETYPE_STRING, $anzeige(), false);
                return $out;
            case 'fan':
                $out = [$v('', $name, VARIABLETYPE_BOOLEAN, ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], true)];
                if ($e['stufen'] > 0) {
                    $out[] = $v('g', $name . ' Stufe', VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'MIN' => 1, 'MAX' => (int)$e['stufen'], 'STEP_SIZE' => 1], true);
                }
                return $out;
            case 'lock':
                $out = [$v('', $name . ' verriegelt', VARIABLETYPE_BOOLEAN, ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], true)];
                if ($e['oeffnen']) {
                    $out[] = $v('s', $name . ' öffnen', VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => self::aufzaehlung([2 => 'Öffnen'])], true);
                }
                $out[] = $v('z', $name . ' Zustand', VARIABLETYPE_STRING, $anzeige(), false);
                return $out;
            case 'climate':
                $temp = fn (string $teil, string $n) => $v($teil, $n, VARIABLETYPE_FLOAT, ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'MIN' => (float)($e['min'] ?: 5), 'MAX' => (float)($e['max'] ?: 35),
                    'STEP_SIZE' => (float)($e['schritt'] ?: 0.5), 'DIGITS' => max(1, self::stellen((float)$e['schritt'])), 'SUFFIX' => ' °C'], true);
                $modi = [];
                foreach ($e['modi'] as $mod) {
                    $modi[$mod] = self::KLIMA_MODI[$mod] ?? ('Modus ' . $mod);
                }
                $out = [$v('m', $name . ' Betriebsart', VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => self::aufzaehlung($modi)], true)];
                if ($e['zweiPunkt']) {
                    $out[] = $temp('u', $name . ' Solltemperatur unten');
                    $out[] = $temp('o', $name . ' Solltemperatur oben');
                } else {
                    $out[] = $temp('', $name . ' Solltemperatur');
                }
                if ($e['ist']) {
                    $out[] = $v('t', $name . ' Ist-Temperatur', VARIABLETYPE_FLOAT, $anzeige(['DIGITS' => 1, 'SUFFIX' => ' °C']), false);
                }
                if ($e['aktion']) {
                    $out[] = $v('a', $name . ' Tätigkeit', VARIABLETYPE_STRING, $anzeige(), false);
                }
                return $out;
        }
        return [];
    }

    /** Wertanzeige-OPTIONS (Bool/String): nur Value, Caption, IconActive, IconValue, ColorActive, ColorValue. */
    private static function wertanzeige(array $paare): string
    {
        $o = [];
        foreach ($paare as [$wert, $text]) {
            $o[] = ['Value' => $wert, 'Caption' => $text, 'IconActive' => false, 'IconValue' => '', 'ColorActive' => false, 'ColorValue' => -1];
        }
        return (string)json_encode($o);
    }

    /** Aufzählungs-OPTIONS: nur Value, Caption, IconActive, IconValue, Color. */
    private static function aufzaehlung(array $werte): string
    {
        $o = [];
        foreach ($werte as $wert => $text) {
            $o[] = ['Value' => $wert, 'Caption' => (string)$text, 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        return (string)json_encode($o);
    }

    private static function stellen(float $schritt): int
    {
        if ($schritt <= 0 || $schritt >= 1) {
            return 0;
        }
        return min(4, (int)ceil(-log10($schritt) - 1e-9));
    }

    // ------------------------------------------------------------------ Zustände

    /** StateResponse-Typ → Entitätstyp, oder null. */
    public static function zustandsTyp(int $typ): ?string
    {
        foreach (self::TYPEN as [$name, $zustand]) {
            if ($zustand === $typ) {
                return $name;
            }
        }
        return null;
    }

    /**
     * Zustandsnachricht auswerten. $e = die Entität mit diesem key.
     * @return array [ident => wert]; leer, wenn das Gerät „kein Zustand" meldet
     */
    public static function zustand(int $typ, string $daten, array $e): array
    {
        $P = ESPHomeHubProtokoll::class;
        $m = $P::zerlegen($daten);
        $k = (int)$e['key'];
        $i = fn (string $teil = '') => self::ident($k, $teil);
        switch ($e['typ']) {
            case 'sensor':
                if ($P::bool($m, 3)) {
                    return [];
                }
                $w = $P::float($m, 2);
                return is_nan($w) ? [] : [$i() => round($w, (int)$e['nachkomma'])];
            case 'binary_sensor':
                return $P::bool($m, 3) ? [] : [$i() => $P::bool($m, 2)];
            case 'text_sensor':
            case 'select':
            case 'text':
                return $P::bool($m, 3) ? [] : [$i() => $P::text($m, 2)];
            case 'event':
                return [$i() => $P::text($m, 2)];
            case 'switch':
                return [$i() => $P::bool($m, 2)];
            case 'number':
                if ($P::bool($m, 3)) {
                    return [];
                }
                $w = $P::float($m, 2);
                return is_nan($w) ? [] : [$i() => round($w, 6)];
            case 'light':
                $out = [$i() => $P::bool($m, 2)];
                if ($e['helligkeit']) {
                    $out[$i('h')] = (int)round($P::float($m, 3) * 100);
                }
                if ($e['rgb']) {
                    $rgb = array_map(fn ($f) => max(0, min(255, (int)round($P::float($m, $f) * 255))), [4, 5, 6]);
                    $out[$i('f')] = ($rgb[0] << 16) | ($rgb[1] << 8) | $rgb[2];
                }
                if (isset($m[8]) && $e['farbtemp'] && $P::float($m, 8) > 0) {
                    $out[$i('k')] = (int)round(1e6 / $P::float($m, 8));
                }
                return $out;
            case 'cover':
            case 'valve':
                $pos = $e['typ'] === 'cover' ? $P::float($m, 3) : $P::float($m, 2);
                $bewegung = $e['typ'] === 'cover' ? $P::zahl($m, 5) : $P::zahl($m, 3);
                return [
                    $i() => $e['position'] ? (int)round($pos * 100) : $pos > 0,
                    $i('z') => self::BEWEGUNG[$bewegung] ?? ('Zustand ' . $bewegung),
                ];
            case 'fan':
                $out = [$i() => $P::bool($m, 2)];
                if ($e['stufen'] > 0 && $P::zahl32($m, 6) > 0) {
                    $out[$i('g')] = $P::zahl32($m, 6);
                }
                return $out;
            case 'lock':
                $s = $P::zahl($m, 2);
                $out = [$i('z') => self::SCHLOSS[$s] ?? ('Zustand ' . $s)];
                if ($s === 1) {
                    $out[$i()] = true;
                } elseif ($s === 2 || $s === 7) {
                    $out[$i()] = false;
                }
                return $out;
            case 'climate':
                $out = [$i('m') => $P::zahl($m, 2)];
                if ($e['zweiPunkt']) {
                    $out[$i('u')] = round($P::float($m, 5), 2);
                    $out[$i('o')] = round($P::float($m, 6), 2);
                } else {
                    $out[$i()] = round($P::float($m, 4), 2);
                }
                if ($e['ist']) {
                    $ist = $P::float($m, 3);
                    if (!is_nan($ist)) {
                        $out[$i('t')] = round($ist, 2);
                    }
                }
                if ($e['aktion']) {
                    $a = $P::zahl($m, 8);
                    $out[$i('a')] = self::KLIMA_AKTION[$a] ?? ('Zustand ' . $a);
                }
                return $out;
        }
        return [];
    }

    // ------------------------------------------------------------------ Befehle

    /**
     * Befehl für eine Variable bauen.
     * @return array{0:int,1:string,2:mixed} [CommandRequest-Typ, Nutzdaten, sofort zu setzender Wert oder null]
     * @throws InvalidArgumentException mit nutzerlesbarem Text
     */
    public static function befehl(array $e, string $teil, $wert): array
    {
        $P = ESPHomeHubProtokoll::class;
        $listTyp = (int)array_search($e['typ'], array_map(fn ($t) => $t[0], self::TYPEN), true);
        $befehl = self::TYPEN[$listTyp][2] ?? 0;
        if ($befehl === 0) {
            throw new InvalidArgumentException('„' . $e['name'] . '" lässt sich nicht schalten.');
        }
        $key = $P::feldFixed32(1, (int)$e['key']);
        $b = fn (int $f) => $P::feldBool($f, true);
        switch ($e['typ'] . '/' . $teil) {
            case 'switch/':
                return [$befehl, $key . $P::feldBool(2, (bool)$wert), null];
            case 'button/':
                return [$befehl, $key, 0];
            case 'number/':
                $w = (float)$wert;
                if (($e['min'] != $e['max']) && ($w < $e['min'] || $w > $e['max'])) {
                    throw new InvalidArgumentException('Wert ' . $w . ' liegt außerhalb ' . $e['min'] . '–' . $e['max'] . '.');
                }
                return [$befehl, $key . $P::feldFloat(2, $w), null];
            case 'select/':
                if (!in_array((string)$wert, $e['optionen'], true)) {
                    throw new InvalidArgumentException('„' . $wert . '" ist keine Option von „' . $e['name'] . '".');
                }
                return [$befehl, $key . $P::feldText(2, (string)$wert), null];
            case 'text/':
                return [$befehl, $key . $P::feldText(2, (string)$wert), null];
            case 'light/':
                return [$befehl, $key . $b(2) . $P::feldBool(3, (bool)$wert), null];
            case 'light/h':
                $h = max(0, min(100, (int)$wert));
                return [$befehl, $key . $b(2) . $P::feldBool(3, $h > 0) . ($h > 0 ? $b(4) . $P::feldFloat(5, $h / 100) : ''), null];
            case 'light/f':
                $f = (int)$wert;
                return [$befehl, $key . $b(2) . $b(3) . $b(6) . $P::feldFloat(7, (($f >> 16) & 255) / 255) . $P::feldFloat(8, (($f >> 8) & 255) / 255) . $P::feldFloat(9, ($f & 255) / 255), null];
            case 'light/k':
                $kelvin = max(1, (int)$wert);
                return [$befehl, $key . $b(2) . $b(3) . $b(12) . $P::feldFloat(13, 1e6 / $kelvin), null];
            case 'cover/':
                return [$befehl, $key . $b(4) . $P::feldFloat(5, max(0, min(100, (int)$wert)) / 100), null];
            case 'valve/':
                return [$befehl, $key . $b(2) . $P::feldFloat(3, max(0, min(100, (int)$wert)) / 100), null];
            case 'cover/s':
            case 'valve/s':
                $s = (int)$wert;
                $cover = $e['typ'] === 'cover';
                if ($s === 1) {
                    return [$befehl, $key . ($cover ? $b(8) : $b(4)), 1];
                }
                return [$befehl, $key . ($cover ? $b(4) . $P::feldFloat(5, $s === 0 ? 1.0 : 0.0) : $b(2) . $P::feldFloat(3, $s === 0 ? 1.0 : 0.0)), $s];
            case 'fan/':
                return [$befehl, $key . $b(2) . $P::feldBool(3, (bool)$wert), null];
            case 'fan/g':
                return [$befehl, $key . $b(2) . $b(3) . $b(10) . $P::feldZahl(11, max(1, min((int)$e['stufen'], (int)$wert))), null];
            case 'lock/':
            case 'lock/s':
                if ($e['code']) {
                    throw new InvalidArgumentException('„' . $e['name'] . '" verlangt einen Code — das unterstützt ESPHomeHub noch nicht.');
                }
                $cmd = $teil === 's' ? 2 : ((bool)$wert ? 1 : 0);
                return [$befehl, $key . $P::feldZahl(2, $cmd), $teil === 's' ? 2 : null];
            case 'climate/m':
                if (!in_array((int)$wert, $e['modi'], true)) {
                    throw new InvalidArgumentException('Betriebsart ' . $wert . ' kennt „' . $e['name'] . '" nicht.');
                }
                return [$befehl, $key . $b(2) . $P::feldZahl(3, (int)$wert), null];
            case 'climate/':
                return [$befehl, $key . $b(4) . $P::feldFloat(5, (float)$wert), null];
            case 'climate/u':
                return [$befehl, $key . $b(6) . $P::feldFloat(7, (float)$wert), null];
            case 'climate/o':
                return [$befehl, $key . $b(8) . $P::feldFloat(9, (float)$wert), null];
        }
        throw new InvalidArgumentException('Unbekannte Aktion für „' . $e['name'] . '".');
    }
}
