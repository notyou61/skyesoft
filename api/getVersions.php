<?php
// ======================================================================
//  Skyesoft — getVersions.php
//  Version Governance Provider • PHP 8 • Codex 1.1.0
//  Implements: Article IX (Error Handling), Version Governance Standard
// ======================================================================

#region SECTION I — Metadata & Error Handling
declare(strict_types=1);
header("Content-Type: application/json; charset=UTF-8");

function fail(string $msg): never {
    echo json_encode([
        "success" => false,
        "error"   => "❌ $msg"
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
#endregion

#region SECTION II — Load Codex (Authority Layer)

$codexPath = __DIR__ . "/../codex/codex.json";

if (!file_exists($codexPath)) {
    fail("Codex missing at /codex/codex.json");
}

$codex = json_decode(file_get_contents($codexPath), true);
if (!is_array($codex)) {
    fail("Codex JSON invalid or unreadable.");
}

$codexVersion = $codex["meta"]["version"] ?? "unknown";
#endregion

#region SECTION III — Load versions.json (Authoritative SOT)
// ----------------------------------------------------------------------
$versionsPath = __DIR__ . "/../assets/data/versions.json";

if (!file_exists($versionsPath)) {
    fail("versions.json missing from assets/data/");
}

$versions = json_decode(file_get_contents($versionsPath), true);
if (!is_array($versions) || !isset($versions["system"])) {
    fail("versions.json invalid structure.");
}
#endregion

#region SECTION IV — Build Module Version Matrix
// ----------------------------------------------------------------------
$modules = [];

if (isset($versions["modules"]) && is_array($versions["modules"])) {
    foreach ($versions["modules"] as $mod) {
        $modules[] = [
            "id"           => $mod["id"]           ?? "unknown",
            "version"      => $mod["version"]      ?? "0.0.0",
            "lastModified" => $mod["lastModified"] ?? null,
            "governedBy"   => $mod["governedBy"]   ?? [],
            "dependsOn"    => $mod["dependsOn"]    ?? [],
            "changeNotes"  => $mod["changeNotes"]  ?? ""
        ];
    }
}
#endregion

#region SECTION V — Compose Response Payload
// ----------------------------------------------------------------------
$response = [
    "success" => true,

    "codex" => [
        "version"     => $codexVersion,
        "lastUpdated" => $versions["codex"]["lastUpdated"] ?? null,
        "notes"       => $versions["codex"]["notes"] ?? null
    ],

    "system" => [
        "siteVersion" => $versions["system"]["siteVersion"] ?? "unknown",
        "deployTime"  => $versions["system"]["deployTime"]  ?? null,
        "commitHash"  => $versions["system"]["commitHash"]  ?? null,
        "state"       => $versions["system"]["state"]       ?? "dev"
    ],

    "modules" => $modules
];
#endregion

#region SECTION VI — Output
// ----------------------------------------------------------------------
echo json_encode($response, JSON_UNESCAPED_SLASHES);
exit;
#endregion