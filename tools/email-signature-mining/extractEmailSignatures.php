<?php
/**
 * extractEmailSignatures.php
 * Skyesoft – Email Signature Mining – Phase 2
 *
 * Produces structured ELC Candidate JSON optimized for review and
 * conversion into a copyable Entity–Location–Contact Proposal Candidate.
 *
 * Version: 2.3
 * Location: tools/email-signature-mining/extractEmailSignatures.php
 *
 * Input:
 *   emailJSONObjects/messages_part_*.json
 *
 * Output:
 *   emailSignatureExtraction/elcCandidates.json
 *   emailSignatureExtraction/reports/signatureDiscoveryReport.html
 *   emailSignatureExtraction/logs/extraction.log
 */

declare(strict_types=1);

// ============================================================
// SECTION 00 — PATHS
// ============================================================

$baseDir   = __DIR__;
$jsonDir   = $baseDir . '/emailJSONObjects/';
$outputDir = $baseDir . '/emailSignatureExtraction/';
$reportDir = $outputDir . 'reports/';
$logDir    = $outputDir . 'logs/';

foreach ([$outputDir, $reportDir, $logDir] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create directory: ' . $dir);
    }
}

$candidatesFile = $outputDir . 'elcCandidates.json';
$reportFile     = $reportDir . 'signatureDiscoveryReport.html';
$logFile        = $logDir . 'extraction.log';

// ============================================================
// SECTION 01 — CONFIG
// ============================================================

$maxSignatureLines = 12;
$minCandidateChars = 30;
$minRawScore       = 3;
$minElcScore       = 5;
$debugReportLimit  = 200;

$commonClosings = [
    'thanks',
    'thank you',
    'thank you,',
    'best',
    'best,',
    'best regards',
    'best regards,',
    'regards',
    'regards,',
    'sincerely',
    'sincerely,',
    'warm regards',
    'warm regards,',
    'warmest regards',
    'kind regards',
    'kind regards,',
    'cheers',
    'all the best',
    'respectfully',
    'cordially',
];

$signatureTerminators = [
    '-----original message-----',
    '________________________________',
    'confidentiality notice',
    'confidential notice',
    'this email and any attachments',
    'this message and any attachments',
    'the information contained in this email',
    'unsubscribe',
    'manage preferences',
    'privacy policy',
    'view in browser',
    'view online',
    'powered by',
    'terms of use',
    'copyright ©',
    'copyright (c)',
    'you are receiving this email',
    'this message was intended for',
    'get outlook for ios',
    'sent from my iphone',
    'sent from my ipad',
    'sent from outlook',
];

// ============================================================
// SECTION 02 — LOGGING / UTF-8
// ============================================================

