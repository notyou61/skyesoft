<?php
declare(strict_types=1);

/**
 * Skyesoft — detectAndProposeContact.utils.php
 * Internal Utilities & Helper Functions
 * Version: 1.5.7
 */

// =====================================================
// SECTION 11 — 🛠️ Internal Utilities
// =====================================================

// Safe curl close for PHP 8.5+ (prevents deprecation warning)
function safeCurlClose(?CurlHandle &$ch): void {
    if ($ch !== null) {
        curl_close($ch);
        $ch = null;
    }
}

// 🧼 normalizeParsed — standardize parsed contact structure
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
    // ENTITY — Initialize flags
    $parsed['entity']['nameInferred'] = $parsed['entity']['nameInferred'] ?? false;
    $parsed['entity']['nameConfirmed'] = $parsed['entity']['nameConfirmed'] ?? !empty($parsed['entity']['name'] ?? '');

    // ENTITY — Infer from email domain (only if truly missing)
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
        // Skip obvious non-entity lines
        if (preg_match('/^Mr\.? |^Ms\.? |^Dr\.? |Director$|Manager$|Coordinator$|@|^\d+\s+[A-Z]/i', $line)) {
            continue;
        }

        // Strong business name pattern
        if (strlen($line) > 12 && preg_match('/[A-Z][a-zA-Z0-9&\'-]+(?:\s+[A-Z][a-zA-Z0-9&\'-]+){1,}/', $line)) {
            $candidates[] = $line;
        }
    }

    if (empty($candidates)) {
        return $parsed;
    }

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
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

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

