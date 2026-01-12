<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — mutator.php
 *  Role: Governed Reconciler (Positive Corrective Action)
 *  Authority: Reconciliation Governance Standard (RGS) under AGS v2
 *  PHP: 8.3+
 *
 *  Governance Principles (January 2026):
 *   • Only merkleIntegrity and repositoryInventoryConformance are eligible
 *   • Resolution occurs ONLY when the specific violation condition disappears
 *   • Original observation and timestamp are immutable
 *   • AI narrative is attempted only if OPENAI_API_KEY and prompt are available
 *   • Silent degradation (no narrative) when AI is unavailable — never fallback text
 *   • Supports both passive (manual/external) and active (automated rebuild) resolution
 *   • No automatic resolution based solely on artifact presence
 *   • Skips reconciliation during verification/sentinel passes
 *
 * ===================================================================== */

#region SECTION 0 — Environment & Paths

$rootDir  = dirname(__DIR__);
$dataDir  = $rootDir . '/data/records';

$auditLogPath   = $dataDir . '/auditResults.json';
$merkleRootPath = $dataDir . '/codexMerkleRoot.txt';
$codexPath      = $rootDir . '/codex/codex.json';

// ⬇️ LOAD ENVIRONMENT EARLY (CRITICAL)
loadEnvLocal($rootDir);

#endregion

#region SECTION I — Load Audit Ledger

if (!file_exists($auditLogPath)) {
    exit(1);
}

$ledger = json_decode(file_get_contents($auditLogPath), true);
if (!is_array($ledger) || !isset($ledger['violations']) || !is_array($ledger['violations'])) {
    exit(1);
}

$auditLog = &$ledger['violations'];

if (!is_array($auditLog)) {
    exit(1);
}

#endregion

#region SECTION II — Helpers

function now(): int
{
    return time();
}

function loadEnvLocal(string $root): void
{
    $envPath = $root . '/secure/env.local';
    if (!file_exists($envPath)) return;

    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;

        [$key, $value] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}

function runCodexMerkleBuilder(): bool
{
    global $rootDir;
    $cmd = 'php ' . escapeshellarg($rootDir . '/scripts/codexMerkleBuilder.php');
    exec($cmd, $out, $code);
    return $code === 0;
}

function readMerkleRoot(): ?string
{
    global $merkleRootPath;
    if (!file_exists($merkleRootPath)) {
        return null;
    }
    return trim(file_get_contents($merkleRootPath));
}

/**
 * Exact same normalization logic as used in auditor.php and codexMerkleBuilder.php
 */
function normalizeJson(mixed $data): mixed
{
    if (is_array($data)) {
        ksort($data, SORT_STRING);
        foreach ($data as $key => &$value) {
            $value = normalizeJson($value);
        }
        unset($value);
    }
    return $data;
}

/**
 * Compute the current observed Merkle root from live codex.json
 * Must match Auditor's exact computation: sha256( sha256( json_encode(normalized) ) )
 */
function computeCurrentObservedRoot(string $codexPath): ?string
{
    if (!file_exists($codexPath)) {
        return null;
    }

    $codex = json_decode(file_get_contents($codexPath), true);
    if (!is_array($codex)) {
        return null;
    }

    $normalized = normalizeJson($codex);
    $encoded = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($encoded === false) {
        return null;
    }

    $innerHash = hash('sha256', $encoded);
    return hash('sha256', $innerHash);
}

/**
 * Render resolution narrative — governed, non-authoritative, silent degradation.
 * Returns structured narrative ONLY on successful AI call.
 * Returns null otherwise — resolution timestamp still applies without narrative.
 */
