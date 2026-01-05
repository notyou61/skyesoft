<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — auditor.php (AGS v2 compliant — hardened & Reentrant)
 *  Role: Auditor
 *  Authority: Audit Governance Standard v2
 *  PHP: 8.3+
 *
 *  Purpose:
 *   • Observe Codex integrity via Merkle verification
 *   • Perform name conformance checks on canonical registries
 *   • Emit governed violation observations only
 *   • Track persistence of unresolved violations
 *   • Infer resolution by absence of observation in current run
 *
 *  Reentrancy:
 *   • All helper functions guarded with function_exists()
 *   • Safe to require multiple times (supports Sentinel 2-pass verification)
 *
 *  Explicitly Forbidden:
 *   • NO notification logic
 *   • NO batch assignment
 *   • NO email dispatch
 *   • NO mutation of resolution or notification fields
 *
 *  AI Narrative Augmentation (Governed, Non-Authoritative)
 *   • Centralized renderer called only in Section V
 *   • Never influences detection, identity hashing, lifecycle, or resolution
 *   • Graceful degradation: violationNotes = null if unavailable
 *   • For non-Merkle rules, placeholder notes are provided when AI is unavailable
 * ===================================================================== */

#region SECTION 0 — Runtime Context

$root         = dirname(__DIR__);
$timestamp    = time();
$auditMode    = "governance";
$auditLogPath = $root . '/data/records/auditResults.json';

$isCli        = (PHP_SAPI === 'cli');
$isProduction = !$isCli;

if (!defined('SKYESOFT_LIB_MODE')) {
    define('SKYESOFT_LIB_MODE', false);
}

if (!defined('SKYESOFT_VERIFICATION_PASS')) {
    define('SKYESOFT_VERIFICATION_PASS', false);
}

if (!defined('SKYESOFT_LIB_MODE') || !SKYESOFT_LIB_MODE) {
    header("Content-Type: application/json; charset=UTF-8");
}

$isVerificationPass = defined('SKYESOFT_VERIFICATION_PASS') && SKYESOFT_VERIFICATION_PASS;

if (!isset($violationBatch) || !is_string($violationBatch)) {
    throw new RuntimeException(
        'AUDITOR CONTRACT VIOLATION: violationBatch must be injected by Sentinel'
    );
}

#endregion SECTION 0

#region SECTION I — Path Resolution

$codexPath      = $root . '/codex/codex.json';
$merkleTreePath = $root . '/data/records/codexMerkleTree.json';
$merkleRootPath = $root . '/data/records/codexMerkleRoot.txt';

#endregion SECTION I

#region SECTION II — Helpers (Reentrant)

if (!function_exists('normalizeJson')) {
    /**
     * Recursively normalize JSON structure — identical to codexMerkleBuilder.php
     */
    function normalizeJson(mixed $data): mixed
    {
        if (is_array($data)) {
            ksort($data, SORT_STRING);
            foreach ($data as $key => $value) {
                $data[$key] = normalizeJson($value);
            }
        }
        return $data;
    }
}

if (!function_exists('recursiveChunks')) {
    function recursiveChunks(mixed $node, string $path = ''): array {
        $chunks = [];

        if (!is_array($node)) {
            $chunks[$path] = hash('sha256', json_encode($node, JSON_UNESCAPED_SLASHES));
            return $chunks;
        }

        ksort($node);
        foreach ($node as $key => $value) {
            $fullPath = $path === '' ? (string)$key : "$path.$key";
            $chunks += recursiveChunks($value, $fullPath);
        }

        return $chunks;
    }
}

if (!function_exists('buildMerkle')) {
    function buildMerkle(array $leaves): string
    {
        $layer = array_values($leaves);

        if (empty($layer)) {
            return hash('sha256', '');
        }

        while (count($layer) > 1) {
            $nextLayer = [];

            for ($i = 0, $n = count($layer); $i < $n; $i += 2) {
                $left  = $layer[$i];
                $right = $layer[$i + 1] ?? $left;
                $nextLayer[] = hash('sha256', $left . $right);
            }

            $layer = $nextLayer;
        }

        return $layer[0];
    }
}

