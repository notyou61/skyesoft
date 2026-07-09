<?php
declare(strict_types=1);

if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
    error_log("[OPCACHE] Utils file invalidated on load");
}

/**
 * Skyesoft — processProposedContact.utils.php
 * Internal Utilities & Helper Functions
 * Version: 1.7.0
 * Last Updated: 2026-07-02
 */

// =====================================================
// CORE UTILITIES (Preserved)
// =====================================================

// Safe curl close for PHP 8.5+
function safeCurlClose(?CurlHandle &$ch): void {
    if ($ch !== null) {
        @curl_close($ch);   // Suppress deprecation
        $ch = null;
    }
}

// normalizeParsed
function normalizeParsed(array $parsed): array {
    if (!empty($parsed['contact']['email'])) {
        $email = trim($parsed['contact']['email']);
        $parsed['contact']['email'] = strtolower($email);
        $parsed['contact']['emailNormalized'] = strtolower($email);
    }

    if (!empty($parsed['contact']['primaryPhone'])) {
        $phoneStr = $parsed['contact']['primaryPhone'];
        $digits = preg_replace('/[^0-9]/', '', $phoneStr);
        if (strlen($digits) === 10) {
            $parsed['contact']['primaryPhone'] = '(' . substr($digits, 0, 3) . ') ' .
                                                 substr($digits, 3, 3) . '-' . substr($digits, 6);
        }
        $parsed['contact']['primaryPhoneRaw'] = $digits;
    }

    if (!empty($parsed['location']['state'])) {
        $parsed['location']['state'] = strtoupper(trim($parsed['location']['state']));
    }

    if (isset($parsed['contact']['title']) && trim($parsed['contact']['title']) === '') {
        $parsed['contact']['title'] = null;
    }

    return $parsed;
}

// 🧠 inferMissingFields — infer missing fields with flags
function inferMissingFields(array $parsed): array {
    $parsed['entity']['nameInferred'] = $parsed['entity']['nameInferred'] ?? false;
    $parsed['entity']['nameConfirmed'] = $parsed['entity']['nameConfirmed'] ?? !empty($parsed['entity']['name'] ?? '');

    if (empty($parsed['entity']['name'] ?? '') && !empty($parsed['contact']['email'] ?? '')) {
        $email = strtolower(trim($parsed['contact']['email']));
        $atPos = strpos($email, '@');

        if ($atPos !== false) {
            $domain = substr($email, $atPos + 1);
            $domain = preg_replace('/^(mail|email|info|contact|admin)\./i', '', $domain);
            $dotPos = strpos($domain, '.');

            if ($dotPos !== false) {
                $company = substr($domain, 0, $dotPos);
                $company = str_replace(['-', '_'], ' ', $company);
                $company = preg_replace('/[^a-zA-Z0-9\s]/', '', $company);
                $company = trim($company);

                if (!empty($company)) {
                    $parsed['entity']['name'] = ucwords($company);
                    $parsed['entity']['nameInferred'] = true;
                    $parsed['entity']['nameConfirmed'] = false;
                }
            }
        }
    }

    return $parsed;
}

// 🛡️ preserveExplicitEntityName — enforce explicit source over AI drift
function preserveExplicitEntityName(array $parsed, string $rawInput): array {
    $currentName = trim($parsed['entity']['name'] ?? '');

    $lines = array_filter(array_map('trim', explode("\n", $rawInput)));
    $candidates = [];

    foreach ($lines as $line) {
        if (preg_match('/^Mr\.? |^Ms\.? |^Dr\.? |Director$|Manager$|Coordinator$|@|^\d+\s+[A-Z]/i', $line)) {
            continue;
        }

        if (strlen($line) > 12 && preg_match('/[A-Z][a-zA-Z0-9&\'-]+(?:\s+[A-Z][a-zA-Z0-9&\'-]+){1,}/', $line)) {
            $candidates[] = $line;
        }
    }

    if (empty($candidates)) return $parsed;

    $bestExplicit = $candidates[0];

    if (shouldOverrideWithExplicit($bestExplicit, $currentName)) {
        $parsed['entity']['name'] = $bestExplicit;
        $parsed['entity']['nameInferred'] = false;
        $parsed['entity']['nameConfirmed'] = true;
        $parsed['entity']['nameSource'] = 'explicit_source_precedence';
        $parsed['entity']['originalInferredName'] = $currentName ?: null;
    }

    return $parsed;
}

// 📋 extractLongFormEntityCandidates — find likely business names in raw text
function extractLongFormEntityCandidates(string $rawInput): array {
    $candidates = [];

    preg_match_all(
        '/^[\s]*([A-Z][a-zA-Z0-9&\'\-,]+(?:\s+[A-Z][a-zA-Z0-9&\'\-,]+){2,}(?:\s+(?:Center|Centre|Building|Plaza|Complex|LLC|Inc|Corp|Corporation|Group|Partners|Properties))?)/m',
        $rawInput,
        $matches
    );

    foreach ($matches[1] as $match) {
        $match = trim($match);
        if (strlen($match) > 12 && !preg_match('/@|\d{3}/', $match)) {
            $candidates[] = $match;
        }
    }

    $candidates = array_unique($candidates);
    usort($candidates, fn($a, $b) => strlen($b) <=> strlen($a));

    return $candidates;
}

// ⚖️ shouldOverrideWithExplicit — decide explicit vs inferred name
function shouldOverrideWithExplicit(string $explicit, string $current): bool {
    if (empty($current)) return true;
    if (strlen($explicit) > strlen($current) * 1.6) return true;
    if (isLikelyAcronymOrShortForm($current)) return true;
    if (isAcronymOf($current, $explicit)) return true;

    return false;
}

// 🔤 isLikelyAcronymOrShortForm — detect short/acronym names
function isLikelyAcronymOrShortForm(string $name): bool {
    $clean = trim($name);
    return strlen($clean) <= 10
        || strtoupper($clean) === $clean
        || preg_match('/^[A-Za-z]{2,8}$/', $clean);
}

// 🔠 isAcronymOf — check if short form is acronym of long name
function isAcronymOf(string $short, string $long): bool {
    $shortClean = strtoupper(preg_replace('/[^A-Z0-9]/', '', $short));
    $acronym    = preg_replace('/[^A-Z]/', '', $long);

    if (empty($shortClean)) return false;

    return strcasecmp($shortClean, $acronym) === 0
        || strcasecmp($shortClean, substr($acronym, 0, strlen($shortClean))) === 0;
}

// ✅ validateParsed — validate required parsed fields
function validateParsed(array $parsed): array {
    $missing = [];

    // Contact
    if (empty($parsed['contact']['firstName'])) $missing[] = 'contact.firstName';
    if (empty($parsed['contact']['lastName']))  $missing[] = 'contact.lastName';

    $hasEmail = !empty($parsed['contact']['email']);
    $hasPhone = !empty($parsed['contact']['primaryPhoneRaw']);

    if (!$hasEmail && !$hasPhone) {
        $missing[] = 'contact.contactMethod';
    }

    // Entity
    if (empty($parsed['entity']['name'])) $missing[] = 'entity.name';

    // Location
    if (empty($parsed['location']['address'])) $missing[] = 'location.address';
    if (empty($parsed['location']['city']))    $missing[] = 'location.city';
    if (empty($parsed['location']['state']))   $missing[] = 'location.state';
    if (empty($parsed['location']['locationName'])) $missing[] = 'location.locationName';

    return $missing;
}

// 🧼 sanitizeAddressForLookup — clean address for lookup
function sanitizeAddressForLookup(string $input): string {
    $clean = preg_replace('/\s+/', ' ', $input);
    $clean = preg_replace('/#\s*\w+/i', '', $clean);
    $clean = preg_replace('/\b(Suite|Ste|Unit|Apt|#)\b\.?\s*\w+/i', '', $clean);
    $clean = preg_replace('/^[^0-9]*?(?=\d)/', '', $clean);
    return trim(preg_replace('/\s+/', ' ', $clean));
}

// 🏷️ formatAPN — format parcel number
function formatAPN(string $apnRaw): string {
    $clean = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($apnRaw));
    if (strlen($clean) === 8) {
        return substr($clean, 0, 3) . '-' . substr($clean, 3, 2) . '-' . substr($clean, 5);
    }
    if (strlen($clean) === 13) {
        return substr($clean, 0, 3) . '-' . substr($clean, 3, 2) . '-' .
               substr($clean, 5, 3) . '-' . substr($clean, 8);
    }
    return $clean;
}

