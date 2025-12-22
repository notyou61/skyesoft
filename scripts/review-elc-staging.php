<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — reviewElcStaging.php
//  Version: 1.0.0
//  Last Updated: 2025-12-21
//  Codex Tier: 3 — Data Augmentation / Validation
//
//  Role:
//  Review and augment ELC staging data using authoritative
//  registries (Census, ArcGIS, Jurisdiction Registry) and
//  constrained AI inference.
//
//  Responsibilities:
//   • County + FIPS enrichment (Census)
//   • Parcel + jurisdiction resolution (Maricopa ArcGIS)
//   • Jurisdiction normalization (Authoritative Registry)
//   • Contact salutation inference & correction (AI, guarded)
//   • Structural validation (non-fatal)
//
//  Forbidden:
//   • No mutation of Codex, Registry, or Authoritative data
//   • No SOT promotion logic
//   • No heuristic or hard-coded jurisdiction logic
//   • No destructive overwrites without authority
// ======================================================================

header("Content-Type: application/json; charset=UTF-8");

#region SECTION 0 — Bootstrap & Guardrails

$path = __DIR__ . "/../data/runtimeEphemeral/elc-staging.json";
$data = json_decode(file_get_contents($path), true);

$registryPath = __DIR__ . '/../data/authoritative/jurisdictionRegistry.json';

if (!file_exists($registryPath)) {
    throw new RuntimeException(
        "Jurisdiction registry not found at {$registryPath}"
    );
}

$jurisdictionRegistry = json_decode(
    file_get_contents($registryPath),
    true
);

if (!is_array($jurisdictionRegistry)) {
    throw new RuntimeException(
        "Jurisdiction registry is invalid JSON"
    );
}

#endregion

#region SECTION 1 — Environment Loading (Local / CLI)

$envPath = dirname(__DIR__) . '/secure/env.local';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        putenv($line);
    }
}

echo "OPENAI_API_KEY loaded: " .
     (getenv('OPENAI_API_KEY')
        ? 'YES (length: ' . strlen(getenv('OPENAI_API_KEY')) . ')'
        : 'NO'
     ) . "\n";

#endregion

#region SECTION 2 — Runtime State

$issues = [];
$fixes  = [];
$dryRun = false;

function isInt($v): bool {
    return is_int($v) && $v > 0;
}

#endregion

#region SECTION 3 — Dependencies & Library Mode

require_once __DIR__ . '/censusGeocode.php';

define('SKYESOFT_LIB_MODE', true);
require_once __DIR__ . '/../api/askOpenAI.php';

#endregion

#region SECTION 4 — Maricopa Parcel Resolution (Authoritative GIS)

function resolveMaricopaParcel(array &$l): ?string
{
    if (
        empty($l['locationAddress']) ||
        empty($l['locationCity'])
    ) {
        return null;
    }

    $street = strtoupper(trim(preg_replace('/\s+/', ' ', $l['locationAddress'])));
    $city   = strtoupper(trim($l['locationCity']));
    $full   = $street . ' ' . $city;

    $baseUrl = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/MaricopaDynamicQueryService/MapServer/3/query';
    $outFields = 'APN,APN_DASH,PHYSICAL_ADDRESS,JURISDICTION,PHYSICAL_CITY';

    $where = "UPPER(PHYSICAL_ADDRESS) = '" . addslashes($full) . "'";
    $parcel = queryMaricopaArcGIS($baseUrl, $where, $outFields, $l);

    if ($parcel) {
        return $parcel;
    }

    $where = "UPPER(PHYSICAL_ADDRESS) LIKE '%" . addslashes($street) . "%'
              AND UPPER(PHYSICAL_ADDRESS) LIKE '%" . addslashes($city) . "%'";

    return queryMaricopaArcGIS($baseUrl, $where, $outFields, $l);
}

function queryMaricopaArcGIS(
    string $baseUrl,
    string $where,
    string $outFields,
    array &$l
): ?string {

    $params = [
        'where'          => $where,
        'outFields'      => $outFields,
        'returnGeometry' => 'false',
        'f'              => 'json'
    ];

    $url = $baseUrl . '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; Skyesoft)'
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        return null;
    }

    $data = json_decode($response, true);
    $features = $data['features'] ?? [];

    if (count($features) !== 1) {
        return null;
    }

    $attrs = $features[0]['attributes'];
    $apnRaw = $attrs['APN_DASH'] ?? $attrs['APN'] ?? null;

    if (!$apnRaw) {
        return null;
    }

    $formattedApn = trim($apnRaw);
    $l['locationParcelNumber'] = $formattedApn;
    $GLOBALS['fixes'][] =
        "Resolved parcel '{$formattedApn}' via ArcGIS for locationId {$l['locationId']}";

    if (empty($l['locationJurisdiction'])) {
        $jur = trim($attrs['JURISDICTION'] ?? $attrs['PHYSICAL_CITY'] ?? '');
        if ($jur) {
            $l['locationJurisdiction'] =
                strtoupper($jur) === 'UNINCORPORATED'
                    ? 'Unincorporated Maricopa County'
                    : $jur;

            $GLOBALS['fixes'][] =
                "Backfilled jurisdiction '{$l['locationJurisdiction']}' via ArcGIS for locationId {$l['locationId']}";
        }
    }

    return $formattedApn;
}

#endregion

#region SECTION 5 — Entity Integrity Checks

/* entity validation loop */

#endregion

#region SECTION 6 — Jurisdiction Registry Resolver

