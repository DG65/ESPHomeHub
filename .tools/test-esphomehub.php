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
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;
const VARIABLE_PRESENTATION_VALUE_PRESENTATION = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';
const VARIABLE_PRESENTATION_SWITCH = '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}';
const VARIABLE_PRESENTATION_SLIDER = '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}';
const VARIABLE_PRESENTATION_ENUMERATION = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';
const VARIABLE_PRESENTATION_VALUE_INPUT = '{6F477326-1683-A2FD-D2E7-477F366ECB62}';
const VARIABLE_PRESENTATION_COLOR = '{05CC3CC2-A0B2-5837-A4A7-A07EA0B9DDFB}';

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
    protected function UpdateFormField($f, $p, $v) { $GLOBALS['FORMFELD'][$f][$p] = $v; }
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
        $GLOBALS['MAINTAIN'][] = $ident;
        if ($behalten) {
            $this->z()['v'][$ident] ??= ['typ' => $typ, 'wert' => false];
            $this->z()['v'][$ident]['pres'] = $pres;
            $this->z()['v'][$ident]['symcon'] = symconUmformen($pres);
        } else {
            unset($this->z()['v'][$ident]);
        }
    }
    protected function GetIDForIdent($ident) { $GLOBALS['ZULETZT'] = $ident; return isset($this->z()['v'][$ident]) ? 30000 : false; }
    protected function GetValue($ident) { return $this->z()['v'][$ident]['wert']; }
    protected function EnableAction($ident) { $this->z()['v'][$ident]['aktion'] = true; }
    protected function SetValue($ident, $w) { $this->z()['v'][$ident]['wert'] = $w; $GLOBALS['GESETZT'][] = $ident; }
}

function IPS_GetKernelRunlevel() { return KR_READY; }
$GLOBALS['NAME'] = 'Wohnzimmer';
$GLOBALS['VERBUNDEN_MIT'] = 20000;
function IPS_GetName($id) { return $id === 20000 ? $GLOBALS['SOCKET']['name'] : $GLOBALS['NAME']; }
function IPS_SetName($id, $n) { if ($id === 20000) { $GLOBALS['SOCKET']['name'] = $n; } else { $GLOBALS['UMBENANNT'] = $n; } }
function IPS_CreateInstance($g) { $GLOBALS['TRANSPORT']?->schliessen(); $GLOBALS['SOCKET'] = ['id' => 20000, 'Host' => '', 'Port' => 0, 'Open' => false, 'status' => IS_INACTIVE, 'name' => '']; $GLOBALS['ANGELEGT'] = ($GLOBALS['ANGELEGT'] ?? 0) + 1; return 20000; }
function IPS_ConnectInstance($id, $eltern) { $GLOBALS['VERBUNDEN_MIT'] = $eltern; return true; }
function IPS_DeleteInstance($id) { return true; }
function IPS_InstanceExists($id) { return empty($GLOBALS['GELOESCHT']); }
function IPS_GetLibrary($g) { return ['Version' => '0.1.0', 'Build' => 1]; }
function IPS_SemaphoreEnter($n, $t) { return true; }
function IPS_SemaphoreLeave($n) { return true; }
function IPS_GetInstance($id)
{
    if ($id === 20000) {
        return ['ModuleInfo' => ['ModuleID' => '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}'], 'InstanceStatus' => $GLOBALS['SOCKET']['status']];
    }
    return ['ConnectionID' => $GLOBALS['VERBUNDEN_MIT']];
}
function IPS_GetProperty($id, $k) { return $id === 10000 ? $GLOBALS['Z'][10000]['p'][$k] : $GLOBALS['SOCKET'][$k]; }
// DNS-SD-Ersatz im Format, das Symcon 9.0 live liefert (28.10.2026)
$GLOBALS['ZC'] = [];
$GLOBALS['DNSSD'] = [10244];
function IPS_GetInstanceListByModuleID($g)
{
    return match ($g) {
        '{780B2D48-916C-4D59-AD35-5A429B2355A5}' => $GLOBALS['DNSSD'],
        '{796D9D82-B2F7-4F80-BAA7-8CC50237B2EF}' => isset($GLOBALS['Z'][10000]['p']['Host']) ? [10000] : [],
        default => [],
    };
}
function ZC_QueryServiceType($id, $typ, $domain) { return array_map(fn ($d) => ['Name' => $d['Name'], 'Type' => '_esphomelib._tcp', 'Domain' => 'local'], $GLOBALS['ZC']); }
function ZC_QueryService($id, $name, $typ, $domain)
{
    foreach ($GLOBALS['ZC'] as $d) {
        if ($d['Name'] === $name) {
            return [['Name' => "$name._esphomelib._tcp.local", 'Host' => $d['Host'], 'Port' => $d['Port'], 'TXTRecords' => $d['TXT'], 'IPv4' => $d['IPv4'], 'IPv6' => []]];
        }
    }
    return [];
}
function ESPH_GetDeviceInfo($id) { return geraet()->GetDeviceInfo(); }
// Variablen-Ersatz: ID 30000+n je Ident (GetIDForIdent liefert 30000 für jede vorhandene Variable).
/** So gibt Symcon 9.0 eine Darstellung zurück (live 28.10.2026): ganze Kommazahlen als int, OPTIONS mit
 *  sortierten Schlüsseln und unmaskierten Umlauten, dazu weitere Standardschlüssel. */
