<?php
/**
 * cron-run.php — Skyesoft Automation Engine
 * Version: 2.1.0
 * Tier: 3 (Automation)
 * Governed By: Automation Standard, Version Parliament, Repository Standard
 *
 * PURPOSE:
 *  - Run the Version Parliament check
 *  - Load Codex-aligned version schema
 *  - Record repository events
 *  - Append automation hooks
 *  - MUST NEVER MODIFY REPO FILES
 */

#region Headers
header("Content-Type: application/json");
date_default_timezone_set("America/Phoenix");
#endregion

#region Paths
$rootPath     = realpath(dirname(__DIR__));
$apiPath      = $rootPath . "/api/";
$logDir       = $rootPath . "/logs/";
$reportDir    = $rootPath . "/reports/automation/";

if (!file_exists($reportDir)) {
    mkdir($reportDir, 0777, true);
}
#endregion

#region Utility: Log Automation Events
function logAutomationEvent($msg) {
    global $logDir;
    $file = $logDir . "automation.log";
    $stamp = date("Y-m-d H:i:s");
    file_put_contents($file, "[$stamp] $msg\n", FILE_APPEND);
}
#endregion

#region Exec safe wrapper (PHP 5.6)
function runCmd($cmd) {
    $out = array();
    $code = 0;
    exec($cmd . " 2>&1", $out, $code);
    return array("output" => $out, "code" => $code);
}
#endregion

#region Step1: Run Version Parliament Check
$parliamentCmd = "php " . $apiPath . "git-version-check.php";
$parliamentRun = runCmd($parliamentCmd);

$parliamentJson = json_decode(implode("\n", $parliamentRun["output"]), true);

if (!is_array($parliamentJson)) {
    logAutomationEvent("ERROR: git-version-check returned invalid JSON.");
    $parliamentJson = array(
        "status" => "error",
        "state"  => "unknown",
        "local"  => null,
        "remote" => null
    );
}
#endregion

#region Step2: Load Codex Versions
$getVersionsCmd = "php " . $apiPath . "getVersions.php";
$versionsRun    = runCmd($getVersionsCmd);

$versionsJson = json_decode(implode("\n", $versionsRun["output"]), true);

if (!is_array($versionsJson)) {
    logAutomationEvent("ERROR: getVersions.php returned invalid JSON.");
    $versionsJson = array(
        "status"  => "error",
        "message" => "invalid",
        "data"    => array()
    );
}
#endregion

#region Step3: Build Automation Hook
$event = array(
    "timestamp"   => date("Y-m-d H:i:s"),
    "repoState"   => $parliamentJson["state"],
    "localHash"   => $parliamentJson["local"],
    "remoteHash"  => $parliamentJson["remote"],
    "versions"    => isset($versionsJson["data"]) ? $versionsJson["data"] : array(),
    "status"      => "recorded"
);
#endregion

#region Step4: Append to hooks.json
$hooksFile = $reportDir . "hooks.json";

$history = array();
if (file_exists($hooksFile)) {
    $raw = file_get_contents($hooksFile);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $history = $decoded;
    }
}

$history[] = $event;

file_put_contents($hooksFile, json_encode($history, JSON_PRETTY_PRINT));
#endregion

#region Step5: Log Summary
logAutomationEvent(
    "CronRun: state=" . $parliamentJson["state"] .
    " local=" . $parliamentJson["local"] .
    " remote=" . $parliamentJson["remote"]
);
#endregion

#region Output
echo json_encode(array(
    "success" => true,
    "cron"    => "executed",
    "event"   => $event
), JSON_PRETTY_PRINT);
#endregion