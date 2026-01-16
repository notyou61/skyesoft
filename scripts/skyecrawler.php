<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — skyecrawler.php
 *  Role: External Discovery Assistant (Platform-Aware)
 *  Authority: Skyecrawl External Discovery Standard (Codex)
 *
 *  CURRENT GOAL (2026 phase)
 *  -------------------------
 *  1. Find the authoritative online code location for a jurisdiction
 *  2. Identify which major platform hosts it (AmLegal vs Municode mainly)
 *  3. Record a trustworthy entry-point URL
 *  4. Classify automation potential honestly
 *
 *  IMPORTANT REALITY (Jan 2026):
 *  - American Legal → usually safe to deep-link automatically
 *  - Municode     → almost all modern implementations are React SPAs
 *                   → reliable static deep-chapter discovery is NOT possible
 *                   → we stop at base code URL + flag for human review
 *
 *  This script performs LINK DISCOVERY ONLY — no content scraping.
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

#region SECTION II — Helpers

function googleSearch(string $query, string $key, string $cx): array
{
    $url = 'https://www.googleapis.com/customsearch/v1'
         . '?key=' . urlencode($key)
         . '&cx='  . urlencode($cx)
         . '&q='   . urlencode($query)
         . '&num=5';

    $json = @file_get_contents($url);
    if ($json === false) return [];

    $data = json_decode($json, true);
    return $data['items'] ?? [];
}

function detectCodePlatform(string $url): string
{
    $url = strtolower($url);
    if (str_contains($url, 'codelibrary.amlegal.com')) return 'amlegal';
    if (str_contains($url, 'library.municode.com') || str_contains($url, 'municode.com')) {
        return 'municode';
    }
    return 'unknown';
}

#endregion

#region SECTION III — Main Discovery Loop

$folders = scandir($jurisdictionDir);

foreach ($folders as $folder) {
    if ($folder === '.' || $folder === '..') continue;
    if ($target && strtolower($folder) !== $target) continue;

    $path = "{$jurisdictionDir}/{$folder}";
    if (!is_dir($path)) continue;

    $sourceFile = "{$path}/source.json";
    if (file_exists($sourceFile)) {
        echo "⏭ Skipping {$folder} — source.json already exists\n";
        continue;
    }

    echo "🔍 Discovering source for {$folder}...\n";

    $platform = 'unknown';
    $baseUrl  = null;

    // Priority 1: Try American Legal first (best automation potential)
    $query = "site:codelibrary.amlegal.com az {$folder}";
    $results = googleSearch($query, $googleKey, $googleCx);

    foreach ($results as $item) {
        if (str_contains($item['link'], 'codelibrary.amlegal.com')) {
            $baseUrl  = $item['link'];
            $platform = 'amlegal';
            break;
        }
    }

    // Priority 2: Fall back to Municode
    if (!$baseUrl) {
        $query = "site:library.municode.com az {$folder} codes";
        $results = googleSearch($query, $googleKey, $googleCx);

        foreach ($results as $item) {
            $link = $item['link'];
            if (str_contains($link, "/{$folder}/codes/") || str_contains($link, "/{$folder}/")) {
                $baseUrl  = $link;
                $platform = 'municode';
                break;
            }
        }
    }

    // Last chance: slightly broader Municode search
    if (!$baseUrl) {
        $query = "site:library.municode.com {$folder} arizona zoning OR code OR ordinances";
        $results = googleSearch($query, $googleKey, $googleCx);

        foreach ($results as $item) {
            $link = $item['link'];
            if (str_contains($link, "/{$folder}/")) {
                $baseUrl  = $link;
                $platform = 'municode';
                break;
            }
        }
    }

    if (!$baseUrl) {
        echo "  ❌ No usable code source location found\n";
        continue;
    }

    // Platform consistency check (trust URL more than search snippet)
    $detected = detectCodePlatform($baseUrl);
    if ($detected !== 'unknown' && $detected !== $platform) {
        echo "  ⚠️ Platform mismatch → overriding to detected: {$detected}\n";
        $platform = $detected;
    }

    echo "  • Platform: {$platform}\n";
    echo "  • Base URL: {$baseUrl}\n";

    // Final decision — honest automation boundary
    $entryUrl = $baseUrl;
    $reviewStatus = match ($platform) {
        'amlegal'  => 'AUTO_DISCOVERED',
        'municode' => 'PENDING_HUMAN_REVIEW',
        default    => 'UNKNOWN_SOURCE'
    };

    // Prepare structured output
    $draft = [
        'jurisdiction' => [
            'label'           => ucwords($folder),
            'jurisdictionType' => 'City',
            'state'           => 'AZ',
            'country'         => 'US'
        ],
        'ordinance' => [
            'title'    => "{$folder} Sign Regulations (entry point)",
            'subject'  => 'Sign regulations',
            'authority' => $folder
        ],
        'authoritativeSource' => [
            'type'              => 'web',
            'url'               => $entryUrl,
            'publisher'         => $platform,
            'contentFormats'    => ['html'],
            'accessExpectation' => 'PUBLIC',
            'canonical'         => $platform === 'amlegal'   // only AmLegal gets canonical for now
        ],
        'discoveryMeta' => [
            'discoveredBy'   => 'skyecrawler.php',
            'platform'       => $platform,
            'discoveryModel' => strtoupper($platform) . '_BASE_ONLY',
            'discoveredAt'   => time(),
            'reviewStatus'   => $reviewStatus
        ],
        'notes' => [
            'Entry point discovery only — no content was scraped.',
            $platform === 'amlegal'
                ? 'American Legal implementations usually allow reliable deep linking.'
                : 'Municode is predominantly a modern React SPA — deep chapter discovery currently requires human navigation.',
            'Human verification of sign regulations location is required for municode implementations.'
        ]
    ];

    file_put_contents(
        $sourceFile,
        json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    echo "  📝 Created draft source.json for {$folder}  ({$reviewStatus})\n";
}

#endregion

#region SECTION IV — Final Output

echo "\n✔ Skyecrawler run complete\n";

#endregion