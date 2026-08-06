<?php
declare(strict_types=1);
// =============================================
// Skyesoft — locationZoningReport.php
// Dynamic Location Zoning & Sign Code Report
// Version: 3.2.0 (Phoenix Special Designations & Auditable Zoning Summary)
// =============================================

// Force PHP error logging to local folder (skyesoft/reports/php-error.log)
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');
error_reporting(E_ALL);

error_log('[locationZoningReport] --- REPORT SCRIPT STARTED ---');

// -------------------------------------------------------------------------
// 1. Core Inclusions & Session Bootstrap
// -------------------------------------------------------------------------
require_once __DIR__ . '/../api/sessionBootstrap.php';
require_once __DIR__ . '/../api/utils/envLoader.php';
require_once __DIR__ . '/../api/dbConnect.php';
require_once __DIR__ . '/../vendor/autoload.php'; // Composer / mPDF Autoloader

skyesoftLoadEnv();

$db = getPDO();

// Validate locationId parameter
$locationId = filter_input(INPUT_GET, 'locationId', FILTER_VALIDATE_INT);
if (!$locationId) {
    http_response_code(400);
    die('Invalid or missing locationId parameter.');
}

$forceRefresh = filter_input(INPUT_GET, 'refresh', FILTER_VALIDATE_BOOLEAN) ?? false;
$jsonReviewMode = filter_input(INPUT_GET, 'json', FILTER_VALIDATE_BOOLEAN) ?? false;

// -------------------------------------------------------------------------
// 2. Database Retrieval (Skyesoft Schema)
// -------------------------------------------------------------------------
try {
    $stmt = $db->prepare("
        SELECT
            l.locationId,
            l.locationEntityId,
            l.locationName,
            l.locationPlaceId,
            l.locationAddress,
            l.locationAddressSuite,
            l.locationCity,
            l.locationState,
            l.locationZip,
            l.locationParcelNumber,
            l.locationParcelNumberRaw,
            l.locationJurisdiction,
            l.locationCounty,
            l.locationIsBilling,
            l.locationIsNotValid,

            p.*,
            l.locationId AS locationId,

            e.entityId,
            e.entityName,
            e.entityType,
            e.entityStatus

        FROM tblLocations l
        LEFT JOIN tblEntities e
            ON l.locationEntityId = e.entityId
        LEFT JOIN tblLocationParcelDetails p
            ON p.locationId = l.locationId
        WHERE l.locationId = :locationId
        LIMIT 1;
    ");
    $stmt->execute([':locationId' => $locationId]);
    $loc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$loc) {
        http_response_code(404);
        die('Location record not found.');
    }

    // Load all resolved street frontages for the parcel
    $frontageStmt = $db->prepare("
        SELECT
            frontageId,
            streetName,
            frontageLengthFeet,
            frontageMethod,
            streetClassCode,
            streetClassification,
            roadTier AS roadwayTier,
            verificationStatus,
            confidence,
            requiresManualReview,
            parcelSource,
            streetSource AS streetGeometrySource,
            verifiedAt
        FROM tblLocationFrontages
        WHERE parcelDetailsId = :parcelDetailsId
        ORDER BY
            requiresManualReview ASC,
            streetName ASC;
    ");
    $frontageStmt->execute([
        ':parcelDetailsId' => $loc['parcelDetailsId']
    ]);
    $streetFrontages = $frontageStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('Database query failed in locationZoningReport.php: ' . $e->getMessage());
    die('Database connection error.');
}

// -------------------------------------------------------------------------
// 3. AI Sign Code Analysis Pipeline & Helper Functions
// -------------------------------------------------------------------------

/**
 * Execute an OpenAI chat completion call using application/json formatting.
 */
function callOpenAIForSignCode(string $systemPrompt, string $userPrompt): array
{
    $apiKey = skyesoftGetEnv('OPENAI_API_KEY');

    error_log(
        '[locationZoningReport] OPENAI_API_KEY: ' .
        (($apiKey !== null && trim($apiKey) !== '') ? 'AVAILABLE' : 'MISSING')
    );

    if ($apiKey === null || trim($apiKey) === '') {
        throw new RuntimeException('OPENAI_API_KEY was not loaded.');
    }

    $payload = [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ],
        'response_format' => ['type' => 'json_object'],
        'temperature' => 0.1,
        'max_tokens' => 5000
    ];

    $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($encodedPayload === false) {
        throw new RuntimeException('Unable to encode the OpenAI request payload.');
    }

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => $encodedPayload,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 90
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($response === false) {
        $statusLine = 'No HTTP response';
        error_log('[locationZoningReport] OpenAI HTTP status: ' . $statusLine);
        throw new RuntimeException('OpenAI cURL Error: ' . ($error ?: 'Unknown network/cURL failure'));
    }

    $headers = substr($response, 0, $headerSize);
    $rawResponse = substr($response, $headerSize);

    $headerLines = explode("\r\n", trim($headers));
    $statusLine = $headerLines[0] ?? ('HTTP/1.1 ' . $httpCode);

    error_log('[locationZoningReport] OpenAI HTTP status: ' . $statusLine);

    if ($httpCode !== 200) {
        error_log('[locationZoningReport] OpenAI error response: ' . substr($rawResponse, 0, 2000));
        throw new RuntimeException('OpenAI returned ' . $statusLine);
    }

    $responseData = json_decode($rawResponse, true);
    $finishReason = $responseData['choices'][0]['finish_reason'] ?? null;
    if ($finishReason === 'length') {
        throw new RuntimeException('OpenAI response was truncated before the JSON analysis was complete.');
    }

    $content = $responseData['choices'][0]['message']['content'] ?? null;
    if (!$content) {
        throw new RuntimeException('OpenAI response missing message content.');
    }

    $decodedJson = json_decode($content, true);
    if (!is_array($decodedJson)) {
        throw new RuntimeException('Failed to parse OpenAI JSON content response.');
    }

    return $decodedJson;
}

/**
 * Confirm that an AI result uses the current report schema.
 */
function isUsableSignCodeAnalysis(array $analysis): bool
{
    return isset(
        $analysis['analysisStatus'],
        $analysis['ordinance'],
        $analysis['attachedSigns'],
        $analysis['detachedSigns'],
        $analysis['findings'],
        $analysis['recommendedNextSteps']
    )
        && is_array($analysis['ordinance'])
        && is_array($analysis['attachedSigns'])
        && is_array($analysis['detachedSigns'])
        && is_array($analysis['findings'])
        && is_array($analysis['recommendedNextSteps']);
}

/**
 * Find specialDesignations inside the parcel-details row or a stored JSON payload.
 */
