<?php
declare(strict_types=1);

// =============================================
// Skyesoft — addressZoningReport.php
// Address Check Zoning Report (no saved location required)
// Version: 1.0.2
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

/** Format Unix or database timestamps in Phoenix local time. */
function formatReportTimestamp(mixed $value, bool $includeTime = false): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    try {
        $phoenixTimeZone = new DateTimeZone('America/Phoenix');

        if (is_numeric($value)) {
            $dateTime = new DateTimeImmutable('@' . (int)$value);
            $dateTime = $dateTime->setTimezone($phoenixTimeZone);
        } else {
            $dateTime = new DateTimeImmutable((string)$value, $phoenixTimeZone);
        }

        return $dateTime->format(
            $includeTime
                ? 'F j, Y g:i A'
                : 'F j, Y'
        );
    } catch (Throwable $e) {
        return null;
    }
}

$phoenixNow = new DateTimeImmutable(
    'now',
    new DateTimeZone('America/Phoenix')
);

$reportDateFormatted = $phoenixNow->format('F j, Y');

/** Format a compact validation result. */
function validationResult(bool $valid): string
{
    return $valid
        ? '<span class="status status--resolved">Validated</span>'
        : '<span class="status status--review">Review required</span>';
}


// Read address-check payload
try {
    $payload = readAddressCheckPayload();
} catch (Throwable $e) {
    http_response_code(400);

    echo 'Unable to generate address zoning report: '
        . escapeReportValue($e->getMessage());

    exit;
}


// Get resolved location
$location = $payload['data']['location'] ?? null;

if (!is_array($location)) {
    http_response_code(422);
    die('The address-check response does not contain data.location.');
}


// Get primary parcel records
$parcels = is_array($location['parcelDetails'] ?? null)
    ? $location['parcelDetails']
    : [];

$parcel = is_array($parcels[0] ?? null)
    ? $parcels[0]
    : [];

$parcelRecord = is_array($parcel['parcelRecord'] ?? null)
    ? $parcel['parcelRecord']
    : [];

$assessor = is_array($parcel['assessor'] ?? null)
    ? $parcel['assessor']
    : [];

$zoning = is_array($parcel['zoning'] ?? null)
    ? $parcel['zoning']
    : (
        is_array($location['zoning'] ?? null)
            ? $location['zoning']
            : []
    );

// Extract frontages
$frontages = is_array($parcel['frontages'] ?? null)
    ? $parcel['frontages']
    : [];

$parcelGeometry = is_array($parcel['parcelGeometry'] ?? null)
    ? $parcel['parcelGeometry']
    : [];

$parcelMapAddress = trim((string)(
    $location['locationAddress']
    ?? $location['locationResolvedAddress']
    ?? ''
));

$parcelMapSvg = buildParcelSvg(
    $parcelGeometry,
    $frontages,
    $parcelMapAddress
);


// Confirm report eligibility
$success = (bool)($payload['success'] ?? false);

$resolved = $success
    && ($payload['status'] ?? '') === 'resolved'
    && ($zoning['status'] ?? '') === 'resolved';

if (!$resolved) {
    http_response_code(422);
    die(
        'The address-check response is not fully resolved '
        . 'and cannot support this report.'
    );
}


// Prepare report values
$fullAddress = trim((string)(
    $location['locationAddress']
    ?? $location['locationResolvedAddress']
    ?? ''
));

$parcelNumber = trim((string)(
    $parcel['parcelNumber']
    ?? $parcelRecord['apnRaw']
    ?? ''
));

$ownerName = trim((string)(
    $parcel['ownerName']
    ?? $parcelRecord['ownerName']
    ?? ''
));

$jurisdiction = trim((string)(
    $location['jurisdictionName']
    ?? $parcel['jurisdiction']
    ?? ''
));

$jurisdictionType = trim((string)(
    $location['jurisdictionType']
    ?? ''
));

$county = trim((string)(
    $location['locationCounty']
    ?? ''
));

$propertyType = trim((string)(
    $assessor['propertyType']
    ?? ''
));

$zoningCode = trim((string)(
    $zoning['zoningCode']
    ?? $parcelRecord['zoningCode']
    ?? ''
));

$zoningDescription = trim((string)(
    $zoning['zoningDescription']
    ?? $parcelRecord['zoningDescription']
    ?? ''
));

