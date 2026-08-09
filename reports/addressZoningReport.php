<?php
declare(strict_types=1);

// =============================================
// Skyesoft — addressZoningReport.php
// Address Check Zoning Report (no saved location required)
// Version: 1.0.0
// =============================================

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../api/sessionBootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';

/** Escape a value for HTML output. */
function escapeReportValue(mixed $value): string
{
    return htmlspecialchars(trim((string)($value ?? '')), ENT_QUOTES, 'UTF-8');
}

/** Display a value without turning missing research into a false determination. */
function displayReportValue(mixed $value, string $fallback = 'Not provided by address check'): string
{
    $trimmed = trim((string)($value ?? ''));

    return $trimmed !== ''
        ? escapeReportValue($trimmed)
        : '<span class="unverified">' . escapeReportValue($fallback) . '</span>';
}

/** Read the address-check response from JSON POST, form POST, or a base64url GET value. */
function readAddressCheckPayload(): array
{
    $rawBody = (string)file_get_contents('php://input');
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    $rawPayload = '';

    if ($rawBody !== '' && str_contains($contentType, 'application/json')) {
        $rawPayload = $rawBody;
    } elseif (isset($_POST['payload'])) {
        $rawPayload = (string)$_POST['payload'];
    } elseif (isset($_GET['payload'])) {
        $encoded = strtr((string)$_GET['payload'], '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode($encoded, true);
        $rawPayload = $decoded !== false ? $decoded : '';
    }

    if ($rawPayload === '') {
        throw new InvalidArgumentException('Missing address-check JSON payload.');
    }

    $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($payload)) {
        throw new InvalidArgumentException('The address-check payload must be a JSON object.');
    }

    return $payload;
}

/** Build a branded section heading using the same icon set as the location report. */
function buildAddressSectionHeading(string $title, string $iconFile): string
{
    $iconPath = __DIR__ . '/../assets/images/icons/' . basename($iconFile);
    $iconHtml = file_exists($iconPath)
        ? '<img src="' . escapeReportValue($iconPath) . '" class="section-icon" alt="" />'
        : '';

    return '<div class="section-heading">' . $iconHtml
        . '<span class="section-heading-title">' . escapeReportValue($title) . '</span></div>';
}

/** Format Unix or database timestamps consistently. */
function formatReportTimestamp(mixed $value, bool $includeTime = false): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    $timestamp = is_numeric($value) ? (int)$value : strtotime((string)$value);

    if ($timestamp === false || $timestamp <= 0) {
        return null;
    }

    return date($includeTime ? 'F j, Y g:i A' : 'F j, Y', $timestamp);
}

/** Format a compact validation result. */
function validationResult(bool $valid): string
{
    return $valid
        ? '<span class="status status--resolved">Validated</span>'
        : '<span class="status status--review">Review required</span>';
}

try {
    $payload = readAddressCheckPayload();
} catch (Throwable $e) {
    http_response_code(400);
    echo 'Unable to generate address zoning report: ' . escapeReportValue($e->getMessage());
    exit;
}

$location = $payload['data']['location'] ?? null;
if (!is_array($location)) {
    http_response_code(422);
    die('The address-check response does not contain data.location.');
}

$parcels = is_array($location['parcelDetails'] ?? null) ? $location['parcelDetails'] : [];
$parcel = is_array($parcels[0] ?? null) ? $parcels[0] : [];
$parcelRecord = is_array($parcel['parcelRecord'] ?? null) ? $parcel['parcelRecord'] : [];
$assessor = is_array($parcel['assessor'] ?? null) ? $parcel['assessor'] : [];
$zoning = is_array($parcel['zoning'] ?? null)
    ? $parcel['zoning']
    : (is_array($location['zoning'] ?? null) ? $location['zoning'] : []);
$matchQuality = is_array($location['locationMatchQuality'] ?? null)
    ? $location['locationMatchQuality']
    : [];

$success = (bool)($payload['success'] ?? false);
$resolved = $success
    && ($payload['status'] ?? '') === 'resolved'
    && ($zoning['status'] ?? '') === 'resolved';