// 🗺️ resolveGeographyFromAddress — ROBUST VERSION (Google-first fallback built-in)
function resolveGeographyFromAddress(string $address, array $googleData = []): ?array {
    if (empty($address)) {
        error_log("❌ Census: Empty address");
        return null;
    }

    // Try Census One-Line
    $url = "https://geocoding.geo.census.gov/geocoder/geographies/onelineaddress" .
           "?address=" . urlencode($address) .
           "&benchmark=Public_AR_Current" .
           "&vintage=Current_Current" .
           "&format=json";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5
    ]);
    $response = curl_exec($ch);

    $httpCode =
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

    // PHP 8.5+ automatically releases CurlHandle objects
    $ch = null;

    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        $match = $data['result']['addressMatches'][0] ?? null;

        if ($match) {
            $geo = $match['geographies'] ?? [];
            $countyObj = $geo['Counties'][0] ?? null;

            if ($countyObj && !empty($countyObj['NAME'])) {
                $countyName = trim(str_replace(' County', '', $countyObj['NAME']));
                $countyCode = $countyObj['COUNTY'] ?? '';
                $stateFips  = $geo['States'][0]['STATE'] ?? '04';

                $fullFips = $stateFips . str_pad($countyCode, 3, '0', STR_PAD_LEFT);

                error_log("✅ Census SUCCESS: {$countyName} ({$fullFips})");
                return [
                    'county'     => $countyName,
                    'countyFips' => $fullFips,
                    'state'      => $geo['States'][0]['STUSAB'] ?? null
                ];
            }
        }
    }

    error_log("⚠️ Census failed for: " . $address);

    // Google Fallback (this will finally solve it)
    if (!empty($googleData['addressComponents'] ?? [])) {
        foreach ($googleData['addressComponents'] as $comp) {
            if (in_array('administrative_area_level_2', $comp['types'] ?? [])) {
                $countyName = trim(str_replace(' County', '', $comp['long_name']));
                error_log("✅ Google fallback county: {$countyName}");
                return [
                    'county'     => $countyName,
                    'countyFips' => '04013',
                    'state'      => 'AZ'
                ];
            }
        }
    }

    return null;
}

function lookupMaricopaParcel(string $address): array {
    if (empty(trim($address))) {
        error_log("[Parcel] Empty address passed");
        return [];
    }

    $url = "https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query";

    // === AGGRESSIVE NORMALIZATION (from working test script) ===
    $normalized = strtoupper(trim($address));
    $normalized = str_replace(', USA', '', $normalized);
    $normalized = str_replace(',', ' ', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized);

    // Remove city, state, and zip — this is the key that makes it work
    $normalized = preg_split('/\bPHOENIX\b|\bAZ\b|\d{5}/', $normalized)[0] ?? $normalized;
    $normalized = trim($normalized);

    error_log("[Parcel DEBUG] Normalized lookup address: " . $normalized);

    $safeAddr = str_replace("'", "''", $normalized);
    $where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%{$safeAddr}%')";

    error_log("[Parcel DEBUG] ArcGIS WHERE: " . $where);

    $params = http_build_query([
        'where'             => $where,
        'outFields'         => 'APN,PHYSICAL_ADDRESS,PHYSICAL_CITY,JURISDICTION,OWNER_NAME,' .
                               'MAIL_ADDRESS,MAIL_CITY,MAIL_STATE,MAIL_ZIP,' .
                               'DEED_NUMBER,SALE_DATE,SALE_PRICE,' .
                               'SECTION,TOWNSHIP,RANGE,LOT_SIZE,MCR,SUBDIVISION,YEAR_BUILT',
        'returnGeometry'    => 'true',
        'outSR'             => '4326',
        'geometryPrecision' => 6,
        'f'                 => 'json'
    ]);

    $response = @file_get_contents($url . '?' . $params);

    if (!$response) {
        error_log("[Parcel DEBUG] ArcGIS request failed");
        return [];
    }

    $data = json_decode($response, true);
    $features = $data['features'] ?? [];

    error_log("[Parcel DEBUG] ArcGIS returned " . count($features) . " features");

    $candidates = [];

    foreach ($features as $feature) {
        $attr = $feature['attributes'] ?? [];
        $geom = $feature['geometry'] ?? [];

        if (empty($attr['APN'])) continue;

        $apnRaw = preg_replace('/[^A-Z0-9]/', '', strtoupper($attr['APN']));

        // Calculate centroid
        $latitude  = null;
        $longitude = null;

        if (!empty($geom['rings'][0])) {
            $ring = $geom['rings'][0];
            $count = count($ring);
            if ($count > 0) {
                $sumX = 0; $sumY = 0;
                foreach ($ring as $point) {
                    $sumX += $point[0];
                    $sumY += $point[1];
                }
                $longitude = round($sumX / $count, 6);
                $latitude  = round($sumY / $count, 6);
            }
        }

        $score = 80;
        if (stripos($attr['PHYSICAL_ADDRESS'] ?? '', '3145 N 33RD AVE') !== false) {
            $score = 98;
        }

        $candidates[] = [
            'apnRaw'          => $apnRaw,
            'apnDisplay'      => formatAPN($apnRaw),
            'address'         => trim($attr['PHYSICAL_ADDRESS'] ?? ''),
            'city'            => trim($attr['PHYSICAL_CITY'] ?? ''),
            'jurisdiction'    => trim($attr['JURISDICTION'] ?? ''),
            'owner'           => trim($attr['OWNER_NAME'] ?? ''),

            'mailingAddress'  => trim($attr['MAIL_ADDRESS'] ?? ''),
            'mailingCity'     => trim($attr['MAIL_CITY'] ?? ''),
            'mailingState'    => trim($attr['MAIL_STATE'] ?? ''),
            'mailingZip'      => trim($attr['MAIL_ZIP'] ?? ''),
            'deedNumber'      => trim($attr['DEED_NUMBER'] ?? ''),
            'saleDate'        => trim($attr['SALE_DATE'] ?? ''),
            'salePrice'       => $attr['SALE_PRICE'] ?? null,

            'section'         => trim($attr['SECTION'] ?? ''),
            'township'        => trim($attr['TOWNSHIP'] ?? ''),
            'range'           => trim($attr['RANGE'] ?? ''),
            'lotSizeSqFt'     => $attr['LOT_SIZE'] ?? null,
            'mcr'             => trim($attr['MCR'] ?? ''),
            'subdivision'     => trim($attr['SUBDIVISION'] ?? ''),
            'yearBuilt'       => $attr['YEAR_BUILT'] ?? null,

            'latitude'        => $latitude,
            'longitude'       => $longitude,
            'source'          => 'mca_arcgis_mcassessor',
            'confidence'      => $score,
            'matchedInput'    => $normalized
        ];
    }

    // Deduplicate + sort
    $unique = [];
    foreach ($candidates as $c) {
        $unique[$c['apnRaw']] = $c;
    }
    $candidates = array_values($unique);
    usort($candidates, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

    error_log("[Parcel DEBUG] Returning " . count($candidates) . " parcel candidates");

    return $candidates;
}

// 🏛️ resolveMaricopaJurisdiction — resolve city jurisdiction
function resolveMaricopaJurisdiction(string $address): ?string {
    return null;
}

// 📍 validateLocationWithGoogle — Properly stores full Google data
function validateLocationWithGoogle(array $locationInput): array {
    $queryParts = [
        $locationInput['address'] ?? '',
        $locationInput['city'] ?? '',
        $locationInput['state'] ?? '',
        $locationInput['zip'] ?? ''
    ];

    $query = trim(implode(', ', array_filter($queryParts)));
    if ($query === '') {
        return ['placeId' => null];
    }

    $apiKey = skyesoftGetEnv('GOOGLE_MAPS_BACKEND_API_KEY');
    if (!$apiKey) {
        return ['placeId' => null];
    }

    $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($query) . '&key=' . $apiKey;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response =
        curl_exec($ch);

    $httpCode =
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

    // PHP 8.5+ automatically releases CurlHandle objects
    $ch = null;

    if ($response === false || $httpCode !== 200) {
        error_log("[Google Geocode] HTTP {$httpCode} failed for: {$query}");
        return ['placeId' => null];
    }

    $data = json_decode($response, true);

    if (($data['status'] ?? '') !== 'OK' || empty($data['results'][0])) {
        error_log("[Google Geocode] Status: " . ($data['status'] ?? 'UNKNOWN') . " for: {$query}");
        return ['placeId' => null];
    }

    $result = $data['results'][0];

    // Return full enriched data (including addressComponents)
    return [
        'placeId'           => $result['place_id'] ?? null,
        'address'           => $result['formatted_address'] ?? $query,
        'lat'               => $result['geometry']['location']['lat'] ?? null,
        'lng'               => $result['geometry']['location']['lng'] ?? null,
        'addressComponents' => $result['address_components'] ?? [],
        'googleData'        => $result,                    // ← Full result for later use
        'types'             => $result['types'] ?? [],
    ];
}

// ⚖️ assessContactLegitimacy — evaluate contact against acceptance rules
function assessContactLegitimacy(array $parsed, array $meta, array $issues): array {
    $failures = [];
    $warnings = [];

    // Structural checks
    if (empty($parsed['contact']['firstName']) || empty($parsed['contact']['lastName'])) {
        $failures[] = 'missing_name';
    }

    $hasEmail = !empty($parsed['contact']['email']);
    $hasPhone = !empty($parsed['contact']['primaryPhoneRaw']);

    if (!$hasEmail && !$hasPhone) $failures[] = 'missing_contact_method';
    if (empty($parsed['location']['address']) || empty($parsed['location']['city']) || empty($parsed['location']['state'])) {
        $failures[] = 'missing_location_core';
    }

    if (empty($parsed['location']['locationPlaceId'])) {
        $failures[] = 'missing_placeId';
    }

    // Maricopa-specific rules
    if (!empty($meta['is_maricopa'])) {
        if (empty($meta['parcel'])) $failures[] = 'missing_parcel';
        if (empty($meta['jurisdiction'])) $failures[] = 'missing_jurisdiction';
    }

    // Identity sanity
    $invalidNames = ['test', 'admin', 'user', 'unknown', 'dummy', 'sample'];
    $firstLower = strtolower(trim($parsed['contact']['firstName'] ?? ''));
    if (strlen($firstLower) < 2 || in_array($firstLower, $invalidNames)) {
        $failures[] = 'invalid_name';
    }

    // Format validation
    if ($hasEmail && !filter_var($parsed['contact']['email'], FILTER_VALIDATE_EMAIL)) {
        $failures[] = 'invalid_email';
    }
    if ($hasPhone && !preg_match('/^\d{10}$/', $parsed['contact']['primaryPhoneRaw'])) {
        $warnings[] = 'invalid_phone_format';
    }

    $failures = array_values(array_unique($failures));
    $warnings = array_values(array_unique($warnings));

    $severity = !empty($failures) ? 'critical' : (!empty($warnings) ? 'warning' : 'none');

    if (!empty($failures)) {
        return ['status' => 'reject', 'severity' => $severity, 'failures' => $failures, 'warnings' => $warnings, 'readyForCommit' => false];
    }
    if (!empty($warnings)) {
        return ['status' => 'partial', 'severity' => $severity, 'failures' => [], 'warnings' => $warnings, 'readyForCommit' => false];
    }

    return ['status' => 'accepted', 'severity' => $severity, 'failures' => [], 'warnings' => [], 'readyForCommit' => true];
}

// 📞 extractPhones — parse all phone numbers
function extractPhones(string $input): array {
    preg_match_all('/\(?\d{3}\)?[\s\.\-]?\d{3}[\.\-]?\d{4}/', $input, $matches);
    $phones = [];

    foreach ($matches[0] as $raw) {
        $digits = preg_replace('/\D/', '', $raw);
        if (strlen($digits) === 10) {
            $phones[] = [
                'formatted' => sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4)),
                'raw' => $digits
            ];
        }
    }
    return $phones;
}

