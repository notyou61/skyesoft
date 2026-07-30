<?php
/**
 * extractEmailSignatures.php
 * Skyesoft – Email Signature Mining – Phase 1 Discovery Tool
 *
 * Version: 1.3
 * Location: tools/email-signature-mining/extractEmailSignatures.php
 */

declare(strict_types=1);

// ============================================================
// PATHS
// ============================================================
$baseDir      = __DIR__;                                           // tools/email-signature-mining
$jsonDir      = $baseDir . '/emailJSONObjects/';
$outputDir    = $baseDir . '/emailSignatureExtraction/';
$reportDir    = $outputDir . 'reports/';
$signatureDir = $outputDir . 'signatures/';
$logDir       = $outputDir . 'logs/';
$cacheDir     = $outputDir . 'cache/';

foreach ([$outputDir, $reportDir, $signatureDir, $logDir, $cacheDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

$reportFile = $reportDir . 'signatureDiscoveryReport.html';
$logFile    = $logDir . 'extraction.log';

// ============================================================
// CONFIG
// ============================================================
$maxSignatureLines = 18;
$minCandidateChars = 40;
$commonClosings = [
    'thanks', 'thank you', 'best regards', 'best,', 'regards',
    'sincerely', 'warm regards', 'warmest regards', 'kind regards',
    'cheers', 'all the best', 'respectfully', 'cordially',
];

// ============================================================
// LOGGING
// ============================================================
function logMsg(string $msg): void {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

// ============================================================
// PIPELINE FUNCTIONS (V1.3 IMPL)
// ============================================================

/**
 * 1. Message Classification
 * Filters out system folders, internal emails, and automated messages.
 */
function classifyMessage(array $msg): array
{
    $folder  = strtolower($msg['folder_path'] ?? '');
    $sender  = strtolower($msg['sender_email'] ?? '');
    $subject = strtolower($msg['subject'] ?? '');

    // Outlook system folders
    foreach ([
        'sync issues',
        'conflicts',
        'local failures',
        'server failures'
    ] as $skip) {
        if (str_contains($folder, $skip)) {
            return ['skip' => true, 'reason' => 'System Folder'];
        }
    }

    // Internal Christy
    if (str_ends_with($sender, '@christysigns.com')) {
        return ['skip' => true, 'reason' => 'Internal'];
    }

    // Automated mail
    if (
        preg_match('/(noreply|no-reply|mailer-daemon|postmaster)/', $sender) ||
        preg_match('/(newsletter|unsubscribe|notification|voicemail|delivery)/', $subject)
    ) {
        return ['skip' => true, 'reason' => 'Automated'];
    }

    return ['skip' => false];
}

/**
 * 2. Signature Quality Scoring
 * Assigns points based on elements typical of valid signatures.
 */
function scoreSignature(string $sig): int
{
    $score = 0;

    if (preg_match('/@/', $sig)) {
        $score += 2;
    }

    if (preg_match('/\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', $sig)) {
        $score += 2;
    }

    if (preg_match('/manager|director|planner|engineer|project/i', $sig)) {
        $score++;
    }

    if (preg_match('/https?:\/\//i', $sig)) {
        $score--;
    }

    if (substr_count($sig, "\n") >= 3) {
        $score++;
    }

    return $score;
}

/**
 * Candidate Extraction Core Logic
 */
function extractCandidateSignature(string $body): ?string
{
    global $maxSignatureLines, $minCandidateChars, $commonClosings;

    if (trim($body) === '') {
        return null;
    }

    $text = str_replace(["\r\n", "\r"], "\n", $body);
    $text = preg_replace("/[ \t]+/", ' ', $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);

    $lines = explode("\n", $text);
    $cleanLines = [];
    $inQuoted = false;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if (preg_match('/^From:\s+/i', $trimmed) ||
            preg_match('/^Sent:\s+/i', $trimmed) ||
            preg_match('/^To:\s+/i', $trimmed) ||
            preg_match('/^Subject:\s+/i', $trimmed) ||
            preg_match('/^_{5,}/', $trimmed) ||
            preg_match('/^-{5,}/', $trimmed)) {
            $inQuoted = true;
            continue;
        }

        if ($inQuoted && (str_starts_with($trimmed, '>') || $trimmed === '')) {
            continue;
        }

        $inQuoted = false;
        $cleanLines[] = $line;
    }

    $text = implode("\n", $cleanLines);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn($l) => $l !== ''));

    if (count($lines) === 0) {
        return null;
    }

    $lastClosingIdx = -1;
    foreach ($lines as $i => $line) {
        $lower = strtolower($line);
        foreach ($commonClosings as $closing) {
            if (str_starts_with($lower, $closing) || $lower === $closing) {
                $lastClosingIdx = $i;
                break 2;
            }
        }
    }

    if ($lastClosingIdx >= 0 && $lastClosingIdx < count($lines) - 1) {
        $candidateLines = array_slice($lines, $lastClosingIdx + 1, $maxSignatureLines);
    } else {
        $candidateLines = array_slice($lines, -$maxSignatureLines);
    }

    $candidate = trim(implode("\n", $candidateLines));

    if (strlen($candidate) < $minCandidateChars) {
        return null;
    }

    if (preg_match('/^(unsubscribe|view in browser|powered by)/i', $candidate)) {
        return null;
    }

    return $candidate;
}