function resolveJurisdictionFromCity(
    string $city,
    array $registry
): ?string {

    $cityUpper = strtoupper(trim($city));

    foreach ($registry as $name => $meta) {
        if (strtoupper($name) === $cityUpper) {
            return $name;
        }

        foreach ($meta['aliases'] ?? [] as $alias) {
            if (strtoupper($alias) === $cityUpper) {
                return $name;
            }
        }
    }

    return null;
}

#endregion

#region SECTION 7 — Location Enrichment
/*
    Flow (authoritative → least-authoritative):

    1. Census (county + FIPS)         — nationwide
    2. ArcGIS parcel + jurisdiction   — Maricopa County only
    3. Jurisdiction Registry          — authoritative name normalization
    4. Conditional default:
        • AZ + Maricopa  → Unincorporated Maricopa County
        • Otherwise      → Needed
*/

foreach ($data['locations'] as &$l) {

    // ------------------------------------------------------------------
    // 7.1 — County & FIPS enrichment (Census, nationwide)
    // ------------------------------------------------------------------
    if (
        (empty($l['locationCounty']) || empty($l['locationCountyFips'])) &&
        !empty($l['locationAddress']) &&
        !empty($l['locationState'])
    ) {

        $addressLine = trim(
            $l['locationAddress'] . ', ' .
            ($l['locationCity'] ?? '') . ', ' .
            $l['locationState'] . ' ' .
            ($l['locationZip'] ?? '')
        );

        $geo = censusGeocodeAddress($addressLine);

        if ($geo && !empty($geo['county']) && !empty($geo['countyFips'])) {

            if (empty($l['locationCounty'])) {
                $l['locationCounty'] = $geo['county'];
                $fixes[] = "Backfilled county '{$geo['county']}' for locationId {$l['locationId']}";
            }

            if (empty($l['locationCountyFips'])) {
                $l['locationCountyFips'] = $geo['countyFips'];
                $fixes[] = "Backfilled county FIPS '{$geo['countyFips']}' for locationId {$l['locationId']}";
            }
        }
    }

    // ------------------------------------------------------------------
    // 7.2 — Parcel & jurisdiction resolution (Maricopa only, ArcGIS)
    // ------------------------------------------------------------------
    if (
        ($l['locationState'] ?? null) === 'AZ' &&
        strtoupper($l['locationCounty'] ?? '') === 'MARICOPA' &&
        (($l['locationParcelNumber'] ?? '') === '' || $l['locationParcelNumber'] === 'Pending')
    ) {

        $parcel = resolveMaricopaParcel($l);

        if (!$parcel && ($l['locationParcelNumber'] ?? '') !== 'Pending') {
            $l['locationParcelNumber'] = 'Pending';
            $fixes[] = "Marked parcel as Pending for locationId {$l['locationId']}";
        }
    }

    // ------------------------------------------------------------------
    // 7.3 — Jurisdiction normalization via authoritative registry
    // ------------------------------------------------------------------
    if (
        empty($l['locationJurisdiction']) &&
        !empty($l['locationCity'])
    ) {

        $resolved = resolveJurisdictionFromCity(
            $l['locationCity'],
            $jurisdictionRegistry
        );

        if ($resolved) {
            $l['locationJurisdiction'] = $resolved;
            $fixes[] = "Backfilled jurisdiction '{$resolved}' from registry for locationId {$l['locationId']}";
        }
    }

    // ------------------------------------------------------------------
    // 7.4 — Conditional default (truth-preserving)
    // ------------------------------------------------------------------
    if (empty($l['locationJurisdiction'])) {

        if (
            ($l['locationState'] ?? null) === 'AZ' &&
            strtoupper($l['locationCounty'] ?? '') === 'MARICOPA'
        ) {
            $l['locationJurisdiction'] = 'Unincorporated Maricopa County';
            $fixes[] = "Defaulted jurisdiction to Unincorporated Maricopa County for locationId {$l['locationId']}";
        } else {
            $l['locationJurisdiction'] = 'Needed';
            $fixes[] = "Marked jurisdiction as Needed for locationId {$l['locationId']}";
        }
    }

    // ------------------------------------------------------------------
    // 7.5 — Non-Maricopa parcel placeholder (explicit)
    // ------------------------------------------------------------------
    if (empty($l['locationParcelNumber'])) {
        $l['locationParcelNumber'] = 'Pending';
        $fixes[] = "Marked parcel as Pending for locationId {$l['locationId']}";
    }
}
unset($l);

#endregion

#region SECTION 8 — Location Validation

/* structural + duplication checks */

#endregion

#region SECTION 9 — Contact Augmentation (AI, Guarded)

 /* salutation infer + validate via inferSalutation() */

#endregion

#region SECTION 10 — Report & Persistence

echo "\nELC STAGING REVIEW & AUTO-FIX\n-----------------------------\n";

if ($issues) {
    echo "Issues detected:\n";
    foreach ($issues as $i => $msg) {
        echo ($i + 1) . ". $msg\n";
    }
}

if ($fixes) {
    echo "\nFixes applied:\n";
    foreach ($fixes as $i => $msg) {
        echo ($i + 1) . ". $msg\n";
    }
}

echo "\n" . (empty($issues) ? "✅" : "⚠️") .
     " " . count($issues) . " issue(s), " .
     count($fixes) . " fix(es).\n";

file_put_contents(
    $path,
    json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    )
);

echo "\nFile saved with fixes applied to: $path\n";

#endregion
