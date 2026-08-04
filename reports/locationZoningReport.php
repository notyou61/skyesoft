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
    $apiKey = skyesoftGetEnv('OPENAI_API_KEY');

    error_log(
        '[locationZoningReport] OPENAI_API_KEY: ' .
        (($apiKey !== null && trim($apiKey) !== '') ? 'AVAILABLE' : 'MISSING')
    );

    if ($apiKey === null || trim($apiKey) !== '') {
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
 * Perform sign code analysis using jurisdiction local filesystem assets and cached database entries.
 */
function getOrRunSignCodeAnalysis(PDO $db, array $loc, bool $forceRefresh = false): array
{
    $cacheDir = __DIR__ . '/../data/cache/signReports';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $cacheFile = $cacheDir . '/location_' . $loc['locationId'] . '.json';

    $jurisdictionSlug = strtolower(trim((string)($loc['locationJurisdiction'] ?? 'phoenix')));
    $jurisdictionSlug = preg_replace('/[^a-z0-9]+/', '-', $jurisdictionSlug);
    $jurisdictionSlug = trim((string)$jurisdictionSlug, '-');
    $jurisdictionDir = __DIR__ . '/../data/authoritative/jurisdictions/' . $jurisdictionSlug;

    if (!is_dir($jurisdictionDir)) {
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
    $signCodeAnalysis = getOrRunSignCodeAnalysis($db, $loc, $forceRefresh);
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

function buildReportSectionHeading(string $title): string 
{
    return '<div class="section-heading">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>';
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
    $verifiedAtFormatted = date('F j, Y', (int)$loc['zoningVerifiedAt']);
}

$formattedLotSize = null;
if (!empty($loc['lotSize'])) {
    $formattedLotSize = is_numeric($loc['lotSize']) ? number_format((float)$loc['lotSize']) . ' sq. ft.' : $loc['lotSize'];
}

$logoPath = __DIR__ . '/../assets/images/christyLogo.png';
$logoHtml = file_exists($logoPath)
    ? '<img src="' . htmlspecialchars($logoPath, ENT_QUOTES, 'UTF-8') . '" style="max-height: 48px; width: auto;" alt="Christy Signs" />'
    : '<div style="font-size: 16px; font-weight: bold; color: #14377c;">Christy Signs</div>';

// Extract dynamic analysis structures
$attached = $signCodeAnalysis['attachedSigns'] ?? [];
$detached = $signCodeAnalysis['detachedSigns'] ?? [];

$ordinanceTitle = $signCodeAnalysis['ordinance']['title'] ?? 'Phoenix Zoning Ordinance - Signs';
$ordinanceRef = $signCodeAnalysis['ordinance']['codeReference'] ?? 'ZO Section 705';
$fullOrdinanceStr = $ordinanceTitle . ' (' . $ordinanceRef . ')';

// -------------------------------------------------------------------------
// 5. CSS & HTML Layout
// -------------------------------------------------------------------------
$css = '
    body { font-family: Arial, sans-serif; font-size: 8.5pt; color: #222222; line-height: 1.35; }
    
    .header-table { width: 100%; border-bottom: 2px solid #14377c; padding-bottom: 8px; margin-bottom: 12px; }
    .header-title { font-size: 13pt; font-weight: bold; color: #14377c; text-align: right; }
    .header-subtitle-main { font-size: 9.5pt; font-weight: bold; color: #333; text-align: right; margin-top: 2px; }
    .header-subtitle-sub { font-size: 8.5pt; color: #555; text-align: right; }
    
    .footer-table { width: 100%; border-top: 1px solid #ccc; padding-top: 4px; font-size: 7.5pt; color: #666; }

    .section-block { margin-bottom: 12px; page-break-inside: avoid; }
    .section-heading { font-size: 9.5pt; font-weight: bold; color: #14377c; border-bottom: 1.5px solid #14377c; padding-bottom: 2px; margin-bottom: 5px; }

    .data-table { width: 100%; border-collapse: collapse; margin-bottom: 2px; }
    .data-table th, .data-table td { border: 1px solid #ccc; padding: 4px 6px; font-size: 8pt; vertical-align: top; }
    .data-table th { width: 32%; text-align: left; background-color: #f8f9fa; color: #333; font-weight: bold; }
    .data-table td { width: 68%; background-color: #ffffff; color: #111; }

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
        <td style="width: 45%; vertical-align: bottom;">' . $logoHtml . '</td>
        <td style="width: 55%; text-align: right; vertical-align: bottom;">
            <div class="header-title">Location Zoning &amp; Sign Code Report</div>
            <div class="header-subtitle-main">' . displayValue($loc['locationName']) . '</div>
            <div class="header-subtitle-sub">' . displayValue($fullAddress) . '</div>
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

<!-- 1. Property Overview -->
<div class="section-block">
    <?= buildReportSectionHeading('1. Property Overview') ?>
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
        <tr>
            <th>Report Date</th>
            <td><?= date('F j, Y') ?></td>
        </tr>
    </table>
</div>

<!-- 2. Zoning Summary -->
<div class="section-block">
    <?= buildReportSectionHeading('2. Zoning Summary') ?>
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
            <th>Confidence</th>
            <td><?= displayValue(isset($loc['confidence']) && $loc['confidence'] !== '' ? $loc['confidence'] . '%' : null) ?></td>
        </tr>
        <tr>
            <th>Overlay / Special District</th>
            <td><?= displayValue(null, 'Verification required') ?></td>
        </tr>
    </table>
</div>

<!-- 3. Attached Sign Allowance -->
<div class="section-block">
    <?= buildReportSectionHeading('3. Attached Sign Allowance') ?>
    <table class="data-table">
        <tr>
            <th>Controlling measurement</th>
            <td><?= displayValue($attached['applicableElevation'] ?? null, 'Applicable building or tenant elevation') ?></td>
        </tr>
        <tr>
            <th>Area rate</th>
            <td>1 sq. ft. per 1 linear ft.</td>
        </tr>
        <tr>
            <th>Minimum allowance</th>
            <td>50 sq. ft.</td>
        </tr>
        <tr>
            <th>Maximum cap</th>
            <td>500 sq. ft.</td>
        </tr>
        <tr>
            <th>Maximum height</th>
            <td>25 ft.</td>
        </tr>
        <tr>
            <th>Applicable frontage</th>
            <td>Measurement required</td>
        </tr>
        <tr>
            <th>Existing attached signs</th>
            <td>Inventory required</td>
        </tr>
    </table>
    <?= renderCitation('Phoenix Zoning Ordinance §705.D.1, Table D-1') ?>

    <div class="callout-box">
        <div class="callout-title">Attached-Sign Calculation</div>
        <div class="callout-body">
            <strong>Allowance Formula:</strong> Greater of 50 sq. ft. or elevation frontage × 1 sq. ft./linear ft., not to exceed 500 sq. ft.<br />
            <strong>Current Result:</strong> Cannot be calculated until the applicable elevation and existing signs are measured.
        </div>
    </div>
    
    <div class="note-text">
        <em>Roofline Controls:</em> Top of sign must remain below roofline by at least 1/2 of vertical sign height. Wall projection and overall height are evaluated separately.
        <div class="citation-subtext" style="margin-top: 1px;">Authority: Phoenix Zoning Ordinance §705.D.3.b</div>
    </div>
</div>

<!-- 4. Detached Sign Standards -->
<div class="section-block">
    <?= buildReportSectionHeading('4. Detached Sign Standards') ?>
    <table class="data-table">
        <tr>
            <th>Parcel classification</th>
            <td><?= displayValue($detached['parcelUseType'] ?? null, 'Single-use or multiple-use verification required') ?></td>
        </tr>
        <tr>
            <th>Street frontage</th>
            <td>Measurement required</td>
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
            <th>Detached sign max height</th>
            <td>Pending classification</td>
        </tr>
        <tr>
            <th>Minimum spacing</th>
            <td>100 ft., when applicable</td>
        </tr>
        <tr>
            <th>Existing signs</th>
            <td>Inventory required</td>
        </tr>
    </table>
    <?= renderCitation('Phoenix Zoning Ordinance §705.D.1, Table D-1; §705.D.2') ?>
</div>

<!-- 5. Additional Requirements -->
<div class="section-block">
    <?= buildReportSectionHeading('5. Additional Requirements') ?>
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
            <td>Required for qualifying wall signs over 100 sq. ft.</td>
        </tr>
        <tr>
            <td colspan="2" style="border-top: none; padding-top: 0; padding-bottom: 4px;">
                <?= renderCitation('Phoenix Building Code §105.2') ?>
            </td>
        </tr>
        <tr>
            <th>Illumination</th>
            <td>Residential adjacency and lighting conditions require review</td>
        </tr>
        <tr>
            <td colspan="2" style="border-top: none; padding-top: 0; padding-bottom: 4px;">
                <?= renderCitation('Phoenix Zoning Ordinance §705.E.2') ?>
            </td>
        </tr>
        <tr>
            <th>Overlay or CSP</th>
            <td>Verify whether site-specific criteria modify the base allowance</td>
        </tr>
        <tr>
            <td colspan="2" style="border-top: none; padding-top: 0; padding-bottom: 4px;">
                <?= renderCitation('Phoenix Zoning Ordinance §705.C') ?>
            </td>
        </tr>
        <tr>
            <th>Projection</th>
            <td>Evaluate separately from maximum sign height</td>
        </tr>
        <tr>
            <td colspan="2" style="border-top: none; padding-top: 0; padding-bottom: 4px;">
                <?= renderCitation('Phoenix Zoning Ordinance §705.D.3.a') ?>
            </td>
        </tr>
    </table>
</div>

<!-- 6. Required Field Information & Next Steps -->
<div class="section-block">
    <?= buildReportSectionHeading('6. Required Field Information') ?>
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

<!-- 7. Report Basis & Disclaimers -->
<div class="section-block" style="margin-top: 10px;">
    <?= buildReportSectionHeading('7. Report Basis & Qualifications') ?>
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
        <strong>Sources &amp; Review Qualifications:</strong> Information shown is derived from authoritative zoning ordinance specifications and local parcel records. Regulatory citations indicate primary governing provisions. All sign plans and dimensional calculations must be verified with governing jurisdiction officials prior to fabrication and permit application.
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
        'margin_top'    => 28,
        'margin_bottom' => 12,
        'margin_header' => 6,
        'margin_footer' => 6
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