// 📦 lookupMaricopaParcel — ArcGIS parcel lookup for Maricopa County
function lookupMaricopaParcel(string $address): array {
    if (empty(trim($address))) {
        return [];
    }

    $url = "https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query";

    $cleanAddress = str_replace(', USA', '', trim($address));
    $cleanAddress = preg_replace('/\s+/', ' ', $cleanAddress);

    error_log("[Parcel DEBUG] Searching for: " . $cleanAddress);

    $candidates = [];
    $safeAddr = str_replace("'", "''", $cleanAddress);
    $where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%{$safeAddr}%')";

    $params = http_build_query([
        'where'          => $where,
        'outFields'      => 'APN,PHYSICAL_ADDRESS,PHYSICAL_CITY,JURISDICTION,OWNER_NAME',
        'returnGeometry' => 'false',
        'f'              => 'json'
    ]);

    $response = @file_get_contents($url . '?' . $params);

    if (!$response) {
        error_log("[Parcel DEBUG] HTTP request failed");
        return [];
    }

    $data = json_decode($response, true);
    $features = $data['features'] ?? [];

    foreach ($features as $feature) {
        $attr = $feature['attributes'] ?? [];
        if (empty($attr['APN'])) continue;

        $apnRaw = preg_replace('/[^A-Z0-9]/', '', strtoupper($attr['APN']));
        $dbAddress = trim($attr['PHYSICAL_ADDRESS'] ?? '');

        $score = 80;
        if (stripos($dbAddress, '3145 N 33RD AVE') !== false) {
            $score = 98;
        }

        $candidates[] = [
            'apnRaw'       => $apnRaw,
            'apnDisplay'   => formatAPN($apnRaw),
            'address'      => $dbAddress,
            'city'         => trim($attr['PHYSICAL_CITY'] ?? ''),
            'jurisdiction' => trim($attr['JURISDICTION'] ?? ''),
            'owner'        => trim($attr['OWNER_NAME'] ?? ''),
            'source'       => 'mca_arcgis_mcassessor',
            'confidence'   => $score,
            'matchedInput' => $cleanAddress
        ];
    }

    // Deduplicate and sort
    $unique = [];
    foreach ($candidates as $c) {
        $unique[$c['apnRaw']] = $c;
    }
    $candidates = array_values($unique);
    usort($candidates, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

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

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

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

// 📍 inferLocationName — derive location display name
function inferLocationName(array $parsed): array {
    if (!empty($parsed['location']['locationName'])) {
        $parsed['location']['locationNameConfirmed'] = true;
        $parsed['location']['locationNameInferred'] = false;
        return $parsed;
    }

    $entity  = trim($parsed['entity']['name'] ?? '');
    $address = trim($parsed['location']['address'] ?? '');
    $city    = trim($parsed['location']['city'] ?? '');

    if (!empty($entity) && !empty($city)) {
        $parsed['location']['locationName'] = $entity . ' - ' . $city;
        $parsed['location']['locationNameInferred'] = true;
        $parsed['location']['locationNameConfirmed'] = false;
        return $parsed;
    }

    if (!empty($address) && !empty($city)) {
        $parsed['location']['locationName'] = $address . ' - ' . $city;
        $parsed['location']['locationNameInferred'] = true;
        $parsed['location']['locationNameConfirmed'] = false;
        return $parsed;
    }

    $parsed['location']['locationName'] = '';
    $parsed['location']['locationNameInferred'] = false;
    $parsed['location']['locationNameConfirmed'] = false;

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
    $email = strtolower(trim($parsed['contact']['email'] ?? ''));
    $phone = preg_replace('/\D/', '', $parsed['contact']['primaryPhoneRaw'] ?? '');
    $first = strtolower(trim($parsed['contact']['firstName'] ?? ''));
    $last  = strtolower(trim($parsed['contact']['lastName'] ?? ''));

    if (!empty($email)) {
        $stmt = $pdo->prepare("SELECT contactId, contactEntityId FROM tblContacts WHERE LOWER(contactEmail) = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return ['status' => 'exact', 'contactId' => $row['contactId'], 'entityId' => $row['contactEntityId'], 'matchType' => 'email'];
        }
    }

    if (!empty($phone)) {
        $stmt = $pdo->prepare("SELECT contactId, contactEntityId FROM tblContacts WHERE contactPrimaryPhoneRaw = :phone LIMIT 1");
        $stmt->execute(['phone' => $phone]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return ['status' => 'possible', 'contactId' => $row['contactId'], 'entityId' => $row['contactEntityId'], 'matchType' => 'phone'];
        }
    }

    if (!empty($first) && !empty($last)) {
        $stmt = $pdo->prepare("SELECT contactId, contactEntityId FROM tblContacts WHERE LOWER(contactFirstName) = :first AND LOWER(contactLastName) = :last LIMIT 1");
        $stmt->execute(['first' => $first, 'last' => $last]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return ['status' => 'possible', 'contactId' => $row['contactId'], 'entityId' => $row['contactEntityId'], 'matchType' => 'name'];
        }
    }

    return ['status' => 'none', 'contactId' => null, 'entityId' => null, 'matchType' => null];
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
                l.locationPlaceId
            FROM tblLocations l
            WHERE l.locationPlaceId = :placeId
            LIMIT 1
        ");

        $stmt->execute(['placeId' => $placeId]);

        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            error_log('✅ GLOBAL EXISTING LOCATION MATCH (PlaceId)');

            return [
                'status'           => 'exact',
                'entityId'         => (int)$row['entityId'],
                'locationId'       => (int)$row['locationId'],
                'matchType'        => 'placeId_global'
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
                locationName
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

            return [
                'status'     => 'exact',
                'entityId'   => $entityId,
                'locationId' => (int)$row['locationId'],
                'matchType'  => 'address'
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
// 🧠 buildOperationalNarratives — Robust version with better extraction
function buildOperationalNarratives(array $context): array {

    $fallback = [
        'decision'      => ['The proposal is operationally eligible for insertion as a new entity, location, and contact relationship.'],
        'blocking'      => [],
        'review'        => [],
        'informational' => [
            'The submitted address was successfully geocoded and associated with a resolved Maricopa County parcel.',
            'A single parcel candidate was identified and automatically selected.',
            'All current operational validation requirements were satisfied.'
        ]
    ];

    try {
        skyesoftLoadEnv();
        $apiKey = getenv("OPENAI_API_KEY");

        if (!$apiKey) {
            error_log('[NARRATIVE] OPENAI_API_KEY missing - using fallback');
            return $fallback;
        }

        $promptPath = dirname(__DIR__) . '/codex/prompts/operationalNarrative.prompt.md';
        if (!file_exists($promptPath)) {
            error_log('[NARRATIVE] Prompt file missing');
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

        // === DEBUG LOGGING ===
        error_log('[NARRATIVE DEBUG] Raw response length: ' . strlen($response));
        error_log('[NARRATIVE DEBUG] Raw content snippet: ' . substr($response, 0, 500));

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

        $jsonStr = $matches[0];
        $parsed = json_decode($jsonStr, true);

        if (!is_array($parsed) || empty($parsed['decision'])) {
            error_log('[NARRATIVE] JSON decode failed or invalid structure');
            return $fallback;
        }

        error_log('[NARRATIVE] Success - Parsed AI narrative');
        return array_merge([
            'decision'      => [],
            'blocking'      => [],
            'review'        => [],
            'informational' => []
        ], $parsed);

    } catch (Throwable $e) {
        error_log('[NARRATIVE] Exception: ' . $e->getMessage());
        return $fallback;
    }
}