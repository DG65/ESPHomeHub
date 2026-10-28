<?php
/**
 * test-esphomehub.php — Prüfstand für ESPHomeHub OHNE laufendes Symcon.
 *
 * 1. Protobuf/Rahmen: varint, negative int32, gepackte Felder, Stückelung
 * 2. Noise + Sitzung byte-genau gegen eine mit noiseprotocol (Referenz von
 *    aioesphomeapi) aufgezeichnete Sitzung (.tools/fixture-noise.json),
 *    auch byteweise zerstückelt und mit Export/Import nach jedem Byte
 * 3. Fehlerfälle: falscher Schlüssel (beide Wege), Gerät ohne/mit Verschlüsselung
 * 4. Gerätemodul mit nachgebildetem Client Socket gegen ein simuliertes
 *    unverschlüsseltes Gerät: Verbindungsaufbau, Ping, Trennen, Wache, Status
 * 5. Store-Regeln: Formular-JSON, Statuscodes, Wertanzeige-OPTIONS, kein
 *    IPS_LogMessage, keine Standardwerte an öffentlichen Methoden
 * 6. Optional echt: ESPHOMEHUB_LIVE="host:port:schluessel[,host:port:]" →
 *    Gerätemodul über echtes TCP, Daten in zufälligen 1–7-Byte-Stücken
 *
 * Aufruf:   php .tools/test-esphomehub.php
 * Rückgabe: 0 = alles bestanden, 1 = mindestens ein Fehlschlag
 */

declare(strict_types=1);
date_default_timezone_set('Europe/Berlin');

const KR_READY = 10103;
const IPS_KERNELMESSAGE = 10100;
const IM_CHANGESTATUS = 10505;
const IS_ACTIVE = 102;
const IS_INACTIVE = 104;
const IS_EBASE = 200;
const KL_MESSAGE = 10201;
const KL_WARNING = 10204;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLE_PRESENTATION_VALUE_PRESENTATION = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';

$ROOT = dirname(__DIR__);
$FEHLER = 0;
$OK = 0;

function pruefe(bool $b, string $was): void
{
    global $FEHLER, $OK;
    if ($b) {
        $OK++;
        return;
    }
    $FEHLER++;
    echo "  ✘ $was\n";
}

function abschnitt(string $t): void
{
    echo "— $t\n";
}

// ------------------------------------------------------------------ Symcon-Ersatz
// Zustand je Instanz global, damit jeder Aufruf ein NEUES Modulobjekt bekommt (wie in Symcon).

$GLOBALS['Z'] = [];
$GLOBALS['SOCKET'] = ['id' => 20000, 'Host' => '', 'Port' => 0, 'Open' => false, 'status' => IS_INACTIVE, 'name' => 'Client Socket'];
$GLOBALS['LOG'] = [];
$GLOBALS['GESENDET'] = '';
$GLOBALS['TRANSPORT'] = null;

class IPSModule
{
    public $InstanceID;