function logMsg(string $msg): void
{
    global $logFile;

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

function cleanUtf8(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

    if ($clean === false) {
        $clean = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    return mb_convert_encoding($clean, 'UTF-8', 'UTF-8');
}

// ============================================================
// SECTION 03 — GENERIC TEXT HELPERS
// ============================================================

function normalizeLine(string $line): string
{
    $line = html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $line = preg_replace('/<mailto:([^>]+)>/i', '$1', $line) ?? $line;
    $line = preg_replace('/<https?:\/\/[^>]+>/i', '', $line) ?? $line;
    $line = preg_replace('/\s+/', ' ', $line) ?? $line;

    return trim($line);
}

function normalizeEmail(string $email): string
{
    return strtolower(trim($email));
}

function normalizePhone(string $phone): string
{
    $phone = trim($phone);
    $digits = preg_replace('/\D+/', '', $phone) ?? '';

    if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
        $digits = substr($digits, 1);
    }

    if (strlen($digits) === 10) {
        return sprintf(
            '(%s) %s-%s',
            substr($digits, 0, 3),
            substr($digits, 3, 3),
            substr($digits, 6, 4)
        );
    }

    return $phone;
}

function containsAny(string $haystack, array $needles): bool
{
    $haystack = strtolower($haystack);

    foreach ($needles as $needle) {
        if ($needle !== '' && str_contains($haystack, strtolower($needle))) {
            return true;
        }
    }

    return false;
}

function isLikelyUrlLine(string $line): bool
{
    return (bool) preg_match('/(?:https?:\/\/|www\.|<https?:\/\/)/i', $line);
}

function isLikelyEmailLine(string $line): bool
{
    return (bool) preg_match(
        '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
        $line
    );
}

function isLikelyPhoneLine(string $line): bool
{
    return (bool) preg_match(
        '/(?:\+?1[\s.\-]?)?\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]?\d{4}/',
        $line
    );
}

function isTerminatorLine(string $line): bool
{
    global $signatureTerminators;

    $normalized = strtolower(normalizeLine($line));

    if ($normalized === '') {
        return false;
    }

    foreach ($signatureTerminators as $terminator) {
        if (str_contains($normalized, strtolower($terminator))) {
            return true;
        }
    }

    return false;
}

// ============================================================
// SECTION 04 — MESSAGE CLASSIFICATION
// ============================================================

function classifyMessage(array $msg): array
{
    $folder  = strtolower((string) ($msg['folder_path'] ?? ''));
    $sender  = strtolower((string) ($msg['sender_email'] ?? ''));
    $subject = strtolower((string) ($msg['subject'] ?? ''));

    foreach ([
        'sync issues',
        'conflicts',
        'local failures',
        'server failures',
    ] as $skipFolder) {
        if (str_contains($folder, $skipFolder)) {
            return ['skip' => true, 'reason' => 'System Folder'];
        }
    }

    if (
        str_ends_with($sender, '@christysigns.com') ||
        str_contains($sender, '/cn=steve') ||
        str_contains($sender, '/cn=rocky') ||
        str_contains($sender, '/cn=wendy')
    ) {
        return ['skip' => true, 'reason' => 'Internal'];
    }

    if (
        preg_match(
            '/(?:noreply|no-reply|do-not-reply|mailer-daemon|postmaster|bounce|automated|notification)/i',
            $sender
        ) ||
        preg_match(
            '/(?:newsletter|unsubscribe|quarantine|voicemail|delivery status|password reset|verification code|invitation to bid)/i',
            $subject
        )
    ) {
        return ['skip' => true, 'reason' => 'Automated'];
    }

    return ['skip' => false, 'reason' => null];
}

// ============================================================
// SECTION 05 — BODY / REPLY CLEANING
// ============================================================

function removeQuotedHistory(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $lines = explode("\n", $body);
    $clean = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if (
            preg_match('/^-----Original Message-----$/i', $trimmed) ||
            preg_match('/^From:\s+/i', $trimmed) ||
            preg_match('/^Sent:\s+/i', $trimmed) ||
            preg_match('/^To:\s+/i', $trimmed) ||
            preg_match('/^Cc:\s+/i', $trimmed) ||
            preg_match('/^Subject:\s+/i', $trimmed) ||
            preg_match('/^On .+ wrote:$/i', $trimmed) ||
            preg_match('/^_{5,}$/', $trimmed)
        ) {
            break;
        }

        if (str_starts_with($trimmed, '>')) {
            continue;
        }

        $clean[] = $line;
    }

    return implode("\n", $clean);
}

// ============================================================
// SECTION 06 — SIGNATURE EXTRACTION
// ============================================================

function findLastClosingIndex(array $lines): int
{
    global $commonClosings;

    $lastIndex = -1;

    foreach ($lines as $index => $line) {
        $normalized = strtolower(rtrim(normalizeLine($line), ','));

        foreach ($commonClosings as $closing) {
            $closingNormalized = strtolower(rtrim($closing, ','));

            if (
                $normalized === $closingNormalized ||
                str_starts_with($normalized, $closingNormalized . ' ')
            ) {
                $lastIndex = $index;
            }
        }
    }

    return $lastIndex;
}