function extractSpecialDesignations(array $parcelRecord): array
{
    $findPayload = static function (mixed $value) use (&$findPayload): ?array {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
                return null;
            }

            $decoded = json_decode($trimmed, true);
            if (!is_array($decoded)) {
                return null;
            }

            $value = $decoded;
        }

        if (!is_array($value)) {
            return null;
        }

        if (isset($value['specialDesignations']) && is_array($value['specialDesignations'])) {
            return $value['specialDesignations'];
        }

        foreach ($value as $child) {
            $found = $findPayload($child);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    };

    return $findPayload($parcelRecord) ?? [];
}

/**
 * Supply stable report states when special-designation research is not stored yet.
 */
function normalizeSpecialDesignations(array $specialDesignations): array
{
    $overlays = is_array($specialDesignations['zoningOverlays'] ?? null)
        ? $specialDesignations['zoningOverlays']
        : [];
    $csp = is_array($specialDesignations['comprehensiveSignPlan'] ?? null)
        ? $specialDesignations['comprehensiveSignPlan']
        : [];

    return [
        'zoningOverlays' => array_replace([
            'status' => 'notDetermined',
            'matches' => [],
            'source' => null,
            'checkedAt' => null
        ], $overlays),
        'comprehensiveSignPlan' => array_replace([
            'status' => 'notDetermined',
            'appliesToParcel' => null,
            'cases' => [],
            'source' => null,
            'checkedAt' => null,
            'manualReviewRequired' => true
        ], $csp)
    ];
}

/**
 * Build the exact location-data object supplied to the sign-code analyst.
 */
function buildLocationDataObject(array $loc, array $streetFrontages): array
{
    $specialDesignations = normalizeSpecialDesignations(extractSpecialDesignations($loc));

    return [
        'locationId' => (int)$loc['locationId'],
        'locationName' => $loc['locationName'],
        'address' => $loc['locationAddress'],
        'city' => $loc['locationCity'],
        'state' => $loc['locationState'],
        'zip' => $loc['locationZip'],
        'jurisdiction' => $loc['locationJurisdiction'],
        'county' => $loc['locationCounty'],
        'parcelNumber' => $loc['locationParcelNumberRaw'] ?: $loc['locationParcelNumber'],
        'zoningCode' => $loc['zoningCode'],
        'zoningDescription' => $loc['zoningDescription'],
        'zoningSource' => $loc['zoningSource'],
        'zoningVerifiedAt' => $loc['zoningVerifiedAt'],
        'lotSize' => $loc['lotSize'],
        'specialDesignations' => $specialDesignations,
        'streetFrontages' => array_map(
            static function (array $frontage): array {
                return [
                    'frontageId' => (int)$frontage['frontageId'],
                    'streetName' => $frontage['streetName'],
                    'frontageLengthFeet' => $frontage['frontageLengthFeet'] !== null
                        ? (float)$frontage['frontageLengthFeet']
                        : null,
                    'frontageMethod' => $frontage['frontageMethod'],
                    'streetClassCode' => $frontage['streetClassCode'],
                    'streetClassification' => $frontage['streetClassification'],
                    'roadwayTier' => $frontage['roadwayTier'],
                    'verificationStatus' => $frontage['verificationStatus'],
                    'confidence' => $frontage['confidence'] !== null
                        ? (float)$frontage['confidence']
                        : null,
                    'requiresManualReview' => (bool)$frontage['requiresManualReview'],
                    'parcelSource' => $frontage['parcelSource'],
                    'streetGeometrySource' => $frontage['streetGeometrySource'],
                    'verifiedAt' => $frontage['verifiedAt']
                ];
            },
            $streetFrontages
        ),
        'streetFrontageNotice' => 'Parcel frontage is not tenant, suite, storefront, or building-elevation width.'
    ];
}

/**
 * Convert a stored timestamp to Unix seconds while preserving unknown values.
 */
function toUnixTimestamp(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_int($value) || (is_string($value) && ctype_digit($value))) {
        return (int)$value;
    }

    $timestamp = strtotime((string)$value);

    return $timestamp !== false ? $timestamp : null;
}

/**
 * Normalize timestamp-shaped fields in the complete review payload.
 */
function normalizeReportTimestamps(mixed $value, ?string $key = null): mixed
{
    if (is_array($value)) {
        foreach ($value as $childKey => $childValue) {
            $value[$childKey] = normalizeReportTimestamps($childValue, (string)$childKey);
        }

        return $value;
    }

    $isTimestampField = $key !== null && (
        preg_match('/(?:At|Timestamp)$/i', $key) === 1
        || in_array(strtolower($key), ['timestamp', 'created', 'updated', 'verified'], true)
    );

    return $isTimestampField ? toUnixTimestamp($value) : $value;
}

/**
 * Perform sign code analysis using jurisdiction local filesystem assets and cached database entries.
 */