function symconUmformen(array $pres): array
{
    $out = ['USAGE_TYPE' => 0, 'ICON' => ''];
    foreach ($pres as $k => $w) {
        if (is_float($w) && floor($w) == $w) {
            $w = (int)$w;
        }
        if ($k === 'OPTIONS') {
            $o = json_decode($w, true);
            foreach ($o as &$e) {
                ksort($e);
            }
            $w = json_encode($o, JSON_UNESCAPED_UNICODE);
        }
        $out[$k] = $w;
    }
    return $out;
}
function IPS_GetVariablePresentation($id) { return $GLOBALS['Z'][10000]['v'][$GLOBALS['ZULETZT']]['symcon'] ?? []; }
function IPS_GetVariable($id) { return ['VariableType' => $GLOBALS['Z'][10000]['v'][$GLOBALS['ZULETZT']]['typ']]; }
function IPS_GetObject($id) { return ['ObjectPosition' => 0]; }
function IPS_SetPosition($id, $p) { return true; }
function GetValueFormatted($id) { return '…'; }
function IPS_SetProperty($id, $k, $v) { $GLOBALS['SOCKET'][$k] = $v; }
function IPS_SetConfiguration($id, $json) { foreach (json_decode($json, true) as $k => $v) { $GLOBALS['SOCKET'][$k] = $v; } }
function IPS_ApplyChanges($id)
{
    $GLOBALS['SOCKET_APPLY'] = ($GLOBALS['SOCKET_APPLY'] ?? 0) + 1;
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
require_once $ROOT . '/ESPHomeHubKonfigurator/module.php';
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
    $eingang = $h('server_hello') . $h('server_handshake') . $h('server_hello_response') . $h('server_device_info') . $h('server_liste') . $h('server_zustand') . $h('server_ping') . $h('server_unbekannt');
    foreach (str_split($eingang, $stueck) as $teil) {
        $e = $s->empfangen($teil);
        $gesendet .= $e['senden'];
        $ereignisse = array_merge($ereignisse, $e['ereignisse']);
        if ($umladen) {
            $s = ESPHomeHubSitzung::import($s->export());
        }
    }
    $log['gesendet'] = $gesendet === $h('client_hello') . $h('client_device_info_request') . $h('client_list_entities') . $h('client_abo') . $h('client_pong');
    $log['ereignisse'] = array_map(fn ($e) => $e[0], $ereignisse);
    $log['geraet'] = array_values(array_filter($ereignisse, fn ($e) => $e[0] === 'geraet'))[0][1] ?? [];
    $log['server'] = $s->server();
    $log['bereit'] = $s->bereit();
    $log['nachrichten'] = array_map(fn ($e) => $e[1], array_values(array_filter($ereignisse, fn ($e) => $e[0] === 'nachricht')));
    return $log;
};
foreach ([[4096, false], [1, false], [1, true], [5, true]] as [$stueck, $umladen]) {
    $l = $fahren($stueck, $umladen);
    $wie = "Stücke à $stueck Byte" . ($umladen ? ', Export/Import' : '');
    pruefe($l['start'], "ClientHello + Handshake-Nachricht 1 identisch ($wie)");
    pruefe($l['gesendet'], "Hello, DeviceInfo, ListEntities, SubscribeStates, PingResponse identisch verschlüsselt ($wie)");
    pruefe($l['ereignisse'] === ['hello', 'geraet', 'nachricht', 'nachricht', 'entitaetenFertig', 'nachricht', 'ping', 'nachricht'], "Ereignisfolge ($wie): " . implode(',', $l['ereignisse']));
    pruefe($l['bereit'] && ($l['geraet']['anzeigename'] ?? '') === 'Fixtur Gerät' && ($l['geraet']['hersteller'] ?? '') === 'Espressif', "DeviceInfo gelesen ($wie)");
    pruefe($l['server'] === ['name' => 'fixtur', 'mac' => 'AABBCCDDEEFF'], "ServerHello Name/MAC ($wie)");
    pruefe($l['nachrichten'] === [16, 17, 25, 250], "Liste, Zustand und unbekannte Nachricht werden durchgereicht ($wie)");
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

// ------------------------------------------------------------------ 3b. Entitäten gegen api_pb2
abschnitt('Entitäten gegen aioesphomeapi api_pb2 (Beschreibung, Zustand, Befehl)');
$fe = json_decode((string)file_get_contents(__DIR__ . '/fixture-entitaeten.json'), true);
$ents = [];
foreach ($fe['liste'] as $l) {
    $e = ESPHomeHubEntitaeten::beschreiben($l['typ'], (string)hex2bin($l['daten']));
    if ($e !== null) {
        $ents[$e['key']] = $e;
        pruefe($e['name'] === $l['name'], 'Name gelesen: ' . $l['name']);
        pruefe(ESPHomeHubEntitaeten::variablen($e, 'Gerät') !== [], 'Variablen für ' . $e['typ']);
    } else {
        pruefe(isset(ESPHomeHubEntitaeten::NICHT_UNTERSTUETZT[$l['typ']]), 'nur bekannte Typen übergangen: ' . $l['typ']);
    }
}
pruefe(count($ents) === 17, '17 von 18 Entitäten beschrieben');
foreach ($fe['zustaende'] as $z) {
    $ist = ESPHomeHubEntitaeten::zustand($z['typ'], (string)hex2bin($z['daten']), $ents[$z['key']]);
    $ok = count($ist) === count($z['erwartet']);
    foreach ($z['erwartet'] as $k => $w) {
        $ok = $ok && array_key_exists($k, $ist) && (is_float($w) || is_float($ist[$k]) ? abs($ist[$k] - $w) < 1e-6 : $ist[$k] === $w);
    }
    pruefe($ok, 'Zustand ' . $ents[$z['key']]['typ'] . ' ' . json_encode($z['erwartet'], JSON_UNESCAPED_UNICODE) . ' → ' . json_encode($ist, JSON_UNESCAPED_UNICODE));
}
foreach ($fe['befehle'] as $c) {
    $e = $ents[$c['key']];
    [$typ, $daten] = ESPHomeHubEntitaeten::befehl($e, $c['teil'], $c['wert']);
    pruefe($typ === $c['typ'] && bin2hex($daten) === $c['daten'], 'Befehl ' . $e['typ'] . '/' . $c['teil'] . ' = ' . json_encode($c['wert']) . ': ' . bin2hex($daten) . ' erwartet ' . $c['daten']);
}
$id = ESPHomeHubEntitaeten::ident(0xDEADBEEF, 'h');
pruefe($id === 'edeadbeef_h' && ESPHomeHubEntitaeten::identLesen($id) === [0xDEADBEEF, 'h'] && ESPHomeHubEntitaeten::identLesen('Verbunden') === null, 'Ident hin und zurück');

// ------------------------------------------------------------------ 3c. Zeitzone gegen aioesphomeapi
abschnitt('Zeitzone (GetTimeResponse) gegen aioesphomeapi');
$fz = json_decode((string)file_get_contents(__DIR__ . '/fixture-zeitzone.json'), true);
foreach ($fz['faelle'] as $f) {
    $ist = bin2hex(ESPHomeHubZeitzone::getTimeResponse($fz['epoche'], $f['posix']));
    pruefe($ist === $f['bytes'], 'GetTimeResponse „' . $f['posix'] . '": ' . $ist . ' erwartet ' . $f['bytes']);
    if ($f['iana'] !== '' && is_file('/usr/share/zoneinfo/' . $f['iana'])) {
        $p = ESPHomeHubZeitzone::posix($f['iana']);
        pruefe($p === $f['posix'], 'POSIX aus zoneinfo ' . $f['iana'] . ': ' . $p);
    }
    if (in_array($f['iana'], ['Europe/Berlin', 'Europe/London', 'America/New_York', 'Asia/Kolkata', 'America/Sao_Paulo', 'Australia/Lord_Howe', 'Pacific/Chatham'], true)) {
        $p = ESPHomeHubZeitzone::posixAusPhp($f['iana'], 2026);
        pruefe($p === $f['posix'], 'POSIX ohne zoneinfo (aus PHP) ' . $f['iana'] . ': ' . $p . ' erwartet ' . $f['posix']);
    }
}
[$s] = ESPHomeHubSitzung::starten(null, 'x');
$s->empfangen(ESPHomeHubProtokoll::klartextRahmen(2, '') . ESPHomeHubProtokoll::klartextRahmen(10, ''));
$s->setzeZeitzone('CET-1CEST,M3.5.0,M10.5.0/3');
$s = ESPHomeHubSitzung::import($s->export());
$e = $s->empfangen(ESPHomeHubProtokoll::klartextRahmen(36, ''));
$puffer = $e['senden'];
$r = ESPHomeHubProtokoll::rahmenLesen($puffer);
$m = ESPHomeHubProtokoll::zerlegen($r[2] ?? '');
pruefe(($r[1] ?? 0) === 37 && abs(ESPHomeHubProtokoll::fixed32($m, 1) - time()) < 5 && ESPHomeHubProtokoll::text($m, 2) === 'CET-1CEST,M3.5.0,M10.5.0/3' && isset($m[3]), 'Sitzung beantwortet GetTimeRequest mit Uhrzeit und Zeitzone (übersteht Export/Import)');

// ------------------------------------------------------------------ 4. Gerätemodul mit simuliertem Gerät
abschnitt('Gerätemodul gegen simuliertes Gerät (unverschlüsselt)');

/** Simuliertes ESPHome-Gerät ohne Verschlüsselung; antwortet in Stücken. */
final class SimGeraet
{
    public bool $offen = false;
    public array $empfangen = [];
    public string $clientInfo = '';
    public array $daten = [];
    private string $puffer = '';
    public function oeffnen(string $host, int $port): bool { $this->offen = $host === 'sim.local'; return $this->offen; }
    public function schliessen(): void { $this->offen = false; $this->puffer = ''; }
    public function senden(string $bytes): void
    {
        $this->puffer .= $bytes;
        while (($r = ESPHomeHubProtokoll::rahmenLesen($this->puffer)) !== null) {
            $this->empfangen[] = $r[1];
            $this->daten[] = $r[2];
            if ($r[1] === 1) {
                $this->clientInfo = ESPHomeHubProtokoll::text(ESPHomeHubProtokoll::zerlegen($r[2]), 1);
            }
            $antwort = match ($r[1]) {
                1 => ESPHomeHubProtokoll::klartextRahmen(2, ESPHomeHubProtokoll::feldZahl(1, 1) . ESPHomeHubProtokoll::feldZahl(2, 19) . ESPHomeHubProtokoll::feldText(4, 'sim')),
                9 => ESPHomeHubProtokoll::klartextRahmen(10, ESPHomeHubProtokoll::feldText(2, 'sim') . ESPHomeHubProtokoll::feldText(3, 'AA:BB:CC:00:00:01') . ESPHomeHubProtokoll::feldText(4, '2026.10.1') . ESPHomeHubProtokoll::feldText(6, 'esp32dev') . ESPHomeHubProtokoll::feldText(13, 'Simulator Küche')),
                11 => $GLOBALS['SIM_LISTE'],
                20 => $GLOBALS['SIM_ZUSTAENDE'],
                33 => ESPHomeHubProtokoll::klartextRahmen(26, $r[2]),   // Schalter: Gerät meldet den neuen Zustand zurück
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

// Das simulierte Gerät meldet alle Entitäten und Zustände aus der api_pb2-Referenz (fixture-entitaeten.json).
$fe = json_decode((string)file_get_contents(__DIR__ . '/fixture-entitaeten.json'), true);
$GLOBALS['SIM_LISTE'] = implode('', array_map(fn ($l) => ESPHomeHubProtokoll::klartextRahmen($l['typ'], (string)hex2bin($l['daten'])), $fe['liste'])) . ESPHomeHubProtokoll::klartextRahmen(19, '');
$GLOBALS['SIM_ZUSTAENDE'] = implode('', array_map(fn ($z) => ESPHomeHubProtokoll::klartextRahmen($z['typ'], (string)hex2bin($z['daten'])), $fe['zustaende']));
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
pruefe($sim->empfangen === [1, 9, 11, 20], 'Hello, DeviceInfo, ListEntities, SubscribeStates gesendet');
pruefe(zustand()['status'] === 102 && zustand()['v']['Verbunden']['wert'] === true, 'verbunden: Status 102, Variable Verbunden = true');
$info = json_decode(geraet()->GetDeviceInfo(), true);
pruefe(($info['anzeigename'] ?? '') === 'Simulator Küche' && ($info['modell'] ?? '') === 'esp32dev', 'GetDeviceInfo liefert die Geräteinfo');

// Entitäten → Variablen
$v = zustand()['v'];
pruefe(count(json_decode(geraet()->GetEntities(), true)) === 17, '17 unterstützte Entitäten übernommen (Sirene übergangen)');
pruefe(str_contains(zustand()['a']['NichtUnterstuetzt'], 'siren'), 'Sirene als nicht unterstützt vermerkt');
pruefe(($v['e00000005']['pres']['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_SWITCH && ($v['e00000005']['aktion'] ?? false), 'Schalter: SWITCH mit Aktion');
pruefe(($v['e00000001']['pres']['SUFFIX'] ?? '') === ' °C' && ($v['e00000001']['pres']['DIGITS'] ?? -1) === 1 && !isset($v['e00000001']['aktion']), 'Sensor: Einheit, Nachkommastellen, keine Aktion');
pruefe(($v['e00000002']['pres']['DIGITS'] ?? -1) === 0, 'negative accuracy_decimals → 0 Stellen');
pruefe(isset($v['e0000000a_h'], $v['e0000000a_f'], $v['e0000000a_k']) && ($v['e0000000a_k']['pres']['MIN'] ?? 0) === 2000 && ($v['e0000000a_k']['pres']['MAX'] ?? 0) === 6536, 'Licht RGB+Farbtemperatur: Helligkeit, Farbe, Kelvin 2000–6536');
pruefe(isset($v['e0000000b']) && !isset($v['e0000000b_h']), 'Licht nur an/aus: keine Helligkeit');
pruefe(($v['e0000000c']['pres']['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_SLIDER && isset($v['e0000000c_s'], $v['e0000000c_z']), 'Rollladen: Position, Steuerung, Bewegung');
pruefe(($v['e0000000d']['typ'] ?? -1) === VARIABLETYPE_BOOLEAN, 'Ventil ohne Position: offen/geschlossen');
pruefe(isset($v['e00000010_m'], $v['e00000010'], $v['e00000010_t'], $v['e00000010_a']), 'Klima: Betriebsart, Soll, Ist, Tätigkeit');
pruefe(count(json_decode($v['e00000010_m']['pres']['OPTIONS'], true)) === 3, 'Klima: nur unterstützte Betriebsarten');

// Zustände
pruefe($v['e00000001']['wert'] === 21.5 && $v['e00000005']['wert'] === true && $v['e00000008']['wert'] === 'Komfort', 'Zustände: Sensor gerundet, Schalter, Auswahl');
pruefe($v['e0000000a_f']['wert'] === 0xFF8000 && $v['e0000000a_h']['wert'] === 60 && $v['e0000000a_k']['wert'] === 4000, 'Zustände: Lichtfarbe, Helligkeit, Kelvin');
pruefe($v['e0000000f']['wert'] === true && $v['e0000000f_z']['wert'] === 'Blockiert', 'Schloss: blockiert ändert „verriegelt" nicht');
pruefe($v['e00000010_t']['wert'] === 20.3 && $v['e00000010_a']['wert'] === 'Heizt', 'Klima: Ist-Temperatur und Tätigkeit');

// RequestAction → Befehl, Variable folgt der Rückmeldung
$sim->empfangen = [];
$sim->daten = [];
geraet()->RequestAction('e00000005', false);
pumpen();
pruefe($sim->empfangen === [33] && bin2hex($sim->daten[0]) === $fe['befehle'][1]['daten'], 'Schalter aus: SwitchCommandRequest wie api_pb2');
pruefe(zustand()['v']['e00000005']['wert'] === false, 'Schalter folgt der Rückmeldung des Geräts');
$sim->empfangen = [];
geraet()->RequestAction('e00000006', 0);
pruefe($sim->empfangen === [62] && zustand()['v']['e00000006']['wert'] === 0, 'Knopf: ButtonCommandRequest, Wert sofort');
$GLOBALS['GESETZT'] = [];
geraet()->RequestAction('e00000006', 0);
pruefe($GLOBALS['GESETZT'] === ['e00000006'], 'zweiter Knopfdruck aktualisiert die Variable trotz gleichem Wert');
$fehler = function (string $ident, $wert): string {
    try {
        geraet()->RequestAction($ident, $wert);
        return '';
    } catch (Exception $e) {
        return $e->getMessage();
    }
};
pruefe(str_contains($fehler('e000000ff', 1), 'Unbekannte Variable'), 'unbekannte Variable → Fehlermeldung');
pruefe(str_contains($fehler('e00000008', 'Turbo'), 'keine Option'), 'ungültige Auswahl → Fehlermeldung');
pruefe(str_contains($fehler('e00000007', 99.0), 'außerhalb'), 'Zahl außerhalb der Grenzen → Fehlermeldung');
pruefe(str_contains($fehler('e00000001', 3), 'nicht schalten'), 'Sensor lässt sich nicht schalten');

// Neu verbinden: dieselben Entitäten → keine Darstellung neu schreiben (kein Update-Sturm)
$GLOBALS['MAINTAIN'] = [];
geraet()->Neuverbinden();
pumpen();
pruefe(($GLOBALS['MAINTAIN'] ?? []) === [] && zustand()['status'] === 102, 'zweite Verbindung: keine Variable neu geschrieben');

// ApplyChanges ohne Änderung (Symcon-Start, Modul-Update): Socket nicht anfassen (live 28.10.2026: SIGABRT nach
// mehrfachem Schließen/Öffnen innerhalb einer Sekunde)
$GLOBALS['SOCKET_APPLY'] = 0;
$sim->empfangen = [];
geraet()->ApplyChanges();
pumpen();
pruefe($GLOBALS['SOCKET_APPLY'] === 0 && $sim->empfangen === [] && zustand()['status'] === 102, 'unverändertes Übernehmen: Socket und Sitzung bleiben');
$GLOBALS['Z'][10000]['b']['Sitzung'] = '';   // z. B. nach Modul-Update ohne Puffer
geraet()->ApplyChanges();
pumpen();
pruefe($GLOBALS['SOCKET_APPLY'] === 0 && $sim->empfangen === [1, 9, 11, 20] && zustand()['status'] === 102, 'ohne Sitzung, Socket offen: nur neue API-Sitzung, kein Socket-Wechsel');
$GLOBALS['Z'][10000]['p']['Port'] = 6054;
$GLOBALS['Z'][10000]['p']['Port'] = 6053;
$GLOBALS['Z'][10000]['a']['Angewendet'] = 'anders';
$sim->empfangen = [];
geraet()->ApplyChanges();
pumpen();
pruefe($GLOBALS['SOCKET_APPLY'] >= 1 && $sim->empfangen === [1, 9, 11, 20], 'geänderte Adresse/Schlüssel: Socket wird neu aufgebaut');

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
pruefe($sim->empfangen === [1, 9, 11, 20] && zustand()['v']['Verbunden']['wert'] === true, 'Wache verbindet nach Trennung neu');

$GLOBALS['Z'][10000]['b']['LetzterEmpfang'] = (string)(time() - 200);
$sim->empfangen = [];
geraet()->Wache();
pumpen();
pruefe($sim->empfangen === [1, 9, 11, 20], 'Wache verbindet nach 150 s Stille neu');
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

// Instanz heißt wie ihre Adresse (vom Konfigurator per Hand-Adresse angelegt) → nach dem Verbinden Anzeigename
$GLOBALS['Z'][10000]['p']['Host'] = 'sim.local';
$GLOBALS['NAME'] = 'sim.local';
$GLOBALS['UMBENANNT'] = null;
geraet()->Neuverbinden();
pumpen();
pruefe($GLOBALS['UMBENANNT'] === 'Simulator Küche', 'Instanz mit Adresse als Name bekommt den Anzeigenamen des Geräts');
$GLOBALS['NAME'] = 'Wohnzimmer';
$GLOBALS['UMBENANNT'] = null;
geraet()->Neuverbinden();
pumpen();
pruefe($GLOBALS['UMBENANNT'] === null, 'selbst gewählter Instanzname bleibt');

// Langer Instanzname mit Umlaut an der Schnittgrenze: Gerät kürzt client_info auf 31 Byte (live 28.10.2026)
$GLOBALS['NAME'] = 'ESPHome-Test (Claude, löschen)';
$GLOBALS['Z'][10000]['p']['Host'] = 'sim.local';
geraet()->ApplyChanges();
pumpen();
pruefe(strlen($sim->clientInfo) <= 31 && mb_check_encoding($sim->clientInfo, 'UTF-8') && $sim->clientInfo === 'Symcon ESPHome-Test (Claude, l', 'client_info ≤ 31 Byte, an der Zeichengrenze gekürzt: ' . $sim->clientInfo);

// Instanz per Skript angelegt: kein Client Socket → Wache legt ihn an, benennt ihn, verbindet
$GLOBALS['NAME'] = 'Flur';
$GLOBALS['VERBUNDEN_MIT'] = 0;
$sim->empfangen = [];
geraet()->Wache();
pumpen();
pruefe(($GLOBALS['ANGELEGT'] ?? 0) === 1 && $GLOBALS['VERBUNDEN_MIT'] === 20000, 'fehlender Client Socket wird angelegt und verbunden');
pruefe($GLOBALS['SOCKET']['name'] === 'ESPHome-Verbindung (Flur)', 'namenloser Socket wird benannt');
pruefe($sim->empfangen === [1, 9, 11, 20] && zustand()['status'] === 102, 'danach verbunden');
geraet()->Wache();
pruefe(($GLOBALS['ANGELEGT'] ?? 0) === 1, 'vorhandener Socket: kein zweiter');

// Gerät gelöscht, der Socket meldet danach noch eine Statusänderung (live 28.10.2026: Warnung „Instanz existiert nicht")
$GLOBALS['GELOESCHT'] = true;
$vorher = zustand()['status'];
try {
    geraet()->MessageSink(0, 20000, IM_CHANGESTATUS, [200, 0]);
    $still = zustand()['status'] === $vorher;
} catch (Throwable $e) {
    $still = false;
}
$GLOBALS['GELOESCHT'] = false;
pruefe($still, 'Statusmeldung nach dem Löschen wird ignoriert');

// ------------------------------------------------------------------ 4b. Konfigurator
abschnitt('Konfigurator (DNS-SD wie Symcon 9.0)');
$GLOBALS['Z'][10000]['p']['Host'] = '192.168.2.50';   // Instanz mit alter IP, Gerät ist umgezogen
$GLOBALS['ZC'] = [
    ['Name' => 'wohnzimmer', 'Host' => 'wohnzimmer.local.', 'Port' => 6053, 'IPv4' => ['192.168.2.77'],
     'TXT' => ['friendly_name=Wohnzimmer', 'version=2026.10.1', 'mac=aabbcc000001', 'platform=ESP32', 'board=esp32dev', 'network=wifi', 'api_encryption=Noise_NNpsk0_25519_ChaChaPoly_SHA256']],
    ['Name' => 'kueche', 'Host' => 'kueche.local', 'Port' => 6053, 'IPv4' => ['192.168.2.78'],
     'TXT' => ['friendly_name=Küche', 'version=2025.12.0', 'mac=aabbcc000002', 'platform=ESP8266', 'board=d1_mini', 'api_encryption_supported=Noise_NNpsk0_25519_ChaChaPoly_SHA256']],
];
$k = new ESPHomeHubKonfigurator(11000);
$k->Create();
$GLOBALS['Z'][11000]['p']['Adressen'] = json_encode([['Adresse' => '192.168.2.78', 'Port' => 6053], ['Adresse' => '10.0.5.9', 'Port' => 6060]]);
$k->ApplyChanges();
$form = json_decode((string)$k->GetConfigurationForm(), true);
pruefe(is_array($form), 'Konfigurator-Formular ist gültiges JSON');
$konf = null;
array_walk_recursive($form, function () {});
foreach ($form['elements'] as $el) {
    foreach ($el['items'] ?? [] as $it) {
        if (($it['type'] ?? '') === 'Configurator') {
            $konf = $it;
        }
    }
}
$zeilen = [];
foreach ($konf['values'] ?? [] as $z) {
    $zeilen[$z['geraet']] = $z;
}
pruefe(count($konf['values'] ?? []) === 3, '3 Zeilen: 2 gefunden, 1 von Hand (die doppelte Hand-Adresse fällt weg): ' . count($konf['values'] ?? []));
pruefe(($zeilen['wohnzimmer']['instanceID'] ?? 0) === 10000, 'vorhandene Instanz an der MAC erkannt, obwohl die IP gewechselt hat');
pruefe(($zeilen['wohnzimmer']['schluessel'] ?? '') === '🔑 nötig' && ($zeilen['kueche']['schluessel'] ?? '') === 'ohne', 'Schlüssel-Spalte aus api_encryption');
pruefe(($zeilen['kueche']['name'] ?? '') === 'Küche' && ($zeilen['kueche']['chip'] ?? '') === 'ESP8266 d1_mini' && ($zeilen['kueche']['mac'] ?? '') === 'AA:BB:CC:00:00:02', 'Anzeigename, Chip, MAC aus TXT');
$c = $zeilen['kueche']['create'] ?? [];
pruefe(($c[0]['moduleID'] ?? '') === '{796D9D82-B2F7-4F80-BAA7-8CC50237B2EF}' && ($c[0]['configuration'] ?? []) === ['Host' => '192.168.2.78', 'Port' => 6053]
    && ($c[1]['moduleID'] ?? '') === '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}' && ($c[1]['configuration']['Host'] ?? '') === '192.168.2.78', 'Anlegen als Kette: Gerät + Client Socket mit Adresse');
pruefe(($zeilen['10.0.5.9']['quelle'] ?? '') === 'von Hand eingetragen' && ($zeilen['10.0.5.9']['adresse'] ?? '') === '10.0.5.9:6060' && ($zeilen['10.0.5.9']['schluessel'] ?? '') === '?', 'Hand-Adresse mit eigenem Port');

// Hand-Adresse, zu der schon eine Instanz gehört: MAC/Version aus deren Geräteinfo (live 28.10.2026)
$GLOBALS['ZC'] = [];
$GLOBALS['Z'][11000]['p']['Adressen'] = json_encode([['Adresse' => '192.168.2.50', 'Port' => 6053]]);
$hz = json_decode((string)$k->GetConfigurationForm(), true);
$hw = [];
array_walk_recursive($hz, function () {});
foreach ($hz['elements'] as $el) {
    foreach ($el['items'] ?? [] as $it) {
        if (($it['type'] ?? '') === 'Configurator') {
            $hw = $it['values'];
        }
    }
}
pruefe(count($hw) === 1 && $hw[0]['instanceID'] === 10000 && $hw[0]['mac'] === 'AA:BB:CC:00:00:01' && $hw[0]['version'] === '2026.10.1' && $hw[0]['geraet'] === 'sim', 'Hand-Zeile mit vorhandener Instanz: MAC, Version, Name aus der Geräteinfo');
$kopf = '';
array_walk_recursive($form['elements'], function ($v, $key) use (&$kopf) {
    if ($key === 'caption' && str_contains((string)$v, 'im Netz gefunden (zuletzt')) {
        $kopf = $v;
    }
});
pruefe(str_starts_with($kopf, '✅ 2 ESPHome-Geräte im Netz gefunden'), 'Kopfzeile mit Anzahl und Uhrzeit: ' . $kopf);
$acks = [];
array_walk_recursive($form['elements'], function ($v, $key) use (&$acks) {
    if ($key === 'onClick') {
        $acks[] = $v;
    }
});
pruefe(array_filter($acks, fn ($a) => str_starts_with($a, 'ESPH_')) === [] && in_array('ESPHC_Suchen($id);', $acks, true), 'Konfigurator nutzt nur den eigenen Präfix ESPHC');

// Gerät gerade nicht im Netz: vorhandene Instanz bleibt sichtbar
$GLOBALS['ZC'] = [];
$GLOBALS['Z'][11000]['p']['Adressen'] = '[]';
$z = json_decode($k->GetDevices(), true);
$form = json_decode((string)$k->GetConfigurationForm(), true);
$werte = $form['elements'][array_key_last(array_filter($form['elements'], fn ($e) => str_contains($e['caption'] ?? '', 'Geräte im Netz')))]['items'][2]['values'] ?? [];
pruefe($z === [] && count($werte) === 1 && $werte[0]['instanceID'] === 10000 && $werte[0]['quelle'] === 'nicht gefunden' && !isset($werte[0]['create']), 'nicht gefundene Instanz wird ohne „Anlegen" gelistet');

// Ohne DNS-SD-Instanz: klare Kopfzeile, Hand-Adressen gehen weiter
$GLOBALS['DNSSD'] = [];
$GLOBALS['Z'][11000]['p']['Adressen'] = json_encode([['Adresse' => 'esp.local', 'Port' => 6053]]);
$z = json_decode($k->GetDevices(), true);
$form = (string)$k->GetConfigurationForm();
pruefe(count($z) === 1 && str_contains($form, 'Keine DNS-SD-Instanz'), 'ohne DNS-SD: Hinweis, Hand-Adresse bleibt');
$GLOBALS['DNSSD'] = [10244];
ob_start();
$k->Suchen();
$echo = ob_get_clean();
pruefe(str_contains($echo, '0 ESPHome-Geräte im Netz gefunden'), 'Knopf „Neu suchen" meldet das Ergebnis: ' . $echo);

// ------------------------------------------------------------------ 4c. Formular: Aufbau (SUITE.md 3a)
abschnitt('Formular: Aufbau (SUITE.md 3a)');
$GLOBALS['Z'][10000]['p']['Host'] = 'sim.local';
/** Titel der Panels in Formular-Reihenfolge, je ['caption', expanded, sichtbar]. */
$panels = function (string $json): array {
    $out = [];
    foreach (json_decode($json, true)['elements'] as $e) {
        if (($e['type'] ?? '') === 'ExpansionPanel') {
            $out[] = [trim((string)preg_replace('/^[^\p{L}]+/u', '', $e['caption'])), $e['expanded'] ?? null, $e['visible'] ?? true];
        }
    }
    return $out;
};
$titel = fn (array $p) => array_map(fn ($x) => preg_replace('/\s*\(.*$/u', '', $x[0]), $p);
$fach = ['Verbindung', 'Gerät', 'Entitäten'];
$nurFach = fn (array $p) => array_values(array_filter($p, fn ($x) => in_array(preg_replace('/\s*\(.*$/u', '', $x[0]), $fach, true)));

$GLOBALS['Z'][10000]['p']['FormLayout'] = '[]';
$p = $panels(geraet()->GetConfigurationForm());
$t = $titel($p);
$iAufbau = array_search('Formular: Aufbau', $t, true);
$iRueck = array_search('Rückmeldungen', $t, true);
$iEnt = array_search('Entitäten', $t, true);
pruefe(array_column($nurFach($p), 0) === $fach, 'Standard: Fachpanels in Standardreihenfolge');
pruefe($iAufbau !== false && $iAufbau === $iEnt + 1 && ($iRueck === false || $iRueck === $iAufbau + 1), 'Aufbau-Panel direkt nach den Fachpanels, vor dem Forum-Hinweis');
pruefe($p[$iAufbau][1] === false, 'Aufbau-Panel standardmäßig eingeklappt');
pruefe($nurFach($p)[0][1] === false && $nurFach($p)[1][1] === true, 'Standard-Aufklappen bleibt (Adresse da: Verbindung zu, Gerät auf)');

$GLOBALS['Z'][10000]['p']['FormLayout'] = json_encode([
    ['Key' => 'entitaeten', 'Opened' => true, 'Closed' => false, 'Hidden' => false, 'Pos' => 1],
    ['Key' => 'geraet', 'Opened' => true, 'Closed' => false, 'Hidden' => true, 'Pos' => 2],
    ['Key' => 'verbindung', 'Opened' => true, 'Closed' => true, 'Hidden' => false, 'Pos' => 3],
    ['Key' => 'gibtsnicht', 'Hidden' => true, 'Pos' => 1],
]);
$json = geraet()->GetConfigurationForm();
$f = $nurFach($panels($json));
pruefe(array_column($f, 0) === ['Entitäten', 'Gerät', 'Verbindung'], 'Position bestimmt die Reihenfolge: ' . implode(', ', array_column($f, 0)));
pruefe($f[0][1] === true, 'Aufgeklappt wirkt');
pruefe($f[1][2] === false, 'Ausgeblendet = visible:false, Panel bleibt im Formular');
pruefe($f[2][1] === false, 'Widerspruch Zugeklappt + Aufgeklappt → zugeklappt');
$names = [];
array_walk_recursive(json_decode($json, true)['elements'], function ($v, $k) use (&$names) { if ($k === 'name') { $names[] = $v; } });
pruefe(in_array('Host', $names, true) && in_array('Schluessel', $names, true), 'Felder des ausgeblendeten Bereichs bleiben im Formular');
$aufbau = null;
foreach (json_decode($json, true)['elements'] as $e) {
    if (str_contains((string)($e['caption'] ?? ''), 'Formular: Aufbau')) { $aufbau = $e; }
}
pruefe($aufbau !== null && str_contains($aufbau['caption'], '(1 ausgeblendet)'), 'Überschrift nennt die Zahl ausgeblendeter Bereiche');
$liste = null;
foreach ($aufbau['items'] ?? [] as $it) { if (($it['name'] ?? '') === 'FormLayout') { $liste = $it; } }
pruefe($liste !== null && array_column($liste['values'], 'Key') === ['entitaeten', 'geraet', 'verbindung'], 'Liste zeigt nur bekannte Schlüssel, unbekannter fällt weg');
$keySpalte = array_values(array_filter($liste['columns'] ?? [], fn ($c) => $c['name'] === 'Key'))[0] ?? [];
pruefe(($keySpalte['visible'] ?? true) === false && ($keySpalte['save'] ?? false) === true, 'Schlüssel-Spalte unsichtbar mit save:true');

$GLOBALS['Z'][10000]['p']['FormLayout'] = json_encode([['Key' => 'geraet', 'Pos' => 1], ['Key' => 'verbindung', 'Pos' => 1]]);
$f = $nurFach($panels(geraet()->GetConfigurationForm()));
pruefe(array_column($f, 0) === ['Verbindung', 'Gerät', 'Entitäten'], 'Gleiche Position → Standardreihenfolge, fehlender Schlüssel → Standardposition');
$GLOBALS['Z'][10000]['p']['FormLayout'] = 'kaputt';
pruefe(array_column($nurFach($panels(geraet()->GetConfigurationForm())), 0) === $fach, 'Unlesbare Einstellung → Standard');

$GLOBALS['FORMFELD'] = [];
$antwort = geraet()->ResetFormLayout();
$zur = json_decode((string)($GLOBALS['FORMFELD']['FormLayout']['values'] ?? ''), true);
pruefe(is_array($zur) && array_column($zur, 'Key') === ['verbindung', 'geraet', 'entitaeten'] && array_column($zur, 'Pos') === [1, 2, 3]
    && !in_array(true, array_merge(array_column($zur, 'Opened'), array_column($zur, 'Closed'), array_column($zur, 'Hidden')), true), 'Zurücksetzen füllt die Liste mit dem Standard');
pruefe(str_contains($antwort, 'Übernehmen') && zustand()['p']['FormLayout'] === 'kaputt', 'Zurücksetzen speichert nicht selbst, sondern verweist auf „Übernehmen"');
$GLOBALS['Z'][10000]['p']['FormLayout'] = '[]';

$kk = new ESPHomeHubKonfigurator(11500);
$kk->Create();
$kk->ApplyChanges();
pruefe(!str_contains((string)$kk->GetConfigurationForm(), '"FormLayout"') && !str_contains((string)$kk->GetConfigurationForm(), '🪟  Formular: Aufbau'), 'Konfigurator (zwei Fachpanels) ohne Aufbau-Panel');

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
// SUITE.md Stolperstein 9: erlaubte OPTIONS-Schlüssel je Darstellung, Aufzählung nur mit Aktion,
// Wertanzeige-OPTIONS nur an Bool/String.
foreach (zustand()['v'] as $ident => $var) {
    $art = $var['pres']['PRESENTATION'] ?? '';
    $erlaubt = $art === VARIABLE_PRESENTATION_ENUMERATION
        ? ['Value', 'Caption', 'IconActive', 'IconValue', 'Color']
        : ['Value', 'Caption', 'IconActive', 'IconValue', 'ColorActive', 'ColorValue'];
    $optionen = json_decode($var['pres']['OPTIONS'] ?? '[]', true);
    $falsch = array_filter($optionen, fn ($o) => array_diff(array_keys($o), $erlaubt) !== [] || array_diff($erlaubt, array_keys($o)) !== []);
    pruefe($falsch === [], "OPTIONS $ident: genau die erlaubten Schlüssel");
    if ($art === VARIABLE_PRESENTATION_ENUMERATION) {
        pruefe($var['aktion'] ?? false, "Aufzählung $ident hat eine Aktion");
    }
    if ($art === VARIABLE_PRESENTATION_VALUE_PRESENTATION && $optionen !== []) {
        pruefe(in_array($var['typ'], [VARIABLETYPE_BOOLEAN, VARIABLETYPE_STRING], true), "Wertanzeige-OPTIONS nur an Bool/String ($ident)");
    }
    if (in_array($art, [VARIABLE_PRESENTATION_SWITCH, VARIABLE_PRESENTATION_SLIDER, VARIABLE_PRESENTATION_VALUE_INPUT, VARIABLE_PRESENTATION_COLOR], true)) {
        pruefe($var['aktion'] ?? false, "eingabefähige Darstellung $ident hat eine Aktion");
    }
    if ($art === VARIABLE_PRESENTATION_SLIDER) {
        $int = $var['typ'] === VARIABLETYPE_INTEGER;
        pruefe(is_int($var['pres']['MIN']) === $int && is_int($var['pres']['MAX']) === $int && is_int($var['pres']['STEP_SIZE']) === $int, "Schieberegler $ident: MIN/MAX/STEP passend zum Typ (SUITE.md 18)");
    }
    pruefe(!isset($var['pres']['DIGITS']) || $art !== VARIABLE_PRESENTATION_VALUE_INPUT, "VALUE_INPUT ohne DIGITS ($ident)");
}
$lib = json_decode((string)file_get_contents($ROOT . '/library.json'), true);
$mod = json_decode((string)file_get_contents($ROOT . '/ESPHomeHubGeraet/module.json'), true);
$kmod = json_decode((string)file_get_contents($ROOT . '/ESPHomeHubKonfigurator/module.json'), true);
pruefe($kmod['name'] === 'ESPHomeHubKonfigurator' && $kmod['type'] === 4 && count($kmod['aliases']) === 1 && $kmod['vendor'] === 'ESPHome', 'Konfigurator module.json: Klasse, Typ 4, ein Alias, vendor');
pruefe($kmod['prefix'] !== $mod['prefix'], 'Konfigurator und Gerät mit verschiedenem Präfix (SUITE.md: Cannot redeclare)');
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
        // Jede schaltbare Variable einmal setzen; der Wert muss vom Gerät zurückkommen.
        $GLOBALS['Z'][10000]['v'] = array_intersect_key(zustand()['v'], ['Verbunden' => 1]) + zustand()['v'];
        $probe = [
            'switch/' => true, 'number/' => 23.5, 'select/' => 'Komfort', 'text/' => 'Grüße aus Symcon', 'light/' => true,
            'light/h' => 40, 'light/f' => 0x00FF00, 'fan/' => true, 'fan/g' => 2, 'cover/' => 30, 'cover/s' => 0, 'valve/s' => 0,
            'lock/' => true, 'climate/m' => 3, 'climate/u' => 20.5, 'climate/' => 20.5, 'button/' => 0,
        ];
        $ents = json_decode(geraet()->GetEntities(), true);
        echo '    ' . count($ents) . ' Entitäten: ' . implode(', ', array_map(fn ($e) => $e['typ'] . ' „' . $e['name'] . '"', $ents)) . "\n";
        foreach ($ents as $e) {
            foreach (ESPHomeHubEntitaeten::variablen($e, 'Gerät') as $var) {
                $teil = (string)(ESPHomeHubEntitaeten::identLesen($var['ident'])[1] ?? '');
                $wert = $probe[$e['typ'] . '/' . $teil] ?? null;
                if (!$var['aktion'] || $wert === null) {
                    continue;
                }
                try {
                    geraet()->RequestAction($var['ident'], $wert);
                } catch (Exception $ex) {
                    pruefe(false, $var['name'] . ': ' . $ex->getMessage());
                    continue;
                }
                $t->pumpen(1.2);
                $ist = zustand()['v'][$var['ident']]['wert'] ?? null;
                $ok = is_float($wert) ? abs((float)$ist - $wert) < 0.01 : $ist === $wert;
                // Rollladen „Öffnen": Steuerung zeigt den Befehl, die Position fährt auf 100.
                pruefe($ok, "$wie: {$var['name']} = " . json_encode($wert, JSON_UNESCAPED_UNICODE) . ' → ' . json_encode($ist, JSON_UNESCAPED_UNICODE));
            }
        }
        $werte = [];
        foreach (zustand()['v'] as $ident => $var) {
            $werte[] = $ident . '=' . json_encode($var['wert'], JSON_UNESCAPED_UNICODE);
        }
        echo '    Werte: ' . implode(' ', $werte) . "\n";
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