function renderResolutionNarrative(
    array $facts,
    string $ruleId,
    string $rootDir
): ?array {
    $promptPath = $rootDir . '/codex/prompts/resolutionNarrative.prompt';

    if (!file_exists($promptPath)) {
        return null;
    }

    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) {
        return null;
    }

    $template = @file_get_contents($promptPath);
    if ($template === false) {
        return null;
    }

    $enhancedFacts = $facts;
    $enhancedFacts['ruleId'] = $ruleId;

    $factsJson = json_encode(
        $enhancedFacts,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    $prompt = str_replace('{{FACTS_JSON}}', $factsJson, $template);

    $payload = json_encode([
        'model'       => 'gpt-4o-mini',
        'temperature' => 0.0,
        'max_tokens'  => 450,
        'messages'    => [
            ['role' => 'system', 'content' => $prompt]
        ]
    ], JSON_UNESCAPED_SLASHES);

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ],
            'content' => $payload,
            'timeout' => 15
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true
        ]
    ]);

    $response = @file_get_contents('https://api.openai.com/v1/chat/completions', false, $context);
    if ($response === false) {
        return null;
    }

    $data = json_decode($response, true);
    if (
        json_last_error() !== JSON_ERROR_NONE ||
        !isset($data['choices'][0]['message']['content'])
    ) {
        return null;
    }

    $content = trim($data['choices'][0]['message']['content'] ?? '');
    $content = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $content);
    $content = trim($content);

    $parsed = json_decode($content, true);

    if (
        is_array($parsed) &&
        isset($parsed['summary']) && is_string($parsed['summary']) &&
        isset($parsed['details']) && is_array($parsed['details'])
    ) {
        return [
            'summary' => $parsed['summary'],
            'details' => array_values($parsed['details'])
        ];
    }

    return null;
}

#endregion

#region SECTION III — Governed Reconciliation Engine

// Safety: skip reconciliation in verification/sentinel passes
if (defined('SKYESOFT_VERIFICATION_PASS') && SKYESOFT_VERIFICATION_PASS) {
    echo "✔ Verification pass detected — reconciliation skipped\n";
    exit(0);
}

$updated = false;