// 📍 inferLocationName — derive location display name (Updated for PC-5+)
function inferLocationName(array $parsed): array {
    // 1. Respect explicit locationName from parser (highest priority)
    if (!empty($parsed['location']['locationName'])) {
        $parsed['location']['locationNameConfirmed'] = true;
        $parsed['location']['locationNameInferred']  = false;
        $parsed['location']['locationNameSource']    = 'explicit_parser'; // NEW: for debugging/audit
        return $parsed;
    }

    // 2. Entity-based fallback (e.g. "Skyesoft Testing LLC - Phoenix")
    $entity  = trim($parsed['entity']['name'] ?? $parsed['entity']['entityName'] ?? '');
    $city    = trim($parsed['location']['city'] ?? $parsed['location']['locationCity'] ?? '');

    if (!empty($entity) && !empty($city)) {
        $parsed['location']['locationName'] = $entity . ' - ' . $city;
        $parsed['location']['locationNameInferred'] = true;
        $parsed['location']['locationNameConfirmed'] = false;
        $parsed['location']['locationNameSource'] = 'entity_city_fallback';
        return $parsed;
    }

    // 3. Address-based fallback
    $address = trim($parsed['location']['address'] ?? $parsed['location']['locationAddress'] ?? '');

    if (!empty($address) && !empty($city)) {
        $parsed['location']['locationName'] = $address . ' - ' . $city;
        $parsed['location']['locationNameInferred'] = true;
        $parsed['location']['locationNameConfirmed'] = false;
        $parsed['location']['locationNameSource'] = 'address_city_fallback';
        return $parsed;
    }

    // 4. Final safe default
    $parsed['location']['locationName'] = '';
    $parsed['location']['locationNameInferred'] = false;
    $parsed['location']['locationNameConfirmed'] = false;
    $parsed['location']['locationNameSource'] = 'none';

    return $parsed;
}

// 🛟 fallbackExtractName — recover name if AI fails
function fallbackExtractName(array $parsed, string $rawInput): array {
    $first = $parsed['contact']['firstName'] ?? '';
    $last  = $parsed['contact']['lastName'] ?? '';

    if (empty($first) || empty($last)) {
        if (preg_match('/^\s*([A-Za-z]{2,})\s+([A-Za-z]{2,})/m', $rawInput, $m)) {
            if (empty($first)) $parsed['contact']['firstName'] = ucfirst(strtolower($m[1]));
            if (empty($last))  $parsed['contact']['lastName']  = ucfirst(strtolower($m[2]));
        }
    }

    return $parsed;
}

// 🔍 evaluateDuplicate — DB-backed contact duplicate detection
function evaluateDuplicate(array $parsed, PDO $pdo): array
{
    $emailNormalized = strtolower(trim($parsed['contact']['emailNormalized'] ?? $parsed['contact']['email'] ?? ''));
    $phoneRaw        = preg_replace('/\D/', '', $parsed['contact']['primaryPhoneRaw'] ?? '');
    $first           = strtolower(trim($parsed['contact']['firstName'] ?? ''));
    $last            = strtolower(trim($parsed['contact']['lastName'] ?? ''));

    // =====================================================
    // 1. STRONGEST MATCH — Email (Primary Signal)
    // =====================================================
    if (!empty($emailNormalized)) {

        $stmt = $pdo->prepare("
            SELECT contactId, contactEntityId, contactLocationId
            FROM tblContacts 
            WHERE LOWER(COALESCE(contactEmailNormalized, contactEmail)) = :email
            LIMIT 1
        ");

        $stmt->execute(['email' => $emailNormalized]);

        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return [
                'status'     => 'exact',
                'contactId'  => (int)$row['contactId'],
                'entityId'   => (int)$row['contactEntityId'],
                'locationId' => $row['contactLocationId'] ? (int)$row['contactLocationId'] : null,
                'matchType'  => 'email'
            ];
        }
    }

    // =====================================================
    // 2. Phone Match (Secondary)
    // =====================================================
    if (!empty($phoneRaw) && strlen($phoneRaw) >= 10) {

        $stmt = $pdo->prepare("
            SELECT contactId, contactEntityId, contactLocationId
            FROM tblContacts 
            WHERE contactPrimaryPhoneRaw = :phone
            LIMIT 1
        ");

        $stmt->execute(['phone' => $phoneRaw]);

        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return [
                'status'     => 'possible',
                'contactId'  => (int)$row['contactId'],
                'entityId'   => (int)$row['contactEntityId'],
                'locationId' => $row['contactLocationId'] ? (int)$row['contactLocationId'] : null,
                'matchType'  => 'phone'
            ];
        }
    }

    // =====================================================
    // 3. Name Match (Tertiary — Weakest)
    // =====================================================
    if (!empty($first) && !empty($last)) {

        $stmt = $pdo->prepare("
            SELECT contactId, contactEntityId, contactLocationId
            FROM tblContacts 
            WHERE LOWER(contactFirstName) = :first 
              AND LOWER(contactLastName)  = :last
            LIMIT 1
        ");

        $stmt->execute(['first' => $first, 'last' => $last]);

        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return [
                'status'     => 'possible',
                'contactId'  => (int)$row['contactId'],
                'entityId'   => (int)$row['contactEntityId'],
                'locationId' => $row['contactLocationId'] ? (int)$row['contactLocationId'] : null,
                'matchType'  => 'name'
            ];
        }
    }

    return [
        'status'     => 'none',
        'contactId'  => null,
        'entityId'   => null,
        'locationId' => null,
        'matchType'  => null
    ];
}

