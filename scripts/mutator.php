<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — mutator.php
 *  Role: Reconciler / Mutator (Positive Corrective Action)
 *  Authority: Reconciliation Governance Standard (RGS)
 *  PHP: 8.3+
 *
 *  Purpose:
 *   • Perform governed corrective actions after audit
 *   • Write resolution metadata with verifiable facts
 *   • Never assert correctness — only record actions taken
 *
 * ===================================================================== */

#region SECTION 0 — Environment

$rootDir  = dirname(__DIR__);
$dataDir  = $rootDir . '/data/records';

$auditLogPath   = $dataDir . '/auditResults.json';
$merkleRootPath = $dataDir . '/codexMerkleRoot.txt';
$merkleTreePath = $dataDir . '/codexMerkleTree.json';
$codexPath      = $rootDir . '/codex/codex.json';

#endregion SECTION 0

#region SECTION I — Load Audit Ledger

if (!file_exists($auditLogPath)) {
    exit(1);
}

$auditLog = json_decode(file_get_contents($auditLogPath), true);
if (!is_array($auditLog)) {
    exit(1);
}

#endregion SECTION I

#region SECTION II — Helpers

function now(): int
{
    return time();
}

function runCodexMerkleBuilder(): bool
{
    global $rootDir;

    $cmd = 'php ' . escapeshellarg($rootDir . '/scripts/codexMerkleBuilder.php');
    exec($cmd, $out, $code);
    return $code === 0;
}

if (!function_exists('loadEnvLocal')) {
    function loadEnvLocal(string $root): bool
    {
        $candidates = [
            $root . '/secure/env.local',
            str_replace('\\Documents\\', '\\OneDrive\\Documents\\', $root) . '/secure/env.local',
            dirname($root) . '/secure/env.local',
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                        continue;
                    }
                    [$key, $value] = explode('=', $line, 2);
                    putenv(trim($key) . '=' . trim($value));
                }
                return true;
            }
        }
        return false;
    }
}

function readMerkleRoot(): ?string
{
    global $merkleRootPath;
    if (!file_exists($merkleRootPath)) {
        return null;
    }
    return trim(file_get_contents($merkleRootPath));
}

function generateResolutionNarrative(
    array $violation,
    array $facts,
    string $promptFile
): ?array {

    if (!file_exists($promptFile)) {
        return null;
    }

    loadEnvLocal(realpath(dirname(__DIR__)));

    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) {
        return null;
    }

    $template = file_get_contents($promptFile);
    if ($template === false) {
        return null;
    }

    $payloadPrompt = str_replace(
        ['{{VIOLATION_JSON}}', '{{FACTS_JSON}}'],
        [
            json_encode($violation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ],
        $template
    );

    $payload = json_encode([
        'model'       => 'gpt-4o-mini',
        'temperature' => 0.0,
        'messages'    => [
            ['role' => 'system', 'content' => $payloadPrompt]
        ]
    ]);

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

    $response = @file_get_contents(
        'https://api.openai.com/v1/chat/completions',
        false,
        $context
    );

    if ($response === false) {
        return null;
    }

    $data = json_decode($response, true);
    if (!isset($data['choices'][0]['message']['content'])) {
        return null;
    }

    $content = trim($data['choices'][0]['message']['content']);

    if (preg_match('/^```/', $content)) {
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $content);
        $content = trim($content);
    }

    $parsed = json_decode($content, true);

    if (
        !is_array($parsed) ||
        !isset($parsed['summary'], $parsed['details']) ||
        !is_array($parsed['details'])
    ) {
        return null;
    }

    return [
        'summary' => $parsed['summary'],
        'details' => array_values($parsed['details'])
    ];
}

#endregion SECTION II

#region SECTION III — Reconciliation Engine

$updated = false;