function getOrRunSignCodeAnalysis(
    PDO $db,
    array $loc,
    array $streetFrontages,
    bool $forceRefresh = false
): array
{
    $cacheDir = __DIR__ . '/../data/cache/signReports';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $cacheFile = $cacheDir . '/location_' . $loc['locationId'] . '.json';

    $jurisdictionSlug = strtolower(trim((string)($loc['locationJurisdiction'] ?? '')));
    $jurisdictionSlug = preg_replace('/[^a-z0-9]+/', '-', $jurisdictionSlug);
    $jurisdictionSlug = trim((string)$jurisdictionSlug, '-');

    if ($jurisdictionSlug === '') {
        throw new RuntimeException('The location jurisdiction must be verified before sign-code analysis.');
    }

    $jurisdictionDir = __DIR__ . '/../data/authoritative/jurisdictions/' . $jurisdictionSlug;

    if (!is_dir($jurisdictionDir)) {
        throw new RuntimeException('No authoritative sign-code package exists for jurisdiction: ' . $jurisdictionSlug);
    }

    $signCodeJsonPath = $jurisdictionDir . '/signCode.json';
    $promptPath = __DIR__ . '/../codex/prompts/signCodeReportAnalysis.prompt.md';

    if (!file_exists($signCodeJsonPath)) {
        throw new RuntimeException('Missing required signCode.json at ' . $signCodeJsonPath);
    }
    if (!file_exists($promptPath)) {
        throw new RuntimeException('Missing required system prompt at ' . $promptPath);
    }

    $signCodeJson = file_get_contents($signCodeJsonPath);
    $promptTemplate = file_get_contents($promptPath);

    if ($signCodeJson === false || json_decode($signCodeJson, true) === null) {
        throw new RuntimeException('The jurisdiction signCode.json is missing or invalid.');
    }
    if ($promptTemplate === false || trim($promptTemplate) === '') {
        throw new RuntimeException('The sign-code analysis prompt is empty or unreadable.');
    }

    $cacheVersion = hash('sha256', implode('|', [
        (string)$loc['locationId'],
        (string)($loc['zoningCode'] ?? ''),
        (string)($loc['zoningVerifiedAt'] ?? ''),
        hash('sha256', (string)json_encode($streetFrontages, JSON_UNESCAPED_SLASHES)),
        hash('sha256', $signCodeJson),
        hash('sha256', $promptTemplate)
    ]));

    if (!$forceRefresh && file_exists($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (
            is_array($cached)
            && ($cached['_cacheVersion'] ?? '') === $cacheVersion
            && isUsableSignCodeAnalysis($cached)
        ) {
            return $cached;
        }
    }

    $locationDataJson = json_encode(
        buildLocationDataObject($loc, $streetFrontages),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    if ($locationDataJson === false) {
        throw new RuntimeException('Unable to encode the location data JSON.');
    }

    $projectSignDataJson = json_encode([
        'existingSigns' => [],
        'proposedSigns' => []
    ], JSON_PRETTY_PRINT);

    $codexContextJson = json_encode([
        'reportType' => 'Location Zoning & Sign Code Report',
        'targetClient' => $loc['entityName'] ?? 'Internal Review'
    ], JSON_PRETTY_PRINT);

    $userPrompt = str_replace(
        ['{{LOCATION_DATA_JSON}}', '{{SIGN_CODE_JSON}}', '{{PROJECT_SIGN_DATA_JSON}}', '{{CODEX_CONTEXT_JSON}}'],
        [$locationDataJson, $signCodeJson, $projectSignDataJson, $codexContextJson],
        $promptTemplate
    );

    if (preg_match('/\{\{[A-Z0-9_]+\}\}/', $userPrompt, $unresolved)) {
        throw new RuntimeException('Unresolved prompt placeholder: ' . $unresolved[0]);
    }

    $systemPrompt = "You are the Skyesoft Sign Code Report Analyst. Perform regulatory sign code analysis and return strict JSON adhering to prompt schema.";

    $analysisResult = callOpenAIForSignCode($systemPrompt, $userPrompt);

    if (!isUsableSignCodeAnalysis($analysisResult)) {
        throw new RuntimeException('OpenAI returned JSON that does not match the current sign-code report schema.');
    }

    $analysisResult['_cacheVersion'] = $cacheVersion;

    $cacheJson = json_encode($analysisResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($cacheJson === false || file_put_contents($cacheFile, $cacheJson, LOCK_EX) === false) {
        throw new RuntimeException('The completed sign-code analysis could not be cached.');
    }

    return $analysisResult;
}

// Execute analysis with fallback handling
$signCodeAnalysis = [];
$analysisError = null;
try {
    $signCodeAnalysis = getOrRunSignCodeAnalysis($db, $loc, $streetFrontages, $forceRefresh);
} catch (Throwable $e) {
    error_log(
        '[locationZoningReport] Sign-code analysis failed | ' .
        get_class($e) . ' | ' .
        $e->getMessage() . ' | File: ' .
        $e->getFile() . ' | Line: ' .
        $e->getLine()
    );

    $analysisError = $e->getMessage();
}

// -------------------------------------------------------------------------
// 4. Data Formatting & Visual Helpers
// -------------------------------------------------------------------------

function displayValue(mixed $value, string $fallback = 'Verification required'): string
{
    $trimmed = trim((string)($value ?? ''));

    return $trimmed !== ''
        ? htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8')
        : '<span class="unverified">' . htmlspecialchars($fallback, ENT_QUOTES, 'UTF-8') . '</span>';
}

function renderCitation(?string $citationText): string
{
    if (empty($citationText)) {
        return '';
    }
    return '<div class="citation-subtext">Authority: ' . htmlspecialchars($citationText, ENT_QUOTES, 'UTF-8') . '</div>';
}

function buildReportSectionHeading(string $title, string $iconFile): string
{
    $iconPath = __DIR__ . '/../assets/images/icons/' . basename($iconFile);
    $iconHtml = file_exists($iconPath)
        ? '<img src="' . htmlspecialchars($iconPath, ENT_QUOTES, 'UTF-8') . '" class="section-icon" alt="" />'
        : '';

    return '<div class="section-heading">'
        . $iconHtml
        . '<span class="section-heading-title">'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        . '</span></div>';
}

/**
 * Resolve and load the structured sign-code package used by the report.
 */
function loadReportSignCode(array $loc): array
{
    $jurisdictionSlug = strtolower(trim((string)($loc['locationJurisdiction'] ?? '')));
    $jurisdictionSlug = preg_replace('/[^a-z0-9]+/', '-', $jurisdictionSlug);
    $jurisdictionSlug = trim((string)$jurisdictionSlug, '-');

    if ($jurisdictionSlug === '') {
        return [];
    }

    $jurisdictionDir = __DIR__ . '/../data/authoritative/jurisdictions/' . $jurisdictionSlug;

    if (!is_dir($jurisdictionDir)) {
        return [];
    }

    $signCodePath = $jurisdictionDir . '/signCode.json';
    $signCodeJson = file_exists($signCodePath) ? file_get_contents($signCodePath) : false;
    $signCodeData = $signCodeJson !== false ? json_decode($signCodeJson, true) : null;

    return is_array($signCodeData) ? $signCodeData : [];
}

/**
 * Format a dimensional value without inventing a value when none exists.
 */
function formatDimension(mixed $value, string $unit): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    return htmlspecialchars(
        (string)$value . ' ' . $unit,
        ENT_QUOTES,
        'UTF-8'
    );
}

/**
 * Format a Unix or database timestamp for client-facing source metadata.
 */
function formatCheckedAt(mixed $value): ?string
{
    $timestamp = toUnixTimestamp($value);

    return $timestamp !== null && $timestamp > 0
        ? date('F j, Y g:i A', $timestamp)
        : null;
}

/**
 * Build the visible Phoenix overlay determination without overstating the GIS result.
 */
function describeZoningOverlays(array $overlays): string
{
    $status = (string)($overlays['status'] ?? 'notDetermined');
    $matches = is_array($overlays['matches'] ?? null) ? $overlays['matches'] : [];

    if ($status === 'found' && $matches !== []) {
        $names = [];
        foreach ($matches as $match) {
            $name = trim((string)($match['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names !== []
            ? implode('; ', array_values(array_unique($names)))
            : 'Phoenix zoning overlay or special district identified';
    }

    if ($status === 'noneIdentified') {
        return 'No zoning overlay or special district identified in Phoenix GIS';
    }

    return 'Unable to determine—manual research required';
}

/**
 * Map the CSP review state to the approved report language.
 */
function describeComprehensiveSignPlan(array $csp): string
{
    $status = (string)($csp['status'] ?? 'notDetermined');
    $cases = is_array($csp['cases'] ?? null) ? $csp['cases'] : [];

    if ($status === 'confirmed') {
        $caseNumbers = [];
        $verifiedCaseNumber = trim((string)($csp['caseNumber'] ?? ''));
        if ($verifiedCaseNumber !== '') {
            $caseNumbers[] = $verifiedCaseNumber;
        }
        foreach ($cases as $case) {
            $caseNumber = trim((string)($case['caseNumber'] ?? ''));
            if ($caseNumber !== '') {
                $caseNumbers[] = $caseNumber;
            }
        }

        return 'CSP confirmed' . ($caseNumbers !== [] ? '—' . implode(', ', array_unique($caseNumbers)) : '');
    }

    if ($status === 'noneIdentified') {
        return 'No CSP candidate identified in Phoenix GIS';
    }

    if ($status === 'manualReviewRequired') {
        $caseNumbers = [];
        foreach ($cases as $case) {
            $caseNumber = trim((string)($case['caseNumber'] ?? ''));
            if ($caseNumber !== '') {
                $caseNumbers[] = $caseNumber;
            }
        }

        $candidateText = $caseNumbers !== [] ? ' (' . implode(', ', array_unique($caseNumbers)) . ')' : '';
        return 'Potential related zoning case(s)' . $candidateText . '—manual CSP review required';
    }

    return 'Unable to determine—manual research required';
}

// APN Fallback logic
$parcelNumber = $loc['locationParcelNumberRaw']
    ?: $loc['locationParcelNumber']
    ?: null;

// Address assembly
$streetLine = trim($loc['locationAddress'] ?? '');
if (!empty($loc['locationAddressSuite'])) {
    $streetLine .= ($streetLine !== '' ? ', ' : '') . trim($loc['locationAddressSuite']);
}

$cityStateZipParts = [];
if (!empty($loc['locationCity'])) {
    $cityStateZipParts[] = trim($loc['locationCity']);
}
if (!empty($loc['locationState'])) {
    $stateZip = trim($loc['locationState']);
    if (!empty($loc['locationZip'])) {
        $stateZip .= ' ' . trim($loc['locationZip']);
    }
    $cityStateZipParts[] = $stateZip;
} elseif (!empty($loc['locationZip'])) {
    $cityStateZipParts[] = trim($loc['locationZip']);
}

$cityStateZip = implode(', ', $cityStateZipParts);

if ($streetLine !== '' && $cityStateZip !== '') {
    $fullAddress = $streetLine . ', ' . $cityStateZip;
} else {
    $fullAddress = $streetLine !== '' ? $streetLine : $cityStateZip;
}

$verifiedAtFormatted = null;
if (!empty($loc['zoningVerifiedAt'])) {
    $verifiedTimestamp = is_numeric($loc['zoningVerifiedAt'])
        ? (int)$loc['zoningVerifiedAt']
        : strtotime((string)$loc['zoningVerifiedAt']);

    if ($verifiedTimestamp !== false && $verifiedTimestamp > 0) {
        $verifiedAtFormatted = date('F j, Y', $verifiedTimestamp);
    }
}

$formattedLotSize = null;
if (!empty($loc['lotSize'])) {
    $formattedLotSize = is_numeric($loc['lotSize']) ? number_format((float)$loc['lotSize']) . ' sq. ft.' : $loc['lotSize'];
}

$logoPath = __DIR__ . '/../assets/images/christyLogo.png';
$logoHtml = file_exists($logoPath)
    ? '<img src="' . htmlspecialchars($logoPath, ENT_QUOTES, 'UTF-8') . '" class="header-logo" alt="Christy Signs" />'
    : '<div style="font-size: 16px; font-weight: bold; color: #14377c;">Christy Signs</div>';

// Extract dynamic analysis structures
$attached = $signCodeAnalysis['attachedSigns'] ?? [];
$detached = $signCodeAnalysis['detachedSigns'] ?? [];
$signCodeData = loadReportSignCode($loc);
$specialDesignations = normalizeSpecialDesignations(extractSpecialDesignations($loc));
$zoningOverlays = $specialDesignations['zoningOverlays'];
$comprehensiveSignPlan = $specialDesignations['comprehensiveSignPlan'];

$commercialIndustrial = $signCodeData['identificationSignStandards']['commercialIndustrial'] ?? [];
$wallStandard = $commercialIndustrial['wall'] ?? [];
$groundStandard = $commercialIndustrial['ground'] ?? [];
$groundClassStandards = $groundStandard['streetClassStandards'] ?? [];
$groundRules = $signCodeData['groundSignRules'] ?? [];
$citywideRules = $signCodeData['citywideRules'] ?? [];
$permitPreparation = $signCodeData['permitPreparationRequirements'] ?? [];
$administrativeFees = $signCodeData['administrativeFees'] ?? [];

$wallFormula = $wallStandard['areaFormula'] ?? [];
$wallAreaRate = $wallFormula['squareFeetPerLinearFootOfElevation'] ?? 1;
$wallMinimumArea = $wallFormula['minimumSquareFeet'] ?? 50;
$wallMaximumArea = $wallFormula['maximumSquareFeet'] ?? 500;
$wallPlacementHeight = $wallStandard['standardPlacementHeightFeet'] ?? 25;
$groundSpacing = $groundStandard['minimumSpacingFeet'] ?? null;

$ordinanceTitle = $signCodeAnalysis['ordinance']['title'] ?? 'Phoenix Zoning Ordinance - Signs';
$ordinanceRef = $signCodeAnalysis['ordinance']['codeReference'] ?? 'ZO Section 705';
$fullOrdinanceStr = $ordinanceTitle . ' (' . $ordinanceRef . ')';

// Return the complete report object for authenticated top-down review
if ($jsonReviewMode) {
    $reviewPayload = [
        'report' => [
            'reportType' => 'Location Zoning & Sign Code Report',
            'schemaVersion' => '1.0.0',
            'locationId' => (int)$locationId,
            'generatedAt' => time(),
            'forceRefreshRequested' => $forceRefresh,
            'preparedBy' => [
                'name' => 'Steve Skye',
                'company' => 'Christy Signs'
            ],
            'title' => [
                'locationName' => $loc['locationName'],
                'fullAddress' => $fullAddress
            ]
        ],
        'sourceRecord' => $loc,
        'locationData' => buildLocationDataObject($loc, $streetFrontages),
        'reportSections' => [
            'propertyOverview' => [
                'icon' => 'property.png',
                'title' => 'Property Overview',
                'content' => [
                    'locationName' => $loc['locationName'],
                    'locationPlaceId' => $loc['locationPlaceId'],
                    'locationAddress' => $loc['locationAddress'],
                    'locationAddressSuite' => $loc['locationAddressSuite'] ?? '',
                    'locationCity' => $loc['locationCity'],
                    'locationState' => $loc['locationState'],
                    'locationZip' => $loc['locationZip'],
                    'locationParcelNumber' => $loc['locationParcelNumber'],
                    'locationJurisdiction' => $loc['locationJurisdiction'],
                    'locationCounty' => $loc['locationCounty'],
                    'ownerName' => $loc['ownerName'],
                    'subdivision' => $loc['subdivision'],
                    'lotSize' => $loc['lotSize'],
                    'yearBuilt' => $loc['yearBuilt'] !== null ? (int)$loc['yearBuilt'] : null,
                    'zoningCode' => $loc['zoningCode'],
                    'zoningDescription' => $loc['zoningDescription'],
                    'zoningSource' => $loc['zoningSource'],
                    'zoningVerifiedAt' => toUnixTimestamp($loc['zoningVerifiedAt'] ?? null),
                    'source' => $loc['source'],
                    'entityName' => $loc['entityName']
                ]
            ],
            'zoningSummary' => [
                'icon' => 'temple.png',
                'title' => 'Zoning Summary',
                'content' => [
                    'zoningCode' => $loc['zoningCode'],
                    'zoningDescription' => $loc['zoningDescription'],
                    'zoningSource' => $loc['zoningSource'],
                    'zoningVerifiedAt' => toUnixTimestamp($loc['zoningVerifiedAt'] ?? null),
                    'specialDesignations' => $specialDesignations,
                    'ordinance' => $signCodeAnalysis['ordinance'] ?? []
                ]
            ],
            'attachedSignAllowance' => [
                'icon' => 'bricks.png',
                'title' => 'Attached Sign Allowance',
                'content' => $attached
            ],
            'detachedSignAllowance' => [
                'icon' => 'ruler.png',
                'title' => 'Detached Sign Allowance',
                'content' => [
                'analysis' => $detached,
                'streetFrontages' => buildLocationDataObject($loc, $streetFrontages)['streetFrontages'],
                'streetFrontageNotice' => 'Parcel frontage is not tenant, suite, storefront, or building-elevation width.'
                ]
            ],
            'additionalRequirements' => [
                'icon' => 'clipboard.png',
                'title' => 'Additional Requirements',
                'content' => [
                'analysis' => $signCodeAnalysis['additionalRequirements'] ?? [],
                'citywideRules' => $citywideRules,
                'groundSignRules' => $groundRules
                ]
            ],
            'permitDrawingAndFieldVerificationRequirements' => [
                'icon' => 'pencil.png',
                'title' => 'Permit Drawing & Field Verification Requirements',
                'content' => [
                'analysis' => $signCodeAnalysis['permitRequirements'] ?? [],
                'permitPreparationRequirements' => $permitPreparation,
                'administrativeFees' => $administrativeFees
                ]
            ],
            'requiredFieldInformationAndNextSteps' => [
                'icon' => 'workman.png',
                'title' => 'Required Field Information & Next Steps',
                'content' => [
                'findings' => $signCodeAnalysis['findings'] ?? [],
                'recommendedNextSteps' => $signCodeAnalysis['recommendedNextSteps'] ?? []
                ]
            ],
            'reportBasisAndQualifications' => [
                'icon' => 'scroll.png',
                'title' => 'Report Basis & Qualifications',
                'content' => [
                'analysisStatus' => $analysisError !== null
                    ? 'analysis_error'
                    : ($signCodeAnalysis['analysisStatus'] ?? 'partial'),
                'ordinanceTitle' => $ordinanceTitle,
                'ordinanceReference' => $ordinanceRef,
                'fullOrdinanceReference' => $fullOrdinanceStr,
                'parcelSource' => $loc['source'],
                'zoningSource' => $loc['zoningSource'],
                'frontageDisclaimer' => 'GIS-verified measurements are not a boundary survey and are not certified by a registered land surveyor.',
                'reportQualification' => 'Final sign eligibility remains subject to jurisdiction review, field verification, and approved permit drawings.'
                ]
            ]
        ],
        'authoritativeSignCode' => $signCodeData,
        'analysis' => $signCodeAnalysis,
        'diagnostics' => [
            'analysisError' => $analysisError,
            'parcelConfidence' => isset($loc['confidence']) && $loc['confidence'] !== ''
                ? (float)$loc['confidence']
                : null,
            'specialDesignationReviewRequired' => (bool)($comprehensiveSignPlan['manualReviewRequired'] ?? false),
            'specialDesignationCandidateCases' => $comprehensiveSignPlan['cases'] ?? [],
            'analysisCacheVersion' => $signCodeAnalysis['_cacheVersion'] ?? null,
            'streetFrontageRecordCount' => count($streetFrontages),
            'streetFrontagesRequiringManualReview' => count(array_filter(
                $streetFrontages,
                static fn(array $frontage): bool => (bool)$frontage['requiresManualReview']
            ))
        ]
    ];

    $reviewPayload = normalizeReportTimestamps($reviewPayload);

    $reviewJson = json_encode(
        $reviewPayload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($reviewJson === false) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo "{\"error\":\"Unable to encode the complete report review JSON.\"}";
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: inline; filename="location-zoning-report-' . $locationId . '.json"');
    echo $reviewJson;
    exit;
}

// -------------------------------------------------------------------------
// 5. CSS & HTML Layout
// -------------------------------------------------------------------------
$css = '
    body { font-family: Arial, sans-serif; font-size: 8.5pt; color: #222222; line-height: 1.35; }
    
    .header-table { width: 100%; border-collapse: collapse; border-bottom: 2px solid #14377c; margin: 0; }
    .header-table td { padding: 0 0 2px 0; }
    .header-logo { display: block; height: 58px; width: auto; }
    .header-title { font-size: 13pt; line-height: 1.05; font-weight: bold; color: #14377c; text-align: right; }
    .header-subtitle-main { font-size: 9.5pt; line-height: 1.05; font-weight: bold; color: #333; text-align: right; margin-top: 1px; }
    .header-subtitle-sub { font-size: 8.5pt; line-height: 1.05; color: #555; text-align: right; margin-top: 1px; }
    .header-report-date { font-size: 7.5pt; line-height: 1.05; color: #666; text-align: right; margin-top: 1px; }
    
    .footer-table { width: 100%; border-top: 1px solid #ccc; padding-top: 4px; font-size: 7.5pt; color: #666; }

    .section-block { margin-bottom: 12px; page-break-inside: avoid; }
    .section-heading { font-size: 9.5pt; font-weight: bold; color: #14377c; border-bottom: 1.5px solid #14377c; padding-bottom: 2px; margin-bottom: 5px; }
    .section-icon { display: inline-block; width: 14px; height: 14px; margin-right: 5px; vertical-align: -2px; }
    .section-heading-title { display: inline-block; vertical-align: middle; }

    .data-table { width: 100%; border-collapse: collapse; margin-bottom: 2px; }
    .data-table th, .data-table td { border: 1px solid #ccc; padding: 4px 6px; font-size: 8pt; vertical-align: top; }
    .data-table th { width: 32%; text-align: left; background-color: #f8f9fa; color: #333; font-weight: bold; }
    .data-table td { width: 68%; background-color: #ffffff; color: #111; }

    .matrix-table { width: 100%; border-collapse: collapse; margin: 3px 0; }
    .matrix-table th, .matrix-table td { border: 1px solid #ccc; padding: 3px 4px; font-size: 7.4pt; text-align: center; vertical-align: middle; }
    .matrix-table th { background-color: #eef3f8; color: #263d59; font-weight: bold; }
    .matrix-table td:first-child { text-align: left; font-weight: bold; background-color: #f8f9fa; }

    .two-column-table { width: 100%; border-collapse: separate; border-spacing: 5px 0; margin-left: -5px; margin-right: -5px; }
    .two-column-table td { width: 50%; vertical-align: top; }
    .compact-list { margin: 2px 0 3px 0; padding-left: 16px; font-size: 7.5pt; line-height: 1.25; }
    .compact-list li { margin-bottom: 2px; }

    .citation-subtext { font-size: 7pt; color: #4a607a; margin-top: 2px; margin-bottom: 4px; font-style: italic; }
    .unverified { color: #777; font-style: italic; }
    .note-text { font-size: 7.5pt; color: #444; margin-top: 3px; line-height: 1.25; }

    .callout-box { background-color: #f0f4f9; border: 1px solid #b8cbe5; border-left: 4px solid #14377c; padding: 6px 9px; margin: 5px 0; }
    .callout-title { font-size: 8.5pt; font-weight: bold; color: #14377c; margin-bottom: 3px; }
    .callout-body { font-size: 8pt; color: #222; }

    .basis-table { width: 100%; border-collapse: collapse; margin-top: 4px; background-color: #f8f9fa; }
    .basis-table td { border: 1px solid #e0e0e0; padding: 3px 6px; font-size: 7.5pt; color: #444; }
';

$headerHtml = '
<table class="header-table">
    <tr>
        <td style="width: 45%; vertical-align: top;">' . $logoHtml . '</td>
        <td style="width: 55%; text-align: right; vertical-align: top;">
            <div class="header-title">Location Zoning &amp; Sign Code Report</div>
            <div class="header-subtitle-main">Christy Signs</div>
            <div class="header-subtitle-sub">3145 N 33rd Ave, Phoenix, AZ 85017</div>
            <div class="header-report-date">Report Date: ' . date('F j, Y') . '</div>
        </td>
    </tr>
</table>';

$footerHtml = '
<table class="footer-table">
    <tr>
        <td style="width: 70%;">Prepared by Steve Skye | Christy Signs</td>
        <td style="width: 30%; text-align: right;">Page {PAGENO} of {nbpg}</td>
    </tr>
</table>';

ob_start();
?>

<!-- Property Overview -->
<div class="section-block">
    <?= buildReportSectionHeading('Property Overview', 'property.png') ?>
    <table class="data-table">
        <tr>
            <th>Location</th>
            <td><?= displayValue($loc['locationName']) ?></td>
        </tr>
        <tr>
            <th>Address</th>
            <td><?= displayValue($fullAddress) ?></td>
        </tr>
        <tr>
            <th>APN</th>
            <td><?= displayValue($parcelNumber) ?></td>
        </tr>
        <tr>
            <th>Jurisdiction</th>
            <td><?= displayValue($loc['locationJurisdiction']) ?></td>
        </tr>
        <tr>
            <th>County</th>
            <td><?= displayValue($loc['locationCounty']) ?></td>
        </tr>
        <tr>
            <th>Lot Size</th>
            <td><?= displayValue($formattedLotSize) ?></td>
        </tr>
        <tr>
            <th>Property Owner</th>
            <td><?= displayValue($loc['ownerName']) ?></td>
        </tr>
    </table>
</div>

<!-- Zoning Summary -->
<div class="section-block">
    <?= buildReportSectionHeading('Zoning Summary', 'temple.png') ?>
    <table class="data-table">
        <tr>
            <th>Zoning District</th>
            <td><?= displayValue($loc['zoningCode']) ?></td>
        </tr>
        <tr>
            <th>Description</th>
            <td><?= displayValue($loc['zoningDescription']) ?></td>
        </tr>
        <tr>
            <th>Zoning Source</th>
            <td><?= displayValue($loc['zoningSource']) ?></td>
        </tr>
        <tr>
            <th>Verified</th>
            <td><?= displayValue($verifiedAtFormatted) ?></td>
        </tr>
        <tr>
            <th>Overlay / Special District</th>
            <td>
                <?= displayValue(describeZoningOverlays($zoningOverlays)) ?>
                <?php if (!empty($zoningOverlays['source'])): ?>
                    <div class="citation-subtext">Source: <?= displayValue($zoningOverlays['source']) ?></div>
                <?php endif; ?>
                <?php if (!empty($zoningOverlays['checkedAt'])): ?>
                    <div class="citation-subtext">Checked: <?= displayValue(formatCheckedAt($zoningOverlays['checkedAt'])) ?></div>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>Comprehensive Sign Plan</th>
            <td>
                <?= displayValue(describeComprehensiveSignPlan($comprehensiveSignPlan)) ?>
                <?php if (!empty($comprehensiveSignPlan['source'])): ?>
                    <div class="citation-subtext">Source: <?= displayValue($comprehensiveSignPlan['source']) ?></div>
                <?php endif; ?>
                <?php if (!empty($comprehensiveSignPlan['checkedAt'])): ?>
                    <div class="citation-subtext">Checked: <?= displayValue(formatCheckedAt($comprehensiveSignPlan['checkedAt'])) ?></div>
                <?php endif; ?>
            </td>
        </tr>
    </table>
</div>

<!-- Attached Sign Allowance -->
<div class="section-block">
    <?= buildReportSectionHeading('Attached Sign Allowance', 'bricks.png') ?>
    <table class="data-table">
        <tr>
            <th>Controlling measurement</th>
            <td><?= displayValue($attached['applicableElevation'] ?? null, 'Occupied building or tenant elevation width') ?></td>
        </tr>
        <tr>
            <th>Area rate</th>
            <td><?= displayValue($wallAreaRate) ?> sq. ft. per 1 linear ft. of elevation</td>
        </tr>
        <tr>
            <th>Minimum allowance</th>
            <td><?= displayValue($wallMinimumArea) ?> sq. ft.</td>
        </tr>
        <tr>
            <th>Maximum cap</th>
            <td><?= displayValue($wallMaximumArea) ?> sq. ft.</td>
        </tr>
        <tr>
            <th>Standard placement height</th>
            <td>Up to <?= displayValue($wallPlacementHeight) ?> ft. above grade (not an absolute maximum height)</td>
        </tr>
    </table>
    <?= renderCitation('Phoenix Zoning Ordinance §705.D.1, Table D-1') ?>

    <div class="callout-box">
        <div class="callout-title">Attached-Sign Allowance Rules & Controls</div>
        <div class="callout-body">
            <strong>Formula:</strong> Greater of <?= displayValue($wallMinimumArea) ?> sq. ft. or elevation width × <?= displayValue($wallAreaRate) ?> sq. ft./linear ft. (capped at <?= displayValue($wallMaximumArea) ?> sq. ft.).<br />
            <strong>Placement / Roofline Controls:</strong> When placed above <?= displayValue($wallPlacementHeight) ?> ft., the top of the sign must remain below the roofline by at least 1/2 of the sign's vertical height.<br />
            <strong>Final Calculation:</strong> Requires physical elevation frontage and inventory of existing signage.
        </div>
    </div>
    <?= renderCitation('Phoenix Zoning Ordinance §705.D.1, Table D-1; §705.D.3.i') ?>
</div>

<!-- Detached Sign Allowance -->
<div class="section-block">
    <?= buildReportSectionHeading('Detached Sign Allowance', 'ruler.png') ?>
    <table class="matrix-table">
        <tr>
            <th rowspan="2" style="width: 30%;">Sign / Street Class</th>
            <th colspan="2">As of Right</th>
            <th colspan="2">Design Review Maximum</th>
        </tr>
        <tr>
            <th>Height</th>
            <th>Area</th>
            <th>Height</th>
            <th>Area</th>
        </tr>
        <?php
        $groundLabels = [
            'freeway' => 'Freeway',
            'highVolumePrimary' => 'High-volume primary',
            'lowVolumePrimary' => 'Low-volume primary',
            'highVolumeSecondary' => 'High-volume secondary',
            'lowVolumeSecondary' => 'Low-volume secondary'
        ];
        foreach ($groundLabels as $groundKey => $groundLabel):
            $groundRow = $groundClassStandards[$groundKey] ?? [];
            $asOfRight = $groundRow['asOfRight'] ?? [];
            $designReview = $groundRow['designReviewMaximum'] ?? [];
        ?>
            <tr>
                <td><?= htmlspecialchars($groundLabel, ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= formatDimension($asOfRight['maximumHeightFeet'] ?? null, 'ft.') ?></td>
                <td><?= formatDimension($asOfRight['maximumAreaSquareFeet'] ?? null, 'sq. ft.') ?></td>
                <td><?= formatDimension($designReview['maximumHeightFeet'] ?? null, 'ft.') ?></td>
                <td><?= formatDimension($designReview['maximumAreaSquareFeet'] ?? null, 'sq. ft.') ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?= renderCitation('Phoenix Zoning Ordinance §705.D.1, Table D-1') ?>

    <table class="data-table">
        <tr>
            <th>Parcel classification</th>
            <td><?= displayValue($detached['parcelUseType'] ?? null, 'Single-use or multiple-use verification required') ?></td>
        </tr>
        <tr>
            <th>Street frontage</th>
            <td>
                <?php if (!empty($streetFrontages)): ?>
                    <?php foreach ($streetFrontages as $frontage): ?>
                        <div>
                            <strong><?= displayValue($frontage['streetName'] ?? null) ?>:</strong>
                            <?= displayValue($frontage['frontageLengthFeet'] ?? null) ?> ft.
                            <?php if (!empty($frontage['streetClassification'])): ?>
                                — <?= displayValue($frontage['streetClassification']) ?>
                            <?php endif; ?>
                            <?php if (!empty($frontage['requiresManualReview'])): ?>
                                <span class="unverified">(manual review required)</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="unverified">No defensible parcel frontage resolved</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>Street classification</th>
            <td><?= displayValue($detached['streetClassification'] ?? null, 'Freeway, high-volume, or low-volume') ?></td>
        </tr>
        <tr>
            <th>Sign classification</th>
            <td><?= displayValue($detached['signClassification'] ?? null, 'Primary or secondary identification sign') ?></td>
        </tr>
        <tr>
            <th>Allowed count</th>
            <td>Pending classification and frontage</td>
        </tr>
        <tr>
            <th>Maximum area</th>
            <td>Pending classification</td>
        </tr>
        <tr>
            <th>Minimum spacing</th>
            <td><?= displayValue($groundSpacing, '100') ?> ft., when applicable</td>
        </tr>
        <tr>
            <th>Existing signs</th>
            <td>Inventory required</td>
        </tr>
    </table>
    <?= renderCitation('Phoenix Zoning Ordinance §705.D.2.a–b') ?>

    <div class="callout-box">
        <div class="callout-title">Detached-Sign Determination</div>
        <div class="callout-body">
            <strong>Allowance Basis:</strong> Count and classification depend on parcel use, the separately measured frontage of each street, street classification, and existing detached signs.<br />
            <strong>Current Result:</strong> <?= displayValue($detached['calculation']['displayedResult'] ?? null, 'The applicable count, maximum area, and maximum height cannot be finalized until those site conditions are verified.') ?><br />
            <strong>Design Review:</strong> Enhanced values shown above are conditional maximums and are not the ordinary as-of-right allowance.
        </div>
        <?= renderCitation('Phoenix Zoning Ordinance §705.D.1, Table D-1; §705.D.2.a–b') ?>
    </div>

    <table class="data-table">
        <tr><th>Single-use: 100 ft. or less</th><td>One secondary identification sign</td></tr>
        <tr><th>Single-use: over 100–300 ft.</th><td>One primary identification sign</td></tr>
        <tr><th>Single-use: over 300 ft.</th><td>Same number and sizes as a multiple-use parcel with the same frontage</td></tr>
        <tr><th>Multiple-use primary signs</th><td>One for the first 300 ft. or portion, plus one for each additional full 300 ft.</td></tr>
        <tr><th>Multiple-use secondary signs</th><td>One per 150 ft., reduced by primary signs on the same frontage</td></tr>
    </table>
    <?= renderCitation('Phoenix Zoning Ordinance §705.D.2.a–b') ?>

    <div class="note-text">
        <strong>Placement controls:</strong> Verify the permitted yard, property-line/back-of-curb relationship, building separation for signs over 8 ft., sight-distance conditions, required address copy, and the ten-item information limit.
        <?= renderCitation('Phoenix Zoning Ordinance §705.D.2.f–j') ?>
    </div>
</div>

<!-- Additional Requirements -->
<div class="section-block">
    <?= buildReportSectionHeading('Additional Requirements', 'clipboard.png') ?>
    <table class="data-table">
        <tr>
            <th>Sign permit</th>
            <td>Required before installation or alteration unless exempt</td>
        </tr>
        <tr>
            <td colspan="2" style="border-top: none; padding-top: 0; padding-bottom: 4px;">
                <?= renderCitation('Phoenix Zoning Ordinance §705.B.1.a') ?>
            </td>
        </tr>
        <tr>
            <th>Engineered plans</th>
            <td>Threshold depends on sign type: wall signs over 100 sq. ft.; ground/pole signs over 35 sq. ft. and over 6 ft. high; other sign types have separate thresholds and exceptions.</td>
        </tr>
        <tr>
            <td colspan="2" style="border-top: none; padding-top: 0; padding-bottom: 4px;">
                <?= renderCitation('Phoenix Zoning Ordinance §705.B.1.d(1)(a)–(e)') ?>
            </td>
        </tr>
        <tr>
            <th>Illumination</th>
            <td>Residential adjacency and lighting conditions require review</td>
        </tr>
        <tr>
            <td colspan="2" style="border-top: none; padding-top: 0; padding-bottom: 4px;">
                <?= renderCitation('Phoenix Zoning Ordinance §705.C.6') ?>
            </td>
        </tr>
        <tr>
            <th>Overlay or CSP</th>
            <td>Verify whether site-specific criteria modify the base allowance</td>
        </tr>
        <tr>
            <td colspan="2" style="border-top: none; padding-top: 0; padding-bottom: 4px;">
                <?= renderCitation('Phoenix Zoning Ordinance §705; applicable overlay, stipulation, and approved-plan provisions') ?>
            </td>
        </tr>
        <tr>
            <th>Projection</th>
            <td>Evaluate separately from maximum sign height</td>
        </tr>
        <tr>
            <td colspan="2" style="border-top: none; padding-top: 0; padding-bottom: 4px;">
                <?= renderCitation('Phoenix Zoning Ordinance §705.B.3.a–b') ?>
            </td>
        </tr>
    </table>
</div>

<!-- Permit Drawing & Field Verification Requirements -->
<div class="section-block">
    <?= buildReportSectionHeading('Permit Drawing & Field Verification Requirements', 'pencil.png') ?>
    <table class="two-column-table">
        <tr>
            <td>
                <table class="data-table">
                    <tr><th colspan="2" style="width: 100%; background-color: #eef3f8; color: #14377c;">Attached Signs</th></tr>
                    <?php foreach (($permitPreparation['wallSigns'] ?? []) as $requirement): ?>
                        <tr><td style="width: 100%;"><?= htmlspecialchars((string)$requirement, ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </td>
            <td>
                <table class="data-table">
                    <tr><th colspan="2" style="width: 100%; background-color: #eef3f8; color: #14377c;">Detached Signs</th></tr>
                    <?php foreach (($permitPreparation['groundSigns'] ?? []) as $requirement): ?>
                        <tr><td style="width: 100%;"><?= htmlspecialchars((string)$requirement, ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </td>
        </tr>
    </table>
    <div class="citation-subtext">Procedural reference: City of Phoenix Sign Permit Submittal Checklist. Confirm current submittal requirements before filing.</div>
</div>

<!-- Required Field Information & Next Steps -->
<div class="section-block">
    <?= buildReportSectionHeading('Required Field Information & Next Steps', 'workman.png') ?>
    <ul style="margin: 3px 0 6px 0; padding-left: 18px; font-size: 8pt; color: #222;">
        <li>Applicable building or tenant elevation width</li>
        <li>Existing attached-sign count and total area</li>
        <li>Street frontage length and classification</li>
        <li>Existing detached-sign inventory</li>
        <li>Single-use or multiple-use parcel classification</li>
        <li>Proposed sign dimensions, height, and illumination</li>
        <li>Applicable overlay, CSP, or approved sign program</li>
    </ul>

    <div class="callout-box">
        <div class="callout-title">Recommended Next Steps</div>
        <ol style="margin: 0; padding-left: 18px; font-size: 8pt; color: #222;">
            <?php if (!empty($signCodeAnalysis['recommendedNextSteps']) && is_array($signCodeAnalysis['recommendedNextSteps'])): ?>
                <?php foreach ($signCodeAnalysis['recommendedNextSteps'] as $step): ?>
                    <li style="margin-bottom: 2px;"><?= htmlspecialchars((string)$step, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            <?php else: ?>
                <li style="margin-bottom: 2px;">Verify the linear frontage of the building or suite to calculate the maximum allowable area for attached signs.</li>
                <li style="margin-bottom: 2px;">Confirm the street frontage feet to determine the maximum number and area of detached signs.</li>
                <li>Perform an on-site inventory of all existing signage and check for site-specific CSP guidelines.</li>
            <?php endif; ?>
        </ol>
    </div>
</div>

<!-- Report Basis & Qualifications -->
<div class="section-block" style="margin-top: 10px;">
    <?= buildReportSectionHeading('Report Basis & Qualifications', 'scroll.png') ?>
    <table class="basis-table">
        <tr>
            <td><strong>Analysis Status:</strong> <?= displayValue($analysisError ? 'Analysis Error' : ucwords(str_replace('_', ' ', (string)($signCodeAnalysis['analysisStatus'] ?? 'Partial')))) ?></td>
            <td><strong>Jurisdiction:</strong> <?= displayValue($loc['locationJurisdiction']) ?></td>
        </tr>
        <tr>
            <td><strong>Applicable Code:</strong> <?= displayValue($fullOrdinanceStr) ?></td>
            <td><strong>General Permit Threshold:</strong> Sign permit required prior to installation or alteration</td>
        </tr>
    </table>

    <p style="font-size: 7.5pt; color: #666666; line-height: 1.25; margin-top: 6px;">
        <strong>Sources &amp; Review Qualifications:</strong> Information shown is derived from structured ordinance data and local parcel records. Regulatory citations identify the underlying ordinance; procedural references do not override current ordinance requirements. Base zoning may be modified by approved plans, stipulations, overlays, special districts, a Comprehensive Sign Plan, or nonconforming conditions. Verify all site measurements, existing signs, citations, and final requirements with the governing jurisdiction before design completion, fabrication, or permit filing.
    </p>
</div>

<?php
$html = ob_get_clean();

// -------------------------------------------------------------------------
// 6. Render PDF with mPDF
// -------------------------------------------------------------------------
try {
    $mpdf = new \Mpdf\Mpdf([
        'mode'          => 'utf-8',
        'format'        => 'Letter',
        'margin_left'   => 10,
        'margin_right'  => 10,
        'margin_top'    => 27,
        'margin_bottom' => 18,
        'margin_header' => 6,
        'margin_footer' => 7
    ]);

    $mpdf->SetTitle('Location Zoning & Sign Code Report - ' . ($loc['locationName'] ?? 'Location #' . $locationId));
    $mpdf->SetAuthor('Steve Skye');

    $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->SetHTMLHeader($headerHtml);
    $mpdf->SetHTMLFooter($footerHtml);
    $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);

    $mpdf->Output('Location_Zoning_Report_' . $locationId . '.pdf', \Mpdf\Output\Destination::INLINE);
} catch (\Mpdf\MpdfException $e) {
    http_response_code(500);
    echo 'Error generating PDF report: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}