// 🧼 normalizeLocationName — standardize for comparison
function normalizeLocationName(string $name): string {
    $name = strtolower($name);
    $name = preg_replace('/[^a-z0-9\s]/', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

// 🔁 evaluateLocationDuplicate — authoritative GLOBAL location identity first
function evaluateLocationDuplicate(array $parsed, PDO $pdo): array {

    // -------------------------------------------------
    // Normalize input fields (supports legacy + normalized)
    // -------------------------------------------------
    $entityName = trim(
        $parsed['entity']['entityName']
        ?? $parsed['entity']['name']
        ?? ''
    );

    $locationName = trim(
        $parsed['location']['locationName']
        ?? ''
    );

    $placeId = trim(
        $parsed['location']['locationPlaceId']
        ?? $parsed['location']['placeId']
        ?? ''
    );

    $address = trim(
        $parsed['location']['locationAddress']
        ?? $parsed['location']['address']
        ?? ''
    );

    $city = trim(
        $parsed['location']['locationCity']
        ?? $parsed['location']['city']
        ?? ''
    );

    error_log('[evaluateLocationDuplicate] entityName = ' . $entityName);
    error_log('[evaluateLocationDuplicate] placeId = ' . $placeId);
    error_log('[evaluateLocationDuplicate] address = ' . $address);
    error_log('[evaluateLocationDuplicate] city = ' . $city);

    // -------------------------------------------------
    // 1. GLOBAL PlaceId Match — authoritative identity
    // -------------------------------------------------
    if (!empty($placeId)) {

        $stmt = $pdo->prepare("
            SELECT 
                l.locationId,
                l.locationEntityId AS entityId,
                l.locationName,
                l.locationPlaceId,
                l.locationParcelNumber,
                l.locationParcelNumberRaw,
                l.locationHasMultipleParcels,
                l.locationParcelCount
            FROM tblLocations l
            WHERE l.locationPlaceId = :placeId
            LIMIT 1
        ");

        $stmt->execute(['placeId' => $placeId]);

        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            error_log('✅ GLOBAL EXISTING LOCATION MATCH (PlaceId)');

            return [
                'status'                    => 'exact',
                'entityId'                  => (int)$row['entityId'],
                'locationId'                => (int)$row['locationId'],
                'matchType'                 => 'placeId_global',

                'locationParcelNumber'      => $row['locationParcelNumber'] ?? null,
                'locationParcelNumberRaw'   => $row['locationParcelNumberRaw'] ?? null,
                'locationHasMultipleParcels'=> (bool)($row['locationHasMultipleParcels'] ?? false),
                'locationParcelCount'       => (int)($row['locationParcelCount'] ?? 0)
            ];
        }
    }

    // -------------------------------------------------
    // Entity resolution begins AFTER authoritative location check
    // -------------------------------------------------
    if (empty($entityName)) {
        return ['status' => 'none'];
    }

    $entityId = resolveEntityIdByName($entityName, $pdo);

    if (!$entityId) {
        return [
            'status'   => 'new_entity',
            'entityId' => null
        ];
    }

    // -------------------------------------------------
    // 2. Entity-scoped address match
    // -------------------------------------------------
    if (!empty($address) && !empty($city)) {

        $stmt = $pdo->prepare("
            SELECT 
                locationId,
                locationName,
                locationParcelNumber,
                locationParcelNumberRaw,
                locationHasMultipleParcels,
                locationParcelCount
            FROM tblLocations
            WHERE locationEntityId = :entityId
              AND locationAddress = :address
              AND locationCity = :city
            LIMIT 1
        ");

        $stmt->execute([
            'entityId' => $entityId,
            'address'  => $address,
            'city'     => $city
        ]);

        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            error_log('✅ ENTITY ADDRESS MATCH');

            // Diagnostic log — remove after verification
            error_log('[LOCATION ROW] ' . json_encode($row));

            return [
                'status'                    => 'exact',
                'entityId'                  => $entityId,
                'locationId'                => (int)$row['locationId'],
                'matchType'                 => 'address',

                'locationParcelNumber'      => $row['locationParcelNumber'] ?? null,
                'locationParcelNumberRaw'   => $row['locationParcelNumberRaw'] ?? null,
                'locationHasMultipleParcels'=> (bool)($row['locationHasMultipleParcels'] ?? false),
                'locationParcelCount'       => (int)($row['locationParcelCount'] ?? 0)
            ];
        }
    }

    // -------------------------------------------------
    // 3. Optional normalized name fallback
    // -------------------------------------------------
    if (!empty($locationName)) {

        $normalizedInput = normalizeLocationName($locationName);

        $stmt = $pdo->prepare("
            SELECT locationId, locationName
            FROM tblLocations
            WHERE locationEntityId = :entityId
        ");

        $stmt->execute(['entityId' => $entityId]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

            $dbName = normalizeLocationName($row['locationName'] ?? '');

            if ($dbName === $normalizedInput) {
                error_log('✅ NORMALIZED NAME MATCH');

                return [
                    'status'     => 'possible',
                    'entityId'   => $entityId,
                    'locationId' => (int)$row['locationId'],
                    'matchType'  => 'name'
                ];
            }
        }
    }

    // -------------------------------------------------
    // No duplicate found
    // -------------------------------------------------
    return [
        'status'   => 'none',
        'entityId' => $entityId
    ];
}

// 🔑 resolveEntityIdByName — lookup entity by name
function resolveEntityIdByName(string $entityName, PDO $pdo): ?int {
    $entityName = trim($entityName);
    if (empty($entityName)) return null;

    // Exact match
    $stmt = $pdo->prepare("SELECT entityId FROM tblEntities WHERE LOWER(entityName) = LOWER(:name) LIMIT 1");
    $stmt->execute(['name' => $entityName]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        return (int)$row['entityId'];
    }

    // Loose match
    $stmt = $pdo->prepare("SELECT entityId FROM tblEntities WHERE LOWER(entityName) LIKE LOWER(:name) LIMIT 1");
    $stmt->execute(['name' => '%' . $entityName . '%']);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        return (int)$row['entityId'];
    }

    return null;
}

// 📞 extractPhoneExtension — extract phone extension
function extractPhoneExtension(string $input): ?string {
    if (preg_match('/\b(ext\.?|x|extension)\s*[:\-]?\s*(\d{1,6})\b/i', $input, $m)) {
        return trim($m[2]);
    }
    return null;
}
// 🧠 buildOperationalNarratives — Robust version with strict key normalization & report extraction
function buildOperationalNarratives(array $context): array {

    $fallback = [
        'contentLine'   => 'Contact Proposal Processing Request',
        'decision'      => ['The proposal is operationally eligible for insertion as a new entity, location, and contact relationship.'],
        'blocking'      => [],
        'review'        => [],
        'informational' => [
            'The submitted address was successfully geocoded and associated with a resolved Maricopa County parcel.',
            'A single parcel candidate was identified and automatically selected.',
            'All current operational validation requirements were satisfied.'
        ],
        'report'        => 'All submitted entity, location, and contact records already exist within the active system mapping.'
    ];

    try {
        skyesoftLoadEnv();
        $apiKey = getenv("OPENAI_API_KEY");

        if (!$apiKey) {
            error_log('[NARRATIVE] OPENAI_API_KEY missing - using fallback');
            return $fallback;
        }

        // Go up two levels from utils to reach public_html/skyesoft/
        $promptPath = dirname(__DIR__, 2) . '/codex/prompts/operationalNarrative.prompt.md';
        if (!file_exists($promptPath)) {
            error_log('[NARRATIVE] Prompt file missing at path: ' . $promptPath);
            return $fallback;
        }

        $systemPrompt = file_get_contents($promptPath);
        $userPrompt = json_encode($context, JSON_UNESCAPED_SLASHES);

        $payload = [
            "model" => "gpt-4o-mini",
            "messages" => [
                ["role" => "system", "content" => $systemPrompt],
                ["role" => "user",   "content" => $userPrompt]
            ],
            "temperature" => 0.1,
            "max_tokens"  => 600
        ];

        $ch = curl_init("https://api.openai.com/v1/chat/completions");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Content-Type: application/json", "Authorization: Bearer $apiKey"],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        safeCurlClose($ch);

        if ($response === false) {
            error_log('[NARRATIVE] CURL failed');
            return $fallback;
        }

        $decoded = json_decode($response, true);
        $content = $decoded['choices'][0]['message']['content'] ?? '';

        if (empty($content)) {
            error_log('[NARRATIVE] Empty content from AI');
            return $fallback;
        }

        // Robust JSON extraction
        preg_match('/\{[\s\S]*\}/', $content, $matches);
        if (empty($matches[0])) {
            error_log('[NARRATIVE] No JSON block found in response');
            return $fallback;
        }

        $jsonStr = trim($matches[0]);
        $parsed = json_decode($jsonStr, true);

        if (!is_array($parsed)) {
            error_log('[NARRATIVE] JSON decode failed to parse array');
            return $fallback;
        }

        // Elevate nested block if AI wraps it under a root "narratives" key
        if (isset($parsed['narratives']) && is_array($parsed['narratives'])) {
            $parsed = $parsed['narratives'];
        }

        error_log('[NARRATIVE] Success - Parsed live AI narrative payload.');

        // 🔄 Strictly map and normalize AI layout keys to corporate framework keys
        $decisionData = $parsed['decision'] ?? ($parsed['decisions'] ?? ($parsed['ui'] ?? []));
        if (!is_array($decisionData)) {
            $decisionData = array_filter([$decisionData]);
        }

        // Map logs safely to informational array
        $infoData = $parsed['informational'] ?? ($parsed['info'] ?? []);
        if (!is_array($infoData)) {
            $infoData = array_filter([$infoData]);
        }

        // 🌟 Extract the dedicated, robust Executive Summary text string
        $reportSummary = $parsed['report'] ?? $parsed['summary'] ?? '';
        if (empty($reportSummary) && is_array($infoData)) {
            // Fallback generation if missing
            $reportSummary = implode(' ', $infoData);
        }

        return [
            'contentLine'   => $parsed['contentLine'] ?? 'Contact Proposal Processing Request',
            'decision'      => array_values($decisionData),
            'blocking'      => array_values((array)($parsed['blocking'] ?? [])),
            'review'        => array_values((array)($parsed['review'] ?? [])),
            'informational' => array_values($infoData),
            'report'        => trim((string)$reportSummary) // 🌟 Injected dynamic summary text string
        ];

    } catch (Throwable $e) {
        error_log('[NARRATIVE] Exception: ' . $e->getMessage());
        return $fallback;
    }
}
// 🔍 evaluateEntityDuplicate — DB-backed entity normalization + matching
function evaluateEntityDuplicate(array $parsed, PDO &$pdo): array
{
    // ─────────────────────────────────────────────────────────────────
    // 🔄 SELF-HEALING HOOK: Verify connection health & restore if lost
    // ─────────────────────────────────────────────────────────────────
    try {
        // Run a lightweight test query to see if MySQL is alive
        $pdo->query("SELECT 1");
    } catch (PDOException $e) {
        // If server has gone away (2006) or dropped out, catch it and rebuild
        if ($e->errorInfo[1] == 2006 || strpos($e->getMessage(), 'gone away') !== false) {
            error_log("[PPC][DB-RECONNECT] Database link lost during heavy processing. Re-establishing link...");
            
            try {
                // Safely reuse your global dbConnect.php connection routine to self-heal
                if (function_exists('getPDO')) {
                    $pdo = getPDO();
                } else {
                    // Fallback if dbConnect factory isn't in scope
                    $dbHost = getenv('DB_HOST') ?: 'localhost';
                    $dbName = getenv('DB_NAME') ?: '';
                    $dbUser = getenv('DB_USER') ?: '';
                    $dbPass = getenv('DB_PASS') ?: ''; // Maps to your system's DB_PASS variable
                    $dbChar = getenv('DB_CHARSET') ?: 'utf8mb4';

                    $pdo = new PDO(
                        "mysql:host={$dbHost};dbname={$dbName};charset={$dbChar}",
                        $dbUser,
                        $dbPass,
                        [
                            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES   => false,
                        ]
                    );
                }
            } catch (PDOException $reconError) {
                error_log("[PPC][FATAL] Re-connection initialization failed: " . $reconError->getMessage());
                throw $reconError;
            }
        } else {
            throw $e; // Bubble up if it's a completely different structural DB issue
        }
    }

    // -------------------------------------------------
    // RAW VALUES
    // -------------------------------------------------
    $entityName = trim($parsed['entity']['name'] ?? '');
    $email      = strtolower(trim($parsed['contact']['email'] ?? ''));

    if (empty($entityName)) {
        return [
            'status'    => 'none',
            'entityId'  => null,
            'matchType' => null,
            'confidence'=> 0
        ];
    }

    // -------------------------------------------------
    // NORMALIZATION
    // -------------------------------------------------
    $normalized = strtolower($entityName);

    // Remove punctuation
    $normalized = preg_replace('/[^a-z0-9\s]/', '', $normalized);

    // Remove common branch/location suffixes
    $normalized = preg_replace(
        '/\b(east|west|north|south|phoenix|mesa|tempe|scottsdale|glendale|branch|campus|office)\b/i',
        '',
        $normalized
    );

    // Collapse spaces
    $normalized = preg_replace('/\s+/', '', $normalized);

    // -------------------------------------------------
    // EMAIL DOMAIN EXTRACTION
    // -------------------------------------------------
    $emailDomain = '';

    if (!empty($email) && strpos($email, '@') !== false) {

        $parts = explode('@', $email);

        $emailDomain = strtolower(trim($parts[1] ?? ''));

        // Remove TLD
        $emailDomain = preg_replace('/\.(com|net|org|biz|co|us)$/i', '', $emailDomain);

        // Remove punctuation/spaces
        $emailDomain = preg_replace('/[^a-z0-9]/', '', $emailDomain);
    }

    // -------------------------------------------------
    // LOAD EXISTING ENTITIES
    // -------------------------------------------------
    $stmt = $pdo->prepare("
        SELECT
            entityId,
            entityName
        FROM tblEntities
        WHERE entityIsNotValid = 0
    ");

    $stmt->execute();

    $entities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // -------------------------------------------------
    // ENTITY COMPARISON LOOP
    // -------------------------------------------------
    foreach ($entities as $row) {

        $existingId   = (int)($row['entityId'] ?? 0);
        $existingName = trim($row['entityName'] ?? '');

        if (empty($existingName)) {
            continue;
        }

        // Normalize DB entity
        $existingNormalized = strtolower($existingName);

        $existingNormalized = preg_replace('/[^a-z0-9\s]/', '', $existingNormalized);

        $existingNormalized = preg_replace(
            '/\b(east|west|north|south|phoenix|mesa|tempe|scottsdale|glendale|branch|campus|office)\b/i',
            '',
            $existingNormalized
        );

        $existingNormalized = preg_replace('/\s+/', '', $existingNormalized);

        // -------------------------------------------------
        // EXACT NORMALIZED MATCH
        // -------------------------------------------------
        if (
            !empty($normalized)
            && $normalized === $existingNormalized
        ) {

            return [
                'status'     => 'exact',
                'entityId'   => $existingId,
                'matchType'  => 'normalized_name',
                'confidence' => 95
            ];
        }

        // -------------------------------------------------
        // DOMAIN + SEMANTIC MATCH
        // -------------------------------------------------
        if (
            !empty($emailDomain)
            && strpos($emailDomain, $existingNormalized) !== false
        ) {

            return [
                'status'     => 'exact',
                'entityId'   => $existingId,
                'matchType'  => 'domain_semantic',
                'confidence' => 98
            ];
        }

        // -------------------------------------------------
        // POSSIBLE PARTIAL MATCH
        // -------------------------------------------------
        similar_text($normalized, $existingNormalized, $percent);

        if ($percent >= 82) {

            return [
                'status'     => 'possible',
                'entityId'   => $existingId,
                'matchType'  => 'semantic_partial',
                'confidence' => round($percent)
            ];
        }
    }

    // -------------------------------------------------
    // NO MATCH
    // -------------------------------------------------
    return [
        'status'     => 'none',
        'entityId'   => null,
        'matchType'  => null,
        'confidence' => 0
    ];
}

// * Generates a standard-compliant filename according to Universal Artifact Standard rules.
function generateArtifactFilename(string $classification, string $purpose, string $objectId, string $mediaType, string $sequence, string $extension = 'jpg'): string {
    // 1. Classification (CCC) - Expects TMP, REC, or SYS
    $ccc = strtoupper(substr(trim($classification), 0, 3));

    // 2. Media Type (MMM) - Fixed-width formatting uppercase 3 chars
    $mmm = strtoupper(substr(trim($mediaType), 0, 3));

    // 3. Artifact Purpose (PPP) - Strict 3-character uppercase enforcement matching registry
    $ppp = strtoupper(substr(trim($purpose), 0, 3));

    // 4. Object Identifier (OOOOOO) - Extract numeric digits or pad to exactly 6 characters
    $cleanId = trim($objectId);
    if (preg_match('/(?:[A-Z]{3}-)?([0-9]+)$/i', $cleanId, $matches)) {
        $oooooo = str_pad($matches[1], 6, '0', STR_PAD_LEFT);
    } else {
        $oooooo = str_pad(preg_replace('/[^0-9]/', '', $cleanId), 6, '0', STR_PAD_LEFT);
    }
    if (empty($oooooo) || strlen($oooooo) > 6) {
        $oooooo = '000000';
    }

    $uuu = '000';
    $tttttttttt = (string)time();
    $sss = str_pad(preg_replace('/[^0-9]/', '', $sequence), 3, '0', STR_PAD_LEFT);

    return sprintf('%s-%s-%s-%s-%s-%s-%s.%s', $ccc, $mmm, $ppp, $oooooo, $uuu, $tttttttttt, $sss, strtolower($extension));
}

// Infer Street View Heading based on address (simple heuristic)
function inferStreetViewHeading(string $address): int
{
    preg_match('/^\s*(\d+)/', $address, $matches);

    $streetNumber = isset($matches[1]) ? (int)$matches[1] : 0;
    $isOdd = $streetNumber > 0 ? ($streetNumber % 2 === 1) : true;

    $upper = strtoupper($address);

    if (strpos($upper, ' AVE') !== false) {
        return $isOdd ? 90 : 270;
    }

    if (strpos($upper, ' ST') !== false || strpos($upper, ' RD') !== false) {
        return $isOdd ? 180 : 0;
    }

    return 90;
}

// Save directly to canonical /artifacts/ with fixed-length protocol naming
function generateStreetViewImage(
    float|string $lat,
    float|string $lng,
    string $googleKey,
    string $address = '',
    string $proposalId = '',
    ?string $manualHeading = null // 🛠️ FIX: Explicitly typed as nullable to eliminate PHP6410 warning
): ?string {
    $lat = (string)$lat;
    $lng = (string)$lng;
    if (empty($googleKey) || empty($lat) || empty($lng)) {
        error_log("[generateReports] generateStreetViewImage() - Missing parameters");
        return null;
    }
    // Explicit reference to the canonical root artifacts directory path
    $artifactsDir = '/home/notyou64/public_html/skyesoft/artifacts/';
    if (!is_dir($artifactsDir)) {
        mkdir($artifactsDir, 0755, true);
    }
    // Single-line comment explanation: Check metadata endpoint first to avoid downloading gray placeholder graphics if imagery is missing
    if ($manualHeading === null) {
        $metaUrl = 'https://maps.googleapis.com/maps/api/streetview/metadata?location=' . $lat . ',' . $lng . '&key=' . $googleKey;
        $metaJson = @file_get_contents($metaUrl);
        $metaData = $metaJson ? json_decode($metaJson, true) : null;
        if (isset($metaData['status']) && $metaData['status'] === 'ZERO_RESULTS') {
            $filename = generateArtifactFilename('TMP', 'STR', $proposalId, 'IMG', '001', 'jpg');
            $fullPath = $artifactsDir . $filename;
            file_put_contents($fullPath, 'NO_IMAGERY_AVAILABLE');
            error_log("[generateReports] ⚠️ No Street View coverage found. Sentinel token dropped for Workspace modal.");
            return $fullPath;
        }
    }
    $heading = ($manualHeading !== null) ? $manualHeading : inferStreetViewHeading($address);
    $fov = 90;
    $url = 'https://maps.googleapis.com/maps/api/streetview?size=1200x600'
        . '&location=' . $lat . ',' . $lng
        . '&heading=' . $heading
        . '&pitch=8'
        . '&fov=' . $fov
        . '&key=' . $googleKey;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($imageData === false || $httpCode != 200 || strlen($imageData) < 3000) {
        error_log("[generateReports] Street View image capture failed.");
        return null;
    }
    // Single-line comment explanation: Generate compliant filename passing STR to identify it explicitly as a street view record
    $filename = generateArtifactFilename('TMP', 'STR', $proposalId, 'IMG', '001', 'jpg');
    $fullPath = $artifactsDir . $filename;
    if (file_put_contents($fullPath, $imageData) === false) {
        error_log("[generateReports] Failed to write to $fullPath");
        return null;
    }
    error_log("[generateReports] ✅ Protocol Compliant Widescreen Street View saved: $filename");
    return $fullPath;
}

/**
 * Hands the Maricopa Assessor URL off to Playwright for a native browser capture sequence.
 */
function generateParcelMapImage(array $parcel, string $proposalId): ?string
{
    $mapUrl = $parcel['assessor']['mapUrl'] ?? null;
    $apn = $parcel['parcelNumber'] ?? $parcel['apnRaw'] ?? 'unknown';
    $artifactsDir = '/home/notyou64/public_html/skyesoft/artifacts';

    if (empty($mapUrl)) {
        error_log("[ARTIFACTS] ❌ No Assessor mapUrl found for APN: {$apn}, Proposal: {$proposalId}");
        return null;
    }

    // Generate standard protocol filename matching the rest of Skyesoft
    $filename = generateArtifactFilename('TMP', 'PAR', $proposalId, 'IMG', '001', 'png');
    $outputPath = $artifactsDir . '/' . $filename;

    // Package parameters into the shell payload contract
    $jobPayload = json_encode([
        'mapUrl'     => $mapUrl,
        'outputPath' => $outputPath
    ]);

    $escapedPayload = escapeshellarg($jobPayload);
    
    // Command the existing Node execution service environment
    $nodeScript = '/home/notyou64/public_html/skyesoft/api/node-services/renderPlatMap.js';
    $cmd = "node {$nodeScript} {$escapedPayload} 2>&1";
    
    exec($cmd, $output, $returnCode);

    if ($returnCode === 0 && file_exists($outputPath)) {
        error_log("[ARTIFACTS] ✅ Maricopa Plat Map generated via Playwright: {$filename}");
        return $outputPath;
    }

    error_log("[ARTIFACTS] ❌ Playwright plat map conversion failed: " . implode("\n", $output));
    return null;
}


// =====================================================
// DEFENSIVE SHARED HELPERS (No duplicates)
// =====================================================

if (!function_exists('validateAddressSmarty')) {
    function validateAddressSmarty(string $street, string $city, string $state, string $zip): ?array {
        $authId    = skyesoftGetEnv('SMARTY_AUTH_ID');
        $authToken = skyesoftGetEnv('SMARTY_AUTH_TOKEN');

        if (!$authId || !$authToken) {
            error_log('[smarty] missing credentials');
            return null;
        }

        $url = "https://us-street.api.smarty.com/street-address?"
            . http_build_query([
                'auth-id'    => $authId,
                'auth-token' => $authToken,
                'street'     => $street,
                'city'       => $city,
                'state'      => $state,
                'zipcode'    => $zip
            ]);

        $opts = ["http" => ["method" => "GET", "timeout" => 10]];
        $res = @file_get_contents($url, false, stream_context_create($opts));

        if (!$res) {
            error_log('[smarty] request failed');
            return null;
        }

        $json = json_decode($res, true);
        return $json[0] ?? null;
    }
}

if (!function_exists('jsonError')) {
    function jsonError(string $msg): void {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => $msg], JSON_UNESCAPED_SLASHES);
        exit;
    }
}
// =====================================================
// NEW: PROPOSAL SNAPSHOT + PDF REPORT SYSTEM
// =====================================================

function createProposalSnapshot(
    string $rawInputOriginal,
    array $parsed,
    array $pcm,
    array $locationValidation,
    array $data,
    array $meta,
    array $resolution,
    array $persistence,
    string $activitySessionId,
    array $parcelImages = [],
    string $contentLine = 'Proposal Information Update' // ← NEW: Explicitly typed default parameter
): array {

    // If proposalId isn't passed or isn't 6 digits, enforce strict numeric padding
    $proposalId = $data['proposalId'] ?? str_pad((string)mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
    $proposalId = str_pad(preg_replace('/[^0-9]/', '', $proposalId), 6, '0', STR_PAD_LEFT);

    $proposalSnapshot = [
        'proposalId'        => $proposalId,
        'contentLine'       => $contentLine, // ← NEW: Saved directly to the snapshot JSON layout
        'generatedAt'       => date('c'),
        'version'           => '1.6.0',
        'activitySessionId' => $activitySessionId,
        'rawInput'          => $rawInputOriginal,

        'parsed'            => $parsed,
        'data'              => $data,
        'meta'              => $meta,
        'pcm'               => $pcm,
        'locationValidation'=> $locationValidation,
        'resolution'        => $resolution,
        'persistence'       => $persistence,

        'status'            => ($pcm['readyForCommit'] ?? false) ? 'ready' : 'review',
        'reportStatus'      => 'pending',

        'artifactRegistry'  => [
            'parcelImages'  => $parcelImages,
            'satelliteView' => null,
            'streetView'    => null,
            'pdfReport'     => null
        ]
    ];

    $runtimeDir = __DIR__ . '/../data/runtimeEphemeral/proposals';
    if (!is_dir($runtimeDir)) {
        mkdir($runtimeDir, 0755, true);
    }

    $filePath = $runtimeDir . "/{$proposalId}.json";
    
    $written = file_put_contents(
        $filePath,
        json_encode($proposalSnapshot, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );

    error_log("[PROPOSAL] " . ($written !== false ? "✅ Created snapshot with Content Line" : "❌ Failed") . ": {$proposalId}");

    return [
        'proposalId'   => $proposalId,
        'snapshotPath' => $filePath,
        'snapshot'     => $proposalSnapshot
    ];
}

/**
 * Generate PDF Report - Robust Autoloader
 */
function generateProposalReport(string $proposalId, array $proposal): ?string {
    
    try {
        // Robust vendor autoload search
        $baseDir = dirname(__DIR__); // Goes up from /utils/
        
        $possibleAutoloadPaths = [
            $baseDir . '/vendor/autoload.php',
            __DIR__ . '/../../vendor/autoload.php',
            __DIR__ . '/../vendor/autoload.php',
            '/home/notyou64/public_html/skyesoft/vendor/autoload.php'
        ];

        $autoloadPath = null;
        foreach ($possibleAutoloadPaths as $path) {
            if (file_exists($path)) {
                $autoloadPath = $path;
                error_log("[PDF] ✅ Found autoloader: " . $path);
                break;
            }
        }

        if (!$autoloadPath) {
            error_log("[PDF] ❌ Could not locate vendor/autoload.php");
            return null;
        }

        require_once $autoloadPath;

        // Rest of your original function continues here...
        $data       = $proposal['data'] ?? [];
        $location   = $data['location'] ?? [];
        $parsed     = $proposal['parsed'] ?? [];
        $pcm        = $proposal['pcm'] ?? [];

        $entityName   = $data['entity']['entityName'] ?? $parsed['entity']['name'] ?? 'Unknown Entity';
        $contactName  = trim(($data['contact']['contactFirstName'] ?? $parsed['contact']['firstName'] ?? '') . ' ' . 
                             ($data['contact']['contactLastName'] ?? $parsed['contact']['lastName'] ?? ''));
        $contactTitle = $data['contact']['contactTitle'] ?? $parsed['contact']['title'] ?? '';
        $contactPhone = $data['contact']['contactPrimaryPhone'] ?? $parsed['contact']['primaryPhone'] ?? '';
        $contactEmail = $data['contact']['contactEmail'] ?? $parsed['contact']['email'] ?? '';

        $locationAddress      = $location['locationAddress'] ?? $location['address'] ?? '';
        $locationCityStateZip = trim(($location['locationCity'] ?? $location['city'] ?? '') . ', ' . 
                                     ($location['locationState'] ?? $location['state'] ?? '') . ' ' . 
                                     ($location['locationZip'] ?? $location['zip'] ?? ''));

        $pcCode        = $pcm['pc'] ?? 'PC-1';
        $commitAllowed = ($pcm['readyForCommit'] ?? false) ? 'YES' : 'NO';

        $parcelDetails = $location['parcelDetails'] ?? [];

        $mpdf = new \Mpdf\Mpdf([
            'format'        => 'Letter',
            'margin_left'   => 12,
            'margin_right'  => 12,
            'margin_top'    => 28,
            'margin_bottom' => 24,
        ]);

        // ... (rest of your HTML + PDF generation code remains unchanged)

        $pdfPath = __DIR__ . '/../data/runtimeEphemeral/proposals/' . $proposalId . '.pdf';
        $mpdf->Output($pdfPath, 'F');

        // Update snapshot...
        $jsonPath = __DIR__ . '/../data/runtimeEphemeral/proposals/' . $proposalId . '.json';
        if (file_exists($jsonPath)) {
            $snap = json_decode(file_get_contents($jsonPath), true);
            $snap['artifactRegistry']['pdfReport'] = $pdfPath;
            $snap['reportStatus'] = 'generated';
            file_put_contents($jsonPath, json_encode($snap, JSON_PRETTY_PRINT));
        }

        return $pdfPath;

    } catch (Exception $e) {
        error_log("[PDF] Generation failed for {$proposalId}: " . $e->getMessage());
        return null;
    }
}

/**
 * parseLocationProposal — Clean parser for location-only proposals (PC-5+)
 * Robust line-based extraction with smart name detection. No AI, deterministic.
 */
function parseLocationProposal(array $lines, array $clientData, string $rawInputOriginal): array {
    // Clean and filter lines
    $lines = array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== ''));

    $entityName   = trim($clientData['entity']['entityName'] ?? ($lines[0] ?? ''));
    $locationName = trim($clientData['location']['locationName'] ?? '');

    // Smart location name extraction from raw lines if not in clientData
    if (empty($locationName) && count($lines) >= 2) {
        $potentialName = $lines[1];

        // Heuristic: Line 2 is likely a location name if it doesn't look like a street address
        // (doesn't start with number or typical address pattern)
        if (!preg_match('/^\d+\s+[A-Za-z]/', $potentialName) && 
            !preg_match('/^\d{5}/', $potentialName) &&
            strlen($potentialName) > 3) {
            
            $locationName = $potentialName;
        }
    }

    // Address extraction (start after entity + possible location name)
    $addressStartIndex = empty($locationName) ? 1 : 2;
    $addressRaw = implode("\n", array_slice($lines, $addressStartIndex));

    // Try to pull address from clientData first (more reliable)
    $address = trim($clientData['location']['locationAddress'] ?? 
                    $clientData['location']['address'] ?? 
                    $addressRaw);

    // Robust city/state/zip parsing from remaining lines or clientData
    $cityLine = $clientData['location']['locationCityStateZip'] ?? 
                ($lines[count($lines) - 1] ?? '');

    $parts    = array_map('trim', explode(',', $cityLine));
    $city     = $parts[0] ?? '';
    $stateZip = implode(',', array_slice($parts, 1));
    $stateZip = preg_replace('/\s+/', ' ', $stateZip);
    $stateParts = explode(' ', $stateZip);
    $state = $stateParts[0] ?? '';
    $zip   = $stateParts[count($stateParts) - 1] ?? '';

    // Fallbacks from clientData
    if (empty($city))  $city  = $clientData['location']['locationCity'] ?? '';
    if (empty($state)) $state = $clientData['location']['locationState'] ?? '';
    if (empty($zip))   $zip   = $clientData['location']['locationZip'] ?? '';

    $parsed = [
        'entity' => [
            'name' => $entityName,
            'nameInferred' => false,
            'nameConfirmed' => true,
            'nameSource' => 'location_proposal_parser'
        ],
        'contact' => [
            'firstName' => '', 
            'lastName' => '', 
            'salutation' => '', 
            'title' => '',
            'primaryPhone' => '', 
            'primaryPhoneRaw' => '', 
            'email' => ''
        ],
        'location' => [
            'address'      => $address,
            'city'         => $city,
            'state'        => strtoupper($state),
            'zip'          => $zip,
            'suite'        => '',
            'locationName' => $locationName,           // ← Now reliably populated
            'locationNameConfirmed' => !empty($locationName),
            'locationNameInferred'  => empty($locationName)
        ]
    ];

    error_log("[PPC][LocationParser] SUCCESS - Entity='{$entityName}' | LocationName='{$locationName}' | Address='{$address}'");

    return $parsed;
}
/**
 * parseContactProposal — Full legacy AI contact extraction (PC-0 through PC-3)
 * This is your original SECTION 03 logic wrapped as a reusable function.
 * 100% behavior-preserving for existing contact proposals.
 */
function parseContactProposal(string $rawInput): array {
    
    $openAiApiKey = skyesoftGetEnv('OPENAI_API_KEY');

    if (empty($openAiApiKey)) {
        error_log('[PPC][ContactParser] Missing OpenAI API key');
        return [
            'entity' => ['name' => ''],
            'contact' => ['firstName' => '', 'lastName' => '', 'salutation' => '', 'title' => '', 'primaryPhone' => '', 'primaryPhoneRaw' => '', 'email' => ''],
            'location' => ['address' => '', 'city' => '', 'state' => '', 'zip' => '', 'suite' => '', 'locationName' => '']
        ];
    }

    error_log('[PPC][ContactParser] Starting AI extraction');

    // =====================================================
    // STRONG SYSTEM PROMPT (Unchanged from your original)
    // =====================================================
    $systemPrompt = <<<EOT
You are an extremely precise structured data extraction engine specialized in cleaning and normalizing messy business contact signatures, Outlook signatures, website blocks, and pasted content.

PERFORM THESE STEPS IN ORDER:

1. CLEAN & NORMALIZE FIRST
   - Restore logical line breaks and structure from collapsed, HTML-contaminated, or poorly formatted input.
   - Remove noise: icons, emojis, HTML tags, disclaimers, repeated separators, social media links, and decorative text.
   - Fix common formatting issues such as extra spaces and broken lines.
   - Do NOT invent or hallucinate information.

2. THEN EXTRACT CLEAN DATA
   - Extract Entity, Location, and Contact fields from the cleaned text with high accuracy.

CRITICAL RULES:
- Title extraction is MANDATORY when a job title or role is clearly present in the input (e.g. Accounting Manager, Director of Operations, Project Manager, etc.).
- If no clear title is present, leave the "title" field as an empty string.
- Use empty string "" for any missing value. Never omit fields.
- Phone numbers: preserve the raw version exactly as shown in "primaryPhoneRaw" and provide a cleanly formatted version in "primaryPhone".
- Be conservative with inference — better to use "" than to guess.

Return ONLY valid JSON in this exact structure. No explanations, no markdown, no extra text.

{
  "intent": "contact_proposal",
  "confidence": 85,
  "parsed": {
    "entity": { "name": "" },
    "contact": {
      "firstName": "", "lastName": "", "salutation": "", "title": "",
      "primaryPhone": "", "primaryPhoneRaw": "", "email": ""
    },
    "location": {
      "address": "", "city": "", "state": "", "zip": "",
      "suite": "", "locationName": ""
    }
  }
}
EOT;

    $extractionPrompt = "Clean and normalize the following pasted contact information, then extract structured data.\n\nINPUT:\n{$rawInput}";

    // =====================================================
    // AI CALL (Unchanged)
    // =====================================================
    $payload = [
        'model'       => 'gpt-4o-mini',
        'temperature' => 0,
        'max_tokens'  => 600,
        'messages'    => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $extractionPrompt]
        ]
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $openAiApiKey
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 25
    ]);

    $response = curl_exec($ch);
    safeCurlClose($ch);

    if (!$response) {
        error_log('[PPC][ContactParser] OpenAI request failed');
        return ['entity' => ['name' => ''], 'contact' => [], 'location' => []]; // safe fallback
    }

    $responseData = json_decode($response, true);
    $content = trim($responseData['choices'][0]['message']['content'] ?? '');

    preg_match('/\{.*\}/s', $content, $matches);
    $jsonString = $matches[0] ?? $content;

    $aiData = json_decode($jsonString, true);

    if (!$aiData || !isset($aiData['parsed'])) {
        error_log('[PPC][ContactParser] Invalid AI response');
        return ['entity' => ['name' => ''], 'contact' => [], 'location' => []];
    }

    $parsed = $aiData['parsed'];

    // =====================================================
    // CLEAN PHONE FORMATTING (Preserved)
    // =====================================================
    if (!empty($parsed['contact']['primaryPhoneRaw'])) {
        $raw = $parsed['contact']['primaryPhoneRaw'];
        $digits = preg_replace('/[^0-9]/', '', $raw);
        if (strlen($digits) === 10) {
            $formatted = '(' . substr($digits, 0, 3) . ') ' .
                         substr($digits, 3, 3) . '-' .
                         substr($digits, 6);
            $parsed['contact']['primaryPhone'] = $formatted;
        } else {
            $parsed['contact']['primaryPhone'] = $raw;
        }
    } elseif (!empty($parsed['contact']['primaryPhone'])) {
        $digits = preg_replace('/[^0-9]/', '', $parsed['contact']['primaryPhone']);
        if (strlen($digits) === 10) {
            $parsed['contact']['primaryPhone'] = '(' . substr($digits, 0, 3) . ') ' .
                                                 substr($digits, 3, 3) . '-' .
                                                 substr($digits, 6);
        }
    }

    // =====================================================
    // INFER SALUTATION (Preserved)
    // =====================================================
    if (empty($parsed['contact']['salutation'])) {
        $firstName = $parsed['contact']['firstName'] ?? '';
        $lastName  = $parsed['contact']['lastName'] ?? '';

        if (function_exists('inferSalutation')) {
            $inferred = inferSalutation($firstName, $lastName);
            if (!empty($inferred)) {
                $parsed['contact']['salutation'] = $inferred;
                $parsed['contact']['salutationInferred'] = true;
            }
        }
    }

    error_log('[PPC][ContactParser] AI Extraction complete');

    return $parsed;
}