$zoningSource = trim((string)(
    $zoning['zoningSource']
    ?? $parcelRecord['zoningSource']
    ?? ''
));

$zoningVerifiedAt = $zoning['zoningVerifiedAt']
    ?? $parcelRecord['zoningVerifiedAt']
    ?? null;

$zoningVerifiedFormatted = formatReportTimestamp(
    $zoningVerifiedAt,
    true
);

$requiresReview = (bool)(
    $zoning['requiresReview']
    ?? false
);

$latitude = $location['locationLatitude'] ?? null;
$longitude = $location['locationLongitude'] ?? null;

$placeId = trim((string)(
    $location['locationPlaceId']
    ?? ''
));

$activitySessionId = trim((string)(
    $payload['activitySessionId']
    ?? ''
));


// Prepare output options
$safeFileToken = preg_replace(
    '/[^A-Za-z0-9_-]+/',
    '-',
    $parcelNumber !== ''
        ? $parcelNumber
        : 'address-check'
);

$jsonReviewMode = filter_input(
    INPUT_GET,
    'json',
    FILTER_VALIDATE_BOOLEAN
) ?? false;


// Return report audit JSON
if ($jsonReviewMode) {
    header('Content-Type: application/json; charset=utf-8');
    header(
        'Content-Disposition: inline; '
        . 'filename="address-zoning-report-'
        . $safeFileToken
        . '.json"'
    );

    echo json_encode([
        'reportType' => 'Address Zoning Report',
        'schemaVersion' => '1.0.0',
        'generatedAt' => time(),
        'addressCheck' => $payload,
        'determinations' => [
            'addressValidated' =>
                (bool)($location['locationValidated'] ?? false),

            'countyValidated' =>
                (bool)($location['locationCensusValidated'] ?? false),

            'parcelResolved' =>
                $parcelNumber !== '',

            'baseZoningResolved' =>
                $zoningCode !== '' && !$requiresReview,

            'specialDesignationsEvaluated' =>
                false,

            'streetFrontageEvaluated' =>
                !empty($frontages), // Updated to reflect frontage evaluation

            'signAllowanceCalculated' =>
                false
        ]
    ], JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

$detachedSignAllowanceDisplay = !empty($frontages)
    ? '<span class="unverified">Pending calculation - frontage and roadway classifications resolved; parcel-use classification and existing detached-sign inventory still required</span>'
    : '<span class="unverified">Requires frontage, street classification, parcel-use classification, and existing-sign inventory</span>';


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

    /* Address site details */
    /* Keep the complete parcel section together wherever it fits. */
    .site-details-block {
        page-break-before: auto;
        page-break-inside: avoid;
    }
    .parcel-map-frame { border: 1px solid #c4ceda; background: #f7f9fc; padding: 4px; text-align: center; }
    .parcel-map-frame svg { display: block; width: 100%; height: 225px; }
    .parcel-map-unavailable { height: 95px; padding-top: 70px; color: #777; font-style: italic; text-align: center; }
    .frontage-table th { width: auto; background: #eef2f7; color: #26384d; }
    .frontage-table td { width: auto; }
    .frontage-high { color: #a82f26; font-weight: bold; }
    .frontage-low { color: #17698d; font-weight: bold; }
    .map-legend { margin-top: 3px; color: #5a6571; font-size: 6.8pt; text-align: right; }
    /* Zoning verification details */
    .verification-details {
        display: inline-block;
        margin-left: 8px;
        color: #444;
        font-size: 7.8pt;
        line-height: 1.3;
        vertical-align: middle;
    }
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
                    Report Date: ' . $reportDateFormatted . '
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
    </table>
</div>

<div class="section-block">
    <?= buildAddressSectionHeading('Zoning Determination', 'temple.png') ?>
    <table class="data-table">
        <tr><th>Base Zoning District</th><td><strong><?= displayReportValue($zoningCode) ?></strong></td></tr>
        <tr><th>Description</th><td><?= displayReportValue($zoningDescription) ?></td></tr>
        <tr>
            <th>Verification</th>
            <td>
                <?= validationResult(!$requiresReview) ?>
                <div class="verification-details">
                    <strong>Source:</strong> <?= displayReportValue($zoningSource) ?><br>
                    <strong>Verified:</strong> <?= displayReportValue($zoningVerifiedFormatted) ?>
                </div>
            </td>
        </tr>
    </table>
    <div class="callout-box">
        <div class="callout-title">Resolved Result</div>
        <div class="callout-body">
            The address check positively resolved the parcel to <strong><?= escapeReportValue($zoningCode) ?></strong>
            <?= $zoningDescription !== '' ? '(' . escapeReportValue($zoningDescription) . ')' : '' ?>.
            The zoning engine
            <?= $requiresReview
                ? 'flagged the base-zoning determination for manual review.'
                : 'validated the base-zoning determination without requiring manual review.' ?>
        </div>
    </div>
</div>

<div class="section-block site-details-block">
    <?= buildAddressSectionHeading('Address Site Details', 'map.png') ?>

    <div class="parcel-map-frame">
        <?= $parcelMapSvg ?>
    </div>
    <div class="map-legend">
        Red = high-volume roadway frontage | Blue = low-volume roadway frontage
    </div>

    <table class="data-table frontage-table" style="margin-top: 5px;">
        <thead>
            <tr>
                <th>Street</th>
                <th>Frontage</th>
                <th>Classification</th>
                <th>Roadway Category</th>
                <th>Verification</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($frontages !== []): ?>
                <?php foreach ($frontages as $frontage): ?>
                    <?php
                    $rawFeet = $frontage['frontageLengthFeet']
                        ?? $frontage['frontageFeet']
                        ?? $frontage['frontage']
                        ?? null;
                    $roadClass = trim((string)(
                        $frontage['roadClass']
                        ?? $frontage['streetClassification']
                        ?? ''
                    ));
                    $roadClassCode = trim((string)(
                        $frontage['streetClassCode']
                        ?? $frontage['roadClassCode']
                        ?? ''
                    ));
                    $roadTier = trim((string)(
                        $frontage['trafficVolume']
                        ?? $frontage['roadTier']
                        ?? ''
                    ));
                    $roadTierKey = strtolower($roadTier);
                    $roadTierDisplay = match ($roadTierKey) {
                        'highvolume' => 'High volume',
                        'lowvolume' => 'Low volume',
                        default => $roadTier !== '' ? $roadTier : 'Not classified'
                    };
                    $verification = trim((string)(
                        $frontage['verificationStatus']
                        ?? 'GIS calculated'
                    ));
                    $verificationDisplay = ucwords(str_replace('_', ' ', $verification));
                    ?>
                    <tr>
                        <td><strong><?= displayReportValue($frontage['streetName'] ?? null, 'Unknown street') ?></strong></td>
                        <td><?= is_numeric($rawFeet) ? number_format((float)$rawFeet, 1) . ' ft' : displayReportValue($rawFeet, 'Unavailable') ?></td>
                        <td>
                            <?= displayReportValue($roadClass, 'Not classified') ?>
                            <?= $roadClassCode !== '' ? ' (' . escapeReportValue($roadClassCode) . ')' : '' ?>
                        </td>
                        <td class="<?= $roadTierKey === 'highvolume' ? 'frontage-high' : 'frontage-low' ?>">
                            <?= escapeReportValue($roadTierDisplay) ?>
                        </td>
                        <td><?= escapeReportValue($verificationDisplay) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5"><span class="unverified">No parcel frontage details were returned.</span></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="section-block">
    <?= buildAddressSectionHeading('Sign-Code Research Status', 'ruler.png') ?>
    <table class="data-table">
        <tr><th>Base zoning</th><td><strong>Resolved</strong> — <?= displayReportValue($zoningCode) ?></td></tr>
        <tr><th>Overlay / Regulatory Plan</th><td><span class="unverified">Not included in the address-check response</span></td></tr>
        <tr><th>Historic Designation</th><td><span class="unverified">Not included in the address-check response</span></td></tr>
        <tr><th>Comprehensive Sign Plan</th><td><span class="unverified">Not included in the address-check response</span></td></tr>
        <tr><th>Attached-sign allowance</th><td><span class="unverified">Requires building or tenant elevation width and existing-sign inventory</span></td></tr>
        <tr><th>Detached-sign allowance</th><td><?= $detachedSignAllowanceDisplay ?></td></tr>
    </table>
</div>

<div class="section-block">
    <?= buildAddressSectionHeading('Required Research & Next Steps', 'workman.png') ?>
    <ol class="compact-list">
        <li>Resolve overlay, historic-property, and Comprehensive Sign Plan determinations for the parcel.</li>
        <li>Confirm the applicable sign-code standards for the resolved zoning district and parcel use.</li>
        <li>Measure the building or tenant elevation and document all existing attached signs.</li>
        <?php if (!empty($frontages)): ?>
            <li>Inventory all existing detached signs and calculate the remaining allowance for each eligible frontage.</li>
        <?php else: ?>
            <li>Resolve parcel frontage and street classification; inventory all existing detached signs.</li>
        <?php endif; ?>
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

/** Normalize a two-coordinate GIS point. */
function normalizeParcelPoint(mixed $point): ?array
{
    if (!is_array($point) || !isset($point[0], $point[1])
        || !is_numeric($point[0]) || !is_numeric($point[1])) {
        return null;
    }

    return [(float)$point[0], (float)$point[1]];
}

/** Return valid parcel polygon rings from the address-check payload. */
function normalizeParcelRings(array $geometry): array
{
    $rings = is_array($geometry['rings'] ?? null)
        ? $geometry['rings']
        : [];
    $normalized = [];

    foreach ($rings as $ring) {
        if (!is_array($ring)) {
            continue;
        }

        $points = [];
        foreach ($ring as $point) {
            $normalizedPoint = normalizeParcelPoint($point);
            if ($normalizedPoint !== null) {
                $points[] = $normalizedPoint;
            }
        }

        if (count($points) >= 3) {
            $normalized[] = $points;
        }
    }

    return $normalized;
}

/** Calculate polygon metrics in the WKID 2223 coordinate system (feet). */
function calculateParcelMetrics(array $geometry, array $frontages): array
{
    $rings = normalizeParcelRings($geometry);
    $xs = [];
    $ys = [];
    $signedAreas = [];

    foreach ($rings as $ring) {
        $twiceArea = 0.0;
        $pointCount = count($ring);

        foreach ($ring as $index => $point) {
            $nextPoint = $ring[($index + 1) % $pointCount];
            $xs[] = $point[0];
            $ys[] = $point[1];
            $twiceArea += ($point[0] * $nextPoint[1])
                - ($nextPoint[0] * $point[1]);
        }

        $signedAreas[] = $twiceArea / 2;
    }

    $areaSquareFeet = 0.0;
    if ($signedAreas !== []) {
        $largestArea = max(array_map('abs', $signedAreas));
        $areaSquareFeet = $largestArea;

        foreach ($signedAreas as $signedArea) {
            if (abs($signedArea) !== $largestArea) {
                $areaSquareFeet -= abs($signedArea);
            }
        }
    }

    $frontageCount = count($frontages);
    $configuration = match (true) {
        $frontageCount <= 0 => 'Not determined',
        $frontageCount === 1 => 'Interior parcel',
        $frontageCount === 2 => 'Two-frontage parcel',
        default => 'Multi-frontage parcel'
    };

    return [
        'rings' => $rings,
        'widthFeet' => $xs !== [] ? max($xs) - min($xs) : null,
        'depthFeet' => $ys !== [] ? max($ys) - min($ys) : null,
        'areaSquareFeet' => $areaSquareFeet > 0 ? $areaSquareFeet : null,
        'areaAcres' => $areaSquareFeet > 0 ? $areaSquareFeet / 43560 : null,
        'frontageCount' => $frontageCount,
        'configuration' => $configuration
    ];
}

/** Keep rotated SVG labels upright and readable. */
function normalizeSvgLabelAngle(float $angle): float
{
    while ($angle > 90) {
        $angle -= 180;
    }
    while ($angle < -90) {
        $angle += 180;
    }

    return $angle;
}

/** Build an mPDF-compatible parcel SVG from GIS rings and matched frontage segments. */
function buildParcelSvg(array $geometry, array $frontages, string $address): string
{
    $rings = normalizeParcelRings($geometry);
    if ($rings === []) {
        return '<div class="parcel-map-unavailable">Parcel geometry was not included in the address-check response.</div>';
    }

    $xs = [];
    $ys = [];
    foreach ($rings as $ring) {
        foreach ($ring as $point) {
            $xs[] = $point[0];
            $ys[] = $point[1];
        }
    }

    $xmin = min($xs);
    $xmax = max($xs);
    $ymin = min($ys);
    $ymax = max($ys);
    $mapWidth = 720.0;
    $mapHeight = 285.0;
    $padding = 48.0;
    $coordinateWidth = max(1.0, $xmax - $xmin);
    $coordinateHeight = max(1.0, $ymax - $ymin);
    $scale = min(
        ($mapWidth - ($padding * 2)) / $coordinateWidth,
        ($mapHeight - ($padding * 2)) / $coordinateHeight
    );
    $offsetX = ($mapWidth - ($coordinateWidth * $scale)) / 2;
    $offsetY = ($mapHeight - ($coordinateHeight * $scale)) / 2;

    $projectPoint = static function (array $point) use (
        $xmin,
        $ymax,
        $scale,
        $offsetX,
        $offsetY
    ): array {
        return [
            $offsetX + (($point[0] - $xmin) * $scale),
            $offsetY + (($ymax - $point[1]) * $scale)
        ];
    };

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="720" height="285" viewBox="0 0 720 285">'
        . '<rect x="0" y="0" width="720" height="285" fill="#f7f9fc" />';

    foreach ($rings as $ring) {
        $path = [];
        foreach ($ring as $point) {
            [$x, $y] = $projectPoint($point);
            $path[] = round($x, 2) . ',' . round($y, 2);
        }
        $svg .= '<polygon points="' . implode(' ', $path)
            . '" fill="#e9eef6" fill-opacity="0.9" stroke="#334b68" stroke-width="2" />';
    }

    // Use the projected parcel center to place dimensions toward the interior.
    $parcelCenter = $projectPoint([
        ($xmin + $xmax) / 2,
        ($ymin + $ymax) / 2
    ]);

    // Dimension every individual property-line segment inside the parcel.
    foreach ($rings as $ring) {
        $pointCount = count($ring);
        $edgeCount = $pointCount;
        if ($pointCount > 1 && $ring[0] === $ring[$pointCount - 1]) {
            $edgeCount--;
        }

        for ($index = 0; $index < $edgeCount; $index++) {
            $nextIndex = ($index + 1) % $edgeCount;
            $start = $ring[$index];
            $end = $ring[$nextIndex];
            [$x1, $y1] = $projectPoint($start);
            [$x2, $y2] = $projectPoint($end);
            $midX = ($x1 + $x2) / 2;
            $midY = ($y1 + $y2) / 2;
            $towardCenterX = $parcelCenter[0] - $midX;
            $towardCenterY = $parcelCenter[1] - $midY;
            $centerDistance = max(1.0, hypot($towardCenterX, $towardCenterY));
            $labelX = $midX + (($towardCenterX / $centerDistance) * 9);
            $labelY = $midY + (($towardCenterY / $centerDistance) * 9);
            $angle = normalizeSvgLabelAngle(rad2deg(atan2($y2 - $y1, $x2 - $x1)));
            $lengthFeet = hypot($end[0] - $start[0], $end[1] - $start[1]);

            if ($lengthFeet < 0.1) {
                continue;
            }

            $svg .= '<text x="' . round($labelX, 2) . '" y="' . round($labelY, 2)
                . '" text-anchor="middle" dominant-baseline="middle"'
                . ' transform="rotate(' . round($angle, 2) . ' ' . round($labelX, 2) . ' ' . round($labelY, 2) . ')"'
                . ' font-family="Arial, sans-serif" font-size="8.5" font-weight="bold" fill="#26384d"'
                . ' stroke="#e9eef6" stroke-width="2.5" paint-order="stroke">'
                . escapeReportValue(number_format($lengthFeet, 1) . ' ft') . '</text>';
        }
    }

    foreach ($frontages as $frontage) {
        $segments = is_array($frontage['parcelSegments'] ?? null)
            ? $frontage['parcelSegments']
            : [];
        $tier = strtolower(trim((string)(
            $frontage['trafficVolume']
            ?? $frontage['roadTier']
            ?? ''
        )));
        $color = $tier === 'highvolume' ? '#c63f32' : '#1976a3';

        foreach ($segments as $segment) {
            $start = normalizeParcelPoint($segment['start'] ?? null);
            $end = normalizeParcelPoint($segment['end'] ?? null);
            if ($start === null || $end === null) {
                continue;
            }

            [$x1, $y1] = $projectPoint($start);
            [$x2, $y2] = $projectPoint($end);
            $svg .= '<line x1="' . round($x1, 2) . '" y1="' . round($y1, 2)
                . '" x2="' . round($x2, 2) . '" y2="' . round($y2, 2)
                . '" stroke="' . $color . '" stroke-width="5" stroke-linecap="round" />';
        }
    }

    // Place each street name outside and parallel to its longest frontage segment.
    foreach ($frontages as $frontage) {
        $segments = is_array($frontage['parcelSegments'] ?? null)
            ? $frontage['parcelSegments']
            : [];
        $longest = null;
        $longestLength = -1.0;
        foreach ($segments as $segment) {
            $start = normalizeParcelPoint($segment['start'] ?? null);
            $end = normalizeParcelPoint($segment['end'] ?? null);
            if ($start === null || $end === null) {
                continue;
            }
            $segmentLength = hypot($end[0] - $start[0], $end[1] - $start[1]);
            if ($segmentLength > $longestLength) {
                $longestLength = $segmentLength;
                $longest = [$start, $end];
            }
        }
        if ($longest === null) {
            continue;
        }

        [$x1, $y1] = $projectPoint($longest[0]);
        [$x2, $y2] = $projectPoint($longest[1]);
        $midX = ($x1 + $x2) / 2;
        $midY = ($y1 + $y2) / 2;
        $awayX = $midX - $parcelCenter[0];
        $awayY = $midY - $parcelCenter[1];
        $awayDistance = max(1.0, hypot($awayX, $awayY));
        $labelX = $midX + (($awayX / $awayDistance) * 16);
        $labelY = $midY + (($awayY / $awayDistance) * 16);
        $angle = normalizeSvgLabelAngle(rad2deg(atan2($y2 - $y1, $x2 - $x1)));

        $svg .= '<text x="' . round($labelX, 2) . '" y="' . round($labelY, 2)
            . '" text-anchor="middle" dominant-baseline="middle"'
            . ' transform="rotate(' . round($angle, 2) . ' ' . round($labelX, 2) . ' ' . round($labelY, 2) . ')"'
            . ' font-family="Arial, sans-serif" font-size="10" font-weight="bold" fill="#17283d">'
            . escapeReportValue($frontage['streetName'] ?? 'Unknown street') . '</text>';
    }

    // Center the resolved address inside the parcel, wrapping only when needed.
    $addressText = escapeReportValue($address);
    if ($addressText !== '') {
        $addressLines = [$addressText];
        if (strlen($address) > 48) {
            $breakAt = strrpos(substr($address, 0, 49), ' ');
            if ($breakAt !== false && $breakAt > 0) {
                $addressLines = [
                    escapeReportValue(substr($address, 0, $breakAt)),
                    escapeReportValue(substr($address, $breakAt + 1))
                ];
            }
        }
        $startY = $parcelCenter[1] - ((count($addressLines) - 1) * 6);
        $svg .= '<text x="' . round($parcelCenter[0], 2) . '" y="' . round($startY, 2)
            . '" text-anchor="middle" font-family="Arial, sans-serif" font-size="11" font-weight="bold" fill="#17283d"'
            . ' stroke="#e9eef6" stroke-width="3" paint-order="stroke">';
        foreach ($addressLines as $lineIndex => $addressLine) {
            $svg .= '<tspan x="' . round($parcelCenter[0], 2) . '" dy="'
                . ($lineIndex === 0 ? '0' : '13') . '">' . $addressLine . '</tspan>';
        }
        $svg .= '</text>';
    }

    // North arrow is always directed upward on the page.
    $svg .= '<g transform="translate(690 34)" font-family="Arial, sans-serif" fill="#17283d">'
        . '<text x="0" y="-13" text-anchor="middle" font-size="11" font-weight="bold">N</text>'
        . '<line x1="0" y1="13" x2="0" y2="-8" stroke="#17283d" stroke-width="2" />'
        . '<polygon points="0,-12 -5,-3 5,-3" fill="#17283d" />'
        . '</g></svg>';

    return $svg;
}