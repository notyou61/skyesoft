<?php
/**
 * extractEmailSignatures.php
 * Skyesoft – Email Signature Mining – Phase 2
 * Produces structured ELC Candidate JSON (high recall)
 *
 * Version: 2.1
 * Location: tools/email-signature-mining/extractEmailSignatures.php
 *
 * Output:
 *   emailSignatureExtraction/elcCandidates.json
 *   emailSignatureExtraction/reports/signatureDiscoveryReport.html  (first 200 only)
 */

declare(strict_types=1);

// ============================================================
// PATHS
// ============================================================
$baseDir      = __DIR__;
$jsonDir      = $baseDir . '/emailJSONObjects/';
$outputDir    = $baseDir . '/emailSignatureExtraction/';
$reportDir    = $outputDir . 'reports/';
$logDir       = $outputDir . 'logs/';

foreach ([$outputDir, $reportDir, $logDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

$candidatesFile = $outputDir . 'elcCandidates.json';
$reportFile     = $reportDir . 'signatureDiscoveryReport.html';
$logFile        = $logDir . 'extraction.log';

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

/**
 * Force a string into valid UTF-8 (prevents json_encode from returning false)
 */
function cleanUtf8(?string $value): string {
    if ($value === null || $value === '') {
        return '';
    }
    // Remove any invalid sequences
    $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
    if ($clean === false) {
        $clean = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
    // Final safety
    return mb_convert_encoding($clean, 'UTF-8', 'UTF-8');
}

// ============================================================
// PIPELINE FUNCTIONS (high recall – essentially unchanged)
// ============================================================

function classifyMessage(array $msg): array
{
    $folder  = strtolower($msg['folder_path'] ?? '');
    $sender  = strtolower($msg['sender_email'] ?? '');
    $subject = strtolower($msg['subject'] ?? '');

    foreach (['sync issues', 'conflicts', 'local failures', 'server failures'] as $skip) {
        if (str_contains($folder, $skip)) {
            return ['skip' => true, 'reason' => 'System Folder'];
        }
    }

    if (str_ends_with($sender, '@christysigns.com')) {
        return ['skip' => true, 'reason' => 'Internal'];
    }

    if (
        preg_match('/(noreply|no-reply|mailer-daemon|postmaster)/', $sender) ||
        preg_match('/(newsletter|unsubscribe|notification|voicemail|delivery)/', $subject)
    ) {
        return ['skip' => true, 'reason' => 'Automated'];
    }

    return ['skip' => false];
}

function scoreSignature(string $sig): int
{
    $score = 0;

    if (preg_match('/@/', $sig)) {
        $score += 2;
    }
    if (preg_match('/\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', $sig)) {
        $score += 2;
    }
    if (preg_match('/manager|director|planner|engineer|project|coordinator|inspector|specialist/i', $sig)) {
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
            preg_match('/^-{5,}/', $trimmed) ||
            preg_match('/^-----Original Message-----/i', $trimmed)) {
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
 * Light heuristic parser – best-effort only.
 * Leaves fields null when uncertain. Reviewer will correct in portal.
 */
function parseSignatureFields(string $raw): array
{
    $result = [
        'entity' => ['name' => null],
        'location' => [
            'streetAddress' => null,
            'city' => null,
            'state' => null,
            'zipCode' => null,
        ],
        'contact' => [
            'name' => null,
            'title' => null,
            'phone' => null,
            'email' => null,
        ],
    ];

    $lines = array_values(array_filter(array_map('trim', explode("\n", $raw))));

    // Email
    if (preg_match('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $raw, $m)) {
        $result['contact']['email'] = $m[0];
    }

    // Phone (simple US)
    if (preg_match('/\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', $raw, $m)) {
        $result['contact']['phone'] = $m[0];
    }

    // Name + Title patterns
    foreach ($lines as $i => $line) {
        if (preg_match('/^([A-Z][a-zA-Z\'\-]+(?:\s+[A-Z][a-zA-Z\'\-]+)+)\s*[|,–\-]\s*(.+)$/', $line, $m)) {
            $result['contact']['name']  = trim($m[1]);
            $result['contact']['title'] = trim($m[2]);
            break;
        }
        if (preg_match('/^([A-Z][a-zA-Z\'\-]+(?:\s+[A-Z][a-zA-Z\'\-]+)+)$/', $line) &&
            isset($lines[$i + 1]) &&
            preg_match('/(Manager|Director|Coordinator|Inspector|Engineer|Specialist|Planner|President|Owner)/i', $lines[$i + 1])) {
            $result['contact']['name']  = $line;
            $result['contact']['title'] = $lines[$i + 1];
            break;
        }
    }

    // Entity
    foreach ($lines as $line) {
        if (preg_match('/(Inc\.|LLC|Corp\.|Corporation|Company|Signs?|Marketing|Group|Associates)/i', $line) &&
            !preg_match('/@/', $line) &&
            strlen($line) > 5 && strlen($line) < 80) {
            $result['entity']['name'] = $line;
            break;
        }
    }

    // Address heuristic
    if (preg_match('/(\d+\s+[A-Za-z0-9\.\s]+(?:Street|St|Avenue|Ave|Road|Rd|Drive|Dr|Lane|Ln|Boulevard|Blvd|Way|Court|Ct)\.?\s*(?:Suite|Ste|Unit|#)?\s*\d*)/i', $raw, $m)) {
        $result['location']['streetAddress'] = trim($m[1]);
    }
    if (preg_match('/([A-Za-z\s]+),\s*([A-Z]{2})\s+(\d{5}(?:-\d{4})?)/', $raw, $m)) {
        $result['location']['city']    = trim($m[1]);
        $result['location']['state']   = $m[2];
        $result['location']['zipCode'] = $m[3];
    }

    return $result;
}

function processSignatureExtraction(array $msg, array &$seen): array
{
    $class = classifyMessage($msg);
    if ($class['skip']) {
        return ['accepted' => false, 'reason' => $class['reason']];
    }

    $body = $msg['body'] ?? '';
    if (trim($body) === '') {
        return ['accepted' => false, 'reason' => 'No Body'];
    }

    $candidate = extractCandidateSignature($body);
    if ($candidate === null) {
        return ['accepted' => false, 'reason' => 'No Signature'];
    }

    $score = scoreSignature($candidate);
    if ($score < 3) {
        return ['accepted' => false, 'reason' => 'Low Quality', 'score' => $score];
    }

    $hash = hash('sha256', strtolower(trim($candidate)));
    if (isset($seen[$hash])) {
        return ['accepted' => false, 'reason' => 'Duplicates', 'score' => $score];
    }
    $seen[$hash] = true;

    return [
        'accepted'  => true,
        'reason'    => 'Business Signature',
        'score'     => $score,
        'signature' => $candidate,
    ];
}

// ============================================================
// MAIN
// ============================================================
logMsg('=== Skyesoft ELC Candidate Extraction (v2.1) started ===');
logMsg('JSON directory  : ' . $jsonDir);
logMsg('Output directory: ' . $outputDir);

$files = glob($jsonDir . 'messages_part_*.json');
sort($files);

if (empty($files)) {
    logMsg('ERROR: No messages_part_*.json files found');
    exit(1);
}

$stats = [
    'files_processed' => 0,
    'messages_total'  => 0,
    'accepted'        => 0,
    'duplicates'      => 0,
    'internal'        => 0,
    'automated'       => 0,
    'low_quality'     => 0,
    'system_folder'   => 0,
    'no_body'         => 0,
    'no_signature'    => 0,
];

$seen = [];
$candidates = [];
$sigCounter = 0;

foreach ($files as $filePath) {
    $filename = basename($filePath);
    logMsg("Processing: $filename");

    $raw = file_get_contents($filePath);
    if ($raw === false) {
        logMsg("  ERROR: Could not read $filename");
        continue;
    }

    $messages = json_decode($raw, true);
    if (!is_array($messages)) {
        logMsg("  ERROR: Invalid JSON in $filename");
        continue;
    }

    $stats['files_processed']++;

    foreach ($messages as $msg) {
        $stats['messages_total']++;

        $result = processSignatureExtraction($msg, $seen);

        if (!$result['accepted']) {
            $key = strtolower(str_replace(' ', '_', $result['reason']));
            if (isset($stats[$key])) {
                $stats[$key]++;
            }
            continue;
        }

        $stats['accepted']++;
        $sigCounter++;

        $parsed = parseSignatureFields($result['signature']);

        // Clean every string that will go into JSON
        $candidates[] = [
            'signatureId'   => sprintf('SIG-%06d', $sigCounter),
            'status'        => 'pending',
            'score'         => $result['score'],
            'source'        => [
                'entryId'     => cleanUtf8($msg['entry_id'] ?? null),
                'folder'      => cleanUtf8($msg['folder_path'] ?? null),
                'senderName'  => cleanUtf8($msg['sender_name'] ?? null),
                'senderEmail' => cleanUtf8($msg['sender_email'] ?? null),
                'subject'     => cleanUtf8($msg['subject'] ?? null),
                'receivedAt'  => cleanUtf8($msg['received_at'] ?? null),
            ],
            'entity'        => [
                'name' => $parsed['entity']['name'] ? cleanUtf8($parsed['entity']['name']) : null,
            ],
            'location'      => [
                'streetAddress' => $parsed['location']['streetAddress'] ? cleanUtf8($parsed['location']['streetAddress']) : null,
                'city'          => $parsed['location']['city'] ? cleanUtf8($parsed['location']['city']) : null,
                'state'         => $parsed['location']['state'] ? cleanUtf8($parsed['location']['state']) : null,
                'zipCode'       => $parsed['location']['zipCode'] ? cleanUtf8($parsed['location']['zipCode']) : null,
            ],
            'contact'       => [
                'name'  => $parsed['contact']['name'] ? cleanUtf8($parsed['contact']['name']) : null,
                'title' => $parsed['contact']['title'] ? cleanUtf8($parsed['contact']['title']) : null,
                'phone' => $parsed['contact']['phone'] ? cleanUtf8($parsed['contact']['phone']) : null,
                'email' => $parsed['contact']['email'] ? cleanUtf8($parsed['contact']['email']) : null,
            ],
            'rawSignature'  => cleanUtf8($result['signature']),
        ];
    }

    unset($messages, $raw);
}

// ============================================================
// WRITE STRUCTURED JSON (robust)
// ============================================================
logMsg('Encoding ' . count($candidates) . ' candidates to JSON...');

$jsonOut = json_encode(
    $candidates,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
);

if ($jsonOut === false) {
    $err = json_last_error_msg();
    logMsg('ERROR: json_encode failed – ' . $err);
    // Fallback: try without pretty print
    $jsonOut = json_encode($candidates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($jsonOut === false) {
        logMsg('ERROR: Even compact encode failed – ' . json_last_error_msg());
        exit(1);
    }
    logMsg('Fell back to compact JSON');
}

$bytes = file_put_contents($candidatesFile, $jsonOut);
if ($bytes === false) {
    logMsg('ERROR: file_put_contents failed for ' . $candidatesFile);
    exit(1);
}

logMsg('=== Extraction complete ===');
logMsg("Files processed : {$stats['files_processed']}");
logMsg("Total Messages  : {$stats['messages_total']}");
logMsg("Accepted        : {$stats['accepted']}");
logMsg("Candidates JSON : $candidatesFile (" . number_format($bytes) . " bytes)");

// ============================================================
// LIGHT HTML DEBUG REPORT (first 200 only)
// ============================================================
$html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Skyesoft ELC Candidates v2.1</title>
<style>
body{font-family:system-ui,sans-serif;background:#f8f9fa;margin:0;padding:24px}
.card{background:#fff;border:1px solid #dee2e6;border-radius:8px;margin-bottom:16px;padding:16px}
.sig{background:#f1f3f5;border-left:4px solid #0d6efd;padding:12px;font-family:monospace;white-space:pre-wrap;font-size:0.85rem}
.meta{color:#6c757d;font-size:0.85rem}
</style></head><body>
<h1>Skyesoft ELC Candidates <small>(v2.1 – high recall)</small></h1>
<p class="meta">Generated: ' . date('Y-m-d H:i:s T') . ' | Candidates: ' . count($candidates) . '</p>';

foreach (array_slice($candidates, 0, 200) as $c) {
    $html .= '<div class="card">
        <strong>' . htmlspecialchars($c['signatureId']) . '</strong> – Score: ' . $c['score'] . '<br>
        <span class="meta">' . htmlspecialchars(($c['source']['senderName'] ?? '') . ' &lt;' . ($c['source']['senderEmail'] ?? '') . '&gt;') . '</span>
        <pre class="sig">' . htmlspecialchars($c['rawSignature']) . '</pre>
        <div class="meta">
            Entity: ' . htmlspecialchars($c['entity']['name'] ?? '—') . ' |
            Contact: ' . htmlspecialchars($c['contact']['name'] ?? '—') . ' |
            Phone: ' . htmlspecialchars($c['contact']['phone'] ?? '—') . ' |
            Email: ' . htmlspecialchars($c['contact']['email'] ?? '—') . '
        </div>
    </div>';
}

$html .= '<p class="meta">Showing first 200 of ' . count($candidates) . ' candidates. Full dataset is in elcCandidates.json</p></body></html>';
file_put_contents($reportFile, $html);

logMsg("Light HTML report : $reportFile");
echo "\nDone.\nCandidates: $candidatesFile (" . number_format($bytes) . " bytes)\n";