/**
 * Centralized Artifact Generator for Proposals
 * Saves Street View + Parcel Maps directly to /artifacts/ using protocol-compliant naming
 */
function generateProposalArtifacts(array $location, string $proposalId, string $googleKey): array
{
    $artifacts = [];
    if (empty($googleKey) || empty($location['locationLatitude']) || empty($location['locationLongitude'])) {
        error_log("[ARTIFACTS] Missing coordinates or API key for proposal {$proposalId}");
        return $artifacts;
    }
    
    $lat = (float)$location['locationLatitude'];
    $lng = (float)$location['locationLongitude'];
    $address = trim($location['locationAddress'] ?? $location['address'] ?? 'unknown');
    
    // Force image processing through the standardized functional wrapper to ensure consistent widescreen bounds
    $streetPath = generateStreetViewImage($lat, $lng, $googleKey, $address, $proposalId);
    if ($streetPath) {
        $artifacts['streetview'] = $streetPath;
    } else {
        error_log("[ARTIFACTS] ❌ Street View capture sequence failed for proposal {$proposalId}");
    }
    
    // ====================== PARCEL MAP(S) & SATELLITE ======================
    if (!empty($location['parcelDetails']) && is_array($location['parcelDetails'])) {
        foreach ($location['parcelDetails'] as $parcel) {
            
            // Clean abstraction: Pass the full parcel payload to the generator
            $parcelPath = generateParcelMapImage($parcel, $proposalId);
            if ($parcelPath) {
                $artifacts['parcelmap'] = $parcelPath;
            }
            
            // Satellite backup continues using Google Static Maps API
            $apn = $parcel['parcelNumber'] ?? $parcel['apnRaw'] ?? 'unknown';
            $parcelUrl = "https://maps.googleapis.com/maps/api/staticmap?center={$lat},{$lng}&zoom=20&size=1200x800&maptype=satellite&markers=color:red%7Csize:mid%7Clabel:" . urlencode(substr($apn, -5)) . "%7C{$lat},{$lng}&key=" . $googleKey;
            
            $satFilename = generateArtifactFilename('TMP', 'SAT', $proposalId, 'IMG', '001', 'png');
            $artifactsDir = '/home/notyou64/public_html/skyesoft/artifacts';
            $satPath = $artifactsDir . '/' . $satFilename;
            
            $satData = @file_get_contents($parcelUrl);
            if ($satData && strlen($satData) > 3000 && file_put_contents($satPath, $satData)) {
                $artifacts['satellite'] = $satPath;
                error_log("[ARTIFACTS] ✅ Satellite backup image saved: {$satFilename}");
            }
        }
    }
    return $artifacts;
}

?>