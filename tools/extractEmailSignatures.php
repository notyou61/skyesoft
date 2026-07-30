<?php
/**
 * extractEmailSignatures.php
 * Skyesoft – Email Signature Mining – Phase 1 Discovery Tool
 *
 * Version: 1.1
 * Location: /tools/extractEmailSignatures.php
 * JSON source: /data/authoritative/messages_part_*.json
 */

declare(strict_types=1);

// ============================================================
// PATHS  (adjusted for Skyesoft layout)
// ============================================================
$baseDir      = dirname(__DIR__);                          // /skyesoft
$jsonDir      = $baseDir . '/data/authoritative/';
$outputDir    = $baseDir . '/tools/emailSignatureExtraction/';
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
    echo $line; // also show in browser when run via URL
}

// ============================================================
// SIGNATURE EXTRACTION
// ============================================================
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

// ============================================================
// MAIN
// ============================================================
logMsg('=== Skyesoft Signature Discovery started ===');
logMsg('JSON directory : ' . $jsonDir);
logMsg('Output directory: ' . $outputDir);

$files = glob($jsonDir . 'messages_part_*.json');
sort($files);

if (empty($files)) {
    logMsg('ERROR: No messages_part_*.json files found in ' . $jsonDir);
    exit(1);
}

$stats = [
    'files_processed'    => 0,
    'messages_total'     => 0,
    'messages_with_body' => 0,
    'candidates_found'   => 0,
    'messages_no_sig'    => 0,
    'errors'             => 0,
];

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

        if (trim($body) === '') {
            $stats['messages_no_sig']++;
            continue;
        }

        $stats['messages_with_body']++;

        $candidate = extractCandidateSignature($body);

        if ($candidate === null) {
            $stats['messages_no_sig']++;
            continue;
        }

        $stats['candidates_found']++;

        $reportRows[] = [
            'entry_id'     => htmlspecialchars($entryId),
            'folder'       => htmlspecialchars($folder),
            'sender_name'  => htmlspecialchars($senderName),
            'sender_email' => htmlspecialchars($senderEmail),
            'subject'      => htmlspecialchars($subject),
            'received_at'  => htmlspecialchars($receivedAt),
            'signature'    => htmlspecialchars($candidate),
        ];
    }

    unset($messages, $raw);
}

// ============================================================
// HTML REPORT
// ============================================================
logMsg('Building HTML report...');

$html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Skyesoft – Signature Discovery Report</title>
<style>
    :root { --bg:#f8f9fa; --card:#fff; --border:#dee2e6; --text:#212529; --muted:#6c757d; --accent:#0d6efd; --sig-bg:#f1f3f5; }
    * { box-sizing:border-box; }
    body { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; background:var(--bg); color:var(--text); margin:0; padding:24px; line-height:1.5; }
    h1 { margin:0 0 8px; font-size:1.75rem; }
    .meta { color:var(--muted); margin-bottom:24px; }
    .stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin-bottom:32px; }
    .stat { background:var(--card); border:1px solid var(--border); border-radius:8px; padding:16px; text-align:center; }
    .stat .num { font-size:1.75rem; font-weight:700; color:var(--accent); }
    .stat .label { font-size:0.85rem; color:var(--muted); margin-top:4px; }
    .card { background:var(--card); border:1px solid var(--border); border-radius:8px; margin-bottom:16px; overflow:hidden; }
    .card-header { padding:12px 16px; background:#e9ecef; border-bottom:1px solid var(--border); display:flex; flex-wrap:wrap; gap:8px 24px; font-size:0.9rem; }
    .card-body { padding:16px; }
    .sig { background:var(--sig-bg); border-left:4px solid var(--accent); padding:12px 16px; font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace; font-size:0.85rem; white-space:pre-wrap; word-break:break-word; margin:0; }
    .folder { font-size:0.8rem; color:var(--muted); }
</style>
</head>
<body>
<h1>Skyesoft – Signature Discovery Report</h1>
<div class="meta">Generated: ' . date('Y-m-d H:i:s T') . '<br>Source: data/authoritative/messages_part_*.json</div>
<div class="stats">
    <div class="stat"><div class="num">' . $stats['files_processed'] . '</div><div class="label">JSON Files</div></div>
    <div class="stat"><div class="num">' . number_format($stats['messages_total']) . '</div><div class="label">Messages</div></div>
    <div class="stat"><div class="num">' . number_format($stats['messages_with_body']) . '</div><div class="label">With Body</div></div>
    <div class="stat"><div class="num">' . number_format($stats['candidates_found']) . '</div><div class="label">Candidates Found</div></div>
    <div class="stat"><div class="num">' . number_format($stats['messages_no_sig']) . '</div><div class="label">No Signature</div></div>
</div>
';

if (empty($reportRows)) {
    $html .= '<div style="text-align:center;padding:48px;color:#6c757d;">No candidate signatures found.</div>';
} else {
    foreach ($reportRows as $row) {
        $html .= '
<div class="card">
    <div class="card-header">
        <div><strong>' . $row['sender_name'] . '</strong> &lt;' . $row['sender_email'] . '&gt;</div>
        <div>' . $row['received_at'] . '</div>
        <div class="folder">' . $row['folder'] . '</div>
    </div>
    <div class="card-body">
        <div style="margin-bottom:8px;"><strong>Subject:</strong> ' . $row['subject'] . '</div>
        <pre class="sig">' . $row['signature'] . '</pre>
    </div>
</div>';
    }
}

$html .= '</body></html>';

file_put_contents($reportFile, $html);

// ============================================================
// FINAL
// ============================================================
logMsg('=== Discovery complete ===');
logMsg("Files processed     : {$stats['files_processed']}");
logMsg("Messages total      : {$stats['messages_total']}");
logMsg("Messages with body  : {$stats['messages_with_body']}");
logMsg("Candidates found    : {$stats['candidates_found']}");
logMsg("Messages no sig     : {$stats['messages_no_sig']}");
logMsg("Report written to   : $reportFile");
logMsg("Log written to      : $logFile");

echo "\nDone.\nReport: $reportFile\n";