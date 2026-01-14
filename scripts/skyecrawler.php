<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — skyecrawler.php
 *  Role: External Discovery Assistant (Prototype)
 *  Authority: Skyecrawl External Discovery Standard (Codex)
 *
 *  PURPOSE
 *  -------
 *  Performs governed, non-authoritative discovery of publicly accessible
 *  external resources and emits draft source.json files for HUMAN REVIEW.
 *
 *  MODES
 *  -----
 *  • No arguments        → discover ALL jurisdictions missing source.json
 *  • <JurisdictionName>  → discover ONLY that jurisdiction (if unresolved)
 *
 * ===================================================================== */

#region SECTION 0 — Paths & Inputs

$rootDir         = dirname(__DIR__);
$jurisdictionDir = $rootDir . '/data/authoritative/jurisdictions';
$envPath         = $rootDir . '/secure/env.local';
$promptPath      = __DIR__ . '/skyecrawler.prompt';

$targetJurisdiction = $argv[1] ?? null;
if ($targetJurisdiction !== null) {
    $targetJurisdiction = strtolower(trim($targetJurisdiction));
}

#endregion

#region SECTION I — Load Environment

if (!file_exists($envPath)) {
    echo "❌ env.local not found\n";
    exit(1);
}

foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    if (!str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    putenv(trim($k) . '=' . trim($v));
}

$googleKey = getenv('GOOGLE_SEARCH_KEY');
$googleCx  = getenv('GOOGLE_SEARCH_CX');

if (!$googleKey || !$googleCx) {
    echo "❌ Google search credentials missing\n";
    exit(1);
}

#endregion

#region SECTION II — Helpers

function googleSearch(string $query, string $key, string $cx): ?array
{
    $url = 'https://www.googleapis.com/customsearch/v1'
         . '?key=' . urlencode($key)
         . '&cx='  . urlencode($cx)
         . '&q='   . urlencode($query);

    $json = @file_get_contents($url);
    if ($json === false) return null;

    $data = json_decode($json, true);
    return is_array($data) ? ($data['items'] ?? null) : null;
}

/**
 * AI-assisted query expansion.
 * Must return array<string>.
 * Falls back deterministically if AI unavailable.
 */
function expandQueries(string $jurisdiction, string $state, string $promptPath): array
{
    if (!file_exists($promptPath)) {
        return [
            "{$jurisdiction} City sign code",
            "City of {$jurisdiction} zoning ordinance signs",
            "{$jurisdiction} {$state} municipal sign regulations"
        ];
    }

    $prompt = str_replace(
        ['{{JURISDICTION}}', '{{STATE}}'],
        [$jurisdiction, $state],
        file_get_contents($promptPath)
    );

    // 🔒 GOVERNANCE NOTE:
    // This assumes an internal AI call that returns JSON array only.
    // Replace `callAI()` with your actual implementation.
    $response = callAI($prompt);

    $queries = json_decode($response, true);
    if (!is_array($queries)) return [];

    return array_values(array_filter($queries, 'is_string'));
}

/**
 * Stub for AI call (replace with real implementation)
 */
function callAI(string $prompt): string
{
    // Intentionally conservative stub for now
    return json_encode([
        "Tempe City sign code",
        "City of Tempe zoning ordinance signs",
        "Tempe AZ municipal sign regulations",
        "Tempe sign ordinance"
    ]);
}

#endregion

#region SECTION III — Discovery Loop

if (!is_dir($jurisdictionDir)) {
    echo "❌ Jurisdiction directory not found\n";
    exit(1);
}

$folders = scandir($jurisdictionDir);

foreach ($folders as $folder) {

    if ($folder === '.' || $folder === '..') continue;

    $folderLower = strtolower($folder);

    // Single-jurisdiction mode
    if ($targetJurisdiction !== null && $folderLower !== $targetJurisdiction) {
        continue;
    }

    $path = $jurisdictionDir . '/' . $folder;
    if (!is_dir($path)) continue;

    $sourceFile = $path . '/source.json';

    // Governance state check — resolved jurisdictions skipped
    if (file_exists($sourceFile)) {
        echo "⏭ Skipping {$folder} (source.json exists)\n";
        continue;
    }

    echo "🔍 Discovering source for {$folder}...\n";

    $queries = expandQueries($folder, 'AZ', $promptPath);

    $topResult = null;

    foreach ($queries as $query) {
        $results = googleSearch($query, $googleKey, $googleCx);
        if ($results && count($results) > 0) {
            $topResult = $results[0];
            break;
        }
    }

    if ($topResult === null) {
        echo "⚠ No results found for {$folder}\n";
        continue;
    }

    $draft = [
        'jurisdiction' => [
            'label' => $folder,
            'jurisdictionType' => 'City',
            'state' => 'AZ',
            'country' => 'US'
        ],
        'ordinance' => [
            'title' => $topResult['title'] ?? 'Unknown sign ordinance',
            'codeReference' => null,
            'subject' => 'Sign regulations',
            'authority' => $folder
        ],
        'authoritativeSource' => [
            'type' => 'web',
            'url' => $topResult['link'] ?? null,
            'publisher' => $topResult['displayLink'] ?? 'Unknown',
            'contentFormats' => ['html'],
            'accessExpectation' => 'PUBLIC',
            'canonical' => false
        ],
        'governance' => [
            'tier' => 'Tier-3',
            'authorityLevel' => 'UNVERIFIED_EXTERNAL_SOURCE',
            'autoAcceptChanges' => false,
            'requiresInterpretation' => true
        ],
        'discoveryMeta' => [
            'discoveredBy' => 'skyecrawler.php',
            'queriesUsed' => $queries,
            'discoveredAt' => time(),
            'reviewStatus' => 'PENDING_HUMAN_REVIEW'
        ],
        'notes' => [
            'Generated via AI-assisted query expansion.',
            'Observational only; requires human verification.'
        ]
    ];

    file_put_contents(
        $sourceFile,
        json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    echo "📝 Draft source.json created for {$folder}\n";
}

#endregion

#region SECTION IV — Output

echo "✔ Skyecrawler run complete (prototype mode)\n";

#endregion
