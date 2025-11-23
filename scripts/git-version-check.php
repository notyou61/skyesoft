<?php
// =====================================================================
// Skyesoft Version Engine
// git-version-check.php (PHP 5.6 Compatible)
// ---------------------------------------------------------------------
// Purpose:
//   • Reads version-map.json
//   • Reads existing versions.json
//   • Automatically increments versions based on timestamps
//   • Handles sourceFile as STRING or ARRAY safely
//   • Outputs updated versions.json
// ---------------------------------------------------------------------

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
// Helper: Normalize string OR array into array
// ---------------------------------------------------------------
function normalizeFileList($input) {
    if (is_string($input)) {
        return array($input);
    }
    if (is_array($input)) {
        return $input;
    }
    return array(); // fallback
}

// ---------------------------------------------------------------
// Helper: Increment semantic version
// ---------------------------------------------------------------
function bumpVersion($v) {
    if (!$v || !is_string($v)) return "1.0.0";

    $parts = explode('.', $v);
    while (count($parts) < 3) $parts[] = 0;

    $major = intval($parts[0]);
    $minor = intval($parts[1]);
    $patch = intval($parts[2]);

    // Codex rule: bump patch
    $patch++;

    return $major . "." . $minor . "." . $patch;
}

// ---------------------------------------------------------------
// Paths
// ---------------------------------------------------------------
$rootPath = realpath(dirname(__DIR__));
$dataPath = $rootPath . '/assets/data';

$mapPath  = $dataPath . '/version-map.json';
$verPath  = $dataPath . '/versions.json';

// Load JSON
$map  = loadJsonSafe($mapPath);
$vers = loadJsonSafe($verPath);

// Ensure module bucket exists
if (!isset($vers['modules']) || !is_array($vers['modules'])) {
    $vers['modules'] = array();
}

// ---------------------------------------------------------------
// Apply Version Map Rules
// ---------------------------------------------------------------
if (isset($map['modules']) && is_array($map['modules'])) {

    foreach ($map['modules'] as $moduleName => $fileDef) {

        // Normalize source file list (string OR array)
        $fileList = normalizeFileList($fileDef);

        // Ensure module entry exists
        if (!isset($vers['modules'][$moduleName])) {
            $vers['modules'][$moduleName] = array(
                "version"     => "1.0.0",
                "lastUpdated" => null
            );
        }

        $latestTs = 0;
        $latestPath = null;

        // Find latest modification time across all mapped files
        foreach ($fileList as $file) {

            if (!is_string($file)) continue;

            $clean = ltrim($file, '/');
            $full  = $rootPath . '/' . $clean;

            if (file_exists($full)) {
                $ts = filemtime($full);
                if ($ts > $latestTs) {
                    $latestTs  = $ts;
                    $latestPath = $clean;
                }
            }
        }

        // Initialize lastUpdated if null
        if ($vers['modules'][$moduleName]['lastUpdated'] === null) {
            $vers['modules'][$moduleName]['lastUpdated'] = $latestTs;
        }

        // If file updated since last run → bump version
        if ($latestTs > $vers['modules'][$moduleName]['lastUpdated']) {

            $vers['modules'][$moduleName]['version'] =
                bumpVersion($vers['modules'][$moduleName]['version']);

            $vers['modules'][$moduleName]['lastUpdated'] = $latestTs;
        }
    }
}

// ---------------------------------------------------------------
// Update Codex version (based on codex.json)
// ---------------------------------------------------------------
if (!isset($vers['codex'])) {
    $vers['codex'] = array("version" => "1.0.0", "lastUpdated" => null);
}

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
// SAVE UPDATED versions.json
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
