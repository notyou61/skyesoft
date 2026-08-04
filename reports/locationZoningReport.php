<?php
declare(strict_types=1);

// Force PHP error logging to local folder (skyesoft/reports/php-error.log)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
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
// ... rest of script continues as before ...

// Validate locationId parameter
$locationId = filter_input(INPUT_GET, 'locationId', FILTER_VALIDATE_INT);
if (!$locationId) {
    http_response_code(400);
    die('Invalid or missing locationId parameter.');
}

$forceRefresh = filter_input(INPUT_GET, 'refresh', FILTER_VALIDATE_BOOLEAN) ?? false;

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

            p.ownerName,
            p.subdivision,
            p.lotSize,
            p.yearBuilt,
            p.zoningCode,
            p.zoningDescription,
            p.zoningSource,
            p.zoningVerifiedAt,
            p.source,
            p.confidence,

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
    // Verify API key availability without logging the key itself
    $apiKey = skyesoftGetEnv('OPENAI_API_KEY');

    error_log(
        '[locationZoningReport] OPENAI_API_KEY: ' .
        (($apiKey !== null && trim($apiKey) !== '') ? 'AVAILABLE' : 'MISSING')
    );

    if ($apiKey === null || trim($apiKey) === '') {
        throw new RuntimeException(
            'OPENAI_API_KEY was not loaded.'
        );
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
        CURLOPT_HEADER => true, // Include HTTP response headers in the output string
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

    // Split headers and body
    $headers = substr($response, 0, $headerSize);
    $rawResponse = substr($response, $headerSize);

    // Extract HTTP status line
    $headerLines = explode("\r\n", trim($headers));
    $statusLine = $headerLines[0] ?? ('HTTP/1.1 ' . $httpCode);

    error_log('[locationZoningReport] OpenAI HTTP status: ' . $statusLine);

    if ($httpCode !== 200) {
        error_log(
            '[locationZoningReport] OpenAI error response: ' .
            substr($rawResponse, 0, 2000)
        );

        throw new RuntimeException(
            'OpenAI returned ' . $statusLine
        );
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
 * Perform sign code analysis using jurisdiction local filesystem assets and cached database entries.
 */
function getOrRunSignCodeAnalysis(PDO $db, array $loc, bool $forceRefresh = false): array
{
    $cacheDir = __DIR__ . '/../data/cache/signReports';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $cacheFile = $cacheDir . '/location_' . $loc['locationId'] . '.json';

    // Determine jurisdiction path
    $jurisdictionSlug = strtolower(trim((string)($loc['locationJurisdiction'] ?? 'phoenix')));
    $jurisdictionSlug = preg_replace('/[^a-z0-9]+/', '-', $jurisdictionSlug);
    $jurisdictionSlug = trim((string)$jurisdictionSlug, '-');
    $jurisdictionDir = __DIR__ . '/../data/authoritative/jurisdictions/' . $jurisdictionSlug;

    if (!is_dir($jurisdictionDir)) {
        // Fallback to Phoenix if jurisdiction folder not found
        $jurisdictionDir = __DIR__ . '/../data/authoritative/jurisdictions/phoenix';
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

    // Invalidate old or incomplete results when governed inputs change
    $cacheVersion = hash('sha256', implode('|', [
        (string)$loc['locationId'],
        (string)($loc['zoningCode'] ?? ''),
        (string)($loc['zoningVerifiedAt'] ?? ''),
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

    // Context payloads
    $locationDataJson = json_encode([
        'locationId' => $loc['locationId'],
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
        'lotSize' => $loc['lotSize']
    ], JSON_PRETTY_PRINT);

    $projectSignDataJson = json_encode([
        'existingSigns' => [],
        'proposedSigns' => []
    ], JSON_PRETTY_PRINT);

    $codexContextJson = json_encode([
        'reportType' => 'Location Zoning & Sign Code Report',
        'targetClient' => $loc['entityName'] ?? 'Internal Review'
    ], JSON_PRETTY_PRINT);

    // Interpolate values into system prompt variables
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

    // Save cache locally
    $cacheJson = json_encode($analysisResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($cacheJson === false || file_put_contents($cacheFile, $cacheJson, LOCK_EX) === false) {
        throw new RuntimeException('The completed sign-code analysis could not be cached.');
    }

    return $analysisResult;
}

// Execute analysis with fallback handling and diagnostic logging
$signCodeAnalysis = [];
$analysisError = null;
try {
    $signCodeAnalysis = getOrRunSignCodeAnalysis($db, $loc, $forceRefresh);
} catch (Throwable $e) {

    // Log complete analysis failure
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

/**
 * Display helper supporting scalar types with htmlspecialchars encoding.
 */
function displayValue(mixed $value, string $fallback = 'Not Yet Verified'): string
{
    $trimmed = trim((string)($value ?? ''));

    return $trimmed !== ''
        ? htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8')
        : '<span class="unverified">' . htmlspecialchars($fallback, ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * Renders citation text with styled block tag.
 */
function renderCitation(?string $citationText): string
{
    if (empty($citationText)) {
        return '';
    }
    return '<div class="citation-tag">Citation: ' . htmlspecialchars($citationText, ENT_QUOTES, 'UTF-8') . '</div>';
}

/**
 * Build an mPDF-compatible section heading table with local PNG icon.
 */
function buildReportSectionHeading(
    string $title,
    string $iconFile
): string {
    $iconPath = __DIR__ . '/../assets/images/icons/' . basename($iconFile);

    $iconHtml = file_exists($iconPath)
        ? '<img src="' . htmlspecialchars($iconPath, ENT_QUOTES, 'UTF-8') . '" class="section-icon" alt="" />'
        : '';

    return '
        <table class="section-heading-table">
            <tr>
                <td class="section-icon-cell">' . $iconHtml . '</td>
                <td class="section-title-cell">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</td>
            </tr>
        </table>
    ';
}

// APN Fallback logic
$parcelNumber = $loc['locationParcelNumberRaw']
    ?: $loc['locationParcelNumber']
    ?: null;

// Address assembly with clean comma separators
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

// Format verification date using Unix timestamp integer
$verifiedAtFormatted = null;
if (!empty($loc['zoningVerifiedAt'])) {
    $verifiedAtFormatted = date('F j, Y', (int)$loc['zoningVerifiedAt']);
}

// Christy Signs Logo (Local Filesystem Path)
$logoPath = __DIR__ . '/../assets/images/christyLogo.png';
$logoHtml = file_exists($logoPath)
    ? '<img src="' . htmlspecialchars($logoPath, ENT_QUOTES, 'UTF-8') . '" style="max-height: 48px; width: auto;" alt="Christy Signs" />'
    : '<div style="font-size: 16px; font-weight: bold; color: #14377c;">Christy Signs</div>';

// Extract Analysis Data Fields safely
$ordinanceRef = $signCodeAnalysis['ordinance']['title'] ?? null;
if (!empty($signCodeAnalysis['ordinance']['codeReference'])) {
    $ordinanceRef .= ($ordinanceRef ? ' (' : '') . $signCodeAnalysis['ordinance']['codeReference'] . ($ordinanceRef ? ')' : '');
}

$attachedSigns = $signCodeAnalysis['attachedSigns'] ?? [];
$detachedSigns = $signCodeAnalysis['detachedSigns'] ?? [];
$generalReqs   = $signCodeAnalysis['generalRequirements'] ?? [];
$missingInputs = $signCodeAnalysis['missingInputs'] ?? [];

// Calculate display text for Attached Area
$attachedAreaDisplay = null;
if (!empty($attachedSigns['calculation']['displayedResult'])) {
    $attachedAreaDisplay = $attachedSigns['calculation']['displayedResult'];
} elseif (isset($attachedSigns['maximumAreaSquareFeet'])) {
    $attachedAreaDisplay = $attachedSigns['maximumAreaSquareFeet'] . ' sq ft max';
} elseif (!empty($attachedSigns['allowanceBasis'])) {
    $attachedAreaDisplay = $attachedSigns['allowanceBasis'];
}

// Calculate display text for Height/Projection
$heightProjDisplay = null;
$heightParts = [];
if (!empty($attachedSigns['heightLimitFeet'])) {
    $heightParts[] = 'Height: ' . $attachedSigns['heightLimitFeet'] . ' ft max';
}
if (!empty($attachedSigns['projectionLimitInches'])) {
    $heightParts[] = 'Projection: ' . $attachedSigns['projectionLimitInches'] . ' in max';
}
if (!empty($detachedSigns['maximumHeightFeet'])) {
    $heightParts[] = 'Detached Height: ' . $detachedSigns['maximumHeightFeet'] . ' ft max';
}
if (count($heightParts) > 0) {
    $heightProjDisplay = implode(' | ', $heightParts);
}

// Find Illumination and Permit Rules from Findings or General Requirements
$illuminationRule = null;
$permitRule = null;

if (isset($signCodeAnalysis['findings']) && is_array($signCodeAnalysis['findings'])) {
    foreach ($signCodeAnalysis['findings'] as $finding) {
        if (($finding['category'] ?? '') === 'illumination' && empty($illuminationRule)) {
            $illuminationRule = [
                'text' => $finding['finding'],
                'citationText' => $finding['citationText'] ?? null
            ];
        }
        if (($finding['category'] ?? '') === 'permit' && empty($permitRule)) {
            $permitRule = [
                'text' => $finding['finding'],
                'citationText' => $finding['citationText'] ?? null
            ];
        }
    }
}

// Fall back to structured general requirements when findings omit a category
foreach ($generalReqs as $requirement) {
    $requirementText = $requirement['requirement'] ?? null;
    if (!$requirementText) {
        continue;
    }

    $searchText = strtolower((string)$requirementText);
    if ($illuminationRule === null && preg_match('/illumin|light|brightness/', $searchText)) {
        $illuminationRule = [
            'text' => $requirementText,
            'citationText' => $requirement['citationText'] ?? null
        ];
    }
    if ($permitRule === null && preg_match('/permit|approval|application/', $searchText)) {
        $permitRule = [
            'text' => $requirementText,
            'citationText' => $requirement['citationText'] ?? null
        ];
    }
}

// -------------------------------------------------------------------------
// 5. CSS & HTML Layout (Magnolia Archetype Standards)
// -------------------------------------------------------------------------
$css = '
    body {
        font-family: Arial, sans-serif;
        font-size: 9pt;
        color: #222222;
        line-height: 1.35;
    }

    /* Header & Footer Layout */
    .header-table {
        width: 100%;
        border-bottom: 2px solid #14377c;
        padding-bottom: 6px;
    }
    .header-title {
        font-size: 12.5pt;
        font-weight: bold;
        color: #14377c;
        margin-bottom: 2px;
    }
    .header-subtitle-main {
        font-size: 9.5pt;
        font-weight: bold;
        color: #333333;
    }
    .header-subtitle-sub {
        font-size: 8.5pt;
        color: #555555;
    }
    .header-report-date {
        margin-top: 2px;
        font-size: 8pt;
        color: #666666;
    }
    .footer-table {
        width: 100%;
        border-top: 1px solid #ccc;
        padding-top: 5px;
        font-size: 8pt;
        color: #666666;
    }

    /* Section Control & Headings with PNG Icons */
    .section-block {
        page-break-inside: avoid;
        margin-bottom: 12px;
    }
    .section-heading-table {
        width: 100%;
        border-collapse: collapse;
        border-bottom: 1px solid #ccc;
        margin: 6px 0 4px;
    }
    .section-icon-cell {
        width: 20px;
        padding: 0 5px 2px 0;
        vertical-align: middle;
    }
    .section-icon {
        width: 15px;
        height: 15px;
    }
    .section-title-cell {
        padding: 0 0 2px;
        vertical-align: middle;
        font-size: 10pt;
        font-weight: bold;
        color: #14377c;
    }

    /* Fully Bordered Magnolia Data Tables */
    .data-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 4px;
    }
    .data-table th, 
    .data-table td {
        border: 1px solid #ccc;
        padding: 4px 6px;
        font-size: 8.5pt;
        vertical-align: top;
    }
    .data-table th {
        width: 28%;
        text-align: left;
        background-color: #f8f9fa;
        color: #333333;
        font-weight: bold;
    }
    .data-table td {
        width: 72%;
        background-color: #ffffff;
        color: #111111;
    }

    /* Citation Tags */
    .citation-tag {
        font-size: 7.5pt;
        font-weight: bold;
        color: #14377c;
        margin-top: 3px;
        font-style: normal;
    }

    /* Unverified Fallback Text */
    .unverified {
        color: #888888;
        font-style: italic;
    }
    .analysis-error {
        color: #9b1c1c;
        font-weight: bold;
    }

    /* Magnolia Blue Callout Box */
    .callout-box {
        background-color: #f0f4f9;
        border: 1px solid #b8cbe5;
        border-left: 4px solid #14377c;
        padding: 8px 10px;
        margin-top: 4px;
    }
    .callout-title-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 4px;
    }
    .callout-title-cell {
        font-size: 9.5pt;
        font-weight: bold;
        color: #14377c;
        vertical-align: middle;
    }
';

// Client-Facing Header
$headerHtml = '
<table class="header-table">
    <tr>
        <td style="width: 45%; vertical-align: bottom;">
            ' . $logoHtml . '
        </td>
        <td style="width: 55%; text-align: right; vertical-align: bottom;">
            <div class="header-title">Location Zoning &amp; Sign Code Report</div>
            <div class="header-subtitle-main">' . displayValue($loc['locationName']) . '</div>
            <div class="header-subtitle-sub">' . displayValue($fullAddress) . '</div>
            <div class="header-report-date">Report Date: ' . date('F j, Y') . '</div>
        </td>
    </tr>
</table>';

// Footer
$footerHtml = '
<table class="footer-table">
    <tr>
        <td style="width: 70%;">Prepared by Steve Skye | Christy Signs</td>
        <td style="width: 30%; text-align: right;">Page {PAGENO} of {nbpg}</td>
    </tr>
</table>';

// Target Icon path for Callout Box
$targetIconPath = __DIR__ . '/../assets/images/icons/target.png';
$targetIconHtml = file_exists($targetIconPath)
    ? '<img src="' . htmlspecialchars($targetIconPath, ENT_QUOTES, 'UTF-8') . '" class="section-icon" alt="" />'
    : '';

// Body Content
ob_start();
?>
<!-- 1. Property Overview -->
<div class="section-block">
    <?= buildReportSectionHeading('Property Overview', 'property.png') ?>
    <table class="data-table">
        <tr>
            <th>Location</th>
            <td><?= displayValue($loc['locationName']) ?></td>
        </tr>
        <tr>
            <th>Customer</th>
            <td><?= displayValue($loc['entityName'] ?? null) ?></td>
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
            <th>Owner</th>
            <td><?= displayValue($loc['ownerName']) ?></td>
        </tr>
        <tr>
            <th>Subdivision</th>
            <td><?= displayValue($loc['subdivision']) ?></td>
        </tr>
        <tr>
            <th>Lot Size</th>
            <td><?= displayValue($loc['lotSize']) ?></td>
        </tr>
        <tr>
            <th>Year Built</th>
            <td><?= displayValue($loc['yearBuilt']) ?></td>
        </tr>
    </table>
</div>

<!-- 2. Zoning Summary -->
<div class="section-block">
    <?= buildReportSectionHeading('Zoning Summary', 'temple.png') ?>
    <table class="data-table">
        <tr>
            <th>Zoning District</th>
            <td><?= displayValue($loc['zoningCode']) ?></td>
        </tr>
        <tr>
            <th>District Description</th>
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
            <th>Confidence</th>
            <td><?= displayValue(isset($loc['confidence']) && $loc['confidence'] !== '' ? $loc['confidence'] . '%' : null) ?></td>
        </tr>
    </table>
</div>

<!-- 3. Sign Ordinance Summary -->
<div class="section-block">
    <?= buildReportSectionHeading('Sign Ordinance Summary', 'scroll.png') ?>
    <table class="data-table">
        <tr>
            <th>Analysis Status</th>
            <td>
                <?php if ($analysisError !== null): ?>
                    <span class="analysis-error">AI sign-code analysis could not be completed. Review the server error log.</span>
                <?php else: ?>
                    <?= displayValue(ucwords(str_replace('_', ' ', (string)($signCodeAnalysis['analysisStatus'] ?? 'complete')))) ?>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>Sign Code Jurisdiction</th>
            <td><?= displayValue($loc['locationJurisdiction']) ?></td>
        </tr>
        <tr>
            <th>Applicable Code / Section</th>
            <td>
                <?= displayValue($ordinanceRef) ?>
                <?= renderCitation($signCodeAnalysis['ordinance']['citationText'] ?? null) ?>
            </td>
        </tr>
        <tr>
            <th>Attached Sign Allowance</th>
            <td>
                <?= displayValue($attachedAreaDisplay, 'Requires elevation measurement') ?>
                <?php if (!empty($attachedSigns['allowanceBasis'])): ?>
                    <br/><span style="font-size: 8pt; color: #555555;">Basis: <?= htmlspecialchars($attachedSigns['allowanceBasis'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
                <?= renderCitation($attachedSigns['applicableRules'][0]['citationText'] ?? null) ?>
            </td>
        </tr>
        <tr>
            <th>Max Height / Projection</th>
            <td>
                <?= displayValue($heightProjDisplay) ?>
                <?= renderCitation($attachedSigns['applicableRules'][0]['citationText'] ?? null) ?>
            </td>
        </tr>
        <tr>
            <th>Illumination Rules</th>
            <td>
                <?= displayValue($illuminationRule['text'] ?? null) ?>
                <?= renderCitation($illuminationRule['citationText'] ?? null) ?>
            </td>
        </tr>
        <tr>
            <th>Permit Requirements</th>
            <td>
                <?= displayValue($permitRule['text'] ?? null) ?>
                <?= renderCitation($permitRule['citationText'] ?? null) ?>
            </td>
        </tr>
    </table>
</div>

<!-- 4. Recommended Next Steps -->
<div class="section-block">
    <div class="callout-box">
        <table class="callout-title-table">
            <tr>
                <?php if ($targetIconHtml !== ''): ?>
                    <td class="section-icon-cell"><?= $targetIconHtml ?></td>
                <?php endif; ?>
                <td class="callout-title-cell">Recommended Next Steps</td>
            </tr>
        </table>
        <ol style="margin: 0; padding-left: 18px;">
            <?php if (!empty($signCodeAnalysis['recommendedNextSteps']) && is_array($signCodeAnalysis['recommendedNextSteps'])): ?>
                <?php foreach ($signCodeAnalysis['recommendedNextSteps'] as $step): ?>
                    <li style="margin-bottom: 3px;"><?= htmlspecialchars((string)$step, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            <?php else: ?>
                <li>Verify zoning designation and sign ordinance requirements with municipal staff or GIS resources.</li>
                <li>Confirm whether any site-specific Master Sign Plan or overlay restrictions exist for this parcel.</li>
                <li>Measure building elevation width and tenant frontage to complete attached-sign area calculations.</li>
            <?php endif; ?>
        </ol>
    </div>
</div>

<!-- 5. Sources and Disclaimers -->
<div class="section-block" style="margin-top: 10px;">
    <p style="font-size: 7.5pt; color: #666666; line-height: 1.25; margin: 0;">
        <strong>Sources &amp; Review Qualifications:</strong> Information shown is derived from authoritative zoning ordinance specifications and local parcel records. Regulatory citations indicate primary governing provisions. All sign plans and dimensional calculations must be verified with governing jurisdiction officials prior to fabrication and permit application.
    </p>
</div>
<?php
$html = ob_get_clean();

// -------------------------------------------------------------------------
// 6. Render PDF with mPDF (Letter + Expanded Body & Header Clearance)
// -------------------------------------------------------------------------
try {
    $mpdf = new \Mpdf\Mpdf([
        'mode'          => 'utf-8',
        'format'        => 'Letter',
        'margin_left'   => 8.5,   // ~0.33 in
        'margin_right'  => 8.5,   // ~0.33 in
        'margin_top'    => 33,    // Height clearance for header
        'margin_bottom' => 14,    // Clearance for footer
        'margin_header' => 6,
        'margin_footer' => 6
    ]);

    $mpdf->SetTitle('Location Zoning & Sign Code Report - ' . ($loc['locationName'] ?? 'Location #' . $locationId));
    $mpdf->SetAuthor('Steve Skye');

    // 1. Pass styles first so headers/footers inherit stylesheet classes
    $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);

    // 2. Set headers and footers
    $mpdf->SetHTMLHeader($headerHtml);
    $mpdf->SetHTMLFooter($footerHtml);

    // 3. Output body HTML
    $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);

    // Stream inline to open directly in browser tab
    $mpdf->Output('Location_Zoning_Report_' . $locationId . '.pdf', \Mpdf\Output\Destination::INLINE);
} catch (\Mpdf\MpdfException $e) {
    http_response_code(500);
    echo 'Error generating PDF report: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}