function trimSignatureNoise(array $lines): array
{
    $clean = [];

    foreach ($lines as $line) {
        $line = normalizeLine($line);

        if ($line === '') {
            continue;
        }

        if (isTerminatorLine($line)) {
            break;
        }

        if (
            preg_match('/^(facebook|linkedin|twitter|instagram|youtube)\b/i', $line) ||
            preg_match('/^(roc#|license#|license no\.?)/i', $line) ||
            preg_match('/^[<>\[\]\s]*$/', $line)
        ) {
            continue;
        }

        $clean[] = $line;
    }

    return $clean;
}

function extractCandidateSignature(string $body): ?string
{
    global $maxSignatureLines, $minCandidateChars;

    if (trim($body) === '') {
        return null;
    }

    $body = removeQuotedHistory($body);
    $body = preg_replace('/[ \t]+/', ' ', $body) ?? $body;
    $body = preg_replace('/\n{3,}/', "\n\n", $body) ?? $body;

    $lines = array_values(array_filter(
        array_map('normalizeLine', explode("\n", $body)),
        static fn(string $line): bool => $line !== ''
    ));

    if ($lines === []) {
        return null;
    }

    $closingIndex = findLastClosingIndex($lines);

    if ($closingIndex >= 0 && $closingIndex < count($lines) - 1) {
        $candidateLines = array_slice(
            $lines,
            $closingIndex + 1,
            $maxSignatureLines
        );
    } else {
        $candidateLines = array_slice($lines, -$maxSignatureLines);
    }

    $candidateLines = trimSignatureNoise($candidateLines);
    $candidate = trim(implode("\n", $candidateLines));

    if ($candidate === '' || mb_strlen($candidate) < $minCandidateChars) {
        return null;
    }

    return $candidate;
}

// ============================================================
// SECTION 07 — RAW SIGNATURE SCORING
// ============================================================

function scoreSignature(string $signature): int
{
    $score = 0;

    if (isLikelyEmailLine($signature)) {
        $score += 2;
    }

    if (isLikelyPhoneLine($signature)) {
        $score += 2;
    }

    if (preg_match(
        '/\b(?:manager|director|planner|engineer|project|coordinator|inspector|specialist|president|owner|executive|administrator|representative|supervisor|consultant)\b/i',
        $signature
    )) {
        $score += 1;
    }

    if (preg_match(
        '/\b(?:inc\.?|llc|corp\.?|corporation|company|group|associates|solutions|services|signs?|department|city of|county of)\b/i',
        $signature
    )) {
        $score += 1;
    }

    if (preg_match(
        '/\b\d{1,6}\s+[A-Za-z0-9.\'’\- ]+\s+(?:street|st|avenue|ave|road|rd|drive|dr|lane|ln|boulevard|blvd|way|court|ct|parkway|pkwy|highway|hwy)\b/i',
        $signature
    )) {
        $score += 2;
    }

    if (substr_count($signature, "\n") >= 2) {
        $score += 1;
    }

    if (isLikelyUrlLine($signature)) {
        $score -= 1;
    }

    if (containsAny($signature, [
        'unsubscribe',
        'manage preferences',
        'privacy policy',
        'view in browser',
        'copyright',
    ])) {
        $score -= 4;
    }

    return $score;
}

// ============================================================
// SECTION 08 — FIELD VALIDATION HELPERS
// ============================================================

function isValidPersonName(string $value): bool
{
    $value = normalizeLine($value);

    if ($value === '' || mb_strlen($value) < 4 || mb_strlen($value) > 70) {
        return false;
    }

    if (
        isLikelyUrlLine($value) ||
        isLikelyEmailLine($value) ||
        isLikelyPhoneLine($value) ||
        preg_match('/\d/', $value)
    ) {
        return false;
    }

    if (containsAny($value, [
        'unsubscribe',
        'manage preferences',
        'privacy',
        'policy',
        'customer service',
        'general contractor',
        'view',
        'support team',
        'marketing team',
        'newsletter',
        'department',
        'office closed',
        'sent from',
        'project status',
        'bid due',
    ])) {
        return false;
    }

    return (bool) preg_match(
        '/^[A-Z][A-Za-z\'’\-]+(?:\s+(?:[A-Z]\.|[A-Z][A-Za-z\'’\-]+)){1,4}(?:,\s*[A-Z]{2,10})?$/u',
        $value
    );
}

