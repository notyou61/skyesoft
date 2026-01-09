<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — mutator.php
 *  Role: Governed Reconciler (Positive Corrective Action)
 *  Authority: Reconciliation Governance Standard (RGS) under AGS
 *  PHP: 8.3+
 *
 *  Governance Principles (Jan 2026):
 *   • Only merkleIntegrity and repositoryInventoryConformance are eligible
 *   • Resolution occurs ONLY when the specific condition disappears
 *   • Original observation and timestamp are immutable
 *   • AI narrative attempted only if OPENAI_API_KEY is set
 *   • Strong deterministic fallback always used when AI unavailable
 *   • No environment/location assumptions; uses real env vars only
 *   • No automatic resolution based on mere presence of artifacts
 *
 * ===================================================================== */

#region SECTION 0 — Environment & Paths

$rootDir  = dirname(__DIR__);
$dataDir  = $rootDir . '/data/records';

$auditLogPath   = $dataDir . '/auditResults.json';
$merkleRootPath = $dataDir . '/codexMerkleRoot.txt';

#endregion

#region SECTION I — Load Audit Ledger

if (!file_exists($auditLogPath)) {
    exit(1);
}

$auditLog = json_decode(file_get_contents($auditLogPath), true);
if (!is_array($auditLog)) {
    exit(1);
}

#endregion

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

function readMerkleRoot(): ?string
{
    global $merkleRootPath;
    if (!file_exists($merkleRootPath)) {
        return null;
    }
    return trim(file_get_contents($merkleRootPath));
}

/**
 * Find the generic resolution narrative prompt.
 */
function findPromptFile(string $rootDir): ?string
{
    $candidates = [
        $rootDir . '/prompts/resolutionNarrative.prompt',
        $rootDir . '/codex/prompts/resolutionNarrative.prompt',
    ];

    foreach ($candidates as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }

    return null;
}

/**
 * Generate resolution narrative — attempt AI, fall back to strong deterministic version.
 */
function generateResolutionNarrative(
    array $violation,
    array $facts,
    string $ruleId
): array {
    $promptFile = findPromptFile(dirname(__DIR__));
    $apiKey = getenv('OPENAI_API_KEY');

    if ($promptFile && $apiKey) {
        $template = @file_get_contents($promptFile);
        if ($template !== false) {
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
                'max_tokens'  => 600,
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
                    'timeout' => 20
                ],
                'ssl' => [
                    'verify_peer'      => true,
                    'verify_peer_name' => true
                ]
            ]);

            $response = @file_get_contents('https://api.openai.com/v1/chat/completions', false, $context);

            if ($response !== false) {
                $data = json_decode($response, true);
                if (isset($data['choices'][0]['message']['content'])) {
                    $content = trim($data['choices'][0]['message']['content']);
                    $content = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $content);
                    $content = trim($content);

                    $parsed = json_decode($content, true);
                    if (is_array($parsed) && isset($parsed['summary'], $parsed['details']) && is_array($parsed['details'])) {
                        return [
                            'summary' => $parsed['summary'],
                            'details' => array_values($parsed['details']),
                            'assistedByAI' => true
                        ];
                    }
                }
            }
        }
    }

    // Deterministic fallback — always structured, governance-compliant
    $timestampStr = date('c', now());
    return [
        'summary' => "Reconciliation condition satisfied — violation no longer observed.",
        'details' => [
            "Rule: {$ruleId}",
            "Resolution timestamp: {$timestampStr}",
            "The specific violation condition was not detected in a subsequent audit run.",
            "No inference is made about the cause of the previous divergence.",
            "No claims are made regarding future stability of the observed state."
        ],
        'assistedByAI' => false
    ];
}

#endregion

#region SECTION III — Governed Reconciliation Engine

$updated = false;

foreach ($auditLog as &$violation) {
    // Skip non-violations and already resolved records
    if (
        ($violation['type'] ?? 'violation') !== 'violation' ||
        ($violation['resolved'] ?? null) !== null
    ) {
        continue;
    }

    $ruleId = $violation['ruleId'] ?? '';

    // Only these classes are eligible for automatic reconciliation
    if (!in_array($ruleId, ['merkleIntegrity', 'repositoryInventoryConformance'], true)) {
        continue;
    }

    $facts = [];
    $reconciliationClass = '';
    $method = '';

    switch ($ruleId) {
        case 'merkleIntegrity':
            $observedRoot = $violation['facts']['observedRoot'] ?? null;
            if (!is_string($observedRoot) || $observedRoot === '') {
                continue 2;
            }

            $governedRootBefore = readMerkleRoot() ?? 'missing';

            if (!runCodexMerkleBuilder()) {
                continue 2;
            }

            $governedRootAfter = readMerkleRoot();
            if (!is_string($governedRootAfter) || $governedRootAfter === '') {
                continue 2;
            }

            // Resolve only if we rebuilt AND now match the observed root
            if ($governedRootBefore === $governedRootAfter || $governedRootAfter !== $observedRoot) {
                continue 2;
            }

            $facts = [
                'artifact'           => 'codex/codex.json',
                'governedRootBefore' => $governedRootBefore,
                'observedRoot'       => $observedRoot,
                'governedRootAfter'  => $governedRootAfter,
                'merkleBuilderRun'   => true,
                'timestamp'          => now()
            ];

            $reconciliationClass = 'GOVERNANCE_REBUILD';
            $method = 'automated';
            break;

        case 'repositoryInventoryConformance':
            $path  = $violation['facts']['path']  ?? null;
            $issue = $violation['facts']['issue'] ?? null;

            if (!$path || !$issue || !is_string($path) || !is_string($issue)) {
                continue 2;
            }

            $absolutePath = $rootDir . '/' . ltrim($path, '/');

            $conditionGone = match ($issue) {
                'missing'      => file_exists($absolutePath),
                'type_mismatch' => false,           // never auto-resolve type mismatches
                'unexpected'   => !file_exists($absolutePath),
                default        => false
            };

            if (!$conditionGone) {
                continue 2;
            }

            $facts = [
                'path'      => $path,
                'issue'     => $issue,
                'direction' => $violation['facts']['direction'] ?? 'unknown',
                'timestamp' => now()
            ];

            $reconciliationClass = 'STRUCTURAL_VERIFICATION';
            $method = 'passive';
            break;
    }

    // Generate narrative (AI if possible, otherwise strong fallback)
    $narrative = generateResolutionNarrative($violation, $facts, $ruleId);

    $violation['resolved'] = now();
    $violation['resolution'] = [
        'method'              => $method,
        'actor'               => 'mutator',
        'reconciliationClass' => $reconciliationClass,
        'facts'               => $facts,
        'summary'             => $narrative['summary'],
        'details'             => $narrative['details'],
        'assistedByAI'        => $narrative['assistedByAI']
    ];

    $updated = true;
}

unset($violation);

#endregion

#region SECTION IV — Persist Changes

if ($updated) {
    file_put_contents(
        $auditLogPath,
        json_encode($auditLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}

echo "✔ Governed reconciliation run complete\n";

#endregion