    public function __construct($id = 0)
    {
        $this->InstanceID = $id;
        $GLOBALS['Z'][$id] ??= ['p' => [], 'a' => [], 'b' => [], 't' => [], 'v' => [], 'status' => 0, 'msg' => []];
    }
    private function &z(): array { return $GLOBALS['Z'][$this->InstanceID]; }
    public function Create() {}
    public function ApplyChanges() {}
    protected function RegisterPropertyString($n, $v) { $this->z()['p'][$n] = (string)$v; }
    protected function RegisterPropertyInteger($n, $v) { $this->z()['p'][$n] = (int)$v; }
    protected function ReadPropertyString($n) { return (string)$this->z()['p'][$n]; }
    protected function ReadPropertyInteger($n) { return (int)$this->z()['p'][$n]; }
    protected function RegisterAttributeString($n, $v) { $this->z()['a'][$n] = (string)$v; }
    protected function RegisterAttributeInteger($n, $v) { $this->z()['a'][$n] = (int)$v; }
    protected function RegisterAttributeBoolean($n, $v) { $this->z()['a'][$n] = (bool)$v; }
    protected function ReadAttributeString($n) { return (string)$this->z()['a'][$n]; }
    protected function ReadAttributeInteger($n) { return (int)$this->z()['a'][$n]; }
    protected function ReadAttributeBoolean($n) { return (bool)$this->z()['a'][$n]; }
    protected function WriteAttributeString($n, $v) { $this->z()['a'][$n] = (string)$v; }
    protected function WriteAttributeInteger($n, $v) { $this->z()['a'][$n] = (int)$v; }
    protected function WriteAttributeBoolean($n, $v) { $this->z()['a'][$n] = (bool)$v; }
    protected function SetBuffer($n, $v) { $this->z()['b'][$n] = (string)$v; }
    protected function GetBuffer($n) { return (string)($this->z()['b'][$n] ?? ''); }
    protected function RegisterTimer($n, $i, $s) { $this->z()['t'][$n] = $i; }
    protected function SetTimerInterval($n, $i) { $this->z()['t'][$n] = $i; }
    protected function SetStatus($s) { $this->z()['status'] = $s; }
    protected function GetStatus() { return $this->z()['status']; }
    protected function RequireParent($g) { $this->z()['eltern'] = $g; }
    protected function RegisterMessage($s, $m) { $this->z()['msg'][$s . ':' . $m] = true; }
    protected function UnregisterMessage($s, $m) { unset($this->z()['msg'][$s . ':' . $m]); }
    protected function SendDebug($t, $m, $f) { $GLOBALS['DEBUG'][] = "$t: $m"; }
    protected function LogMessage($m, $t) { $GLOBALS['LOG'][] = [$t, $m]; }
    protected function UpdateFormField($f, $p, $v) {}
    protected function HasActiveParent() { return $GLOBALS['SOCKET']['status'] === IS_ACTIVE; }
    protected function SendDataToParent($json)
    {
        $d = json_decode($json, true);
        $bytes = mb_convert_encoding($d['Buffer'], 'ISO-8859-1', 'UTF-8');
        $GLOBALS['GESENDET'] .= $bytes;
        if ($GLOBALS['TRANSPORT'] !== null) {
            $GLOBALS['TRANSPORT']->senden($bytes);
        }
        return '';
    }
    protected function MaintainVariable($ident, $name, $typ, $pres, $pos, $behalten)
    {
        if ($behalten) {
            $this->z()['v'][$ident] ??= ['typ' => $typ, 'wert' => false];
            $this->z()['v'][$ident]['pres'] = $pres;
        } else {
            unset($this->z()['v'][$ident]);
        }
    }
    protected function GetIDForIdent($ident) { return isset($this->z()['v'][$ident]) ? 30000 : false; }
    protected function GetValue($ident) { return $this->z()['v'][$ident]['wert']; }
    protected function SetValue($ident, $w) { $this->z()['v'][$ident]['wert'] = $w; }
}

function IPS_GetKernelRunlevel() { return KR_READY; }
function IPS_GetName($id) { return $id === 20000 ? $GLOBALS['SOCKET']['name'] : 'Wohnzimmer'; }
function IPS_SetName($id, $n) { if ($id === 20000) { $GLOBALS['SOCKET']['name'] = $n; } }
function IPS_GetLibrary($g) { return ['Version' => '0.1.0', 'Build' => 1]; }
function IPS_SemaphoreEnter($n, $t) { return true; }
function IPS_SemaphoreLeave($n) { return true; }
function IPS_GetInstance($id)
{
    if ($id === 20000) {
        return ['ModuleInfo' => ['ModuleID' => '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}'], 'InstanceStatus' => $GLOBALS['SOCKET']['status']];
    }
    return ['ConnectionID' => 20000];
}
function IPS_GetProperty($id, $k) { return $GLOBALS['SOCKET'][$k]; }
function IPS_SetProperty($id, $k, $v) { $GLOBALS['SOCKET'][$k] = $v; }
function IPS_SetConfiguration($id, $json) { foreach (json_decode($json, true) as $k => $v) { $GLOBALS['SOCKET'][$k] = $v; } }
function IPS_ApplyChanges($id)
{
    $s = &$GLOBALS['SOCKET'];
    $t = $GLOBALS['TRANSPORT'];
    $t?->schliessen();
    if ($s['Open'] && $t !== null && $t->oeffnen($s['Host'], (int)$s['Port'])) {
        $s['status'] = IS_ACTIVE;
    } else {
        $s['status'] = $s['Open'] ? 200 : IS_INACTIVE;
    }
    geraet()->MessageSink(0, 20000, IM_CHANGESTATUS, [$s['status'], 0]);
}
function IPS_RunScriptText($code) { eval($code); }

