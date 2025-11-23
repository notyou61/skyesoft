<?php
// =====================================================================
// Skyesoft Version Engine
// git-version-check.php
// PHP 5.6 compatible
//
// Purpose:
//   • Reads version-map.json
//   • Reads existing versions.json
//   • Automatically increments versions according to mapping rules
//   • Outputs updated structure back into versions.json
//
// Notes:
//   • GoDaddy shared hosting cannot run 'git' or shell_exec.
//     Therefore versioning is driven by mapping + timestamps only.
//
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

$mapPath   = $dataPath . '/version-map.json';
$verPath   = $dataPath . '/versions.json';

// Load files
$map   = loadJsonSafe($mapPath);
$vers  = loadJsonSafe($verPath);

// ---------------------------------------------------------------
// Safety Defaults
// ---------------------------------------------------------------
if (!isset($vers['modules']) || !is_array($vers['modules'])) {
    $vers['modules'] = array();
}

// ---------------------------------------------------------------
// Helper: Increment version (semantic versioning)
// ---------------------------------------------------------------
function bumpVersion($v) {
    if (!$v || !is_string($v)) return "1.0.0";

    $parts = explode('.', $v);
    while (count($parts) < 3) $parts[] = 0;

    $major = intval($parts[0]);
    $minor = intval($parts[1]);
    $patch = intval($parts[2]);

    // Simple patch bump (Codex v1 rule — increment least disruptive unit)
    $patch++;

    return $major . "." . $minor . "." . $patch;
}

// ---------------------------------------------------------------
// Apply Version Map Rules
// ---------------------------------------------------------------
if (isset($map['modules']) && is_array($map['modules'])) {

    foreach ($map['modules'] as $moduleName => $fileList) {

        // Ensure module exists
        if (!isset($vers['modules'][$moduleName])) {
            $vers['modules'][$moduleName] = array(
                "version" => "1.0.0",
                "lastUpdated" => null
            );
        }

        // Determine last updated timestamp from mapped files
        $latestTs = 0;

        foreach ($fileList as $file) {
            $fullPath = $rootPath . '/' . ltrim($file, '/');

            if (file_exists($fullPath)) {
                $ts = filemtime($fullPath);
                if ($ts > $latestTs) $latestTs = $ts;
            }
        }

        // If never updated, initialize
        if ($vers['modules'][$moduleName]['lastUpdated'] === null) {
            $vers['modules'][$moduleName]['lastUpdated'] = $latestTs;
        }

        // If updated since last run → bump version
        if ($latestTs > $vers['modules'][$moduleName]['lastUpdated']) {
            $vers['modules'][$moduleName]['version'] =
                bumpVersion($vers['modules'][$moduleName]['version']);
            $vers['modules'][$moduleName]['lastUpdated'] = $latestTs;
        }
    }
}

// ---------------------------------------------------------------
// Update overall Codex version (if present)
// ---------------------------------------------------------------
if (!isset($vers['codex'])) {
    $vers['codex'] = array("version" => "1.0.0", "lastUpdated" => null);
}

// Treat codex.json as single source of truth
$codexPath = $dataPath . '/codex.json';
if (file_exists($codexPath)) {
    $ts = filemtime($codexPath);

    if ($vers['codex']['lastUpdated'] === null) {
        $vers['codex']['lastUpdated'] = $ts;
    }

    if ($ts > $vers['codex']['lastUpdated']) {
        $vers['codex']['version'] =
            bumpVersion($vers['codex']['version']);
        $vers['codex']['lastUpdated'] = $ts;
    }
}

// ---------------------------------------------------------------
// Save Updated versions.json
// ---------------------------------------------------------------
@file_put_contents(
    $verPath,
    json_encode($vers, JSON_PRETTY_PRINT)
);

// ---------------------------------------------------------------
// RETURN for cron-run.php
// ---------------------------------------------------------------
return array(
    "success" => true,
    "updated" => $vers,
    "time"    => date('Y-m-d H:i:s')
);