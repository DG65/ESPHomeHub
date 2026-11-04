<?php
/**
 * Kopiert libs/locale.json (Haupttabelle, Deutsch zu den englischen Quelltexten) in beide Modulordner;
 * Symcon liest locale.json nur im Modulordner. Aufruf: php .tools/locale-bauen.php
 */
declare(strict_types=1);
$quelle = (string)file_get_contents(__DIR__ . '/../libs/locale.json');
json_decode($quelle, true, 512, JSON_THROW_ON_ERROR);
foreach (['ESPHomeHubGeraet', 'ESPHomeHubKonfigurator'] as $modul) {
    file_put_contents(__DIR__ . '/../' . $modul . '/locale.json', $quelle);
}
echo "locale.json für 2 Module geschrieben\n";
