<?php
/**
 * git-version-check.php — Skyesoft Version Parliament Checker
 * Version: 2.0.0
 * Tier: 3 (Automation)
 * Governed By: Version Parliament, Automation Standard, Repository Standard
 */

#region Headers
header("Content-Type: application/json");
#endregion

#region Utility: Write logs
function logRepoEvent($message) {
    $logFile = __DIR__ . '/../logs/repo-events.log';
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[" . $timestamp . "] " . $message . "\n";
    file_put_contents($logFile, $entry, FILE_APPEND);
}
#endregion

#region ExecWrapper (PHP 5.6)
function runCommand($cmd) {
    $output = array();
    $returnCode = 0;
    exec($cmd . " 2>&1", $output, $returnCode);
    return array(
        "output" => $output,
        "code"   => $returnCode
    );
}
#endregion

#region Step1: Ensure Git Exists
$gitTest = runCommand("git --version");
if ($gitTest["code"] !== 0) {
    logRepoEvent("Git not available.");
    echo json_encode(array(
        "status"  => "error",
        "message" => "Git unavailable",
        "data"    => array()
    ));
    exit;
}
#endregion

#region Step2: Read Local HEAD
$local = runCommand("git rev-parse HEAD");
if ($local["code"] !== 0) {
    logRepoEvent("Unable to read local HEAD.");
    echo json_encode(array(
        "status"  => "error",
        "message" => "Local HEAD unreadable",
        "data"    => array()
    ));
    exit;
}
$localHash = trim($local["output"][0]);
#endregion

#region Step3: Fetch Remote
runCommand("git fetch origin");
#endregion

#region Step4: Read Remote HEAD
$remote = runCommand("git rev-parse origin/main");
if ($remote["code"] !== 0) {
    logRepoEvent("Unable to read remote HEAD.");
    echo json_encode(array(
        "status"  => "error",
        "message" => "Remote HEAD unreadable",
        "data"    => array()
    ));
    exit;
}
$remoteHash = trim($remote["output"][0]);
#endregion

#region Step5: Determine Repository State
$state = "unknown";

if ($localHash === $remoteHash) {
    $state = "in_sync";
} else {
    $cmp = runCommand("git rev-list --left-right --count $remoteHash...$localHash");
    if ($cmp["code"] === 0 && count($cmp["output"]) > 0) {
        list($behind, $ahead) = explode("\t", $cmp["output"][0]);

        if (intval($ahead) > 0 && intval($behind) === 0) {
            $state = "ahead_of_origin";
        } elseif (intval($behind) > 0 && intval($ahead) === 0) {
            $state = "behind_origin";
        } else {
            $state = "diverged";
        }
    } else {
        $state = "diverged";
    }
}
#endregion

#region LogEvent
logRepoEvent("VersionCheck: local=$localHash remote=$remoteHash state=$state");
#endregion

#region Output
echo json_encode(array(
    "status" => "ok",
    "state"  => $state,
    "local"  => $localHash,
    "remote" => $remoteHash
), JSON_PRETTY_PRINT);
#endregion

?>
