<?php
// ======================================================================
// Skyesoft Cron Engine — Version 1.0
// Purpose: Periodically refresh cached data required by SSE + dashboard
// PHP 5.6 compatible
// ======================================================================

error_reporting(E_ALL);
ini_set('display_errors', 0);

$root = realpath(dirname(__DIR__));
$dataPath = $root . '/assets/data';

// -------------------------------------------------------
// 1) Load getDynamicData.php (non-SSE mode)
// -------------------------------------------------------
$dynamicApi = $root . '/api/getDynamicData.php';

if (!file_exists($dynamicApi)) {
    error_log("CRON ERROR: getDynamicData.php not found");
    exit;
}

// Run the file but capture output
ob_start();
include $dynamicApi;
$data = ob_get_clean();

// If output begins with "data:" (SSE format), trim it
if (strpos($data, "data:") === 0) {
    $data = trim(substr($data, 5));
}

// Try to decode JSON
$json = json_decode($data, true);

// -------------------------------------------------------
// 2) If decode failed, log and exit
// -------------------------------------------------------
if (!is_array($json)) {
    error_log("CRON ERROR: Invalid JSON from getDynamicData");
    exit;
}

// -------------------------------------------------------
// 3) Save a heartbeat file for dashboard auto-refresh
// -------------------------------------------------------
$heartbeatFile = $dataPath . "/cron_heartbeat.json";
file_put_contents(
    $heartbeatFile,
    json_encode(
        array(
            "lastRunUnix" => time(),
            "lastRun"     => date('Y-m-d H:i:s'),
            "status"      => "ok"
        ),
        JSON_PRETTY_PRINT
    )
);

// -------------------------------------------------------
// 4) Cleanup/reset ephemeral sections if needed
// -------------------------------------------------------
$mainDataPath = $dataPath . '/skyesoft-data.json';
if (file_exists($mainDataPath)) {
    $mainData = json_decode(file_get_contents($mainDataPath), true);
    if (!is_array($mainData)) $mainData = array();

    // Reset uiEvent if stuck
    if (isset($mainData['uiEvent']) && $mainData['uiEvent'] !== null) {
        $mainData['uiEvent'] = null;
        file_put_contents($mainDataPath, json_encode($mainData, JSON_PRETTY_PRINT));
    }
}

// Done
exit;