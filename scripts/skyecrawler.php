<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — skyecrawler.php
 *  Role: External Discovery Assistant (Proto Behavior)
 *  Authority: Skyecrawl External Discovery Standard (Codex)
 *
 *  PURPOSE
 *  -------
 *  Performs governed, non-authoritative, one-time discovery of
 *  publicly accessible external resources (e.g., jurisdiction sign codes).
 *
 *  This implementation operates in PROTOTYPE MODE by behavior only.
 *  The filename and interface are canonical to avoid future renaming.
 *
 *  CONSTRAINTS
 *  -----------
 *  • Observational only — no scraping or mirroring
 *  • Emits draft source.json files only
 *  • Never canonizes or asserts authority
 *  • Requires human review for all outputs
 *  • Phoenix is treated as an explicit canonical exception
 *
 *  ENVIRONMENT
 *  -----------
 *  Requires:
 *    - GOOGLE_SEARCH_KEY
 *    - GOOGLE_SEARCH_CX
 *
 * ===================================================================== */

#region SECTION 0 — Environment & Paths

$rootDir = dirname(__DIR__);
$jurisdictionDir = $rootDir . '/data/authoritative/jurisdictions';
$envPath = $rootDir . '/secure/env.local';

#endregion

#region SECTION I — Load Environment

if (!file_exists($envPath)) {
    echo "❌ env.local not found\n";
    exit(1);
}

foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if ($line === '' || str_starts_with(trim($line), '#')) continue;
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
        . '&cx=' . urlencode($cx)
        . '&q=' . urlencode($query);

    $json = @file_get_contents($url);
    if ($json === false) return null;

    $data = json_decode($json, true);
    if (!is_array($data)) return null;

    return $data['items'] ?? null;
}

function isPhoenix(string $label): bool
{
    return strtolower($label) === 'phoenix';
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

    $path = $jurisdictionDir . '/' . $folder;
    if (!is_dir($path)) continue;

    // Phoenix is an explicit exception
    if (isPhoenix($folder)) {
        echo "⏭ Skipping Phoenix (canonical source exists)\n";
        continue;
    }

    $sourceFile = $path . '/source.json';

    if (file_exists($sourceFile)) {
        echo "✔ Source exists for {$folder}, skipping\n";
        continue;
    }

    echo "🔍 Discovering source for {$folder}...\n";

    $query = "{$folder} AZ sign code zoning ordinance";
    $results = googleSearch($query, $googleKey, $googleCx);

    if (!$results || count($results) === 0) {
        echo "⚠ No results found for {$folder}\n";
        continue;
    }

    $top = $results[0];

    $draft = [
        'jurisdiction' => [
            'label' => $folder,
            'jurisdictionType' => 'City',
            'state' => 'AZ',
            'country' => 'US'
        ],
        'ordinance' => [
            'title' => $top['title'] ?? 'Unknown sign ordinance',
            'codeReference' => null,
            'subject' => 'Sign regulations',
            'authority' => $folder
        ],
        'authoritativeSource' => [
            'type' => 'web',
            'url' => $top['link'] ?? null,
            'publisher' => $top['displayLink'] ?? 'Unknown',
            'contentFormats' => ['html'],
            'accessExpectation' => 'PUBLIC',
            'canonical' => false
        ],
        'retrieval' => [
            'preferredFormat' => 'html',
            'fallbackFormats' => ['pdf'],
            'normalizationProfile' => 'UNVERIFIED',
            'crawlAllowed' => false
        ],
        'validation' => [
            'availabilityRequired' => false,
            'httpFailureIsViolation' => false,
            'redirectAllowed' => true,
            'contentTypeValidation' => false
        ],
        'canonization' => [
            'canonizedArtifact' => null,
            'canonizationMethod' => 'HUMAN_REQUIRED',
            'lastCanonizedAt' => null,
            'effectiveDate' => null
        ],
        'governance' => [
            'tier' => 'Tier-3',
            'authorityLevel' => 'UNVERIFIED_EXTERNAL_SOURCE',
            'autoAcceptChanges' => false,
            'requiresInterpretation' => true
        ],
        'discoveryMeta' => [
            'discoveredBy' => 'skyecrawler.php',
            'discoveryMethod' => 'google_custom_search',
            'query' => $query,
            'discoveredAt' => time(),
            'reviewStatus' => 'PENDING_HUMAN_REVIEW'
        ],
        'notes' => [
            'This source file was generated by Skyecrawler in prototype mode.',
            'All fields require human verification before use.',
            'This file is non-canonical and observational only.'
        ]
    ];

    file_put_contents(
        $sourceFile,
        json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    echo "📝 Draft source.json created for {$folder}\n";
}

#endregion

#region SECTION IV — Ouput

echo "✔ Skyecrawler run complete (prototype mode)\n";

#endregion