if (!$resolved) {
    http_response_code(422);
    die('The address-check response is not fully resolved and cannot support this report.');
}

$fullAddress = trim((string)($location['locationAddress'] ?? $location['locationResolvedAddress'] ?? ''));
$parcelNumber = trim((string)($parcel['parcelNumber'] ?? $parcelRecord['apnRaw'] ?? ''));
$ownerName = trim((string)($parcel['ownerName'] ?? $parcelRecord['ownerName'] ?? ''));
$jurisdiction = trim((string)($location['jurisdictionName'] ?? $parcel['jurisdiction'] ?? ''));
$jurisdictionType = trim((string)($location['jurisdictionType'] ?? ''));
$county = trim((string)($location['locationCounty'] ?? ''));
$zoningCode = trim((string)($zoning['zoningCode'] ?? $parcelRecord['zoningCode'] ?? ''));
$zoningDescription = trim((string)($zoning['zoningDescription'] ?? $parcelRecord['zoningDescription'] ?? ''));
$zoningSource = trim((string)($zoning['zoningSource'] ?? $parcelRecord['zoningSource'] ?? ''));
$zoningVerifiedAt = $zoning['zoningVerifiedAt'] ?? $parcelRecord['zoningVerifiedAt'] ?? null;
$zoningVerifiedFormatted = formatReportTimestamp($zoningVerifiedAt, true);
$confidence = $zoning['confidence'] ?? $parcelRecord['confidence'] ?? null;
$mapUrl = trim((string)($assessor['mapUrl'] ?? ''));
$propertyType = trim((string)($assessor['propertyType'] ?? ''));
$latitude = $location['locationLatitude'] ?? null;
$longitude = $location['locationLongitude'] ?? null;
$placeId = trim((string)($location['locationPlaceId'] ?? ''));
$activitySessionId = trim((string)($payload['activitySessionId'] ?? ''));
$requiresReview = (bool)($zoning['requiresReview'] ?? false);
$partialMatch = (bool)($matchQuality['partialMatch'] ?? false);
$mismatches = is_array($matchQuality['mismatches'] ?? null) ? $matchQuality['mismatches'] : [];
$warnings = is_array($matchQuality['warnings'] ?? null) ? $matchQuality['warnings'] : [];

$safeFileToken = preg_replace('/[^A-Za-z0-9_-]+/', '-', $parcelNumber ?: 'address-check');
$jsonReviewMode = filter_input(INPUT_GET, 'json', FILTER_VALIDATE_BOOLEAN) ?? false;

