<?php
/**
 * getVersions.php — Skyesoft Version Engine (Codex-Compliant)
 * Version: 2.0.0
 * Tier: 1 (API)
 * Governed By: Codex:Tier1, Version Parliament, Repository Standard
 */

#region Headers
header("Content-Type: application/json");
#endregion

#region Logging Utility
function logVersionError($message) {
    $logFile = __DIR__ . '/../logs/version-errors.log';
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[" . $timestamp . "] " . $message . "\n";
    file_put_contents($logFile, $entry, FILE_APPEND);
}
#endregion

#region LoadVersions
$versionsPath = __DIR__ . '/../assets/data/versions.json';

if (!file_exists($versionsPath)) {
    logVersionError("versions.json missing at expected path.");

    echo json_encode(array(
        "status" => "error",
        "message" => "versions.json not found",
        "data" => array()
    ));
    exit;
}

$jsonData = file_get_contents($versionsPath);
$versions = json_decode($jsonData, true);

if (!is_array($versions)) {
    logVersionError("versions.json failed JSON decode.");

    echo json_encode(array(
        "status" => "error",
        "message" => "versions.json decode error",
        "data" => array()
    ));
    exit;
}
#endregion

#region SchemaRequirements
$requiredTop = array("system", "codex", "modules");
$requiredModule = array(
    "module", "version", "tier", "category",
    "governedBy", "dependsOn", "changeNotes", "updated"
);

foreach ($requiredTop as $key) {
    if (!array_key_exists($key, $versions)) {
        logVersionError("Missing required top-level key '$key'.");

        echo json_encode(array(
            "status" => "error",
            "message" => "versions.json missing key: $key",
            "data" => array()
        ));
        exit;
    }
}
#endregion

#region ValidateModules
foreach ($versions["modules"] as $index => $mod) {
    foreach ($requiredModule as $reqField) {
        if (!array_key_exists($reqField, $mod)) {
            logVersionError("Module[$index] missing field '$reqField'.");

            echo json_encode(array(
                "status"  => "error",
                "message" => "Module missing field: $reqField",
                "index"   => $index
            ));
            exit;
        }
    }
}
#endregion

#region Output
echo json_encode(array(
    "status"  => "ok",
    "message" => "Schema validated",
    "data"    => $versions
), JSON_PRETTY_PRINT);
#endregion