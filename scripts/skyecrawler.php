<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — skyecrawler.php
 *  Role: External Discovery Assistant (Municode-Aware Prototype)
 *  Authority: Skyecrawl External Discovery Standard (Codex)
 *
 *  GOAL (CURRENT PHASE)
 *  --------------------
 *  Correctly identify the MUNICODE SIGN REGULATION ENTRY POINT
 *  for a jurisdiction (starting with Tempe).
 *
 *  This is LINK DISCOVERY ONLY — no scraping or interpretation.
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
    return is_array($data['items'] ?? null) ? $data['items'] : [];
}

/**
 * Attempt to locate a Municode "sign" node by inspecting links
 * inside the zoning / development code page.
 */
function discoverMunicodeSignNode(string $baseUrl): ?string
{
    $html = @file_get_contents($baseUrl);
    if ($html === false) return null;

    // Look for links containing "sign"
    if (!preg_match_all('/href="([^"]+)"/i', $html, $matches)) {
        return null;
    }

    foreach ($matches[1] as $href) {
        $hrefLower = strtolower($href);
        if (str_contains($hrefLower, 'sign')) {
            // Normalize relative URLs
            if (str_starts_with($href, '/')) {
                return 'https://library.municode.com' . $href;
            }
            if (str_starts_with($href, 'http')) {
                return $href;
            }
        }
    }

    return null;
}

#endregion

#region SECTION III — Discovery Loop

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

    // STEP 1 — Confirm Municode presence
    $query = "site:library.municode.com az {$folder} zoning sign";
    $results = googleSearch($query, $googleKey, $googleCx);

    if (empty($results)) {
        echo "⚠ No Municode results for {$folder}\n";
        continue;
    }

    // STEP 2 — Find the base code URL
    $baseUrl = null;
    foreach ($results as $item) {
        if (str_contains($item['link'], "/{$folder}/codes/")) {
            $baseUrl = $item['link'];
            break;
        }
    }

    if (!$baseUrl) {
        // fallback to city_code
        $baseUrl = "https://library.municode.com/az/{$folder}/codes/zoning_and_development_code";
    }

    echo "  • Found Municode base: {$baseUrl}\n";

    // STEP 3 — Attempt to refine to sign node
    $signUrl = discoverMunicodeSignNode($baseUrl);

    if ($signUrl) {
        echo "  • Refined to sign node: {$signUrl}\n";
    } else {
        echo "  • No sign node found; using base URL\n";
        $signUrl = $baseUrl;
    }

    // STEP 4 — Emit draft source.json
    $draft = [
        'jurisdiction' => [
            'label' => $folder,
            'jurisdictionType' => 'City',
            'state' => 'AZ',
            'country' => 'US'
        ],
        'ordinance' => [
            'title' => "{$folder} Sign Regulations",
            'subject' => 'Sign regulations',
            'authority' => $folder
        ],
        'authoritativeSource' => [
            'type' => 'web',
            'url' => $signUrl,
            'publisher' => 'library.municode.com',
            'contentFormats' => ['html'],
            'accessExpectation' => 'PUBLIC',
            'canonical' => false
        ],
        'discoveryMeta' => [
            'discoveredBy' => 'skyecrawler.php',
            'discoveryModel' => 'MUNICODE_REFINEMENT',
            'baseUrl' => $baseUrl,
            'refined' => $signUrl !== $baseUrl,
            'discoveredAt' => time(),
            'reviewStatus' => 'PENDING_HUMAN_REVIEW'
        ],
        'notes' => [
            'Municode sign node refined via link discovery.',
            'No ordinance content scraped.',
            'Human verification required.'
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

echo "✔ Skyecrawler run complete (Municode-aware prototype)\n";

#endregion