if ($jsonReviewMode) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: inline; filename="address-zoning-report-' . $safeFileToken . '.json"');
    echo json_encode([
        'reportType' => 'Address Zoning Report',
        'schemaVersion' => '1.0.0',
        'generatedAt' => time(),
        'addressCheck' => $payload,
        'determinations' => [
            'addressValidated' => (bool)($location['locationValidated'] ?? false),
            'countyValidated' => (bool)($location['locationCensusValidated'] ?? false),
            'parcelResolved' => $parcelNumber !== '',
            'baseZoningResolved' => $zoningCode !== '',
            'specialDesignationsEvaluated' => false,
            'streetFrontageEvaluated' => false,
            'signAllowanceCalculated' => false
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$logoPath = __DIR__ . '/../assets/images/christyLogo.png';
$logoHtml = file_exists($logoPath)
    ? '<img src="' . escapeReportValue($logoPath) . '" class="header-logo" alt="Christy Signs" />'
    : '<div class="logo-fallback">Christy Signs</div>';

$css = '
    body { font-family: Arial, sans-serif; font-size: 8.5pt; color: #222; line-height: 1.35; }

    /* Report header */
    .header-table {
        width: 100%;
        border-collapse: collapse;
        border-bottom: 2px solid #14377c;
    }

    .header-table td {
        padding: 0 0 3px;
        vertical-align: bottom;
    }

    .header-logo {
        display: block;
        width: auto;
        height: 58px;
    }

    .logo-fallback {
        color: #14377c;
        font-size: 16px;
        font-weight: bold;
    }

    /* Right-side report details */
    .header-report-details {
        display: block;
        width: 100%;
        text-align: right;
    }

    .header-title,
    .header-subtitle-main,
    .header-subtitle-sub,
    .header-report-date {
        display: block;
        width: 100%;
        text-align: right;
    }

    .header-title {
        margin: 0;
        color: #14377c;
        font-size: 13pt;
        font-weight: bold;
        line-height: 1.05;
    }

    .header-subtitle-main {
        margin: 2px 0 0;
        color: #333;
        font-size: 9.5pt;
        font-weight: bold;
        line-height: 1.15;
    }

    .header-subtitle-sub {
        margin: 1px 0 0;
        color: #555;
        font-size: 8.5pt;
        line-height: 1.15;
    }

    .header-report-date {
        margin: 1px 0 0;
        color: #666;
        font-size: 7.5pt;
        line-height: 1.15;
    }

    /* Report footer */
    .footer-table { width: 100%; border-top: 1px solid #ccc; padding-top: 4px; font-size: 7.5pt; color: #666; }

    /* Report sections */
    .section-block { margin-bottom: 11px; page-break-inside: avoid; }
    .section-heading { font-size: 9.5pt; font-weight: bold; color: #14377c; border-bottom: 1.5px solid #14377c; padding-bottom: 2px; margin-bottom: 5px; }
    .section-icon { display: inline-block; width: 14px; height: 14px; margin-right: 5px; vertical-align: -2px; }
    .section-heading-title { display: inline-block; vertical-align: middle; }

    /* Data tables */
    .data-table { width: 100%; border-collapse: collapse; margin-bottom: 2px; }
    .data-table th,
    .data-table td { border: 1px solid #ccc; padding: 4px 6px; font-size: 8pt; vertical-align: top; }
    .data-table th { width: 32%; text-align: left; background: #f8f9fa; color: #333; }
    .data-table td { width: 68%; background: #fff; color: #111; }
    .two-column-table { width: 100%; border-collapse: separate; border-spacing: 5px 0; margin-left: -5px; margin-right: -5px; }
    .two-column-table > tbody > tr > td { width: 50%; vertical-align: top; }

    /* Status indicators */
    .status { display: inline-block; padding: 2px 7px; border-radius: 3px; font-size: 7.5pt; font-weight: bold; }
    .status--resolved { color: #176638; background: #eaf7ef; border: 1px solid #9fd0ae; }
    .status--review { color: #8a5a00; background: #fff5dc; border: 1px solid #e8c46e; }

    /* Report notes */
    .callout-box { background: #f0f4f9; border: 1px solid #b8cbe5; border-left: 4px solid #14377c; padding: 6px 9px; margin: 5px 0; }
    .callout-title { font-size: 8.5pt; font-weight: bold; color: #14377c; margin-bottom: 3px; }
    .callout-body { font-size: 7.8pt; line-height: 1.3; }
    .compact-list { margin: 2px 0; padding-left: 17px; font-size: 7.8pt; }
    .compact-list li { margin-bottom: 2px; }
    .citation-subtext { margin-top: 2px; color: #4a607a; font-size: 7pt; font-style: italic; }
    .unverified { color: #777; font-style: italic; }

    /* Research basis */
    .basis-table { width: 100%; border-collapse: collapse; }
    .basis-table td { width: 50%; border: 1px solid #d5d5d5; padding: 4px 6px; font-size: 7.6pt; }
';

$headerHtml = '
<table class="header-table">
    <tr>
        <td
            width="32%"
            style="width: 32%; vertical-align: bottom;"
        >
            ' . $logoHtml . '
        </td>

        <td
            width="68%"
            align="right"
            style="width: 68%; vertical-align: bottom; text-align: right;"
        >
            <div class="header-report-details">
                <div class="header-title">Address Zoning Report</div>
                <div class="header-subtitle-main">Address Check</div>
                <div class="header-subtitle-sub">
                    ' . escapeReportValue($fullAddress) . '
                </div>
                <div class="header-report-date">
                    Report Date: ' . date('F j, Y') . '
                </div>
            </div>
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
<div class="section-block">
    <?= buildAddressSectionHeading('Property Overview', 'property.png') ?>
    <table class="data-table">
        <tr><th>Address</th><td><?= displayReportValue($fullAddress) ?></td></tr>
        <tr><th>APN</th><td><?= displayReportValue($parcelNumber) ?></td></tr>
        <tr><th>Property Owner</th><td><?= displayReportValue($ownerName) ?></td></tr>
        <tr><th>Property Type</th><td><?= displayReportValue($propertyType) ?></td></tr>
        <tr><th>Jurisdiction</th><td><?= displayReportValue($jurisdiction) ?><?= $jurisdictionType !== '' ? ' (' . escapeReportValue($jurisdictionType) . ')' : '' ?></td></tr>
        <tr><th>County</th><td><?= displayReportValue($county) ?></td></tr>
        <tr><th>Parcel Map</th><td><?= $mapUrl !== '' ? '<a href="' . escapeReportValue($mapUrl) . '">Maricopa County Assessor Map</a>' : displayReportValue(null) ?></td></tr>
    </table>
</div>

<div class="section-block">
    <?= buildAddressSectionHeading('Zoning Determination', 'temple.png') ?>
    <table class="data-table">
        <tr><th>Base Zoning District</th><td><strong><?= displayReportValue($zoningCode) ?></strong></td></tr>
        <tr><th>Description</th><td><?= displayReportValue($zoningDescription) ?></td></tr>
        <tr><th>Status</th><td><?= validationResult(!$requiresReview) ?></td></tr>
        <tr><th>Confidence</th><td><?= $confidence !== null ? escapeReportValue($confidence) . '%' : displayReportValue(null) ?></td></tr>
        <tr><th>Zoning Source</th><td><?= displayReportValue($zoningSource) ?></td></tr>
        <tr><th>Verified</th><td><?= displayReportValue($zoningVerifiedFormatted) ?></td></tr>
    </table>
    <div class="callout-box">
        <div class="callout-title">Resolved Result</div>
        <div class="callout-body">
            The address check positively resolved the parcel to <strong><?= escapeReportValue($zoningCode) ?></strong>
            <?= $zoningDescription !== '' ? '(' . escapeReportValue($zoningDescription) . ')' : '' ?>.
            The zoning engine returned <?= escapeReportValue((string)($confidence ?? 'an unspecified')) ?>% confidence
            and <?= $requiresReview ? 'flagged the base-zoning result for review.' : 'did not flag the base-zoning result for manual review.' ?>
        </div>
    </div>
</div>

<div class="section-block">
    <?= buildAddressSectionHeading('Address & Source Validation', 'clipboard.png') ?>
    <table class="two-column-table"><tr><td>
        <table class="data-table">
            <tr><th>Address</th><td><?= validationResult((bool)($location['locationValidated'] ?? false)) ?></td></tr>
            <tr><th>County</th><td><?= validationResult((bool)($location['locationCensusValidated'] ?? false)) ?></td></tr>
            <tr><th>Parcel</th><td><?= validationResult($parcelNumber !== '') ?></td></tr>
            <tr><th>Base Zoning</th><td><?= validationResult($zoningCode !== '') ?></td></tr>
        </table>
    </td><td>
        <table class="data-table">
            <tr><th>Match Type</th><td><?= displayReportValue($matchQuality['locationType'] ?? null) ?></td></tr>
            <tr><th>Partial Match</th><td><?= $partialMatch ? 'Yes — review required' : 'No' ?></td></tr>
            <tr><th>Mismatches</th><td><?= $mismatches === [] ? 'None' : displayReportValue(implode('; ', array_map('strval', $mismatches))) ?></td></tr>
            <tr><th>Warnings</th><td><?= $warnings === [] ? 'None' : displayReportValue(implode('; ', array_map('strval', $warnings))) ?></td></tr>
        </table>
    </td></tr></table>
</div>

<div class="section-block">
    <?= buildAddressSectionHeading('Sign-Code Research Status', 'ruler.png') ?>
    <table class="data-table">
        <tr><th>Base zoning</th><td><strong>Resolved</strong> — <?= displayReportValue($zoningCode) ?></td></tr>
        <tr><th>Overlay / Regulatory Plan</th><td><span class="unverified">Not included in the address-check response</span></td></tr>
        <tr><th>Historic Designation</th><td><span class="unverified">Not included in the address-check response</span></td></tr>
        <tr><th>Comprehensive Sign Plan</th><td><span class="unverified">Not included in the address-check response</span></td></tr>
        <tr><th>Attached-sign allowance</th><td><span class="unverified">Requires building or tenant elevation width and existing-sign inventory</span></td></tr>
        <tr><th>Detached-sign allowance</th><td><span class="unverified">Requires frontage, street classification, parcel-use classification, and existing-sign inventory</span></td></tr>
    </table>
</div>

<div class="section-block">
    <?= buildAddressSectionHeading('Required Research & Next Steps', 'workman.png') ?>
    <ol class="compact-list">
        <li>Resolve overlay, historic-property, and Comprehensive Sign Plan determinations for the parcel.</li>
        <li>Confirm the applicable sign-code standards for the resolved zoning district and parcel use.</li>
        <li>Measure the building or tenant elevation and document all existing attached signs.</li>
        <li>Resolve parcel frontage and street classification; inventory all existing detached signs.</li>
        <li>Confirm proposed sign dimensions, height, placement, construction, and illumination before permit preparation.</li>
    </ol>
</div>

<div class="section-block">
    <?= buildAddressSectionHeading('Report Basis & Qualifications', 'scroll.png') ?>
    <table class="basis-table">
        <tr><td><strong>Report Type:</strong> Unsaved Address Check</td><td><strong>Result:</strong> Base zoning resolved</td></tr>
        <tr><td><strong>Place ID:</strong> <?= displayReportValue($placeId) ?></td><td><strong>Coordinates:</strong> <?= displayReportValue($latitude) ?>, <?= displayReportValue($longitude) ?></td></tr>
        <tr><td><strong>Activity Session:</strong> <?= displayReportValue($activitySessionId) ?></td><td><strong>Parcel Source:</strong> <?= displayReportValue($parcelRecord['source'] ?? $parcel['source'] ?? null) ?></td></tr>
    </table>
    <p style="font-size: 7.5pt; color: #666; line-height: 1.25; margin-top: 6px;">
        <strong>Qualification:</strong> This report records the positive address, parcel, jurisdiction, and base-zoning results returned by Skyesoft's address-check workflow. It does not represent a saved Skyesoft location or a complete sign-allowance analysis. Base zoning may be modified by overlays, stipulations, approved plans, special districts, a Comprehensive Sign Plan, or nonconforming conditions. Verify remaining site conditions and final requirements with the governing jurisdiction before design completion, fabrication, or permit filing.
    </p>
</div>
<?php
$html = ob_get_clean();

try {
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'Letter',
        'margin_left' => 10,
        'margin_right' => 10,
        'margin_top' => 27,
        'margin_bottom' => 18,
        'margin_header' => 6,
        'margin_footer' => 7
    ]);

    $mpdf->SetTitle('Address Zoning Report - ' . ($fullAddress ?: $parcelNumber));
    $mpdf->SetAuthor('Steve Skye');
    $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->SetHTMLHeader($headerHtml);
    $mpdf->SetHTMLFooter($footerHtml);
    $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);
    $mpdf->Output('Address_Zoning_Report_' . $safeFileToken . '.pdf', \Mpdf\Output\Destination::INLINE);
} catch (\Mpdf\MpdfException $e) {
    http_response_code(500);
    echo 'Error generating PDF report: ' . escapeReportValue($e->getMessage());
}