function geraet(): ESPHomeHubGeraet { return new ESPHomeHubGeraet(10000); }
function zustand(): array { return $GLOBALS['Z'][10000]; }
function empfangen(string $bytes): void
{
    geraet()->ReceiveData(json_encode(['DataID' => '{018EF6B5-AB94-40C6-AA53-46943E824ACF}', 'Buffer' => mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1')]));
}

require_once $ROOT . '/ESPHomeHubGeraet/module.php';
$P = 'ESPHomeHubProtokoll';

// ------------------------------------------------------------------ 1. Protobuf und Rahmen
abschnitt('Protobuf und Rahmen');
foreach ([0, 1, 127, 128, 300, 16384, 0xFFFFFFFF] as $v) {
    $p = 0;
    pruefe($P::varintLesen($P::varint($v), $p) === $v, "varint $v");
}
pruefe(strlen($P::varint(-1)) === 10, 'negatives int32 als 10-Byte-varint');
$m = $P::zerlegen($P::feldZahl(7, -2));
pruefe($P::zahl32($m, 7) === -2, 'int32 negativ zurücklesen (accuracy_decimals)');
$m = $P::zerlegen("\x62\x02\x03\x07");   // Feld 12, gepackt [3, 7] wie supported_color_modes
pruefe($P::zahlen($m, 12) === [3, 7], 'gepackte Wiederholung');
$m = $P::zerlegen($P::feldFloat(2, 22.1) . $P::feldFixed32(1, 0xDEADBEEF) . $P::feldText(3, 'Tür') . $P::feldBool(4, true) . "\xA2\x06\x01\x00");
pruefe(abs($P::float($m, 2) - 22.1) < 1e-5 && $P::fixed32($m, 1) === 0xDEADBEEF && $P::text($m, 3) === 'Tür' && $P::bool($m, 4), 'Feldtypen gemischt, unbekanntes Feld übersprungen');
$rahmen = $P::klartextRahmen(10, str_repeat('x', 200));
$puffer = substr($rahmen, 0, 2);
pruefe($P::rahmenLesen($puffer) === null && strlen($puffer) === 2, 'halber varint-Kopf bleibt im Puffer');
$puffer = $rahmen . "\x00";
$r = $P::rahmenLesen($puffer);
pruefe($r[0] === 'klartext' && $r[1] === 10 && strlen($r[2]) === 200 && $puffer === "\x00", 'Klartextrahmen mit Rest');
try {
    $puffer = "\x07abc";
    $P::rahmenLesen($puffer);
    pruefe(false, 'unbekannte Präambel muss werfen');
} catch (RuntimeException $e) {
    pruefe(true, '');
}
pruefe(ESPHomeHubNoise::schluesselLesen('QEFCQ0RFRkdISUpLTE1OT1BRUlNUVVZXWFlaW1xdXl8=') !== null, 'gültiger Schlüssel');
pruefe(ESPHomeHubNoise::schluesselLesen('abc') === null && ESPHomeHubNoise::schluesselLesen(base64_encode(str_repeat('x', 31))) === null, 'ungültige Schlüssel abgelehnt');

// ------------------------------------------------------------------ 2. Noise + Sitzung gegen Referenz
abschnitt('Noise-Sitzung byte-genau gegen noiseprotocol');
$fx = json_decode((string)file_get_contents(__DIR__ . '/fixture-noise.json'), true);
$h = fn (string $k) => (string)hex2bin($fx[$k]);
$psk = ESPHomeHubNoise::schluesselLesen($fx['psk_b64']);

/** Fährt die aufgezeichnete Sitzung; $stueck = Bytes je Empfang, $umladen = Export/Import nach jedem Stück. */
$fahren = function (int $stueck, bool $umladen) use ($fx, $h, $psk): array {
    [$s, $raus] = ESPHomeHubSitzung::starten($psk, $fx['client'], $h('e_init'));
    $log = ['start' => $raus === $h('client_start')];
    $gesendet = '';
    $ereignisse = [];
    $eingang = $h('server_hello') . $h('server_handshake') . $h('server_hello_response') . $h('server_device_info') . $h('server_ping') . $h('server_unbekannt');
    foreach (str_split($eingang, $stueck) as $teil) {
        $e = $s->empfangen($teil);
        $gesendet .= $e['senden'];
        $ereignisse = array_merge($ereignisse, $e['ereignisse']);
        if ($umladen) {
            $s = ESPHomeHubSitzung::import($s->export());
        }
    }
    $log['gesendet'] = $gesendet === $h('client_hello') . $h('client_device_info_request') . $h('client_pong');
    $log['ereignisse'] = array_map(fn ($e) => $e[0], $ereignisse);
    $log['geraet'] = array_values(array_filter($ereignisse, fn ($e) => $e[0] === 'geraet'))[0][1] ?? [];
    $log['server'] = $s->server();
    $log['bereit'] = $s->bereit();
    $log['unbekannt'] = array_values(array_filter($ereignisse, fn ($e) => $e[0] === 'nachricht'))[0] ?? null;
    return $log;
};
foreach ([[4096, false], [1, false], [1, true], [5, true]] as [$stueck, $umladen]) {
    $l = $fahren($stueck, $umladen);
    $wie = "Stücke à $stueck Byte" . ($umladen ? ', Export/Import' : '');
    pruefe($l['start'], "ClientHello + Handshake-Nachricht 1 identisch ($wie)");
    pruefe($l['gesendet'], "HelloRequest, DeviceInfoRequest, PingResponse identisch verschlüsselt ($wie)");
    pruefe($l['ereignisse'] === ['hello', 'geraet', 'ping', 'nachricht'], "Ereignisfolge ($wie): " . implode(',', $l['ereignisse']));
    pruefe($l['bereit'] && ($l['geraet']['anzeigename'] ?? '') === 'Fixtur Gerät' && ($l['geraet']['hersteller'] ?? '') === 'Espressif', "DeviceInfo gelesen ($wie)");
    pruefe($l['server'] === ['name' => 'fixtur', 'mac' => 'AABBCCDDEEFF'], "ServerHello Name/MAC ($wie)");
    pruefe(($l['unbekannt'][1] ?? 0) === 25, "unbekannte Nachricht wird durchgereicht ($wie)");
}
try {
    $n = ESPHomeHubNoise::starten($h('e_init'));
    $n->nachricht1($psk);
    $n->nachricht2(substr($h('server_handshake'), 4));
    pruefe(bin2hex($n->hash()) === $fx['handshake_hash'], 'Handshake-Hash identisch');
} catch (Throwable $e) {
    pruefe(false, 'Handshake mit Referenz-Antwort: ' . $e->getMessage());
}

// ------------------------------------------------------------------ 3. Fehlerfälle
abschnitt('Fehlerfälle');
[$s] = ESPHomeHubSitzung::starten(str_repeat("\x01", 32), $fx['client'], $h('e_init'));
$e = $s->empfangen($h('server_hello') . $h('server_handshake'));
pruefe(($e['ereignisse'][0][1] ?? '') === 'schluessel' && $s->phase() === ESPHomeHubSitzung::AUS, 'falscher Schlüssel: Prüfsumme der Antwort passt nicht');
[$s] = ESPHomeHubSitzung::starten($psk, 'x');
$e = $s->empfangen($h('server_hello') . $P::noiseRahmen("\x01Handshake MAC failure"));
pruefe(($e['ereignisse'][0][1] ?? '') === 'schluessel', 'falscher Schlüssel: Gerät meldet „Handshake MAC failure"');
[$s] = ESPHomeHubSitzung::starten($psk, 'x');
$e = $s->empfangen($P::klartextRahmen(2, ''));
pruefe(($e['ereignisse'][0][1] ?? '') === 'unverschluesselt', 'Schlüssel eingetragen, Gerät unverschlüsselt');
[$s] = ESPHomeHubSitzung::starten(null, 'x');
$e = $s->empfangen($h('server_hello'));
pruefe(($e['ereignisse'][0][1] ?? '') === 'verschluesselt', 'kein Schlüssel, Gerät verlangt einen');
[$s] = ESPHomeHubSitzung::starten($psk, $fx['client'], $h('e_init'));
$s->empfangen($h('server_hello') . $h('server_handshake') . $h('server_hello_response'));
$kaputt = $h('server_device_info');
$kaputt[10] = chr(ord($kaputt[10]) ^ 1);
$e = $s->empfangen($kaputt);
pruefe(($e['ereignisse'][0][0] ?? '') === 'fehler', 'verfälschter Rahmen nach dem Handshake wird erkannt');

// ------------------------------------------------------------------ 4. Gerätemodul mit simuliertem Gerät
abschnitt('Gerätemodul gegen simuliertes Gerät (unverschlüsselt)');

/** Simuliertes ESPHome-Gerät ohne Verschlüsselung; antwortet in Stücken. */
final class SimGeraet
{
    public bool $offen = false;
    public array $empfangen = [];
    private string $puffer = '';
    public function oeffnen(string $host, int $port): bool { $this->offen = $host === 'sim.local'; return $this->offen; }
    public function schliessen(): void { $this->offen = false; $this->puffer = ''; }
    public function senden(string $bytes): void
    {
        $this->puffer .= $bytes;
        while (($r = ESPHomeHubProtokoll::rahmenLesen($this->puffer)) !== null) {
            $this->empfangen[] = $r[1];
            $antwort = match ($r[1]) {
                1 => ESPHomeHubProtokoll::klartextRahmen(2, ESPHomeHubProtokoll::feldZahl(1, 1) . ESPHomeHubProtokoll::feldZahl(2, 19) . ESPHomeHubProtokoll::feldText(4, 'sim')),
                9 => ESPHomeHubProtokoll::klartextRahmen(10, ESPHomeHubProtokoll::feldText(2, 'sim') . ESPHomeHubProtokoll::feldText(4, '2026.10.1') . ESPHomeHubProtokoll::feldText(6, 'esp32dev') . ESPHomeHubProtokoll::feldText(13, 'Simulator Küche')),
                default => '',
            };
            // Antwort absichtlich in 3-Byte-Stücken zurück (TCP darf beliebig zerlegen).
            $GLOBALS['ANTWORT'][] = $antwort;
        }
    }
}

function pumpen(): void
{
    while (($a = array_shift($GLOBALS['ANTWORT'])) !== null) {
        foreach (str_split($a, 3) as $teil) {
            if ($teil !== '') {
                empfangen($teil);
            }
        }
    }
}

$GLOBALS['ANTWORT'] = [];
$sim = new SimGeraet();
$GLOBALS['TRANSPORT'] = $sim;
$g = geraet();
$g->Create();
pruefe((zustand()['eltern'] ?? '') === '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}', 'Client Socket als Elternteil verlangt');
geraet()->ApplyChanges();
pruefe(zustand()['status'] === 104 && $GLOBALS['SOCKET']['Open'] === false, 'ohne Adresse: Status 104, Socket zu');

$GLOBALS['Z'][10000]['p']['Host'] = 'sim.local';
geraet()->ApplyChanges();
pumpen();
pruefe($GLOBALS['SOCKET']['Open'] === true && $GLOBALS['SOCKET']['Host'] === 'sim.local' && $GLOBALS['SOCKET']['Port'] === 6053, 'Socket auf Adresse und Port 6053 gestellt');
pruefe($GLOBALS['SOCKET']['name'] === 'ESPHome-Verbindung (Wohnzimmer)', 'Socket sprechend benannt');
pruefe($sim->empfangen === [1, 9], 'Hello und DeviceInfoRequest gesendet');
pruefe(zustand()['status'] === 102 && zustand()['v']['Verbunden']['wert'] === true, 'verbunden: Status 102, Variable Verbunden = true');
$info = json_decode(geraet()->GetDeviceInfo(), true);
pruefe(($info['anzeigename'] ?? '') === 'Simulator Küche' && ($info['modell'] ?? '') === 'esp32dev', 'GetDeviceInfo liefert die Geräteinfo');

$GLOBALS['ANTWORT'][] = ESPHomeHubProtokoll::klartextRahmen(7, '');
pumpen();
pruefe(end($sim->empfangen) === 8, 'Ping des Geräts beantwortet');

$GLOBALS['ANTWORT'][] = ESPHomeHubProtokoll::klartextRahmen(5, '');
pumpen();
pruefe(end($sim->empfangen) === 6 && zustand()['v']['Verbunden']['wert'] === false, 'Gerät trennt: DisconnectResponse, Verbunden = false');
$GLOBALS['Z'][10000]['a']['Verbindung'] = json_encode(['zustand' => 'getrennt', 'seit' => time() - 60]);
$sim->empfangen = [];
geraet()->Wache();
pumpen();
pruefe($sim->empfangen === [1, 9] && zustand()['v']['Verbunden']['wert'] === true, 'Wache verbindet nach Trennung neu');

$GLOBALS['Z'][10000]['b']['LetzterEmpfang'] = (string)(time() - 200);
$sim->empfangen = [];
geraet()->Wache();
pumpen();
pruefe($sim->empfangen === [1, 9], 'Wache verbindet nach 150 s Stille neu');
pruefe(count(array_filter($GLOBALS['LOG'], fn ($l) => str_contains($l[1], 'Keine Antwort'))) === 1, 'Stille wird protokolliert');

// Gerät antwortet verschlüsselt, obwohl kein Schlüssel eingetragen ist
$GLOBALS['ANTWORT'][] = $h('server_hello');
geraet()->Neuverbinden();
$sim->empfangen = [];
pumpen();
pruefe(zustand()['status'] === 202 && $GLOBALS['SOCKET']['Open'] === false && zustand()['a']['Gesperrt'] === true, 'Gerät verlangt Schlüssel: Status 202, Socket zu, gesperrt');
geraet()->Wache();
pruefe($GLOBALS['SOCKET']['Open'] === false, 'gesperrt: Wache verbindet nicht neu');

$GLOBALS['Z'][10000]['p']['Schluessel'] = 'kein-base64';
geraet()->ApplyChanges();
pruefe(zustand()['status'] === 201 && $GLOBALS['SOCKET']['Open'] === false, 'ungültiger Schlüssel: Status 201, keine Verbindung');

$GLOBALS['Z'][10000]['p']['Schluessel'] = '';
geraet()->ApplyChanges();
pumpen();
pruefe(zustand()['status'] === 102 && zustand()['a']['Gesperrt'] === false, 'Übernehmen hebt die Sperre auf');

$GLOBALS['Z'][10000]['p']['Host'] = 'weg.local';
geraet()->ApplyChanges();
pruefe(zustand()['status'] === 203, 'nicht erreichbar: Status 203');

// ------------------------------------------------------------------ 5. Store-Regeln
abschnitt('Store-Regeln');
$GLOBALS['Z'][10000]['p']['Host'] = 'sim.local';
$form = json_decode((string)geraet()->GetConfigurationForm(), true);
pruefe(is_array($form) && isset($form['elements'], $form['status']), 'Formular ist gültiges JSON');
$codes = array_column($form['status'] ?? [], 'code');
$quelle = (string)file_get_contents($ROOT . '/ESPHomeHubGeraet/module.php');
preg_match_all('/STATUS_[A-Z_]+ = (\d+)/', $quelle, $tr);
foreach ($tr[1] as $c) {
    pruefe(in_array((int)$c, $codes, true), "Statuscode $c im Formular");
}
$namen = [];
array_walk_recursive($form['elements'], function ($v, $k) use (&$namen) {
    if ($k === 'name') {
        $namen[] = $v;
    }
});
foreach (['Host', 'Port', 'Schluessel'] as $prop) {
    pruefe(in_array($prop, $namen, true), "Property $prop im Formular");
}
$alle = '';
foreach (glob($ROOT . '/{libs,ESPHomeHub*}/*.php', GLOB_BRACE) as $datei) {
    $alle .= file_get_contents($datei);
}
pruefe(!str_contains($alle, 'IPS_LogMessage('), 'kein IPS_LogMessage');
pruefe(preg_match('/public function [A-Z]\w*\([^)]*=/', $alle) === 0, 'keine Standardwerte an öffentlichen Methoden');
foreach (zustand()['v'] as $ident => $var) {
    foreach (json_decode($var['pres']['OPTIONS'] ?? '[]', true) as $opt) {
        $erlaubt = ['Value', 'Caption', 'IconActive', 'IconValue', 'ColorActive', 'ColorValue'];
        pruefe(array_diff(array_keys($opt), $erlaubt) === [], "Wertanzeige-OPTIONS $ident nur erlaubte Schlüssel");
    }
    pruefe(!isset($var['pres']['OPTIONS']) || $var['typ'] === VARIABLETYPE_BOOLEAN || $var['typ'] === 3, "OPTIONS nur an Bool/String ($ident)");
}
$lib = json_decode((string)file_get_contents($ROOT . '/library.json'), true);
$mod = json_decode((string)file_get_contents($ROOT . '/ESPHomeHubGeraet/module.json'), true);
pruefe(array_keys($lib) === ['id', 'author', 'name', 'url', 'compatibility', 'version', 'build', 'date'], 'library.json nur erlaubte Felder');
pruefe($mod['name'] === 'ESPHomeHubGeraet' && count($mod['aliases']) === 1 && $mod['vendor'] === 'ESPHome', 'module.json: Klasse, ein Alias, vendor');
pruefe(!preg_match('/symcon|ips/i', $lib['name'] . ' ' . $mod['aliases'][0]), 'kein Symcon/IPS im Namen');

// ------------------------------------------------------------------ 6. Optional: echtes Gerät
$live = getenv('ESPHOMEHUB_LIVE');
if ($live) {
    abschnitt('Echtes Gerät über TCP (zufällige Stücke)');

    final class TcpTransport
    {
        private $sock = null;
        public function oeffnen(string $host, int $port): bool
        {
            $this->sock = @stream_socket_client("tcp://$host:$port", $en, $es, 3) ?: null;
            if ($this->sock) {
                stream_set_blocking($this->sock, false);
            }
            return $this->sock !== null;
        }
        public function schliessen(): void { if ($this->sock) { fclose($this->sock); } $this->sock = null; }
        public function senden(string $b): void { if ($this->sock) { fwrite($this->sock, $b); } }
        public function pumpen(float $sek): void
        {
            $ende = microtime(true) + $sek;
            while (microtime(true) < $ende && $this->sock) {
                $r = [$this->sock]; $w = $e = null;
                if (@stream_select($r, $w, $e, 0, 100000) > 0) {
                    $d = fread($this->sock, 8192);
                    if ($d === '' && feof($this->sock)) {
                        $this->schliessen();
                        $GLOBALS['SOCKET']['status'] = 200;
                        geraet()->MessageSink(0, 20000, IM_CHANGESTATUS, [200, 0]);
                        return;
                    }
                    while ($d !== '') {
                        $n = random_int(1, 7);
                        empfangen(substr($d, 0, $n));
                        $d = (string)substr($d, $n);
                    }
                }
            }
        }
    }

    foreach (explode(',', $live) as $ziel) {
        [$host, $port, $key] = explode(':', $ziel, 3) + ['', '6053', ''];
        $t = new TcpTransport();
        $GLOBALS['TRANSPORT'] = $t;
        $GLOBALS['Z'][10000]['p'] = ['Host' => $host, 'Port' => (int)$port, 'Schluessel' => $key];
        geraet()->ApplyChanges();
        $t->pumpen(1.5);
        $info = json_decode(geraet()->GetDeviceInfo(), true);
        $wie = "$host:$port " . ($key === '' ? 'ohne Schlüssel' : 'mit Schlüssel');
        pruefe(zustand()['status'] === 102, "$wie: verbunden (Status " . zustand()['status'] . ')');
        echo "    Gerät: " . ($info['anzeigename'] ?? '?') . ' · ESPHome ' . ($info['version'] ?? '?') . ' · ' . ($info['modell'] ?? '?') . "\n";
        if ($key !== '') {
            $GLOBALS['Z'][10000]['p']['Schluessel'] = base64_encode(random_bytes(32));
            geraet()->ApplyChanges();
            $t->pumpen(1.5);
            pruefe(zustand()['status'] === 201, "$wie: falscher Schlüssel → Status 201 (" . zustand()['status'] . ')');
        }
        $t->schliessen();
    }
}

echo "\n" . ($FEHLER === 0 ? "✅ alle $OK Prüfungen bestanden" : "❌ $FEHLER von " . ($OK + $FEHLER) . ' Prüfungen fehlgeschlagen') . "\n";
exit($FEHLER === 0 ? 0 : 1);
