<?php
// =====================================================================
// Skyesoft Version Engine – git-version-check.php
// PHP 5.6 compatible
// =====================================================================

// ---------------------------------------------------------------
// Helper: Load JSON safely
// ---------------------------------------------------------------
function loadJsonSafe($path) {
    if (!file_exists($path)) return array();
    $raw = @file_get_contents($path);
    $json = json_decode($raw, true);
    return is_array($json) ? $json : array();
}

// ---------------------------------------------------------------
// Paths
// ---------------------------------------------------------------
$rootPath = realpath(dirname(__DIR__));
$dataPath = $rootPath . '/assets/data';

$mapPath = $dataPath . '/version-map.json';
$verPath = $dataPath . '/versions.json';
$codexPath = $dataPath . '/codex.json';

// Load
$map  = loadJsonSafe($mapPath);
$vers = loadJsonSafe($verPath);

// Ensure required structure
if (!isset($vers['modules']) || !is_array($vers['modules'])) {
    $vers['modules'] = array();
}

// Track updates for UI
$recentUpdates = array();

// ---------------------------------------------------------------
// Helper: Increment version using semantic patch bump
// ---------------------------------------------------------------
function bumpVersion($v) {
    if (!$v || !is_string($v)) return "1.0.0";

    $parts = explode('.', $v);
    while (count($parts) < 3) $parts[] = 0;

    $major = intval($parts[0]);
    $minor = intval($parts[1]);
    $patch = intval($parts[2]);

    $patch++;

    return $major . "." . $minor . "." . $patch;
}

// ---------------------------------------------------------------
// Apply Version Map Rules to Modules
// ---------------------------------------------------------------
if (isset($map['modules']) && is_array($map['modules'])) {

    foreach ($map['modules'] as $moduleName => $fileList) {

        // Ensure module entry exists
        if (!isset($vers['modules'][$moduleName])) {
            $vers['modules'][$moduleName] = array(
                "version"     => "1.0.0",
                "lastUpdated" => 0
            );
        }

        // Ensure lastUpdated exists
        if (!isset($vers['modules'][$moduleName]['lastUpdated'])) {
            $vers['modules'][$moduleName]['lastUpdated'] = 0;
        }

        $latestTs = 0;

        // Scan mapped files
        foreach ($fileList as $file) {
            $fullPath = $rootPath . '/' . ltrim($file, '/');

            if (file_exists($fullPath)) {
                $ts = @filemtime($fullPath);
                if ($ts > $latestTs) $latestTs = $ts;
            }
        }

        // Version bump if newer
        if ($latestTs > $vers['modules'][$moduleName]['lastUpdated']) {
            $vers['modules'][$moduleName]['version'] =
                bumpVersion($vers['modules'][$moduleName]['version']);

            $vers['modules'][$moduleName]['lastUpdated'] = $latestTs;

            // Track update
            $recentUpdates[] = $moduleName;
        }
    }
}

// ---------------------------------------------------------------
// Codex Version Handling (Top-Level Version)
// ---------------------------------------------------------------
if (!isset($vers['codex'])) {
    $vers['codex'] = array(
        "version"     => "1.0.0",
        "lastUpdated" => 0
    );
}

if (!isset($vers['codex']['lastUpdated'])) {
    $vers['codex']['lastUpdated'] = 0;
}

if (file_exists($codexPath)) {
    $cts = @filemtime($codexPath);

    if ($cts > $vers['codex']['lastUpdated']) {
        $vers['codex']['version'] =
            bumpVersion($vers['codex']['version']);
        $vers['codex']['lastUpdated'] = $cts;

        $recentUpdates[] = "codex";
    }
}

// ---------------------------------------------------------------
// Attach recent updates list for dashboard visibility
// ---------------------------------------------------------------
$vers['_recentUpdates'] = $recentUpdates;

// ---------------------------------------------------------------
// Save Updated versions.json
// ---------------------------------------------------------------
@file_put_contents(
    $verPath,
    json_encode($vers, JSON_PRETTY_PRINT)
);

// ---------------------------------------------------------------
// Return output for cron-run.php
// ---------------------------------------------------------------
return array(
    "success" => true,
    "updated" => $vers,
    "time"    => date('Y-m-d H:i:s')
);
