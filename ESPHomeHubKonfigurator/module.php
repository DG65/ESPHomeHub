<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ESPHomeHubFormular.php';

// ===========================================================================
// ESPHomeHub Konfigurator — findet ESPHome-Geräte im Netz und legt sie an.
//
// Suche über Symcons DNS-SD-Instanz (mDNS, Dienst _esphomelib._tcp). Der
// TXT-Eintrag liefert MAC, Anzeigename, Version, Chip und ob das Gerät einen
// Schlüssel verlangt (api_encryption). mDNS reicht nicht über VLAN-/Docker-
// Grenzen — dafür lassen sich Adressen von Hand eintragen.
//
// Vorhandene Instanzen erkennt der Konfigurator an der MAC (aus deren
// Geräteinfo), sonst an Adresse und Port. Angelegt wird als Kette: Gerät +
// Client Socket in einem Schritt (RequireParent legt bei per Skript erzeugten
// Instanzen keinen Socket an, live 28.10.2026). Den Schlüssel trägt man danach
// in der Geräte-Instanz ein.
// ===========================================================================

class ESPHomeHubKonfigurator extends IPSModule
{
    use ESPHomeHubFormular;

    private const DNSSD = '{780B2D48-916C-4D59-AD35-5A429B2355A5}';
    private const GERAET = '{796D9D82-B2F7-4F80-BAA7-8CC50237B2EF}';
    private const CLIENT_SOCKET = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    private const DIENST = '_esphomelib._tcp';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Adressen', '[]');
        $this->RegisterAttributeInteger('LastDiscoveryTs', 0);
        $this->RegisterAttributeInteger('LastDiscoveryCount', 0);
        $this->registerFormAttributes();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(IS_ACTIVE);
    }

    // ------------------------------------------------------------------ öffentlich

    /** Formular-Knopf: neu suchen und Liste samt Kopfzeile auffrischen. */
    public function Suchen(): void
    {
        $gefunden = $this->geraeteSuchen();
        $this->UpdateFormField('Kopfzeile', 'caption', $this->kopfzeile());
        $this->UpdateFormField('Geraete', 'values', (string)json_encode($this->zeilen($gefunden)));
        $netz = $this->ReadAttributeInteger('LastDiscoveryCount');
        $hand = count($gefunden) - $netz;
        echo '🔍 ' . $netz . ' ESPHome-' . ($netz === 1 ? 'Gerät' : 'Geräte') . ' im Netz gefunden'
            . ($hand > 0 ? ', dazu ' . $hand . ' von Hand eingetragen' : '') . '.';
    }

    /** Gefundene Geräte als JSON (Name, Adresse, Port, MAC, Version, Chip, Schlüssel nötig). */
    public function GetDevices(): string
    {
        return (string)json_encode(array_values($this->geraeteSuchen()));
    }

    // ------------------------------------------------------------------ Suche

    /** @return array<string, array> je Gerät, Schlüssel = MAC (ohne Trenner) bzw. Adresse:Port */
    private function geraeteSuchen(): array
    {
        $gefunden = [];
        $dnssd = IPS_GetInstanceListByModuleID(self::DNSSD);
        if (count($dnssd) > 0) {
            $dienste = @ZC_QueryServiceType($dnssd[0], self::DIENST, '');
            foreach (is_array($dienste) ? $dienste : [] as $d) {
                $antwort = @ZC_QueryService($dnssd[0], (string)$d['Name'], (string)($d['Type'] ?? self::DIENST), (string)($d['Domain'] ?? 'local'));
                $a = is_array($antwort) && isset($antwort[0]) ? $antwort[0] : null;
                if ($a === null) {
                    continue;
                }
                $txt = self::txt((array)($a['TXTRecords'] ?? []));
                $mac = self::macNorm((string)($txt['mac'] ?? ''));
                $g = [
                    'name'        => (string)$d['Name'],
                    'anzeigename' => (string)($txt['friendly_name'] ?? ''),
                    'adresse'     => (string)($a['IPv4'][0] ?? rtrim((string)($a['Host'] ?? ''), '.')),
                    'host'        => rtrim((string)($a['Host'] ?? ''), '.'),
                    'port'        => (int)($a['Port'] ?? 6053),
                    'mac'         => $mac,
                    'version'     => (string)($txt['version'] ?? ''),
                    'chip'        => trim((string)($txt['platform'] ?? '') . ' ' . (string)($txt['board'] ?? '')),
                    'schluessel'  => isset($txt['api_encryption']),
                    'projekt'     => trim((string)($txt['project_name'] ?? '') . ' ' . (string)($txt['project_version'] ?? '')),
                    'quelle'      => 'mdns',
                ];
                $gefunden[$mac !== '' ? $mac : $g['adresse'] . ':' . $g['port']] = $g;
            }
        }
        foreach (json_decode($this->ReadPropertyString('Adressen'), true) ?: [] as $z) {
            $adresse = trim((string)($z['Adresse'] ?? ''));
            if ($adresse === '') {
                continue;
            }
            $port = (int)($z['Port'] ?? 6053) ?: 6053;
            foreach ($gefunden as $g) {
                if (($g['adresse'] === $adresse || $g['host'] === $adresse) && $g['port'] === $port) {
                    continue 2;   // schon per mDNS gefunden
                }
            }
            $gefunden[$adresse . ':' . $port] = [
                'name' => $adresse, 'anzeigename' => '', 'adresse' => $adresse, 'host' => '', 'port' => $port, 'mac' => '',
                'version' => '', 'chip' => '', 'schluessel' => null, 'projekt' => '', 'quelle' => 'hand',
            ];
        }
        $this->WriteAttributeInteger('LastDiscoveryTs', time());
        $this->WriteAttributeInteger('LastDiscoveryCount', count(array_filter($gefunden, fn ($g) => $g['quelle'] === 'mdns')));
        return $gefunden;
    }

    /** TXT-Einträge „schluessel=wert" → Feld. */
    private static function txt(array $eintraege): array
    {
        $out = [];
        foreach ($eintraege as $e) {
            $teile = explode('=', (string)$e, 2);
            $out[strtolower($teile[0])] = $teile[1] ?? '';
        }
        return $out;
    }

    private static function macNorm(string $mac): string
    {
        return strtoupper((string)preg_replace('/[^0-9a-fA-F]/', '', $mac));
    }

    // ------------------------------------------------------------------ Formular

    /** Konfigurator-Zeilen: gefundene Geräte, zugeordnet zu vorhandenen Instanzen, plus nicht gefundene Instanzen. */
    private function zeilen(array $gefunden): array
    {
        $instanzen = [];
        foreach (IPS_GetInstanceListByModuleID(self::GERAET) as $id) {
            $info = json_decode((string)@ESPH_GetDeviceInfo($id), true) ?: [];
            $instanzen[$id] = [
                'mac'     => self::macNorm((string)($info['mac'] ?? '')),
                'adresse' => trim((string)IPS_GetProperty($id, 'Host')),
                'port'    => (int)IPS_GetProperty($id, 'Port'),
                'info'    => $info,
            ];
        }
        $zeilen = [];
        foreach ($gefunden as $g) {
            $treffer = 0;
            foreach ($instanzen as $id => $i) {
                if (($g['mac'] !== '' && $g['mac'] === $i['mac'])
                    || ($i['port'] === $g['port'] && $i['adresse'] !== '' && in_array($i['adresse'], [$g['adresse'], $g['host']], true))) {
                    $treffer = $id;
                    break;
                }
            }
            if ($treffer !== 0) {
                // Von Hand eingetragen: was die Suche nicht liefert, aus der Geräteinfo der Instanz ergänzen.
                $info = $instanzen[$treffer]['info'];
                $g['mac'] = $g['mac'] ?: $instanzen[$treffer]['mac'];
                $g['version'] = $g['version'] ?: (string)($info['version'] ?? '');
                $g['chip'] = $g['chip'] ?: (string)($info['modell'] ?? '');
                $g['name'] = $g['quelle'] === 'hand' && ($info['name'] ?? '') !== '' ? (string)$info['name'] : $g['name'];
                unset($instanzen[$treffer]);
            }
            $anzeige = $g['anzeigename'] !== '' ? $g['anzeigename'] : $g['name'];
            $zeilen[] = [
                'name'       => $treffer !== 0 ? IPS_GetName($treffer) : $anzeige,
                'geraet'     => $g['name'],
                'adresse'    => $g['adresse'] . ($g['port'] !== 6053 ? ':' . $g['port'] : ''),
                'mac'        => self::macAnzeige($g['mac']),
                'version'    => $g['version'],
                'chip'       => $g['chip'],
                'schluessel' => $g['schluessel'] === null ? '?' : ($g['schluessel'] ? '🔑 nötig' : 'ohne'),
                'quelle'     => $g['quelle'] === 'mdns' ? 'im Netz gefunden' : 'von Hand eingetragen',
                'instanceID' => $treffer,
                'create'     => [
                    [
                        'moduleID'      => self::GERAET,
                        'configuration' => ['Host' => $g['adresse'], 'Port' => $g['port']],
                        'name'          => $anzeige,
                    ],
                    [
                        'moduleID'      => self::CLIENT_SOCKET,
                        'configuration' => ['Host' => $g['adresse'], 'Port' => $g['port'], 'Open' => false],
                        'name'          => 'ESPHome-Verbindung (' . $anzeige . ')',
                    ],
                ],
            ];
        }
        // Vorhandene Instanzen, die gerade nicht gefunden wurden (aus, anderes Netz, nur per Adresse erreichbar).
        foreach ($instanzen as $id => $i) {
            $zeilen[] = [
                'name'       => IPS_GetName($id),
                'geraet'     => (string)($i['info']['name'] ?? ''),
                'adresse'    => $i['adresse'] . ($i['port'] !== 6053 ? ':' . $i['port'] : ''),
                'mac'        => self::macAnzeige($i['mac']),
                'version'    => (string)($i['info']['version'] ?? ''),
                'chip'       => (string)($i['info']['modell'] ?? ''),
                'schluessel' => '',
                'quelle'     => 'nicht gefunden',
                'instanceID' => $id,
            ];
        }
        return $zeilen;
    }

    private static function macAnzeige(string $mac): string
    {
        return strlen($mac) === 12 ? implode(':', str_split($mac, 2)) : $mac;
    }

    private function kopfzeile(): string
    {
        $ts = $this->ReadAttributeInteger('LastDiscoveryTs');
        if ($ts === 0) {
            return 'ℹ️ Noch nicht gesucht.';
        }
        $n = $this->ReadAttributeInteger('LastDiscoveryCount');
        if (count(IPS_GetInstanceListByModuleID(self::DNSSD)) === 0) {
            return '⚠️ Keine DNS-SD-Instanz gefunden — die Suche im Netz braucht sie (Kerninstanzen). Adressen von Hand gehen trotzdem.';
        }
        return ($n > 0 ? '✅ ' : 'ℹ️ ') . $n . ' ESPHome-' . ($n === 1 ? 'Gerät' : 'Geräte') . ' im Netz gefunden (zuletzt ' . date('H:i:s', $ts) . ' Uhr).';
    }

    protected function esphomehubPrefix(): string
    {
        return 'ESPHC';
    }

    protected function esphomehubZweck(): array
    {
        return [
            'Findet ESPHome-Geräte im Heimnetz (mDNS) und legt sie mit einem Klick als ESPHome-Gerät samt Verbindung an.',
            'Danach in der neuen Instanz den Schlüssel aus „api: encryption: key" der Geräte-YAML eintragen — die Spalte „Schlüssel" zeigt, welches Gerät einen braucht.',
        ];
    }

    protected function esphomehubDoku(): array
    {
        return [
            'Gesucht wird über Symcons DNS-SD-Instanz nach dem Dienst _esphomelib._tcp. Das reicht nur im selben Netzsegment: hinter VLAN-Grenzen, Docker ohne Host-Netz oder VPN findet die Suche nichts.',
            'Für solche Geräte unten „Adressen von Hand" eintragen (IP-Adresse oder Hostname, Port 6053, sofern in der YAML nicht anders).',
            'Vorhandene Instanzen erkennt der Konfigurator an der MAC-Adresse, eine geänderte IP-Adresse führt also nicht zu einem zweiten Eintrag. Die neue Adresse trägt man in der Instanz ein.',
        ];
    }

    public function GetConfigurationForm()
    {
        $gefunden = $this->geraeteSuchen();
        $suche = [
            'type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '🔍  Geräte im Netz',
            'items' => [
                ['type' => 'Button', 'caption' => '🔍 Neu suchen', 'onClick' => 'ESPHC_Suchen($id);'],
                ['type' => 'Label', 'name' => 'Kopfzeile', 'caption' => $this->kopfzeile()],
                [
                    'type' => 'Configurator', 'name' => 'Geraete', 'caption' => 'ESPHome-Geräte', 'rowCount' => 12,
                    'add' => false, 'delete' => true, 'sort' => ['column' => 'name', 'direction' => 'ascending'],
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'name', 'width' => 'auto'],
                        ['caption' => 'Gerät', 'name' => 'geraet', 'width' => '160px'],
                        ['caption' => 'Adresse', 'name' => 'adresse', 'width' => '150px'],
                        ['caption' => 'MAC', 'name' => 'mac', 'width' => '150px'],
                        ['caption' => 'ESPHome', 'name' => 'version', 'width' => '100px'],
                        ['caption' => 'Chip', 'name' => 'chip', 'width' => '150px'],
                        ['caption' => 'Schlüssel', 'name' => 'schluessel', 'width' => '90px'],
                        ['caption' => 'Quelle', 'name' => 'quelle', 'width' => '150px'],
                    ],
                    'values' => $this->zeilen($gefunden),
                ],
            ],
        ];
        $hand = [
            'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '✏️  Adressen von Hand',
            'items' => [
                ['type' => 'Label', 'caption' => 'Für Geräte, die die Suche nicht erreicht (anderes Netzsegment, VLAN, VPN). Nach „Übernehmen" erscheinen sie oben in der Liste.'],
                [
                    'type' => 'List', 'name' => 'Adressen', 'rowCount' => 5, 'add' => true, 'delete' => true,
                    'columns' => [
                        ['caption' => 'IP-Adresse oder Hostname', 'name' => 'Adresse', 'width' => 'auto', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Port', 'name' => 'Port', 'width' => '100px', 'add' => 6053, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 65535]],
                    ],
                ],
            ],
        ];
        return $this->esphomehubFormular([$suche, $hand], [], [['code' => 102, 'icon' => 'active', 'caption' => 'Bereit']]);
    }
}