foreach ($auditLog as &$v) {

    if (
        ($v['type'] ?? 'violation') !== 'violation' ||
        ($v['resolved'] ?? null) !== null
    ) {
        continue;
    }

    if (($v['ruleId'] ?? '') !== 'merkleIntegrity') {
        continue;
    }

    // Auditor’s authoritative observed root at detection time
    $observedRoot = $v['facts']['observedRoot'] ?? null;
    if (!is_string($observedRoot) || $observedRoot === '') {
        continue;
    }

    // Governed root BEFORE rebuild
    $governedRootBefore = readMerkleRoot();
    $governedRootBefore = (is_string($governedRootBefore) && $governedRootBefore !== '') 
        ? $governedRootBefore 
        : 'missing';

    // Execute corrective action
    if (!runCodexMerkleBuilder()) {
        continue;
    }

    // Governed root AFTER rebuild
    $governedRootAfter = readMerkleRoot();
    if (!is_string($governedRootAfter) || $governedRootAfter === '') {
        continue;
    }

    // === INVARIANTS ===
    // 1. Actual snapshot change occurred (unless previously missing)
    if ($governedRootBefore !== 'missing' && $governedRootBefore === $governedRootAfter) {
        continue;
    }

    // 2. Rebuild successfully reconciled to auditor's observed state
    if ($governedRootAfter !== $observedRoot) {
        continue;
    }

    // Canonical observation — the verdict
    $canonicalObservation = $v['observation'] 
        ?? 'Merkle integrity violation: observed Codex state does not match governed Merkle snapshot.';

    $facts = [
        'artifact'            => 'codex/codex.json',
        'governedRootBefore'  => $governedRootBefore,
        'observedRoot'        => $observedRoot,
        'governedRootAfter'   => $governedRootAfter,
        'merkleBuilder'       => 'scripts/codexMerkleBuilder.php',
        'timestamp'           => now(),
        'observation'         => $canonicalObservation
    ];

    // Robust prompt file resolution
    $promptCandidates = [
        $rootDir . '/prompts/resolutionNarrative.prompt',
        $rootDir . '/codex/prompts/resolutionNarrative.prompt',
        str_replace('\\Documents\\', '\\OneDrive\\Documents\\', $rootDir) . '/prompts/resolutionNarrative.prompt',
        str_replace('\\Documents\\', '\\OneDrive\\Documents\\', $rootDir) . '/codex/prompts/resolutionNarrative.prompt',
    ];

    $promptFile = null;
    foreach ($promptCandidates as $candidate) {
        if (file_exists($candidate)) {
            $promptFile = $candidate;
            break;
        }
    }

    $aiNarrative = null;
    if ($promptFile !== null) {
        $aiNarrative = generateResolutionNarrative(
            violation: $v,
            facts: $facts,
            promptFile: $promptFile
        );
    }

    // Deterministic fallback narrative (governed, non-authoritative)
    if ($aiNarrative === null) {
        $aiNarrative = [
            'summary' => 'Merkle snapshot rebuilt to reconcile governed state with the observed Codex state.',
            'details' => [
                'The Codex Merkle builder was executed as the corrective action.',
                'The governed Merkle root was updated based on the current Codex content.',
                'Resolution is recorded because the new governed root matches the observed root from the audit run.',
                'No claims are made regarding why the divergence occurred.'
            ]
        ];
    }

    // === COMMIT THE RESOLUTION ===
    $v['resolved'] = now();
    $v['resolution'] = [
        'method'              => 'automated',
        'actor'               => 'mutator',
        'reconciliationClass' => 'GOVERNANCE_REBUILD',
        'facts'               => $facts,
        'summary'             => $aiNarrative['summary'],
        'details'             => $aiNarrative['details'],
        'assistedByAI'        => ($promptFile !== null)
    ];

    $updated = true;
}

unset($v);

#endregion SECTION III

#region SECTION IV — Persist Ledger

if ($updated) {
    file_put_contents(
        $auditLogPath,
        json_encode($auditLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}

echo "✔ Mutator reconciliation run complete\n";

#endregion SECTION IV