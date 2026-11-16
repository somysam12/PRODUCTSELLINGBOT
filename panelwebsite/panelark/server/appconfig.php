<?php
/**
 * appconfig.php — app fetches this on every launch (SplashActivity)
 * Returns: 6-slot availability + names + visible game version chips
 */
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__).'/app/Config/db.php';

$libDir = dirname(__DIR__).'/server/libs/';

$defNames = [
    1 => 'Java Mode',  2 => 'ImGui Mode', 3 => 'Auto Mode',
    4 => 'GL Java',    5 => 'GL ImGui',   6 => 'GL Auto',
];
$defDescs = [
    1 => 'BGMI Java menu',      2 => 'BGMI ImGui overlay',   3 => 'BGMI Auto play',
    4 => 'Global Java menu',    5 => 'Global ImGui overlay',  6 => 'Global Auto play',
];

function slotAvailable(int $n, string $libDir): bool {
    $found = glob($libDir.'libslot'.$n.'.*');
    return !empty($found);
}

$rawVers  = getSetting('app_versions', 'bgmi,gl,kr,tw');
$versions = array_values(array_filter(array_map('trim', explode(',', $rawVers))));

$bgmi   = [];
$global = [];
for ($n = 1; $n <= 6; $n++) {
    $entry = [
        'slot'      => $n,
        'name'      => getSetting('slot_name_'.$n, '') ?: $defNames[$n],
        'desc'      => getSetting('slot_desc_'.$n, '') ?: $defDescs[$n],
        'available' => slotAvailable($n, $libDir),
    ];
    if ($n <= 3) $bgmi[]   = $entry;
    else         $global[] = $entry;
}

echo json_encode([
    'bgmi'     => $bgmi,
    'global'   => $global,
    'versions' => $versions,
], JSON_UNESCAPED_UNICODE);