function isValidTitle(string $value): bool
{
    $value = normalizeLine($value);

    if ($value === '' || mb_strlen($value) < 3 || mb_strlen($value) > 100) {
        return false;
    }

    if (
        isLikelyUrlLine($value) ||
        isLikelyEmailLine($value) ||
        containsAny($value, [
            'unsubscribe',
            'manage preferences',
            'privacy policy',
            'view online',
            'copyright',
            'click here',
            'terms of use',
        ])
    ) {
        return false;
    }

    return (bool) preg_match(
        '/\b(?:manager|director|coordinator|inspector|engineer|specialist|planner|president|owner|executive|representative|administrator|supervisor|consultant|officer|agent|associate|project manager|account manager|sales|operations|development|estimator|permit|principal)\b/i',
        $value
    );
}

function isValidEntityName(string $value): bool
{
    $value = normalizeLine($value);

    if ($value === '' || mb_strlen($value) < 3 || mb_strlen($value) > 80) {
        return false;
    }

    if (
        isLikelyUrlLine($value) ||
        isLikelyEmailLine($value) ||
        isLikelyPhoneLine($value) ||
        containsAny($value, [
            'unsubscribe',
            'manage preferences',
            'privacy',
            'copyright',
            'view ',
            'click here',
            'terms of use',
            'sent from',
            'office:',
            'phone:',
            'fax:',
            'email:',
            'web:',
            'roc#',
            'ticket#',
            'contractor, please',
            'this message',
        ])
    ) {
        return false;
    }

    if (substr_count($value, ',') > 2) {
        return false;
    }

    return !preg_match('/^[A-Z][a-z]+\s+[A-Z][a-z]+$/', $value);
}

function isValidStreetAddress(string $value): bool
{
    $value = normalizeLine($value);

    if ($value === '' || mb_strlen($value) > 140) {
        return false;
    }

    if (
        isLikelyUrlLine($value) ||
        containsAny($value, [
            'unsubscribe',
            'privacy',
            'customer',
            'please',
            'copyright',
            'california public',
            'you are receiving',
            'message intended',
        ])
    ) {
        return false;
    }

    // Updated regex to handle trailing suite/unit/apartment designation (#2142, Ste 100, etc.)
    return (bool) preg_match(
        '/\b\d{1,6}\s+[A-Za-z0-9.\'’\-\s]+(?:street|st|avenue|ave|road|rd|drive|dr|lane|ln|boulevard|blvd|way|court|ct|parkway|pkwy|highway|hwy|circle|cir|trail|trl)\b(?:\s*,\s*(?:suite|ste|unit|apt|#)\s*[A-Za-z0-9\-]+)?/i',
        $value
    );
}

// ============================================================
// SECTION 09 — FIELD PARSER
// ============================================================

