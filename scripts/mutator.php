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
    global $rootDir;  // ← Add this line

    $cmd = 'php ' . escapeshellarg($rootDir . '/scripts/codexMerkleBuilder.php');
    exec($cmd, $out, $code);
    return $code === 0;
}

if (!function_exists('loadEnvLocal')) {
    function loadEnvLocal(string $root): void
    {
        $envPath = $root . '/secure/env.local';

        if (!file_exists($envPath)) {
            return;
        }

        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
        }
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

function extractObservedRoot(array $violation): ?string
{
    // Preferred: use structured facts if available (RGS-compliant)
    if (isset($violation['facts']['observedRoot'])) {
        return $violation['facts']['observedRoot'];
    }

    // Fallback: parse narrative text (backward compatibility only)
    foreach ($violation['violationNotes']['details'] ?? [] as $line) {
        if (str_starts_with($line, 'Computed observed root:')) {
            return trim(substr($line, strlen('Computed observed root:')));
        }
    }
    return null;
}

/**
 * Generate governed AI resolution narrative (MANDATORY)
 */
function generateResolutionNarrative(
    array $violation,
    array $facts,
    string $promptFile
): ?array {

    if (!file_exists($promptFile)) {
        return null;
    }

    // ==== CRITICAL FIX: Load environment before reading API key ====
    loadEnvLocal(dirname(__DIR__));

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

    // ==== HARDENED JSON fence stripping ====
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

    switch ($v['ruleId'] ?? '') {

    /* =============================================================
    * MERKLE INTEGRITY RECONCILIATION
    * ============================================================= */
    case 'merkleIntegrity':

        $observedBefore = extractObservedRoot($v);
        if ($observedBefore === null) {
            break;
        }

        // ---- Perform positive corrective action
        if (!runCodexMerkleBuilder()) {
            break;
        }

        $observedAfter = readMerkleRoot();
        if ($observedAfter === null) {
            break;
        }

        // ---- Assemble FACTS (show your work)
        $facts = [
            'artifact'             => 'codex/codex.json',
            'storedGovernanceRoot' => $observedAfter,
            'observedRootBefore'   => $observedBefore,
            'observedRootAfter'    => $observedAfter,
            'merkleBuilder'        => 'scripts/codexMerkleBuilder.php',
            'timestamp'            => now()
        ];

        // ---- Mandatory AI narrative (governed, non-authoritative)
        $aiNarrative = generateResolutionNarrative(
            violation: $v,
            facts: $facts,
            promptFile: $rootDir . '/prompts/resolutionNarrative.prompt'
        );

        // Governance rule: no AI narrative → no resolution
        if (
            $aiNarrative === null ||
            !isset($aiNarrative['summary'], $aiNarrative['details'])
        ) {
            break;
        }

        // ---- Commit resolution atomically
        $v['resolved'] = now();
        $v['resolution'] = [
            'method'              => 'automated',
            'actor'               => 'mutator',
            'reconciliationClass' => 'GOVERNANCE_REBUILD',
            'facts'               => $facts,
            'summary'             => $aiNarrative['summary'],
            'details'             => $aiNarrative['details'],
            'assistedByAI'        => true
        ];

        $updated = true;
        break;

        default:
            // Governed expansion point
            break;
    }
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