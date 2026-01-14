<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — skyecrawler.php
 *  Role: External Discovery Assistant (Host-First Prototype)
 *  Authority: Skyecrawl External Discovery Standard (Codex)
 *
 *  DISCOVERY MODEL
 *  ---------------
 *  1. Identify likely authoritative HOSTS first (municode, .gov, etc.)
 *  2. Run constrained searches against those hosts
 *  3. Rank candidates by host + URL pattern
 *  4. Emit draft source.json for HUMAN REVIEW ONLY
 *
 *  MODES
 *  -----
 *  • No arguments        → discover for ALL jurisdictions missing source.json
 *  • <JurisdictionName>  → discover ONLY that jurisdiction (if unresolved)
 *
 * ===================================================================== */

#region SECTION 0 — Paths & Inputs

$rootDir         = dirname(__DIR__);
$jurisdictionDir = $rootDir . '/data/authoritative/jurisdictions';
$envPath         = $rootDir . '/secure/env.local';

$target = $argv[1] ?? null;
$target = $target ? strtolower(trim($target)) : null;

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

#region SECTION II — Discovery Configuration

$hostProfiles = [
    [
        'name'     => 'Municode Library',
        'domain'   => 'library.municode.com',
        'weight'   => 120,
        'patterns' => ['tempe', 'codes', 'sign', 'zoning']
    ],
    [
        'name'     => 'Municode Root',
        'domain'   => 'municode.com',
        'weight'   => 90,
        'patterns' => ['codes']
    ],
    [
        'name'     => 'City Website',
        'domain'   => '.gov',
        'weight'   => 70,
        'patterns' => ['sign', 'ordinance', 'zoning']
    ]
];

#endregion

#region SECTION III — Helpers

function googleSearch(string $query, string $key, string $cx): array
{
    $url = 'https://www.googleapis.com/customsearch/v1'
         . '?key=' . urlencode($key)
         . '&cx='  . urlencode($cx)
         . '&q='   . urlencode($query);

    $json = @file_get_contents($url);
    if ($json === false) return [];

    $data = json_decode($json, true);
    return is_array($data['items'] ?? null) ? $data['items'] : [];
}

function scoreResult(array $item, array $hostProfiles): int
{
    $score = 0;
    $url   = strtolower($item['link'] ?? '');

    foreach ($hostProfiles as $host) {
        if (str_contains($url, $host['domain'])) {
            $score += $host['weight'];
            foreach ($host['patterns'] as $p) {
                if (str_contains($url, $p)) {
                    $score += 10;
                }
            }
        }
    }

    return $score;
}

#endregion

#region SECTION IV — Discovery Loop

$folders = scandir($jurisdictionDir);

foreach ($folders as $folder) {

    if ($folder === '.' || $folder === '..') continue;
    if ($target && strtolower($folder) !== $target) continue;

    $path = "{$jurisdictionDir}/{$folder}";
    if (!is_dir($path)) continue;

    $sourceFile = "{$path}/source.json";
    if (file_exists($sourceFile)) {
        echo "⏭ Skipping {$folder} (source.json exists)\n";
        continue;
    }

    echo "🔍 Discovering source for {$folder}...\n";

    $queries = [
        "site:library.municode.com {$folder} AZ sign",
        "site:library.municode.com {$folder} zoning sign",
        "{$folder} AZ sign code municode",
        "{$folder} Arizona sign ordinance",
        "City of {$folder} AZ sign code"
    ];


    $candidates = [];

    foreach ($queries as $q) {
        foreach (googleSearch($q, $googleKey, $googleCx) as $item) {
            $item['__score'] = scoreResult($item, $hostProfiles);
            $candidates[] = $item;
        }
    }

    if (empty($candidates)) {
        echo "⚠ No candidates found for {$folder}\n";
        continue;
    }

    usort($candidates, fn($a, $b) => $b['__score'] <=> $a['__score']);
    $best = $candidates[0];

    $draft = [
        'jurisdiction' => [
            'label' => $folder,
            'jurisdictionType' => 'City',
            'state' => 'AZ',
            'country' => 'US'
        ],
        'ordinance' => [
            'title' => $best['title'] ?? "{$folder} Sign Ordinance",
            'codeReference' => null,
            'subject' => 'Sign regulations',
            'authority' => $folder
        ],
        'authoritativeSource' => [
            'type' => 'web',
            'url' => $best['link'] ?? null,
            'publisher' => $best['displayLink'] ?? 'Unknown',
            'contentFormats' => ['html'],
            'accessExpectation' => 'PUBLIC',
            'canonical' => false
        ],
        'retrieval' => [
            'preferredFormat' => 'html',
            'fallbackFormats' => ['pdf'],
            'normalizationProfile' => 'MUNICODE_STANDARD_V1',
            'crawlAllowed' => false
        ],
        'governance' => [
            'tier' => 'Tier-3',
            'authorityLevel' => 'UNVERIFIED_EXTERNAL_SOURCE',
            'autoAcceptChanges' => false,
            'requiresInterpretation' => true
        ],
        'discoveryMeta' => [
            'discoveredBy' => 'skyecrawler.php',
            'discoveryModel' => 'HOST_FIRST',
            'score' => $best['__score'],
            'queriesUsed' => $queries,
            'discoveredAt' => time(),
            'reviewStatus' => 'PENDING_HUMAN_REVIEW'
        ],
        'notes' => [
            'Generated via host-first discovery.',
            'Metadata only; no content mirrored.',
            'Requires human validation before use.'
        ]
    ];

    file_put_contents(
        $sourceFile,
        json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    echo "📝 Draft source.json created for {$folder}\n";
}

#endregion

#region SECTION V — Output

echo "✔ Skyecrawler run complete (host-first prototype)\n";

#endregion