/**
 * Rich Extraction Wrapper
 * Combines candidates and scoring into an informative decision array.
 */
function processSignatureExtraction(array $msg, array &$seen): array
{
    // 1. Classification Step
    $class = classifyMessage($msg);
    if ($class['skip']) {
        return [
            'accepted' => false,
            'reason'   => $class['reason']
        ];
    }

    $body = $msg['body'] ?? '';
    if (trim($body) === '') {
        return [
            'accepted' => false,
            'reason'   => 'No Body'
        ];
    }

    // 2. Extractor Call
    $candidate = extractCandidateSignature($body);
    if ($candidate === null) {
        return [
            'accepted' => false,
            'reason'   => 'No Signature'
        ];
    }

    // 3. Quality Scoring Step
    $score = scoreSignature($candidate);
    if ($score < 3) {
        return [
            'accepted' => false,
            'reason'   => 'Low Quality',
            'score'    => $score
        ];
    }

    // 4. Deduplication Step
    $hash = hash('sha256', strtolower(trim($candidate)));
    if (isset($seen[$hash])) {
        return [
            'accepted' => false,
            'reason'   => 'Duplicates',
            'score'    => $score
        ];
    }

    // Mark hash as processed
    $seen[$hash] = true;

    return [
        'accepted'  => true,
        'reason'    => 'Business Signature',
        'score'     => $score,
        'signature' => $candidate
    ];
}

// ============================================================
// MAIN PIPELINE
// ============================================================
logMsg('=== Skyesoft Signature Discovery (v1.3) started ===');
logMsg('JSON directory  : ' . $jsonDir);
logMsg('Output directory: ' . $outputDir);

$files = glob($jsonDir . 'messages_part_*.json');
sort($files);

if (empty($files)) {
    logMsg('ERROR: No messages_part_*.json files found in ' . $jsonDir);
    exit(1);
}

// 5. Expanded Tracking Stats
$stats = [
    'files_processed'    => 0,
    'messages_total'     => 0,
    'messages_with_body' => 0,
    'accepted'           => 0,
    'duplicates'         => 0,
    'internal'           => 0,
    'automated'          => 0,
    'low_quality'        => 0,
    'system_folder'      => 0,
    'no_body'            => 0,
    'no_signature'       => 0,
    'errors'             => 0,
];

$seen = [];
$reportRows = [];

foreach ($files as $filePath) {
    $filename = basename($filePath);
    logMsg("Processing: $filename");

    $raw = file_get_contents($filePath);
    if ($raw === false) {
        logMsg("  ERROR: Could not read $filename");
        $stats['errors']++;
        continue;
    }

    $messages = json_decode($raw, true);
    if (!is_array($messages)) {
        logMsg("  ERROR: Invalid JSON in $filename");
        $stats['errors']++;
        continue;
    }

    $stats['files_processed']++;

    foreach ($messages as $msg) {
        $stats['messages_total']++;

        $senderName  = $msg['sender_name']  ?? '(unknown)';
        $senderEmail = $msg['sender_email'] ?? '';
        $subject     = $msg['subject']      ?? '(no subject)';
        $receivedAt  = $msg['received_at']  ?? '';
        $body        = $msg['body']         ?? '';
        $folder      = $msg['folder_path']  ?? '';
        $entryId     = $msg['entry_id']     ?? '';

        if (trim($body) !== '') {
            $stats['messages_with_body']++;
        }

        // Run Rich Pipeline Execution
        $result = processSignatureExtraction($msg, $seen);

        if (!$result['accepted']) {
            $reasonKey = strtolower(str_replace(' ', '_', $result['reason']));
            if (isset($stats[$reasonKey])) {
                $stats[$reasonKey]++;
            }
            continue;
        }

        $stats['accepted']++;

        // 4. Storing Original Body alongside rich payload
        $reportRows[] = [
            'entry_id'      => htmlspecialchars($entryId),
            'folder'        => htmlspecialchars($folder),
            'sender_name'   => htmlspecialchars($senderName),
            'sender_email'  => htmlspecialchars($senderEmail),
            'subject'       => htmlspecialchars($subject),
            'received_at'   => htmlspecialchars($receivedAt),
            'signature'     => htmlspecialchars($result['signature']),
            'original_body' => htmlspecialchars($body),
            'score'         => $result['score'],
            'reason'        => $result['reason'],
        ];
    }

    unset($messages, $raw);
}

// ============================================================
// HTML REPORT GENERATION
// ============================================================
logMsg('Building HTML report...');