function parseSignatureFields(string $raw): array
{
    $result = [
        'entity' => [
            'name' => null,
        ],
        'location' => [
            'streetAddress' => null,
            'city'          => null,
            'state'         => null,
            'zipCode'       => null,
        ],
        'contact' => [
            'name'  => null,
            'title' => null,
            'phone' => null,
            'email' => null,
        ],
    ];

    $lines = array_values(array_filter(
        array_map('normalizeLine', explode("\n", $raw)),
        static fn(string $line): bool => $line !== ''
    ));

    if ($lines === []) {
        return $result;
    }

    // Email
    if (preg_match(
        '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
        $raw,
        $emailMatch
    )) {
        $result['contact']['email'] = normalizeEmail($emailMatch[0]);
    }

    // Phone
    if (preg_match(
        '/(?:\+?1[\s.\-]?)?\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]?\d{4}/',
        $raw,
        $phoneMatch
    )) {
        $result['contact']['phone'] = normalizePhone($phoneMatch[0]);
    }

    // Contact name and title
    foreach ($lines as $index => $line) {
        if (preg_match(
            '/^(.+?)\s*[|–—]\s*(.+)$/u',
            $line,
            $combinedMatch
        )) {
            $possibleName  = trim($combinedMatch[1]);
            $possibleTitle = trim($combinedMatch[2]);

            if (isValidPersonName($possibleName)) {
                $result['contact']['name'] = $possibleName;

                if (isValidTitle($possibleTitle)) {
                    $result['contact']['title'] = $possibleTitle;
                }

                break;
            }
        }

        if (isValidPersonName($line)) {
            $result['contact']['name'] = $line;

            $nextLine = $lines[$index + 1] ?? null;
            if ($nextLine !== null && isValidTitle($nextLine)) {
                $result['contact']['title'] = $nextLine;
            }

            break;
        }
    }

    // Address Parsing (Single-Line and Multi-Line Support)
    foreach ($lines as $index => $line) {
        // Option A: Single Line Address Format (e.g. "13580 5th Street, Chino CA 91710")
        if (preg_match(
            '/^(\d{1,6}\s+.+?\b(?:street|st|avenue|ave|road|rd|drive|dr|lane|ln|boulevard|blvd|way|court|ct|parkway|pkwy|highway|hwy|circle|cir)\b\.?)\s*,?\s*([A-Za-z.\'’\- ]+),?\s+([A-Z]{2})\s+(\d{5}(?:-\d{4})?)$/i',
            $line,
            $singleLineMatch
        )) {
            $result['location']['streetAddress'] = trim($singleLineMatch[1]);
            $result['location']['city']          = trim($singleLineMatch[2]);
            $result['location']['state']         = strtoupper($singleLineMatch[3]);
            $result['location']['zipCode']       = $singleLineMatch[4];
            break;
        }

        // Option B: Multi-Line Address Format
        if (isValidStreetAddress($line)) {
            $result['location']['streetAddress'] = $line;

            $nextLine = $lines[$index + 1] ?? '';
            $nextNext = $lines[$index + 2] ?? '';

            $cityLine = $nextLine;

            // Check if the suite/unit was put on its own separate line
            if (
                $nextLine !== '' &&
                preg_match('/^(?:suite|ste|unit|apt|#)\s*[A-Za-z0-9\-]+$/i', $nextLine)
            ) {
                $result['location']['streetAddress'] .= ', ' . $nextLine;
                $cityLine = $nextNext;
            }

            // Match "Chandler, AZ 85226"
            if (preg_match(
                '/^([A-Za-z.\'’\-\s]+),\s*([A-Z]{2})\s+(\d{5}(?:-\d{4})?)$/i',
                $cityLine,
                $cityMatch
            )) {
                $result['location']['city']    = trim($cityMatch[1]);
                $result['location']['state']   = strtoupper($cityMatch[2]);
                $result['location']['zipCode'] = $cityMatch[3];
            }

            break;
        }
    }

    // Fallback city/state/ZIP search
    if ($result['location']['city'] === null) {
        foreach ($lines as $line) {
            if (preg_match(
                '/^([A-Za-z.\'’\- ]+),?\s+([A-Z]{2})\s+(\d{5}(?:-\d{4})?)$/',
                $line,
                $cityMatch
            )) {
                $result['location']['city']    = trim($cityMatch[1]);
                $result['location']['state']   = strtoupper($cityMatch[2]);
                $result['location']['zipCode'] = $cityMatch[3];
                break;
            }
        }
    }

    // Entity Parsing
    $nameIndex    = null;
    $titleIndex   = null;
    $addressIndex = null;

    foreach ($lines as $index => $line) {
        if ($result['contact']['name'] !== null && $line === $result['contact']['name']) {
            $nameIndex = $index;
        }

        if ($result['contact']['title'] !== null && $line === $result['contact']['title']) {
            $titleIndex = $index;
        }

        if (
            $result['location']['streetAddress'] !== null &&
            str_starts_with($result['location']['streetAddress'], $line)
        ) {
            $addressIndex = $index;
        }
    }

    $preferredIndexes = [];

    if ($titleIndex !== null) {
        $preferredIndexes[] = $titleIndex + 1;
    }

    if ($nameIndex !== null) {
        $preferredIndexes[] = $nameIndex + 1;
        $preferredIndexes[] = $nameIndex + 2;
    }

    if ($addressIndex !== null) {
        $preferredIndexes[] = $addressIndex - 1;
    }

    foreach (array_unique($preferredIndexes) as $index) {
        if (!isset($lines[$index])) {
            continue;
        }

        $candidate = $lines[$index];

        if (
            isValidEntityName($candidate) &&
            $candidate !== $result['contact']['name'] &&
            $candidate !== $result['contact']['title']
        ) {
            $result['entity']['name'] = $candidate;
            break;
        }
    }

    // General entity fallback
    if ($result['entity']['name'] === null) {
        foreach ($lines as $line) {
            if (
                $line === $result['contact']['name'] ||
                $line === $result['contact']['title'] ||
                $line === $result['location']['streetAddress'] ||
                isLikelyEmailLine($line) ||
                isLikelyPhoneLine($line)
            ) {
                continue;
            }

            if (
                isValidEntityName($line) &&
                (
                    preg_match(
                        '/\b(?:inc\.?|llc|corp\.?|corporation|company|group|associates|solutions|services|signs?|department|city of|county of|insurance|construction|development|international|technologies|systems|partners?)\b/i',
                        $line
                    ) ||
                    ($nameIndex !== null && array_search($line, $lines, true) > $nameIndex)
                )
            ) {
                $result['entity']['name'] = $line;
                break;
            }
        }
    }

    return $result;
}

