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

function displayValue(mixed $value, string $fallback = 'Not Yet Verified'): string
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
    return '<div class="citation-tag">Citation: ' . htmlspecialchars($citationText, ENT_QUOTES, 'UTF-8') . '</div>';
}

function buildReportSectionHeading(string $title, string $iconFile): string 
{
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

$logoPath = __DIR__ . '/../assets/images/christyLogo.png';
$logoHtml = file_exists($logoPath)
    ? '<img src="' . htmlspecialchars($logoPath, ENT_QUOTES, 'UTF-8') . '" style="max-height: 48px; width: auto;" alt="Christy Signs" />'
    : '<div style="font-size: 16px; font-weight: bold; color: #14377c;">Christy Signs</div>';

// Extract dynamic analysis structures
$attached = $signCodeAnalysis['attachedSigns'] ?? [];
$detached = $signCodeAnalysis['detachedSigns'] ?? [];

$ordinanceRef = $signCodeAnalysis['ordinance']['title'] ?? null;
if (!empty($signCodeAnalysis['ordinance']['codeReference'])) {
    $ordinanceRef .= ($ordinanceRef ? ' (' : '') . $signCodeAnalysis['ordinance']['codeReference'] . ($ordinanceRef ? ')' : '');
}

// -------------------------------------------------------------------------
// 5. CSS & HTML Layout
// -------------------------------------------------------------------------
$css = '
    body { font-family: Arial, sans-serif; font-size: 8.5pt; color: #222222; line-height: 1.3; }
    .header-table { width: 100%; border-bottom: 2px solid #14377c; padding-bottom: 6px; }
    .header-title { font-size: 12pt; font-weight: bold; color: #14377c; }
    .header-subtitle-main { font-size: 9pt; font-weight: bold; color: #333; }
    .header-subtitle-sub { font-size: 8pt; color: #555; }
    .footer-table { width: 100%; border-top: 1px solid #ccc; padding-top: 4px; font-size: 7.5pt; color: #666; }

    .section-block { page-break-inside: avoid; margin-bottom: 8px; }
    .section-heading-table { width: 100%; border-collapse: collapse; border-bottom: 1px solid #ccc; margin: 4px 0 3px; }
    .section-icon-cell { width: 18px; vertical-align: middle; }
    .section-icon { width: 14px; height: 14px; }
    .section-title-cell { vertical-align: middle; font-size: 9.5pt; font-weight: bold; color: #14377c; }

    .data-table { width: 100%; border-collapse: collapse; margin-bottom: 3px; }
    .data-table th, .data-table td { border: 1px solid #ccc; padding: 3px 5px; font-size: 8pt; vertical-align: top; }
    .data-table th { width: 28%; text-align: left; background-color: #f8f9fa; color: #333; font-weight: bold; }
    .data-table td { width: 72%; background-color: #ffffff; color: #111; }

    .status-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; background-color: #f4f6f9; border: 1px solid #d0d7de; }
    .status-table td { padding: 4px 8px; font-size: 7.5pt; border: 1px solid #d0d7de; }

    .formula-box { background-color: #f8f9fa; border: 1px dashed #14377c; padding: 5px; font-family: monospace; font-size: 7.5pt; margin-top: 3px; }
    .citation-tag { font-size: 7.5pt; font-weight: bold; color: #14377c; margin-top: 2px; }
    .unverified { color: #888; font-style: italic; }
    .analysis-error { color: #9b1c1c; font-weight: bold; }

    .callout-box { background-color: #f0f4f9; border: 1px solid #b8cbe5; border-left: 4px solid #14377c; padding: 6px 8px; margin-top: 4px; }
    .callout-title { font-size: 8.5pt; font-weight: bold; color: #14377c; margin-bottom: 3px; }
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

$targetIconPath = __DIR__ . '/../assets/images/icons/target.png';
$targetIconHtml = file_exists($targetIconPath)
    ? '<img src="' . htmlspecialchars($targetIconPath, ENT_QUOTES, 'UTF-8') . '" class="section-icon" alt="" />'
    : '';

ob_start();
?>

<!-- Metadata & Status Block -->
<table class="status-table">
    <tr>
        <td><strong>Sign Code Jurisdiction:</strong> <?= displayValue($loc['locationJurisdiction']) ?></td>
        <td><strong>Applicable Code:</strong> <?= displayValue($ordinanceRef, 'Phoenix Zoning Ordinance §705') ?></td>
        <td>
            <strong>Analysis Status:</strong> 
            <?php if ($analysisError !== null): ?>
                <span class="analysis-error">Analysis Error</span>
            <?php else: ?>
                <?= displayValue(ucwords(str_replace('_', ' ', (string)($signCodeAnalysis['analysisStatus'] ?? 'complete')))) ?>
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <td colspan="3">
            <strong>Permit & Processing Thresholds:</strong> Sign permit required prior to installation/alteration. Structural engineered plans required for wall signs exceeding 100 sq. ft. Illumination controls apply when adjacent to residential districts.
        </td>
    </tr>
</table>

<!-- 1. Property Overview & Zoning Summary -->
<div class="section-block">
    <?= buildReportSectionHeading('Property Overview & Zoning Details', 'property.png') ?>
    <table class="data-table">
        <tr>
            <th>Location / Customer</th>
            <td><?= displayValue($loc['locationName']) ?> | <?= displayValue($loc['entityName'] ?? null) ?></td>
            <th>Zoning District</th>
            <td><?= displayValue($loc['zoningCode']) ?> (<?= displayValue($loc['zoningDescription']) ?>)</td>
        </tr>
        <tr>
            <th>Address / APN</th>
            <td><?= displayValue($fullAddress) ?> | APN: <?= displayValue($parcelNumber) ?></td>
            <th>Lot Size / Verified</th>
            <td><?= displayValue($loc['lotSize']) ?> sq. ft. | <?= displayValue($verifiedAtFormatted) ?> (<?= displayValue(isset($loc['confidence']) && $loc['confidence'] !== '' ? $loc['confidence'] . '%' : null) ?>)</td>
        </tr>
    </table>
</div>

<!-- 2. Attached Signs Table -->
<div class="section-block">
    <?= buildReportSectionHeading('Attached Sign Design Allowance', 'scroll.png') ?>
    <table class="data-table">
        <tr>
            <th>Sign Type / Allowance Basis</th>
            <td>Wall / Attached Identification Sign</td>
        </tr>
        <tr>
            <th>Area Calculation Rate</th>
            <td>1 sq. ft. per 1 linear foot of applicable building or tenant elevation</td>
        </tr>
        <tr>
            <th>Minimum Allowance Floor</th>
            <td>50 sq. ft. minimum floor</td>
        </tr>
        <tr>
            <th>Maximum Allowance Cap</th>
            <td>500 sq. ft. maximum cap</td>
        </tr>
        <tr>
            <th>Applicable Elevation Frontage</th>
            <td><?= displayValue($attached['applicableElevation'] ?? null, 'Building or tenant elevation where sign will be installed') ?></td>
        </tr>
        <tr>
            <th>Calculated Allowance Breakdown</th>
            <td>
                Applicable elevation frontage: Measurement required<br/>
                Rate: 1 sq. ft. per linear foot<br/>
                Minimum floor: 50 sq. ft. | Maximum cap: 500 sq. ft.<br/>
                <strong>Applicable Maximum Allowance: Pending linear frontage measurement</strong><br/>
                Less existing attached signs: Pending inventory<br/>
                <strong>Remaining Allowance: Pending measurement & inventory</strong>
            </td>
        </tr>
        <tr>
            <th>Governing Formula</th>
            <td>
                <div class="formula-box">
                    Maximum Allowable Area = Greater of 50 sq. ft. OR (Frontage × 1 sq. ft./ft.), capped at 500 sq. ft.
                </div>
            </td>
        </tr>
        <tr>
            <th>Height & Roofline Controls</th>
            <td>
                25 ft. height limit.<br/>
                <em>Roofline Limit:</em> Top of sign must remain below roofline by at least 1/2 of vertical sign height. Projection from wall and overall height are evaluated separately.
            </td>
        </tr>
    </table>
    <?= renderCitation($attached['applicableRules'][0]['citationText'] ?? 'Phoenix Zoning Ordinance §705.D.1, Table D-1') ?>
</div>

<!-- 3. Detached Signs Table -->
<div class="section-block">
    <?= buildReportSectionHeading('Detached Sign Standards', 'scroll.png') ?>
    <table class="data-table">
        <tr>
            <th>Parcel Use Type</th>
            <td><?= displayValue($detached['parcelUseType'] ?? null, 'Single-use vs. multiple-use verification required') ?></td>
        </tr>
        <tr>
            <th>Street Frontage & Classification</th>
            <td><?= displayValue($detached['streetClassification'] ?? null, 'Measurement & classification required (Freeway / High-Volume / Low-Volume)') ?></td>
        </tr>
        <tr>
            <th>Sign Classification & Allowed Count</th>
            <td><?= displayValue($detached['signClassification'] ?? null, 'Primary vs. secondary identification sign classification') ?></td>
        </tr>
        <tr>
            <th>Maximum Area & Height</th>
            <td>Area: Pending street classification | Height: Pending street/sign classification</td>
        </tr>
        <tr>
            <th>Minimum Spacing Requirement</th>
            <td>100 ft. spacing required between detached signs on same parcel</td>
        </tr>
        <tr>
            <th>Existing Detached Inventory</th>
            <td><?= displayValue($detached['existingInventory'] ?? null, 'On-site inventory required') ?></td>
        </tr>
    </table>
    <?= renderCitation('Phoenix Zoning Ordinance §705.D.1, Table D-1; §705.D.2') ?>
</div>

<!-- 4. Recommended Next Steps -->
<div class="section-block">
    <div class="callout-box">
        <div class="callout-title">Recommended Next Steps for Estimating & Planning</div>
        <ol style="margin: 0; padding-left: 18px;">
            <?php if (!empty($signCodeAnalysis['recommendedNextSteps']) && is_array($signCodeAnalysis['recommendedNextSteps'])): ?>
                <?php foreach ($signCodeAnalysis['recommendedNextSteps'] as $step): ?>
                    <li style="margin-bottom: 2px;"><?= htmlspecialchars((string)$step, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            <?php else: ?>
                <li style="margin-bottom: 2px;">Measure applicable elevation linear width where attached signs will be installed.</li>
                <li style="margin-bottom: 2px;">Perform an on-site inventory of all existing attached and detached signage.</li>
                <li style="margin-bottom: 2px;">Confirm street classification and continuous street frontage length.</li>
                <li>Verify whether a Comprehensive Sign Program (CSP) or overlay district applies to this parcel.</li>
            <?php endif; ?>
        </ol>
    </div>
</div>

<!-- 5. Sources and Disclaimers -->
<div class="section-block" style="margin-top: 6px;">
    <p style="font-size: 7.5pt; color: #666666; line-height: 1.25; margin: 0;">
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
        'margin_left'   => 8.5,
        'margin_right'  => 8.5,
        'margin_top'    => 30,
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