foreach ($auditLog as &$violation) {

    /* ── COMPLETION GUARD: materialize inferred resolution ───────────── */
    if (
        ($violation['resolved'] ?? null) !== null &&
        ($violation['resolution'] ?? null) === null &&
        in_array($violation['ruleId'] ?? '', [
            'merkleIntegrity',
            'repositoryInventoryConformance'
        ], true)
    ) {
        $facts = $violation['facts'] ?? [];
        $ruleId = $violation['ruleId'];

        $violation['resolution'] = [
            'method'              => 'passive',
            'reconciliationClass' => 'INFERRED_BY_NON_OBSERVATION',
            'facts'               => $facts
        ];

        // 🔹 NEW: attempt governed resolution narrative
        $narrative = renderResolutionNarrative($facts, $ruleId, $rootDir);

        if ($narrative !== null) {
            $violation['resolutionNotes'] = [
                'summary' => $narrative['summary'],
                'details' => $narrative['details']
            ];
        }

        $updated = true;
        continue;
    }

    /* ── NORMAL SKIP LOGIC ─────────────────────────────────────────── */
    if (
        ($violation['type'] ?? 'violation') !== 'violation' ||
        ($violation['resolved'] ?? null) !== null
    ) {
        continue;
    }

    $ruleId = $violation['ruleId'] ?? '';
    if (!in_array($ruleId, ['merkleIntegrity', 'repositoryInventoryConformance'], true)) {
        continue;
    }

    $facts = [];
    $reconciliationClass = '';
    $method = '';

    // ── merkleIntegrity case ────────────────────────────────────────────────
    if ($ruleId === 'merkleIntegrity') {

        $currentGovernedRoot = readMerkleRoot();
        if (!is_string($currentGovernedRoot) || $currentGovernedRoot === '') {
            continue;
        }

        $currentObservedRoot = computeCurrentObservedRoot($codexPath);
        if (!is_string($currentObservedRoot) || $currentObservedRoot === '') {
            continue;
        }

        $facts = [
            'artifact'            => 'codex/codex.json',
            'currentObservedRoot' => $currentObservedRoot,
            'currentGovernedRoot' => $currentGovernedRoot,
        ];

        // PASSIVE RESOLUTION: condition already gone (manual/external fix)
        if ($currentObservedRoot === $currentGovernedRoot) {
            $facts['merkleBuilderRun'] = false;
            $method = 'passive';
            $reconciliationClass = 'STATE_CONVERGENCE';
        }
        // ACTIVE RESOLUTION: perform rebuild to make governed root match current codex
        else {
            $facts['governedRootBefore'] = $currentGovernedRoot;
            $facts['merkleBuilderRun'] = false; // will be updated if successful

            if (!runCodexMerkleBuilder()) {
                continue;
            }

            $governedRootAfter = readMerkleRoot();
            if (!is_string($governedRootAfter) || $governedRootAfter === '') {
                continue;
            }

            // Defensive: recompute observed root after builder
            $observedAfter = computeCurrentObservedRoot($codexPath);
            if (!is_string($observedAfter) || $observedAfter === '') {
                continue;
            }

            if ($observedAfter !== $governedRootAfter) {
                continue;
            }

            $facts['governedRootAfter'] = $governedRootAfter;
            $facts['currentObservedRootAfter'] = $observedAfter;
            $facts['merkleBuilderRun'] = true;

            $method = 'automated';
            $reconciliationClass = 'GOVERNANCE_REBUILD';
        }
    }

    // ── repositoryInventoryConformance case ─────────────────────────────────
    elseif ($ruleId === 'repositoryInventoryConformance') {

        $path     = $violation['facts']['path']     ?? null;
        $issue    = $violation['facts']['issue']    ?? null;
        $type     = $violation['facts']['type']     ?? null;
        $expected = $violation['facts']['expected'] ?? null;

        if (!$path || !is_string($path)) {
            continue;
        }

        $absolutePath = $rootDir . '/' . ltrim($path, '/');
        $exists = file_exists($absolutePath);

        // ── CASE 1: unexpected artifact (your test case)
        if ($issue === 'unexpected') {

            // Condition is gone when artifact no longer exists
            if ($exists) {
                continue;
            }

            $facts = [
                'path'             => $path,
                'type'             => $type ?? 'missing',
                'issue'            => $issue,                        // 'unexpected'
                'expected'         => $expected ?? 'missing',
                'observationCount' => $violation['observationCount'] ?? 1,
                'exists'           => false
            ];

            $method = 'passive';
            $reconciliationClass = 'STRUCTURAL_REMOVAL';
        }

        // ── CASE 2: declared but missing artifact
        elseif ($issue === 'missing' && in_array($expected, ['file', 'dir'], true)) {

            if (!$exists) {
                continue;
            }

            $facts = [
                'path'             => $path,
                'type'             => $type ?? 'missing',
                'issue'            => $issue,                        // 'missing'
                'expected'         => $expected,                     // 'file' or 'dir'
                'observationCount' => $violation['observationCount'] ?? 1,
                'exists'           => true
            ];

            $method = 'passive';
            $reconciliationClass = 'STRUCTURAL_RESTORATION';
        }

        // ── CASE 3: type mismatch → manual only
        else {
            continue;
        }
    }

    // Resolution conditions satisfied → record it
    $violation['resolved'] = now();

    // Attempt optional governed narrative (silent on failure)
    $narrative = renderResolutionNarrative($facts, $ruleId, $rootDir);

    $violation['resolution'] = [
        'method'              => $method,
        'reconciliationClass' => $reconciliationClass,
        'facts'               => $facts,
    ];

    if ($narrative !== null) {
        $violation['resolutionNotes'] = [
            'summary' => $narrative['summary'],
            'details' => $narrative['details']
        ];
    }

    $updated = true;
}

unset($violation);

#endregion

#region SECTION IV — Persist Changes

if ($updated) {
    file_put_contents(
        $auditLogPath,
        json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}

echo "✔ Governed reconciliation run complete\n";

#endregion