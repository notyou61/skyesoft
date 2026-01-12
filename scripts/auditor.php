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
 *  Fixed in this version (Jan 2026):
 *   • observationCount now incremented BEFORE AI narrative generation on updates
 *   • AI always receives the current (this-run-included) observation count
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

if (!function_exists('nextViolationId')) {
    function nextViolationId(array $auditLog): string
    {
        $max = 0;

        foreach ($auditLog as $record) {
            if (
                isset($record['violationId']) &&
                preg_match('/VIO-(\d+)/', $record['violationId'], $m)
            ) {
                $max = max($max, (int)$m[1]);
            }
        }

        return sprintf('VIO-%03d', $max + 1);
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

#region SECTION III — Load Audit Log (Canonical Structure)

if (!file_exists($auditLogPath)) {
    file_put_contents(
        $auditLogPath,
        json_encode(
            ['meta' => null, 'violations' => []],
            JSON_PRETTY_PRINT
        )
    );
}

$auditDoc = json_decode(file_get_contents($auditLogPath), true);

if (!is_array($auditDoc)) {
    throw new RuntimeException('AUDITOR CONTRACT VIOLATION: auditResults.json malformed');
}

$auditLog = $auditDoc['violations'] ?? [];

#endregion SECTION III

#region SECTION IV — Violation Collection (Detection Layer — NO AI!)

$violations = [];

$rulesEvaluated = [
    'merkleIntegrity'                => false,
    'repositoryInventoryConformance' => false,
    'jurisdictionalRuleConformance'  => false,
];


// IV.B — Merkle Integrity
$rulesEvaluated['merkleIntegrity'] = true;

if (file_exists($merkleRootPath) && file_exists($codexPath)) {
    $codex = json_decode(file_get_contents($codexPath), true) ?? [];

    $normalized   = normalizeJson($codex);
    $encoded      = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $contentHash  = hash('sha256', $encoded);
    $observedRoot = hash('sha256', $contentHash);

    $storedRoot = trim(file_get_contents($merkleRootPath));

    if ($storedRoot !== $observedRoot) {
        $violations[] = [
            'ruleId'      => 'merkleIntegrity',
            'observation' =>
                'Merkle integrity violation: observed Codex state does not match governed Merkle snapshot.',
            'facts'       => [
                'storedRoot'   => $storedRoot,
                'observedRoot' => $observedRoot
            ]
        ];
    }
}


// IV.C — Repository Inventory Conformance
$rulesEvaluated['repositoryInventoryConformance'] = true;

$inventoryPath = $root . '/data/records/repositoryInventory.json';

if (!file_exists($inventoryPath)) {
    $violations[] = [
        'ruleId'      => 'repositoryInventoryConformance',
        'observation' =>
            'Repository inventory violation: repositoryInventory.json is missing',
        'facts'       => [
            'path'              => '/data/records/repositoryInventory.json',
            'issue'             => 'missing',
            'violationSubclass' => 'MALFORMED_INVENTORY'
        ]
    ];
} else {

    $inventory = json_decode(file_get_contents($inventoryPath), true) ?? [];

    /* ── Inventory structure must be valid ─────────────────────────── */
    if (!isset($inventory['items']) || !is_array($inventory['items'])) {
        $violations[] = [
            'ruleId'      => 'repositoryInventoryConformance',
            'observation' =>
                'Repository inventory violation: malformed or missing items array',
            'facts'       => [
                'path'              => '/data/records/repositoryInventory.json',
                'issue'             => 'malformed',
                'violationSubclass' => 'MALFORMED_INVENTORY'
            ]
        ];
    } else {

        /* ── Declared inventory paths ───────────────────────────────── */
        $declaredPaths = [];
        foreach ($inventory['items'] as $item) {
            if (isset($item['path'], $item['type'])) {
                $declaredPaths[$item['path']] = $item['type'];
            }
        }

        /* ── Allowed path closure (declared + implicit parents) ─────── */
        $allowedPaths = $declaredPaths;
        foreach (array_keys($declaredPaths) as $declaredPath) {
            $parts = explode('/', trim($declaredPath, '/'));
            $accum = '';
            foreach ($parts as $part) {
                if ($part === '') continue;
                $accum .= '/' . $part;
                $allowedPaths[$accum] ??= 'dir';
            }
        }

        /* ── Observed filesystem paths ─────────────────────────────── */
        $observedPaths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileInfo) {
            $relativePath = str_replace('\\', '/', str_replace($root, '', $fileInfo->getPathname()));
            $canonical    = '/' . ltrim($relativePath, '/');

            if ($canonical === '/') continue;

            foreach (['.git', 'node_modules', 'vendor', 'runtimeEphemeral', 'records', 'derived'] as $dir) {
                if (str_starts_with($canonical . '/', "/$dir/")) continue 2;
            }

            if (preg_match('/\.(?:keep|gitkeep)$/', $canonical)) continue;

            $observedPaths[$canonical] = $fileInfo->isDir() ? 'dir' : 'file';
        }

        /* ── Declared → Observed ───────────────────────────────────── */
        foreach ($declaredPaths as $path => $expected) {

            if (!isset($observedPaths[$path])) {
                $violations[] = [
                    'ruleId'      => 'repositoryInventoryConformance',
                    'observation' =>
                        "Repository inventory violation: declared $expected '$path' is missing",
                    'facts'       => [
                        'path'              => $path,
                        'expected'          => $expected,
                        'issue'             => 'missing',
                        'violationSubclass' => 'MISSING_DECLARED_ARTIFACT'
                    ]
                ];
            }
            elseif ($observedPaths[$path] !== $expected) {
                $violations[] = [
                    'ruleId'      => 'repositoryInventoryConformance',
                    'observation' =>
                        "Repository inventory violation: '$path' expected $expected but found {$observedPaths[$path]}",
                    'facts'       => [
                        'path'              => $path,
                        'expected'          => $expected,
                        'observed'          => $observedPaths[$path],
                        'issue'             => 'type_mismatch',
                        'violationSubclass' => 'TYPE_MISMATCH'
                    ]
                ];
            }
        }

        /* ── Observed → Declared ───────────────────────────────────── */
        foreach ($observedPaths as $path => $type) {
            if (!isset($allowedPaths[$path])) {
                $violations[] = [
                    'ruleId'      => 'repositoryInventoryConformance',
                    'observation' =>
                        "Repository inventory violation: unexpected $type '$path' exists but not declared",
                    'facts'       => [
                        'path'              => $path,
                        'type'              => $type,
                        'issue'             => 'unexpected',
                        'violationSubclass' => 'UNEXPECTED_ARTIFACT'
                    ]
                ];
            }
        }
    }
}


// IV.D — Jurisdictional Rule Conformance (governance mode only)
if ($auditMode === 'governance') {
    $rulesEvaluated['jurisdictionalRuleConformance'] = true;

    $jurisdictionRegistryPath = $root . '/data/authoritative/jurisdictionRegistry.json';

    if (!file_exists($jurisdictionRegistryPath)) {
        $violations[] = [
            'ruleId'      => 'jurisdictionalRuleConformance',
            'observation' =>
                'Jurisdiction rule violation: jurisdiction registry is missing',
            'facts'       => [
                'path'  => '/data/authoritative/jurisdictionRegistry.json',
                'issue' => 'missing'
            ]
        ];
    } else {
        $registry = json_decode(file_get_contents($jurisdictionRegistryPath), true);
        if (!is_array($registry)) {
            $violations[] = [
                'ruleId'      => 'jurisdictionalRuleConformance',
                'observation' =>
                    'Jurisdiction rule violation: registry is malformed',
                'facts'       => [
                    'path'  => $jurisdictionRegistryPath,
                    'issue' => 'malformed'
                ]
            ];
        }
    }
}

#endregion SECTION IV

#region SECTION V — Process Violations & Persist Canonical Audit Results

/* ============================================================
 *  SECTION V.A — Canonical Violation Merge (Authoritative)
 * ============================================================ */

$normalizedViolations = [];
$seenObservations = [];

/* ── Normalize & deduplicate observations (this run only) ── */
foreach ($violations as $v) {
    $observation = $v['observation'] ?? '';
    if ($observation === '') continue;
    if (in_array($observation, $seenObservations, true)) continue;

    $seenObservations[] = $observation;

    $ruleId = $v['ruleId'] ?? inferRuleId($observation);
    $facts  = $v['facts'] ?? [];

    $notes = $isVerificationPass
        ? null
        : renderViolationNotes($ruleId, array_merge($facts, [
            'observation' => $observation
        ]), $root);


    $normalizedViolations[] = [
        'ruleId'         => $ruleId,
        'observation'    => $observation,
        'violationNotes' => $notes,
        'facts'          => $facts
    ];
}

/* ── Build identity set for current run ─────────────────── */
$currentIdentities = [];

foreach ($normalizedViolations as $nv) {
    [$file, $path] = extractViolationLocation($nv['observation']);
    $hash = canonicalViolationHash(
        $nv['ruleId'],
        $file,
        $path,
        $nv['observation']
    );
    $currentIdentities[$hash] = true;
}

/* ── Merge into canonical ledger ────────────────────────── */
foreach ($normalizedViolations as $nv) {
    $observation = $nv['observation'];
    $ruleId      = $nv['ruleId'];
    $facts       = $nv['facts'];
    $notes       = $nv['violationNotes'];

    [$file, $path] = extractViolationLocation($observation);
    $hash = canonicalViolationHash($ruleId, $file, $path, $observation);

    $found = false;

    foreach ($auditLog as &$record) {
        if (($record['resolved'] ?? null) !== null) continue;
        if (($record['identityHash'] ?? null) !== $hash) continue;

        $record['lastObserved'] = $timestamp;
        $record['observationCount'] = ($record['observationCount'] ?? 0) + 1;

        if ($notes !== null) {
            $record['violationNotes'] = $notes;
        }

        $record['facts'] = $facts;
        $found = true;
        break;
    }
    unset($record);

    if (!$found) {
        $auditLog[] = [
            'violationId'       => nextViolationId($auditLog),
            'identityHash'      => $hash,
            'ruleId'            => $ruleId,
            'timestamp'         => $timestamp,
            'auditMode'         => $auditMode,
            'observation'       => $observation,
            'violationNotes'    => $notes,
            'notificationSent'  => null,
            'violationBatch'    => $violationBatch,
            'resolved'          => null,
            'resolution'        => null,
            'lastObserved'      => $timestamp,
            'observationCount'  => 1,
            'facts'             => $facts
        ];
    }
}

/* ── Infer resolution by absence (Codex-mandated) ───────── */
foreach ($auditLog as &$record) {
    if (($record['resolved'] ?? null) !== null) continue;
    if (($record['auditMode'] ?? null) !== $auditMode) continue;

    if (!isset($currentIdentities[$record['identityHash'] ?? ''])) {
        $record['resolved'] = $timestamp;
        $record['lastObserved'] = $timestamp;
    }
}
unset($record);


/* ============================================================
 *  SECTION V.B — Compute Persistent Meta (Authoritative)
 * ============================================================ */

$previousMeta = $auditDoc['meta'] ?? [];

/* ── Preserve immutable lineage ───────────────────────────
 * generatedAt represents the genesis timestamp of this audit record.
 * It MUST remain immutable across all subsequent audit runs.
 */
$generatedAt = $previousMeta['generatedAt'] ?? $timestamp;

/* ── Rolling counters ───────────────────────────────────── */
$auditCount = ($previousMeta['auditCount'] ?? 0) + 1;

$violationCount = count($auditLog);

$unresolvedViolations = 0;
foreach ($auditLog as $v) {
    if (($v['resolved'] ?? null) === null) {
        $unresolvedViolations++;
    }
}

/* ── Canonical meta object (NO state field) ──────────────── */
$meta = [
    'title'                => 'Skyesoft Governance Violation',
    'generatedAt'          => $generatedAt,
    'generatedBy'          => 'scripts/auditor.php',
    'codexTier'            => 'Tier-2',
    'purpose'              => 'Governance audit violation record',
    'description'          =>
        'Canonical record of a governed audit violation, including observation, resolution state, and non-authoritative narrative commentary.',
    'auditCount'           => $auditCount,
    'violationCount'       => $violationCount,
    'unresolvedViolations' => $unresolvedViolations,
    'lastAudit'            => $timestamp,
    'lastViolationBatch'   => $violationBatch
];

/* ============================================================
 *  SECTION V.C — Persist Canonical File (SINGLE SOURCE OF TRUTH)
 * ============================================================ */

file_put_contents(
    $auditLogPath,
    json_encode(
        [
            'meta'       => $meta,
            'violations' => $auditLog
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    )
);

/* ============================================================
 *  SECTION V.D — Emit for Sentinel / CLI (NON-PERSISTENT)
 * ============================================================ */

echo json_encode(
    [
        'meta'       => $meta,
        'violations' => $auditLog
    ],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

exit(0);

#endregion SECTION V