// ============================================================
// SECTION 10 — ELC VALIDATION / SCORING
// ============================================================

function scoreParsedELC(array $parsed): int
{
    $score = 0;

    if (!empty($parsed['contact']['name'])) {
        $score += 3;
    }

    if (!empty($parsed['contact']['email'])) {
        $score += 2;
    }

    if (!empty($parsed['contact']['phone'])) {
        $score += 2;
    }

    if (!empty($parsed['contact']['title'])) {
        $score += 1;
    }

    if (!empty($parsed['entity']['name'])) {
        $score += 2;
    }

    if (!empty($parsed['location']['streetAddress'])) {
        $score += 2;
    }

    if (
        !empty($parsed['location']['city']) &&
        !empty($parsed['location']['state']) &&
        !empty($parsed['location']['zipCode'])
    ) {
        $score += 2;
    }

    return $score;
}

function validateELC(array $parsed, string $raw): array
{
    global $minElcScore;

    $reasons = [];

    $contactName = $parsed['contact']['name'] ?? null;
    $email       = $parsed['contact']['email'] ?? null;
    $phone       = $parsed['contact']['phone'] ?? null;
    $entity      = $parsed['entity']['name'] ?? null;
    $street      = $parsed['location']['streetAddress'] ?? null;

    if ($contactName === null) {
        $reasons[] = 'Missing Contact Name';
    }

    if ($email === null && $phone === null) {
        $reasons[] = 'Missing Contact Method';
    }

    if ($entity === null && $street === null) {
        $reasons[] = 'Missing Entity and Location';
    }

    if (containsAny($raw, [
        'manage preferences',
        'unsubscribe',
        'privacy policy',
        'this message was intended for',
        'you are receiving this email',
    ])) {
        $reasons[] = 'Marketing or Footer Content';
    }

    $score = scoreParsedELC($parsed);

    if ($score < $minElcScore) {
        $reasons[] = 'ELC Score Below Threshold';
    }

    return [
        'valid'   => $reasons === [],
        'score'   => $score,
        'reasons' => $reasons,
    ];
}

