<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — auditor.php (AGS v2 compliant — hardened & Reentrant)
 *  Role: Auditor
 *  Authority: Audit Governance Standard v2
 *  PHP: 8.3+
 *
 *  Major changes — Governance alignment pass (January 2026)
 *   • Unified, prompt-governed AI narrative rendering via renderViolationNotes()
 *   • Removed all placeholder / speculative fallback narratives
 *   • Silent degradation: violationNotes = null when AI/prompt unavailable
 *   • Environment loading moved very early & safe (before any key check)
 *   • Strict separation: AI never influences detection, hashing, lifecycle
 *   • Structural groundwork laid for future governed resolution narratives
 *
 *  Still planned (next wave):
 *   • Full governed resolution narrative on inferred resolution
 *   • Dedicated renderResolutionNarrative() helper + prompt
 * ===================================================================== */

#region PRELUDE — Early Helpers (must be defined before usage)

if (!function_exists('loadEnvLocal')) {
    function loadEnvLocal(string $root): void
    {
        $envPath = $root . '/secure/env.local';
        if (!file_exists($envPath)) {
            return;
        }
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (!str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

if (!function_exists('normalizeJson')) {
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
}

if (!function_exists('recursiveChunks')) {
    function recursiveChunks(mixed $node, string $path = ''): array
    {
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
        if (empty($layer)) return hash('sha256', '');
        while (count($layer) > 1) {
            $next = [];
            for ($i = 0, $n = count($layer); $i < $n; $i += 2) {
                $left  = $layer[$i];
                $right = $layer[$i + 1] ?? $left;
                $next[] = hash('sha256', $left . $right);
            }
            $layer = $next;
        }
        return $layer[0];
    }
}

if (!function_exists('inferRuleId')) {
    function inferRuleId(string $observation): string
    {
        return match (true) {
            str_starts_with($observation, 'Merkle integrity violation:')                => 'merkleIntegrity',
            str_starts_with($observation, 'Repository inventory violation:')           => 'repositoryInventoryConformance',
            str_starts_with($observation, 'Required governed artifact missing:')       => 'criticalArtifactPresence',
            str_starts_with($observation, 'Jurisdiction rule violation:')              => 'jurisdictionalRuleConformance',
            default                                                                    => 'unknown'
        };
    }
}

if (!function_exists('canonicalViolationHash')) {
    function canonicalViolationHash(
        string $ruleId,
        string $file,
        string $path,
        string $observation
    ): string {
        $normalized = strtolower(trim($observation));
        $identity = implode('|', [$ruleId, $file, $path, $normalized]);
        return hash('sha256', $identity);
    }
}

if (!function_exists('extractViolationLocation')) {
    function extractViolationLocation(string $observation): array
    {
        if (preg_match("/in '([^']+)' \\(path: ([^)]+)\\)/", $observation, $m)) {
            return [$m[1], $m[2]];
        }
        return ['unknown', 'unknown'];
    }
}

if (!function_exists('renderViolationNotes')) {
    /**
     * Governed, centralized, non-authoritative AI narrative renderer
     *   • Called only from Section V — never from detection
     *   • Returns null on any failure → silent degradation
     *   • criticalArtifactPresence intentionally excluded from narration
     */
    function renderViolationNotes(string $ruleId, array $facts, string $root): ?array
    {
        $promptMap = [
            'merkleIntegrity'                => 'merkleIntegrity.prompt',
            'repositoryInventoryConformance' => 'repositoryInventoryConformance.prompt',
            'jurisdictionalRuleConformance'  => 'jurisdictionalRuleConformance.prompt',
            // criticalArtifactPresence → explicitly no AI narration
        ];

        if (!isset($promptMap[$ruleId])) return null;

        $promptPath = $root . '/codex/prompts/' . $promptMap[$ruleId];
        if (!file_exists($promptPath)) return null;

        $apiKey = getenv('OPENAI_API_KEY');
        if (!$apiKey) return null;

        $template = @file_get_contents($promptPath);
        if ($template === false) return null;

        $factsJson = json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $prompt = str_replace('{{FACTS_JSON}}', $factsJson, $template);

        $payload = json_encode([
            'model'       => 'gpt-4o-mini',
            'temperature' => 0.0,
            'max_tokens'  => 600,
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
                'timeout' => 12
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true
            ]
        ]);

        $response = @file_get_contents('https://api.openai.com/v1/chat/completions', false, $context);
        if ($response === false) return null;

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
}

#endregion PRELUDE

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

$isVerificationPass = defined('SKYESOFT_VERIFICATION_PASS') && SKYESOFT_VERIFICATION_PASS;

// Environment is loaded in PRELUDE — safe to use getenv() now
loadEnvLocal($root);

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

#region SECTION IV — Violation Collection (Detection Layer — NO AI!)

$violations = [];

$rulesEvaluated = [
    'merkleIntegrity'                => false,
    'repositoryInventoryConformance' => false,
    'criticalArtifactPresence'       => false,
    'jurisdictionalRuleConformance'  => false,
];

// IV.B — Critical Artifact Presence
$rulesEvaluated['criticalArtifactPresence'] = true;

$criticalRegistryPath = $root . '/codex/governance/criticalArtifacts.json';

if (!file_exists($criticalRegistryPath)) {
    $violations[] = [
        'ruleId'      => 'criticalArtifactPresence',
        'observation' => 'Critical artifacts registry missing',
        'facts'       => [
            'path'  => '/codex/governance/criticalArtifacts.json',
            'issue' => 'registry_missing'
        ]
    ];
} else {
    $registry = json_decode(file_get_contents($criticalRegistryPath), true);
    $criticalArtifacts = $registry['criticalArtifacts'] ?? [];

    foreach ($criticalArtifacts as $artifact) {
        $path   = $artifact['path'] ?? '';
        $reason = $artifact['reason'] ?? 'No reason provided';
        $absolutePath = $root . $path;

        if (!file_exists($absolutePath)) {
            $violations[] = [
                'ruleId'      => 'criticalArtifactPresence',
                'observation' => "Required governed artifact missing: $path",
                'facts'       => [
                    'path'   => $path,
                    'reason' => $reason,
                    'issue'  => 'missing'
                ]
            ];
        }
    }
}

// IV.C — Merkle Integrity
$rulesEvaluated['merkleIntegrity'] = true;

if (file_exists($merkleRootPath) && file_exists($codexPath)) {
    $codex = json_decode(file_get_contents($codexPath), true) ?? [];

    $normalized = normalizeJson($codex);
    $encoded = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $contentHash = hash('sha256', $encoded);
    $observedRoot = hash('sha256', $contentHash);

    $storedRoot = trim(file_get_contents($merkleRootPath));

    if ($storedRoot !== $observedRoot) {
        $violations[] = [
            'ruleId'      => 'merkleIntegrity',
            'observation' => 'Merkle integrity violation: observed Codex state does not match governed Merkle snapshot.',
            'facts'       => [
                'storedRoot'   => $storedRoot,
                'observedRoot' => $observedRoot
            ]
        ];
    }
}

// IV.D — Repository Inventory Conformance
$rulesEvaluated['repositoryInventoryConformance'] = true;

$inventoryPath = $root . '/data/records/repositoryInventory.json';

$criticalArtifactPaths = [];
$criticalRegistryPath = $root . '/codex/governance/criticalArtifacts.json';
if (file_exists($criticalRegistryPath)) {
    $criticalRegistry = json_decode(file_get_contents($criticalRegistryPath), true) ?? [];
    foreach ($criticalRegistry['criticalArtifacts'] ?? [] as $artifact) {
        if (isset($artifact['path'])) {
            $criticalArtifactPaths[$artifact['path']] = true;
        }
    }
}

if (!file_exists($inventoryPath)) {
    $violations[] = [
        'ruleId'      => 'criticalArtifactPresence',
        'observation' => 'Required governed artifact missing: repositoryInventory.json',
        'facts'       => ['path' => '/data/records/repositoryInventory.json']
    ];
} else {
    $inventory = json_decode(file_get_contents($inventoryPath), true) ?? [];
    if (!isset($inventory['items']) || !is_array($inventory['items'])) {
        $violations[] = [
            'ruleId'      => 'repositoryInventoryConformance',
            'observation' => 'Repository inventory violation: malformed or missing items array',
            'facts'       => ['path' => '/data/records/repositoryInventory.json']
        ];
    } else {
        $declaredPaths = [];
        foreach ($inventory['items'] as $item) {
            if (isset($item['path'], $item['type'])) {
                if (isset($criticalArtifactPaths[$item['path']])) continue;
                $declaredPaths[$item['path']] = $item['type'];
            }
        }

        $observedPaths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileInfo) {
            $fullPath     = $fileInfo->getPathname();
            $relativePath = str_replace($root, '', $fullPath);
            $normalized   = str_replace('\\', '/', $relativePath);
            $canonical    = '/' . ltrim($normalized, '/');

            if ($canonical === '/') continue;

            // Skip common vendor & temp dirs
            foreach (['.git', 'node_modules', 'vendor', 'runtimeEphemeral', 'records', 'derived'] as $dir) {
                if (str_starts_with($canonical . '/', "/$dir/")) continue 2;
            }

            if (preg_match('/\.(?:keep|gitkeep)$/', $canonical)) continue;
            if (isset($criticalArtifactPaths[$canonical])) continue;

            $observedPaths[$canonical] = $fileInfo->isDir() ? 'dir' : 'file';
        }

        // Declared but missing / wrong type
        foreach ($declaredPaths as $path => $expected) {
            if (!isset($observedPaths[$path])) {
                $violations[] = [
                    'ruleId'      => 'repositoryInventoryConformance',
                    'observation' => "Repository inventory violation: declared $expected '$path' is missing",
                    'facts'       => ['path' => $path, 'expected' => $expected, 'issue' => 'missing']
                ];
            } elseif ($observedPaths[$path] !== $expected) {
                $violations[] = [
                    'ruleId'      => 'repositoryInventoryConformance',
                    'observation' => "Repository inventory violation: '$path' expected $expected but found {$observedPaths[$path]}",
                    'facts'       => ['path' => $path, 'expected' => $expected, 'observed' => $observedPaths[$path]]
                ];
            }
        }

        // Observed but undeclared
        foreach ($observedPaths as $path => $type) {
            if (!isset($declaredPaths[$path])) {
                $violations[] = [
                    'ruleId'      => 'repositoryInventoryConformance',
                    'observation' => "Repository inventory violation: unexpected $type '$path' exists but not declared",
                    'facts'       => ['path' => $path, 'type' => $type, 'issue' => 'unexpected']
                ];
            }
        }
    }
}

// IV.E — Jurisdictional Rule Conformance (governance mode only)
if ($auditMode === 'governance') {
    $rulesEvaluated['jurisdictionalRuleConformance'] = true;

    $jurisdictionRegistryPath = $root . '/data/authoritative/jurisdictionRegistry.json';

    if (!file_exists($jurisdictionRegistryPath)) {
        $violations[] = [
            'ruleId'      => 'criticalArtifactPresence',
            'observation' => 'Required governed artifact missing: jurisdictionRegistry.json',
            'facts'       => ['path' => '/data/authoritative/jurisdictionRegistry.json']
        ];
    } else {
        $registry = json_decode(file_get_contents($jurisdictionRegistryPath), true);
        if (!is_array($registry)) {
            $violations[] = [
                'ruleId'      => 'jurisdictionalRuleConformance',
                'observation' => 'Jurisdiction rule violation: registry is malformed',
                'facts'       => ['path' => $jurisdictionRegistryPath]
            ];
        }
    }
}

#endregion SECTION IV

#region SECTION V — Process Violations & Update Persistence

$emitted = [];
$updated = false;

$normalizedViolations = [];
$seen = [];

foreach ($violations as $v) {
    $obs    = $v['observation'] ?? (string)$v;
    $ruleId = $v['ruleId'] ?? inferRuleId($obs);
    $facts  = $v['facts'] ?? [];

    if (in_array($obs, $seen, true)) continue;
    $seen[] = $obs;

    $factsForAI = $facts;
    $factsForAI['observation'] = $obs;

    $notes = $isVerificationPass ? null : renderViolationNotes($ruleId, $factsForAI, $root);

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
    $facts  = $nv['facts'];

    [$file, $path] = extractViolationLocation($obs);
    $hash = canonicalViolationHash($ruleId, $file, $path, $obs);

    $found = false;
    foreach ($auditLog as &$record) {
        if (($record['resolved'] ?? null) !== null) continue;

        if (($record['identityHash'] ?? null) === $hash) {
            $record['lastObserved'] = $timestamp;
            if (!$isVerificationPass) {
                $record['observationCount'] = ($record['observationCount'] ?? 0) + 1;
            }
            if ($notes !== null) {
                $record['violationNotes'] = $notes;
            }
            $record['facts'] = $facts;
            $found = true;
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
            'resolution'        => null,
            'lastObserved'      => $timestamp,
            'observationCount'  => 1,
            'facts'             => $facts
        ];
        $auditLog[] = $newRecord;
        $emitted[]  = $newRecord;
        $updated = true;
    }
}

// Infer resolution by absence
foreach ($auditLog as &$record) {
    if (
        ($record['resolved'] ?? null) !== null ||
        ($record['auditMode'] ?? null) !== $auditMode
    ) {
        continue;
    }

    $ruleId = $record['ruleId'] ?? null;
    if (!$ruleId || !($rulesEvaluated[$ruleId] ?? false)) continue;

    if (!isset($currentIdentities[$record['identityHash'] ?? ''])) {
        $record['resolved'] = $timestamp;
        $record['lastObserved'] = $timestamp;
        $updated = true;
    }
}
unset($record);

if ($updated) {
    file_put_contents(
        $auditLogPath,
        json_encode($auditLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
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