if (!function_exists('inferRuleId')) {
    function inferRuleId(string $observation): string {
        if (str_starts_with($observation, 'Merkle integrity violation:')) {
            return 'merkleIntegrity';
        }
        if (str_starts_with($observation, 'Repository inventory violation:')) {
            return 'repositoryInventoryConformance';
        }
        if (str_starts_with($observation, 'Required governed artifact missing:')) {
            return 'criticalArtifactPresence';
        }
        if (str_starts_with($observation, 'Jurisdiction rule violation:')) {
            return 'jurisdictionalRuleConformance';
        }
        return 'unknown';
    }
}

if (!function_exists('canonicalViolationHash')) {
    function canonicalViolationHash(
        string $ruleId,
        string $file,
        string $path,
        string $observation
    ): string {
        $normalizedObservation = strtolower(trim($observation));
        $identity = implode('|', [$ruleId, $file, $path, $normalizedObservation]);
        return hash('sha256', $identity);
    }
}

if (!function_exists('extractViolationLocation')) {
    function extractViolationLocation(string $observation): array {
        if (preg_match("/in '([^']+)' \\(path: ([^)]+)\\)/", $observation, $m)) {
            return [$m[1], $m[2]];
        }
        return ['unknown', 'unknown'];
    }
}

if (!function_exists('loadEnvLocal')) {
    function loadEnvLocal(string $root): void
    {
        $envPath = $root . '/secure/env.local';

        if (!file_exists($envPath)) {
            error_log("ENV CHECK: env.local NOT FOUND at $envPath");
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

if (!function_exists('renderViolationNotes')) {
    /**
     * Centralized, non-authoritative AI narrative renderer
     * Called ONLY from Section V — never from detection rules
     */
    function renderViolationNotes(string $ruleId, array $facts, string $root): ?array
    {
        error_log('AI CHECK: key=' . (getenv('OPENAI_API_KEY') ? 'present' : 'missing'));
        loadEnvLocal($root);
        $apiKey = getenv('OPENAI_API_KEY');

        if ($ruleId === 'merkleIntegrity') {
            $promptPath = $root . '/codex/prompts/merkleIntegrity.prompt';

            if (!$apiKey) {
                error_log('AI ATTEMPT FAILED: OPENAI_API_KEY not available');
            }
            if (!file_exists($promptPath)) {
                error_log("AI ATTEMPT FAILED: Prompt file missing at $promptPath");
            }

            if ($apiKey && file_exists($promptPath)) {
                $template  = file_get_contents($promptPath);
                if ($template !== false) {
                    $factsJson = json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $prompt    = str_replace('{{FACTS_JSON}}', $factsJson, $template);

                    $payload = json_encode([
                        'model'       => 'gpt-4o-mini',
                        'temperature' => 0.0,
                        'messages'    => [['role' => 'system', 'content' => $prompt]]
                    ], JSON_UNESCAPED_SLASHES);

                    $context = stream_context_create([
                        'http' => [
                            'method'   => 'POST',
                            'header'   => [
                                'Content-Type: application/json',
                                'Authorization: Bearer ' . $apiKey
                            ],
                            'content'  => $payload,
                            'timeout'  => 12
                        ],
                        'ssl' => [
                            'verify_peer'      => true,
                            'verify_peer_name' => true
                        ]
                    ]);

                    $response = @file_get_contents('https://api.openai.com/v1/chat/completions', false, $context);
                    $httpCode = $http_response_header[0] ?? 'unknown';
                    error_log("AI API CALL: HTTP status = $httpCode");

                    if ($response !== false) {
                        $data = json_decode($response, true);
                        if (json_last_error() === JSON_ERROR_NONE && !isset($data['error'])) {
                            $content = $data['choices'][0]['message']['content'] ?? null;
                            if ($content !== null) {
                                $trimmed = trim($content);
                                if (str_starts_with($trimmed, '```json')) {
                                    $trimmed = preg_replace('/^```json\s*|\s*```$/', '', $trimmed);
                                } elseif (str_starts_with($trimmed, '```')) {
                                    $trimmed = preg_replace('/^```.*\s*|\s*```$/', '', $trimmed);
                                }

                                $parsed = json_decode($trimmed, true);
                                if (
                                    is_array($parsed) &&
                                    isset($parsed['summary'], $parsed['details']) &&
                                    is_string($parsed['summary']) &&
                                    is_array($parsed['details'])
                                ) {
                                    error_log('AI SUCCESS: Valid narrative generated for Merkle violation');
                                    return [
                                        'summary' => $parsed['summary'],
                                        'details' => array_values($parsed['details'])
                                    ];
                                }
                            }
                        }
                    }
                }
            }

            error_log('AI FALLBACK: Using deterministic notes for Merkle integrity violation');
            return [
                'summary' => 'The observed Merkle root does not match the governed Merkle snapshot.',
                'details' => [
                    'The computed Merkle root differs from the stored governance root.',
                    'The discrepancy was detected during a Codex integrity audit.',
                    'Human review is required to determine the cause.'
                ]
            ];
        }

        $placeholderSummary = match ($ruleId) {
            'criticalArtifactPresence' => 'A required governed artifact is missing from the repository.',
            'repositoryInventoryConformance' => 'The repository filesystem does not conform to the declared inventory.',
            'jurisdictionalRuleConformance' => 'The jurisdiction registry violates governance rules.',
            default => 'A governance violation has been detected.'
        };

        return [
            'summary' => $placeholderSummary,
            'details' => [
                'This violation requires human review.',
                'AI-generated detailed narrative is not yet available for this rule.',
                'A governed prompt will be added in a future Codex revision.'
            ]
        ];
    }
}

#endregion SECTION II

#region SECTION III — Load Audit Log

if (!file_exists($auditLogPath)) {
    file_put_contents($auditLogPath, json_encode([], JSON_PRETTY_PRINT));
}

$auditLog = json_decode(file_get_contents($auditLogPath), true) ?? [];

if (!function_exists('nextViolationId')) {
    function nextViolationId(array $log): string {
        $max = 0;
        foreach ($log as $r) {
            if (isset($r['violationId']) && preg_match('/VIO-(\d+)/', $r['violationId'], $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
        return sprintf('VIO-%03d', $max + 1);
    }
}

#endregion SECTION III

#region SECTION IV — Violation Collection (Detection Layer Only)

$violations = [];

$rulesEvaluated = [
    'merkleIntegrity'                => false,
    'repositoryInventoryConformance' => false,
    'criticalArtifactPresence'       => false,
    'jurisdictionalRuleConformance'  => false,
];

#region SECTION IV.B — Critical Artifact Presence
$rulesEvaluated['criticalArtifactPresence'] = true;

$required = [
    'codex.json'      => $codexPath,
    'merkleTree.json' => $merkleTreePath,
    'merkleRoot.txt'  => $merkleRootPath,
];

foreach ($required as $label => $path) {
    if (!file_exists($path)) {
        $violations[] = [
            'ruleId'      => 'criticalArtifactPresence',
            'observation' => "Required governed artifact missing: $label at $path",
            'facts'       => ['artifact' => $label, 'path' => $path]
        ];
    }
}
#endregion SECTION IV.B

#region SECTION IV.C — Merkle Integrity (Governed Only)
$rulesEvaluated['merkleIntegrity'] = true;

// Load current live Codex state
$codex = json_decode(file_get_contents($codexPath), true);

// Recompute observed Merkle root — IDENTICAL to codexMerkleBuilder.php
$normalized   = normalizeJson($codex);
$encoded      = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$contentHash  = hash('sha256', $encoded);
$observedRoot = hash('sha256', $contentHash);  // Single-leaf semantic root

// Load stored governance root
$storedRoot = trim(file_get_contents($merkleRootPath));

$canonicalObservation =
    'Merkle integrity violation: observed Codex state does not match governed Merkle snapshot.';

$violationIdentityHash = canonicalViolationHash(
    'merkleIntegrity',
    'unknown',
    'unknown',
    $canonicalObservation
);

$nextObservationCount = 1;
$firstObservedTimestamp = $timestamp;

foreach ($auditLog as $record) {
    if (
        ($record['identityHash'] ?? null) === $violationIdentityHash &&
        ($record['resolved'] ?? null) === null &&
        ($record['auditMode'] ?? '') === $auditMode
    ) {
        $nextObservationCount = ($record['observationCount'] ?? 0) + 1;
        $firstObservedTimestamp = $record['timestamp'];
        break;
    }
}

if ($storedRoot !== $observedRoot) {
    $violations[] = [
        'ruleId'      => 'merkleIntegrity',
        'observation' => $canonicalObservation,
        'facts'       => [
            'storedRoot'       => $storedRoot,
            'observedRoot'     => $observedRoot,
            'observationCount' => $nextObservationCount,
            'firstObserved'    => $firstObservedTimestamp,
            'currentRun'       => $timestamp
        ]
    ];
}
#endregion SECTION IV.C

#region SECTION IV.D — Repository Inventory Conformance
$rulesEvaluated['repositoryInventoryConformance'] = true;

$inventoryPath = $root . '/data/records/repositoryInventory.json';

if (!file_exists($inventoryPath)) {
    $violations[] = [
        'ruleId'      => 'criticalArtifactPresence',
        'observation' => "Required governed artifact missing: repositoryInventory.json at {$inventoryPath}",
        'facts'       => ['artifact' => 'repositoryInventory.json', 'path' => $inventoryPath]
    ];
} else {
    $inventory = json_decode(file_get_contents($inventoryPath), true);

    if (
        !is_array($inventory) ||
        !isset($inventory['items']) ||
        !is_array($inventory['items'])
    ) {
        $violations[] = [
            'ruleId'      => 'repositoryInventoryConformance',
            'observation' => "Repository inventory violation: repositoryInventory.json is malformed or missing items array.",
            'facts'       => ['path' => $inventoryPath]
        ];
    } else {
        // Build declared path map from items[]
        $declaredPaths = [];
        foreach ($inventory['items'] as $item) {
            if (isset($item['path'], $item['type'])) {
                $declaredPaths[$item['path']] = $item['type'];  // Keep "/api"
            }
        }

        // Observe filesystem — canonical root-anchored paths
        $observedPaths = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileInfo) {
            /** @var SplFileInfo $fileInfo */

            $fullPath     = $fileInfo->getPathname();
            $relativePath = str_replace($root, '', $fullPath);

            $normalized   = str_replace('\\', '/', $relativePath);
            $canonicalPath = '/' . ltrim($normalized, '/');

            if ($canonicalPath === '/') {
                continue;
            }

            foreach (['.git', 'node_modules', 'vendor', 'runtimeEphemeral', 'records', 'derived'] as $dir) {
                if (str_starts_with($canonicalPath . '/', '/' . $dir . '/')) {
                    continue 2;
                }
            }

            if (preg_match('/\.(?:keep|gitkeep)$/', $canonicalPath)) {
                continue;
            }

            $observedPaths[$canonicalPath] = $fileInfo->isDir() ? 'dir' : 'file';
        }

        // Declared but missing / type mismatch
        foreach ($declaredPaths as $path => $expectedType) {
            if (!isset($observedPaths[$path])) {
                $violations[] = [
                    'ruleId'      => 'repositoryInventoryConformance',
                    'observation' => "Repository inventory violation: declared {$expectedType} '{$path}' is missing from repository.",
                    'facts'       => [
                        'path'         => $path,
                        'expectedType' => $expectedType,
                        'issue'        => 'missing'
                    ]
                ];
                continue;
            }

            if ($observedPaths[$path] !== $expectedType) {
                $violations[] = [
                    'ruleId'      => 'repositoryInventoryConformance',
                    'observation' => "Repository inventory violation: '{$path}' expected type '{$expectedType}' but found '{$observedPaths[$path]}'.",
                    'facts'       => [
                        'path'         => $path,
                        'expectedType' => $expectedType,
                        'observedType' => $observedPaths[$path],
                        'issue'        => 'type_mismatch'
                    ]
                ];
            }
        }

        // Observed but undeclared
        foreach ($observedPaths as $path => $actualType) {
            if (!isset($declaredPaths[$path])) {
                $violations[] = [
                    'ruleId'      => 'repositoryInventoryConformance',
                    'observation' => "Repository inventory violation: unexpected {$actualType} '{$path}' exists but is not declared.",
                    'facts'       => [
                        'path'         => $path,
                        'observedType' => $actualType,
                        'issue'        => 'unexpected'
                    ]
                ];
            }
        }
    }
}
#endregion SECTION IV.D

#region SECTION IV.E — Jurisdictional Rule Conformance (Governance Only)

if ($auditMode === 'governance') {
    $rulesEvaluated['jurisdictionalRuleConformance'] = true;

    $jurisdictionRegistryPath = $root . '/data/authoritative/jurisdictionRegistry.json';

    if (!file_exists($jurisdictionRegistryPath)) {
        $violations[] = [
            'ruleId'      => 'criticalArtifactPresence',
            'observation' => "Required governed artifact missing: jurisdictionRegistry.json at {$jurisdictionRegistryPath}",
            'facts'       => ['artifact' => 'jurisdictionRegistry.json', 'path' => $jurisdictionRegistryPath]
        ];
    } else {
        $registry = json_decode(file_get_contents($jurisdictionRegistryPath), true);

        if (!is_array($registry)) {
            $violations[] = [
                'ruleId'      => 'jurisdictionalRuleConformance',
                'observation' => "Jurisdiction rule violation: jurisdiction registry is malformed.",
                'facts'       => ['path' => $jurisdictionRegistryPath]
            ];
        }
    }
}

#endregion SECTION IV.E

#endregion SECTION IV

#region SECTION V — Process Violations & Update Persistence (Includes Narrative Augmentation)

$emitted = [];
$updated = false;

$normalizedViolations = [];
$seen = [];

foreach ($violations as $v) {
    if (is_array($v)) {
        $obs    = $v['observation'] ?? '';
        $ruleId = $v['ruleId'] ?? inferRuleId($obs);
        $facts  = $v['facts'] ?? [];
    } else {
        $obs    = (string)$v;
        $ruleId = inferRuleId($obs);
        $facts  = [];
    }

    if (in_array($obs, $seen, true)) {
        continue;
    }
    $seen[] = $obs;

    $notes = renderViolationNotes($ruleId, $facts, $root);

    $normalizedViolations[] = [
        'ruleId'         => $ruleId,
        'observation'    => $obs,
        'violationNotes' => $notes,
        'facts'          => $facts
    ];
}

$currentIdentities = [];
foreach ($normalizedViolations as $nv) {
    $obs    = $nv['observation'];
    $ruleId = $nv['ruleId'];
    [$file, $path] = extractViolationLocation($obs);
    $hash = canonicalViolationHash($ruleId, $file, $path, $obs);
    $currentIdentities[$hash] = true;
}

foreach ($normalizedViolations as $nv) {
    $obs    = $nv['observation'];
    $notes  = $nv['violationNotes'];
    $ruleId = $nv['ruleId'];

    [$file, $path] = extractViolationLocation($obs);
    $hash = canonicalViolationHash($ruleId, $file, $path, $obs);

    $found = false;
    foreach ($auditLog as &$record) {
        if (($record['resolved'] ?? null) !== null) {
            continue;
        }

        if (($record['identityHash'] ?? null) === $hash) {
            $record['lastObserved'] = $timestamp;
            if (!$isVerificationPass) {
                $record['observationCount'] = ($record['observationCount'] ?? 0) + 1;
            }
            if ($notes !== null) {
                $record['violationNotes'] = $notes;
            }
            $found   = true;
            $updated = true;
            $emitted[] = $record;
            break;
        }
    }
    unset($record);

    if (!$found) {
        $newRecord = [
            'violationId'       => nextViolationId($auditLog),
            'identityHash'      => $hash,
            'ruleId'            => $ruleId,
            'timestamp'         => $timestamp,
            'auditMode'         => $auditMode,
            'observation'       => $obs,
            'violationNotes'    => $notes,
            'notificationSent'  => null,
            'violationBatch'    => $violationBatch,
            'resolved'          => null,
            'resolution'       => null,
            'lastObserved'      => $timestamp,
            'observationCount'  => 1
        ];
        $auditLog[] = $newRecord;
        $emitted[]  = $newRecord;
        $updated    = true;
    }
}

foreach ($auditLog as &$record) {
    if (
        ($record['resolved'] ?? null) !== null ||
        ($record['auditMode'] ?? null) !== $auditMode
    ) {
        continue;
    }

    $ruleId = $record['ruleId'] ?? null;
    if (!$ruleId || !($rulesEvaluated[$ruleId] ?? false)) {
        continue;
    }

    if (!isset($currentIdentities[$record['identityHash'] ?? ''])) {
        $record['resolved'] = $timestamp;
        $updated = true;
    }
}
unset($record);

if ($updated) {
    file_put_contents(
        $auditLogPath,
        json_encode($auditLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

$summary = [
    'runComplete'    => true,
    'timestamp'      => $timestamp,
    'auditMode'      => $auditMode,
    'emittedCount'   => count($emitted),
    'mutatableCount' => 0,
];

if (defined('SKYESOFT_LIB_MODE') && SKYESOFT_LIB_MODE) {
    echo json_encode($emitted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $summary;
}

echo json_encode($emitted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit(0);

#endregion SECTION V