// ============================================================
// SECTION 11 — SIGNATURE DEDUPLICATION
// ============================================================

function buildCandidateHash(array $parsed, string $raw): string
{
    $email = normalizeEmail((string) ($parsed['contact']['email'] ?? ''));

    if ($email !== '') {
        return hash('sha256', 'email:' . $email);
    }

    $name   = strtolower(trim((string) ($parsed['contact']['name'] ?? '')));
    $phone  = preg_replace('/\D+/', '', (string) ($parsed['contact']['phone'] ?? '')) ?? '';
    $entity = strtolower(trim((string) ($parsed['entity']['name'] ?? '')));

    if ($name !== '' && ($phone !== '' || $entity !== '')) {
        return hash('sha256', implode('|', [$name, $phone, $entity]));
    }

    return hash('sha256', strtolower(trim($raw)));
}

// ============================================================
// SECTION 12 — PROCESS ONE MESSAGE
// ============================================================

function processSignatureExtraction(array $msg, array &$seen): array
{
    global $minRawScore;

    $classification = classifyMessage($msg);

    if ($classification['skip']) {
        return [
            'accepted' => false,
            'reason'   => $classification['reason'],
        ];
    }

    $body = (string) ($msg['body'] ?? '');

    if (trim($body) === '') {
        return [
            'accepted' => false,
            'reason'   => 'No Body',
        ];
    }

    $signature = extractCandidateSignature($body);

    if ($signature === null) {
        return [
            'accepted' => false,
            'reason'   => 'No Signature',
        ];
    }

    $rawScore = scoreSignature($signature);

    if ($rawScore < $minRawScore) {
        return [
            'accepted' => false,
            'reason'   => 'Low Quality',
            'rawScore' => $rawScore,
        ];
    }

    $parsed = parseSignatureFields($signature);
    $validation = validateELC($parsed, $signature);

    if (!$validation['valid']) {
        return [
            'accepted'   => false,
            'reason'     => 'Invalid ELC',
            'rawScore'   => $rawScore,
            'elcScore'   => $validation['score'],
            'validation' => $validation['reasons'],
        ];
    }

    $hash = buildCandidateHash($parsed, $signature);

    if (isset($seen[$hash])) {
        return [
            'accepted' => false,
            'reason'   => 'Duplicates',
            'rawScore' => $rawScore,
            'elcScore' => $validation['score'],
        ];
    }

    $seen[$hash] = true;

    return [
        'accepted'  => true,
        'reason'    => 'ELC Signature',
        'rawScore'  => $rawScore,
        'elcScore'  => $validation['score'],
        'signature' => $signature,
        'parsed'    => $parsed,
    ];
}

// ============================================================
// SECTION 13 — MAIN
// ============================================================

logMsg('=== Skyesoft ELC Candidate Extraction (v2.3) started ===');
logMsg('JSON directory  : ' . $jsonDir);
logMsg('Output directory: ' . $outputDir);

$files = glob($jsonDir . 'messages_part_*.json');
sort($files);

if ($files === []) {
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
    'invalid_elc'     => 0,
    'low_quality'     => 0,
    'system_folder'   => 0,
    'no_body'         => 0,
    'no_signature'    => 0,
];

$seen       = [];
$candidates = [];
$sigCounter = 0;