$html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Skyesoft – Signature Discovery Report v1.3</title>
<style>
    :root { --bg:#f8f9fa; --card:#fff; --border:#dee2e6; --text:#212529; --muted:#6c757d; --accent:#0d6efd; --sig-bg:#f1f3f5; --orig-bg:#212529; }
    * { box-sizing:border-box; }
    body { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; background:var(--bg); color:var(--text); margin:0; padding:24px; line-height:1.5; }
    h1 { margin:0 0 8px; font-size:1.75rem; }
    .meta { color:var(--muted); margin-bottom:24px; }
    .stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:12px; margin-bottom:32px; }
    .stat { background:var(--card); border:1px solid var(--border); border-radius:8px; padding:12px; text-align:center; }
    .stat .num { font-size:1.5rem; font-weight:700; color:var(--accent); }
    .stat .label { font-size:0.8rem; color:var(--muted); margin-top:4px; }
    .card { background:var(--card); border:1px solid var(--border); border-radius:8px; margin-bottom:16px; overflow:hidden; }
    .card-header { padding:12px 16px; background:#e9ecef; border-bottom:1px solid var(--border); display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; font-size:0.9rem; }
    .card-body { padding:16px; }
    .badge { background:var(--accent); color:#fff; padding:2px 8px; border-radius:12px; font-size:0.75rem; font-weight:bold; }
    .sig { background:var(--sig-bg); border-left:4px solid var(--accent); padding:12px 16px; font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace; font-size:0.85rem; white-space:pre-wrap; word-break:break-word; margin:0 0 12px 0; }
    details { margin-top:8px; background:#f8f9fa; border:1px solid var(--border); border-radius:4px; padding:8px; }
    summary { cursor:pointer; font-weight:600; font-size:0.85rem; color:var(--accent); }
    pre.orig { background:var(--orig-bg); color:#f8f9fa; padding:12px; border-radius:4px; font-size:0.8rem; white-space:pre-wrap; word-break:break-word; max-height:300px; overflow-y:auto; margin-top:8px; }
    .folder { font-size:0.8rem; color:var(--muted); }
</style>
</head>
<body>
<h1>Skyesoft – Signature Discovery Report <small style="font-size:0.9rem;color:var(--muted);">(v1.3)</small></h1>
<div class="meta">Generated: ' . date('Y-m-d H:i:s T') . '<br>Source: emailJSONObjects/messages_part_*.json</div>

<!-- 5. Expanded Statistics Grid -->
<div class="stats">
    <div class="stat"><div class="num">' . number_format($stats['accepted']) . '</div><div class="label">Accepted</div></div>
    <div class="stat"><div class="num">' . number_format($stats['duplicates']) . '</div><div class="label">Duplicates</div></div>
    <div class="stat"><div class="num">' . number_format($stats['internal']) . '</div><div class="label">Internal</div></div>
    <div class="stat"><div class="num">' . number_format($stats['automated']) . '</div><div class="label">Automated</div></div>
    <div class="stat"><div class="num">' . number_format($stats['low_quality']) . '</div><div class="label">Low Quality</div></div>
    <div class="stat"><div class="num">' . number_format($stats['system_folder']) . '</div><div class="label">System Folder</div></div>
    <div class="stat"><div class="num">' . number_format($stats['no_body']) . '</div><div class="label">No Body</div></div>
    <div class="stat"><div class="num">' . number_format($stats['no_signature']) . '</div><div class="label">No Signature</div></div>
</div>
';

if (empty($reportRows)) {
    $html .= '<div style="text-align:center;padding:48px;color:#6c757d;">No candidate signatures found.</div>';
} else {
    foreach ($reportRows as $row) {
        $html .= '
<div class="card">
    <div class="card-header">
        <div>
            <strong>' . $row['sender_name'] . '</strong> &lt;' . $row['sender_email'] . '&gt;
            <span class="folder">(' . $row['folder'] . ')</span>
        </div>
        <div>
            <span class="badge">Score: ' . $row['score'] . '</span>
            <span style="font-size:0.8rem;color:var(--muted);margin-left:8px;">' . $row['received_at'] . '</span>
        </div>
    </div>
    <div class="card-body">
        <div style="margin-bottom:8px;"><strong>Subject:</strong> ' . $row['subject'] . '</div>
        <pre class="sig">' . $row['signature'] . '</pre>
        
        <!-- 4. Original Body Inspection Toggle -->
        <details>
            <summary>Original Email Source</summary>
            <pre class="orig">' . $row['original_body'] . '</pre>
        </details>
    </div>
</div>';
    }
}

$html .= '</body></html>';

file_put_contents($reportFile, $html);

// ============================================================
// LOGGING OUTPUT & CLOSING
// ============================================================
logMsg('=== Discovery complete ===');
logMsg("Files processed    : {$stats['files_processed']}");
logMsg("Total Messages     : {$stats['messages_total']}");
logMsg("Accepted           : {$stats['accepted']}");
logMsg("Duplicates         : {$stats['duplicates']}");
logMsg("Internal Skipped   : {$stats['internal']}");
logMsg("Automated Skipped  : {$stats['automated']}");
logMsg("Low Quality        : {$stats['low_quality']}");
logMsg("System Folder Skip : {$stats['system_folder']}");
logMsg("No Body            : {$stats['no_body']}");
logMsg("No Signature       : {$stats['no_signature']}");
logMsg("Report written to  : $reportFile");
logMsg("Log written to     : $logFile");

echo "\nDone.\nReport: $reportFile\n";