foreach ($files as $filePath) {
    $filename = basename($filePath);
    logMsg('Processing: ' . $filename);

    $raw = file_get_contents($filePath);

    if ($raw === false) {
        logMsg('  ERROR: Could not read ' . $filename);
        continue;
    }

    $messages = json_decode($raw, true);

    if (!is_array($messages)) {
        logMsg('  ERROR: Invalid JSON in ' . $filename . ' — ' . json_last_error_msg());
        continue;
    }

    $stats['files_processed']++;

    foreach ($messages as $msg) {
        if (!is_array($msg)) {
            continue;
        }

        $stats['messages_total']++;

        $result = processSignatureExtraction($msg, $seen);

        if (!$result['accepted']) {
            $key = strtolower(str_replace(' ', '_', (string) $result['reason']));

            if (array_key_exists($key, $stats)) {
                $stats[$key]++;
            }

            continue;
        }

        $stats['accepted']++;
        $sigCounter++;

        $parsed = $result['parsed'];

        // Build combined full address string if parts exist
        $fullAddress = $parsed['location']['streetAddress'];
        if (!empty($parsed['location']['city'])) {
            $fullAddress .= ($fullAddress ? ', ' : '') . $parsed['location']['city'];
            if (!empty($parsed['location']['state'])) {
                $fullAddress .= ' ' . $parsed['location']['state'];
            }
            if (!empty($parsed['location']['zipCode'])) {
                $fullAddress .= ' ' . $parsed['location']['zipCode'];
            }
        }

        $candidates[] = [
            'signatureId' => sprintf('SIG-%06d', $sigCounter),
            'status'      => 'pending',
            'score'       => [
                'raw' => $result['rawScore'],
                'elc' => $result['elcScore'],
            ],
            'source'      => [
                'entryId'     => cleanUtf8($msg['entry_id'] ?? null),
                'folder'      => cleanUtf8($msg['folder_path'] ?? null),
                'senderName'  => cleanUtf8($msg['sender_name'] ?? null),
                'senderEmail' => cleanUtf8($msg['sender_email'] ?? null),
                'subject'     => cleanUtf8($msg['subject'] ?? null),
                'receivedAt'  => cleanUtf8($msg['received_at'] ?? null),
            ],
            'entity'      => [
                'name' => $parsed['entity']['name'] !== null
                    ? cleanUtf8($parsed['entity']['name'])
                    : null,
            ],
            'location'    => [
                'streetAddress' => $fullAddress !== null ? cleanUtf8($fullAddress) : null,
                'city'          => $parsed['location']['city'] !== null
                    ? cleanUtf8($parsed['location']['city'])
                    : null,
                'state'         => $parsed['location']['state'] !== null
                    ? cleanUtf8($parsed['location']['state'])
                    : null,
                'zipCode'       => $parsed['location']['zipCode'] !== null
                    ? cleanUtf8($parsed['location']['zipCode'])
                    : null,
            ],
            'contact'     => [
                'name' => $parsed['contact']['name'] !== null
                    ? cleanUtf8($parsed['contact']['name'])
                    : null,
                'title' => $parsed['contact']['title'] !== null
                    ? cleanUtf8($parsed['contact']['title'])
                    : null,
                'phone' => $parsed['contact']['phone'] !== null
                    ? cleanUtf8($parsed['contact']['phone'])
                    : null,
                'email' => $parsed['contact']['email'] !== null
                    ? cleanUtf8($parsed['contact']['email'])
                    : null,
            ],
            'rawSignature' => cleanUtf8($result['signature']),
        ];
    }

    unset($messages, $raw);
}

// ============================================================
// SECTION 14 — WRITE JSON
// ============================================================

logMsg('Encoding ' . count($candidates) . ' candidates to JSON...');

$jsonOut = json_encode(
    $candidates,
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_INVALID_UTF8_SUBSTITUTE
);

if ($jsonOut === false) {
    logMsg('ERROR: json_encode failed — ' . json_last_error_msg());

    $jsonOut = json_encode(
        $candidates,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($jsonOut === false) {
        logMsg('ERROR: Compact json_encode also failed — ' . json_last_error_msg());
        exit(1);
    }

    logMsg('Fell back to compact JSON');
}

$bytes = file_put_contents($candidatesFile, $jsonOut, LOCK_EX);

if ($bytes === false) {
    logMsg('ERROR: Failed to write output file to ' . $candidatesFile);
    exit(1);
}

logMsg('Successfully wrote ' . $bytes . ' bytes to ' . $candidatesFile);
logMsg('=== Signature Extraction Complete ===');