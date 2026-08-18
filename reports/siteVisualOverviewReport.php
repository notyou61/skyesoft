<?php
declare(strict_types=1);

// ======================================================================
// Skyesoft — siteVisualOverviewReport.php
// Version: 1.0.0
// Ephemeral Site Visual Overview PDF Generator
// ======================================================================
//
// Primary Responsibilities
// • Accept the completed Site Visual Overview workspace payload
// • Validate and embed all required report imagery
// • Generate the standard Christy Signs branded PDF
// • Stream the completed PDF without saving it
// • Delete temporary image artifacts after PDF assembly
//
// Architectural Principles
// • Report imagery remains ephemeral and non-authoritative
// • Only files inside /skyesoft/artifacts may be deleted
// • Report generation performs no domain-data writes
// • Successful report reads are recorded in tblActions
// • The Address Zoning Report defines the PDF visual standard
//
// Compatibility
// • PHP 8.3+
// • mPDF 8.3+
//
// ======================================================================

#region Section 0 — Bootstrap & Shared Helpers

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');
error_reporting(E_ALL);

// ======================================================================
// Session, Database & Action-Layer Bootstrap
// ======================================================================

require_once __DIR__ . '/../api/sessionBootstrap.php';
require_once __DIR__ . '/../api/dbConnect.php';
require_once __DIR__ . '/../api/utils/actions.php';
require_once __DIR__ . '/../vendor/autoload.php';

// Confirm database connection availability
if (!function_exists('getPDO')) {
    throw new RuntimeException(
        'The Skyesoft database connection is unavailable.'
    );
}

// Confirm action-recording availability
if (!function_exists('insertActionPrompt')) {
    throw new RuntimeException(
        'The Skyesoft action-recording layer is unavailable.'
    );
}

/** Escape a report value for HTML output. */
function escapeSiteVisualValue(mixed $value): string
{
    return htmlspecialchars(
        trim((string)($value ?? '')),
        ENT_QUOTES,
        'UTF-8'
    );
}

/** Return a controlled plain-text error response. */
function siteVisualReportError(int $statusCode, string $message): never
{
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

/**
 * Read the Site Visual Overview workspace and supplemental audit context
 * from a JSON request or form payload.
 */
function readSiteVisualReportPayload(): array
{
    $rawBody = (string) file_get_contents(
        'php://input'
    );

    $contentType = strtolower(
        (string) ($_SERVER['CONTENT_TYPE'] ?? '')
    );

    $rawPayload = '';

    // Resolve JSON request body
    if (
        $rawBody !== ''
        && str_contains(
            $contentType,
            'application/json'
        )
    ) {
        $rawPayload = $rawBody;
    } elseif (isset($_POST['payload'])) {
        // Resolve form-submitted payload
        $rawPayload = (string) $_POST['payload'];
    }

    if ($rawPayload === '') {
        throw new InvalidArgumentException(
            'Missing Site Visual Overview workspace payload.'
        );
    }

    // Decode report request
    $payload = json_decode(
        $rawPayload,
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    if (!is_array($payload)) {
        throw new InvalidArgumentException(
            'The Site Visual Overview payload must be a JSON object.'
        );
    }

    // Resolve report workspace
    $workspace =
        $payload['workspace']
        ?? $payload;

    if (!is_array($workspace)) {
        throw new InvalidArgumentException(
            'The Site Visual Overview workspace is invalid.'
        );
    }

    // Return workspace with supplemental audit context
    return [
        'workspace' => $workspace,

        'auditContext' => [
            'activitySessionId' =>
                $payload['activitySessionId']
                ?? null,

            'actionLatitude' =>
                $payload['actionLatitude']
                ?? null,

            'actionLongitude' =>
                $payload['actionLongitude']
                ?? null
        ]
    ];
}

/** Build a branded section heading using the standard report icon set. */
function buildSiteVisualSectionHeading(
    string $title,
    string $iconFile
): string {
    $iconPath = __DIR__
        . '/../assets/images/icons/'
        . basename($iconFile);

    $iconHtml = file_exists($iconPath)
        ? '<img src="'
            . escapeSiteVisualValue($iconPath)
            . '" class="section-icon" alt="" />'
        : '';

    return '<div class="section-heading">'
        . $iconHtml
        . '<span class="section-heading-title">'
        . escapeSiteVisualValue($title)
        . '</span></div>';
}

/** Return a readable value or a consistent unavailable label. */
function displaySiteVisualValue(
    mixed $value,
    string $fallback = 'Not available'
): string {
    $displayValue = trim((string)($value ?? ''));

    return $displayValue !== ''
        ? escapeSiteVisualValue($displayValue)
        : '<span class="unverified">'
            . escapeSiteVisualValue($fallback)
            . '</span>';
}

/** Format a numeric distance consistently. */
function formatSiteVisualDistance(mixed $value): string
{
    return is_numeric($value)
        ? number_format((float)$value, 1) . ' miles'
        : 'Not available';
}

#endregion

#region Section 1 — Artifact Resolution & Cleanup

/** Confirm that a resolved file remains inside the artifacts directory. */
function isSiteVisualArtifactPath(
    string $filePath,
    string $artifactsDirectory
): bool {
    $realFilePath = realpath($filePath);
    $realArtifactsDirectory = realpath($artifactsDirectory);

    if ($realFilePath === false || $realArtifactsDirectory === false) {
        return false;
    }

    $artifactPrefix = rtrim($realArtifactsDirectory, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR;

    return str_starts_with($realFilePath, $artifactPrefix);
}

/** Resolve one local temporary artifact URL to a validated image path. */
function resolveSiteVisualArtifactImage(
    string $previewUrl,
    string $artifactsDirectory
): ?array {
    $urlPath = (string)(parse_url($previewUrl, PHP_URL_PATH) ?? '');
    $filename = basename(rawurldecode($urlPath));

    if (
        $filename === '' ||
        !preg_match(
            '/^tmp-site-visual-[A-Za-z0-9._-]+\.(?:jpe?g|png)$/i',
            $filename
        )
    ) {
        return null;
    }

    $filePath = rtrim($artifactsDirectory, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . $filename;

    if (
        !is_file($filePath) ||
        !is_readable($filePath) ||
        !isSiteVisualArtifactPath($filePath, $artifactsDirectory)
    ) {
        return null;
    }

    $imageInfo = @getimagesize($filePath);

    if ($imageInfo === false) {
        return null;
    }

    return [
        'path' => $filePath,
        'temporary' => true
    ];
}

/** Resolve the streamed satellite preview into a temporary local image. */
function materializeSiteVisualSatellite(
    array $satelliteSection,
    string $artifactsDirectory
): ?array {
    $settings = is_array($satelliteSection['settings'] ?? null)
        ? $satelliteSection['settings']
        : [];

    $latitude = $settings['latitude'] ?? null;
    $longitude = $settings['longitude'] ?? null;
    $zoom = $settings['zoom'] ?? 19;

    if (!is_numeric($latitude) || !is_numeric($longitude)) {
        return null;
    }

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));

    if ($host === '' || !preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
        return null;
    }

    $endpointUrl = 'https://'
        . $host
        . '/skyesoft/api/siteVisualOverviewImages.php'
        . '?type=satellite'
        . '&latitude=' . rawurlencode((string)$latitude)
        . '&longitude=' . rawurlencode((string)$longitude)
        . '&zoom=' . rawurlencode((string)$zoom);

    $curl = curl_init();

    if ($curl === false) {
        return null;
    }

    curl_setopt_array($curl, [
        CURLOPT_URL => $endpointUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Skyesoft-SiteVisualReport/1.0'
    ]);

    $imageData = curl_exec($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $contentType = strtolower((string)curl_getinfo(
        $curl,
        CURLINFO_CONTENT_TYPE
    ));

    curl_close($curl);

    if (
        !is_string($imageData) ||
        $imageData === '' ||
        $httpCode !== 200 ||
        !str_starts_with($contentType, 'image/')
    ) {
        return null;
    }

    $filename = 'tmp-site-visual-report-satellite-'
        . time()
        . '-'
        . bin2hex(random_bytes(6))
        . '.jpg';

    $filePath = rtrim($artifactsDirectory, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . $filename;

    $bytesWritten = file_put_contents($filePath, $imageData, LOCK_EX);

    if ($bytesWritten === false || $bytesWritten <= 0) {
        return null;
    }

    return [
        'path' => $filePath,
        'temporary' => true
    ];
}

/** Delete only validated temporary Site Visual Overview artifacts. */
function deleteSiteVisualArtifacts(
    array $filePaths,
    string $artifactsDirectory
): void {
    foreach (array_values(array_unique($filePaths)) as $filePath) {
        if (
            !is_string($filePath) ||
            !isSiteVisualArtifactPath($filePath, $artifactsDirectory)
        ) {
            continue;
        }

        $filename = basename($filePath);

        if (!str_starts_with($filename, 'tmp-site-visual-')) {
            continue;
        }

        if (is_file($filePath)) {
            @unlink($filePath);
        }
    }
}

#endregion

#region Section 2 — Workspace Validation & Report Model

try {
    $reportRequest = readSiteVisualReportPayload();
    $workspace = $reportRequest['workspace'];
} catch (Throwable $error) {
    siteVisualReportError(
        400,
        'Unable to generate Site Visual Overview Report: '
            . $error->getMessage()
    );
}

// ======================================================================
// Report Action Audit Context
// ======================================================================

$auditContext = is_array(
    $reportRequest['auditContext'] ?? null
)
    ? $reportRequest['auditContext']
    : [];

// Resolve browser coordinates for action auditing
$reportActionLatitude = is_numeric(
    $auditContext['actionLatitude'] ?? null
)
    ? (float) $auditContext['actionLatitude']
    : null;

$reportActionLongitude = is_numeric(
    $auditContext['actionLongitude'] ?? null
)
    ? (float) $auditContext['actionLongitude']
    : null;

$sourceData = is_array($workspace['sourceData'] ?? null)
    ? $workspace['sourceData']
    : [];

$location = is_array($sourceData['data']['location'] ?? null)
    ? $sourceData['data']['location']
    : [];

$sections = is_array($workspace['sections'] ?? null)
    ? $workspace['sections']
    : [];

if ($location === [] || $sections === []) {
    siteVisualReportError(
        400,
        'The Site Visual Overview workspace is incomplete.'
    );
}

$fullAddress = trim((string)(
    $location['locationResolvedAddress']
    ?? $location['locationAddressRaw']
    ?? $location['locationAddress']
    ?? ''
));

$primaryParcel = is_array($location['parcelDetails'][0] ?? null)
    ? $location['parcelDetails'][0]
    : [];

$parcelNumber = trim((string)(
    $primaryParcel['parcelNumber']
    ?? $location['locationParcelNumber']
    ?? ''
));

$ownerName = trim((string)(
    $primaryParcel['ownerName']
    ?? $primaryParcel['owner']['name']
    ?? ''
));

$jurisdiction = trim((string)(
    $location['jurisdictionName']
    ?? $primaryParcel['jurisdiction']
    ?? ''
));

$county = trim((string)($location['locationCounty'] ?? ''));
$latitude = $location['locationLatitude'] ?? null;
$longitude = $location['locationLongitude'] ?? null;

if (
    $fullAddress === '' ||
    !is_numeric($latitude) ||
    !is_numeric($longitude)
) {
    siteVisualReportError(
        400,
        'The validated property address and coordinates are required.'
    );
}

$artifactsDirectory = dirname(__DIR__) . '/artifacts';

if (!is_dir($artifactsDirectory) || !is_readable($artifactsDirectory)) {
    siteVisualReportError(
        500,
        'The Skyesoft artifacts directory is unavailable.'
    );
}

$requiredSectionNames = [
    'satellite',
    'parcel',
    'streetViews',
    'immediateVicinity',
    'extendedContext'
];

foreach ($requiredSectionNames as $requiredSectionName) {
    if (!isset($sections[$requiredSectionName])) {
        siteVisualReportError(
            400,
            'The report is missing the '
                . $requiredSectionName
                . ' section.'
        );
    }
}

$temporaryArtifactPaths = [];
$reportImages = [];

$satelliteImage = materializeSiteVisualSatellite(
    is_array($sections['satellite']) ? $sections['satellite'] : [],
    $artifactsDirectory
);

if ($satelliteImage === null) {
    siteVisualReportError(400, 'The Satellite View image is unavailable.');
}

$reportImages[] = [
    'key' => 'satellite',
    'title' => 'Satellite View',
    'path' => $satelliteImage['path'],
    'icon' => 'map.png',
    'noteTitle' => 'Satellite Image Note',
    'noteText' => 'Satellite imagery is provided for general site orientation and may not reflect current site conditions or survey-grade property positioning.',
    'source' => 'Google Maps Static API'
];

$temporaryArtifactPaths[] = $satelliteImage['path'];

$parcelImage = resolveSiteVisualArtifactImage(
    (string)($sections['parcel']['preview'] ?? ''),
    $artifactsDirectory
);

if ($parcelImage === null) {
    siteVisualReportError(400, 'The Parcel Map image is unavailable.');
}

$reportImages[] = [
    'key' => 'parcel',
    'title' => 'Parcel Map',
    'path' => $parcelImage['path'],
    'icon' => 'map.png',
    'noteTitle' => 'Parcel Map Note',
    'noteText' => 'Parcel boundaries are shown for general reference and are not a survey or legal determination of property lines. Field verification may be required.',
    'source' => 'Google Maps Static API; Maricopa County Assessor Parcel GIS'
];

$temporaryArtifactPaths[] = $parcelImage['path'];

$streetSelections = is_array($sections['streetViews']['selections'] ?? null)
    ? $sections['streetViews']['selections']
    : [];

$streetViewNumber = 0;

foreach ($streetSelections as $selection) {
    if (!is_array($selection) || ($selection['status'] ?? '') !== 'ready') {
        continue;
    }

    $streetImage = resolveSiteVisualArtifactImage(
        (string)($selection['preview'] ?? ''),
        $artifactsDirectory
    );

    if ($streetImage === null) {
        siteVisualReportError(
            400,
            'One of the selected Street View images is unavailable.'
        );
    }

    $streetViewNumber++;

    $reportImages[] = [
        'key' => 'streetView' . $streetViewNumber,
        'title' => 'Street View ' . $streetViewNumber,
        'path' => $streetImage['path'],
        'icon' => 'camera.png',
        'noteTitle' => 'Street View Note',
        'noteText' => 'Street View imagery reflects the map provider\'s available capture and may not represent current site, building, sign, landscaping, or access conditions. Field verification is required before final design.',
        'source' => 'Google Street View Static API'
    ];

    $temporaryArtifactPaths[] = $streetImage['path'];
}

if ($streetViewNumber === 0) {
    siteVisualReportError(400, 'No ready Street View image was provided.');
}

$immediateImage = resolveSiteVisualArtifactImage(
    (string)($sections['immediateVicinity']['preview'] ?? ''),
    $artifactsDirectory
);

if ($immediateImage === null) {
    siteVisualReportError(
        400,
        'The Immediate Vicinity Map image is unavailable.'
    );
}

$reportImages[] = [
    'key' => 'immediateVicinity',
    'title' => 'Immediate Vicinity Map',
    'path' => $immediateImage['path'],
    'icon' => 'map.png',
    'noteTitle' => 'Immediate Vicinity Map Note',
    'noteText' => 'Road names, map labels, access points, and surrounding development are provided for general geographic context and may change. The parcel boundary remains illustrative.',
    'source' => 'Google Maps Static API; Maricopa County Assessor Parcel GIS'
];

$temporaryArtifactPaths[] = $immediateImage['path'];

$extendedImage = resolveSiteVisualArtifactImage(
    (string)($sections['extendedContext']['preview'] ?? ''),
    $artifactsDirectory
);

if ($extendedImage === null) {
    siteVisualReportError(
        400,
        'The Extended Context Map image is unavailable.'
    );
}

$extendedSettings = is_array($sections['extendedContext']['settings'] ?? null)
    ? $sections['extendedContext']['settings']
    : [];

// Resolve Driving Distance (miles) from the keys actually produced
// by siteVisualOverviewImages.php (extendedContext) and any frontend
// mapping that may already exist in the workspace payload.
$resolvedDrivingDistanceMiles = null;
if (
    isset($extendedSettings['drivingDistanceMiles'])
    && is_numeric($extendedSettings['drivingDistanceMiles'])
) {
    $resolvedDrivingDistanceMiles = (float)$extendedSettings['drivingDistanceMiles'];
} elseif (
    isset($extendedSettings['drivingDistanceMeters'])
    && is_numeric($extendedSettings['drivingDistanceMeters'])
) {
    $resolvedDrivingDistanceMiles = round(
        (float)$extendedSettings['drivingDistanceMeters'] / 1609.344,
        1
    );
} elseif (
    !empty($extendedSettings['drivingDistanceText'])
    && is_string($extendedSettings['drivingDistanceText'])
) {
    if (preg_match(
        '/([\d.]+)\s*mi/i',
        $extendedSettings['drivingDistanceText'],
        $distanceMatch
    )) {
        $resolvedDrivingDistanceMiles = (float)$distanceMatch[1];
    }
}

// Resolve Direct / Straight-line Distance (miles).
// Upstream images endpoint returns straightLineMiles (Haversine).
$resolvedDirectDistanceMiles = null;
if (
    isset($extendedSettings['directDistanceMiles'])
    && is_numeric($extendedSettings['directDistanceMiles'])
) {
    $resolvedDirectDistanceMiles = (float)$extendedSettings['directDistanceMiles'];
} elseif (
    isset($extendedSettings['straightLineMiles'])
    && is_numeric($extendedSettings['straightLineMiles'])
) {
    $resolvedDirectDistanceMiles = (float)$extendedSettings['straightLineMiles'];
}

$reportImages[] = [
    'key' => 'extendedContext',
    'title' => 'Extended Context Map',
    'path' => $extendedImage['path'],
    'icon' => 'map.png',
    'noteTitle' => 'Extended Context Map Note',
    'noteText' => 'Driving routes, travel times, and driving distances are estimates and may vary with traffic, closures, construction, or route availability. Direct distance is calculated between the two coordinate points and does not represent travel distance.',
    'source' => 'Google Maps Routes API; Google Maps Static API'
];

$temporaryArtifactPaths[] = $extendedImage['path'];

$phoenixNow = new DateTimeImmutable(
    'now',
    new DateTimeZone('America/Phoenix')
);

$reportDateFormatted = $phoenixNow->format('F j, Y');
$safeFileToken = preg_replace(
    '/[^A-Za-z0-9_-]+/',
    '-',
    $parcelNumber !== '' ? $parcelNumber : 'address'
);

#endregion

#region Section 3 — PDF Branding, CSS, Header & Footer

$logoPath = __DIR__ . '/../assets/images/christyLogo.png';
$logoHtml = file_exists($logoPath)
    ? '<img src="'
        . escapeSiteVisualValue($logoPath)
        . '" class="header-logo" alt="Christy Signs" />'
    : '<div class="logo-fallback">Christy Signs</div>';

$css = '
    body { font-family: Arial, sans-serif; font-size: 8.5pt; color: #222; line-height: 1.35; }

    .header-table { width: 100%; border-collapse: collapse; border-bottom: 2px solid #14377c; }
    .header-table td { padding: 0 0 3px; vertical-align: bottom; }
    .header-logo { display: block; width: auto; height: 58px; }
    .logo-fallback { color: #14377c; font-size: 16px; font-weight: bold; }

    .header-report-details { display: block; width: 100%; text-align: right; }
    .header-title,
    .header-subtitle-main,
    .header-subtitle-sub,
    .header-report-date { display: block; width: 100%; text-align: right; }
    .header-title { margin: 0; color: #14377c; font-size: 13pt; font-weight: bold; line-height: 1.05; }
    .header-subtitle-main { margin: 2px 0 0; color: #333; font-size: 9.5pt; font-weight: bold; line-height: 1.15; }
    .header-subtitle-sub { margin: 1px 0 0; color: #555; font-size: 8.5pt; line-height: 1.15; }
    .header-report-date { margin: 1px 0 0; color: #666; font-size: 7.5pt; line-height: 1.15; }

    .footer-table { width: 100%; border-top: 1px solid #ccc; padding-top: 4px; font-size: 7.5pt; color: #666; }

    .section-block { margin-bottom: 11px; page-break-inside: avoid; }
    .section-heading { font-size: 9.5pt; font-weight: bold; color: #14377c; border-bottom: 1.5px solid #14377c; padding-bottom: 2px; margin-bottom: 5px; }
    .section-icon { display: inline-block; width: 14px; height: 14px; margin-right: 5px; vertical-align: -2px; }
    .section-heading-title { display: inline-block; vertical-align: middle; }

    .data-table { width: 100%; border-collapse: collapse; margin-bottom: 2px; }
    .data-table th,
    .data-table td { border: 1px solid #ccc; padding: 4px 6px; font-size: 8pt; vertical-align: top; }
    .data-table th { width: 32%; text-align: left; background: #f8f9fa; color: #333; }
    .data-table td { width: 68%; background: #fff; color: #111; }
    .data-table--contents th { width: 42%; }
    .data-table--contents td { width: 58%; }

    .callout-box { background: #f0f4f9; border: 1px solid #b8cbe5; border-left: 4px solid #14377c; padding: 6px 9px; margin: 5px 0; }
    .callout-title { font-size: 8.5pt; font-weight: bold; color: #14377c; margin-bottom: 3px; }
    .callout-body { font-size: 7.8pt; line-height: 1.3; }
    .unverified { color: #777; font-style: italic; }

    .report-image-frame { border: 1px solid #c4ceda; background: #f7f9fc; padding: 5px; text-align: center; }
    .report-image { display: block; width: 100%; height: auto; }
    .image-caption { margin: 4px 0 0; color: #4a607a; font-size: 7.2pt; text-align: center; }
    .report-page { page-break-inside: avoid; }
';

$headerHtml = '
<table class="header-table">
    <tr>
        <td width="32%" style="width: 32%; vertical-align: bottom;">
            ' . $logoHtml . '
        </td>
        <td width="68%" align="right" style="width: 68%; vertical-align: bottom; text-align: right;">
            <div class="header-report-details">
                <div class="header-title">Site Visual Overview Report</div>
                <div class="header-subtitle-main">Pre-Design Site Context</div>
                <div class="header-subtitle-sub">'
                    . escapeSiteVisualValue($fullAddress)
                    . '</div>
                <div class="header-report-date">Report Date: '
                    . escapeSiteVisualValue($reportDateFormatted)
                    . '</div>
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

#endregion

#region Section 4 — Report Body

ob_start();
?>
<div class="section-block">
    <?= buildSiteVisualSectionHeading('Report Overview', 'property.png') ?>
    <table class="data-table">
        <tr><th>Address</th><td><?= displaySiteVisualValue($fullAddress) ?></td></tr>
        <tr><th>APN</th><td><?= displaySiteVisualValue($parcelNumber) ?></td></tr>
        <tr><th>Property Owner</th><td><?= displaySiteVisualValue($ownerName) ?></td></tr>
        <tr><th>Jurisdiction</th><td><?= displaySiteVisualValue($jurisdiction) ?></td></tr>
        <tr><th>County</th><td><?= displaySiteVisualValue($county) ?></td></tr>
        <tr>
            <th>Coordinates</th>
            <td><?= escapeSiteVisualValue(number_format((float)$latitude, 7)) ?>,
                <?= escapeSiteVisualValue(number_format((float)$longitude, 7)) ?></td>
        </tr>
    </table>

    <div class="callout-box">
        <div class="callout-title">Report Basis &amp; Qualification</div>
        <div class="callout-body">
            This report is a pre-design visual context document generated from the
            address-check result and the map-provider imagery available when the
            workspace was prepared. It supports preliminary site review and does
            not replace a field survey, legal boundary survey, property criteria
            review, engineering evaluation, or jurisdictional approval.
            <br><strong>Sources:</strong> Google Maps Platform; Maricopa County
            Assessor Parcel GIS
        </div>
    </div>
</div>

<div class="section-block">
    <?= buildSiteVisualSectionHeading(
        $reportImages[0]['title'],
        $reportImages[0]['icon']
    ) ?>

    <div class="report-image-frame">
        <img
            src="<?= escapeSiteVisualValue($reportImages[0]['path']) ?>"
            class="report-image"
            alt="<?= escapeSiteVisualValue($reportImages[0]['title']) ?>"
        />
    </div>

    <div class="image-caption">
        <?= escapeSiteVisualValue($fullAddress) ?>
    </div>

    <div class="callout-box">
        <div class="callout-title">
            <?= escapeSiteVisualValue($reportImages[0]['noteTitle']) ?>
        </div>
        <div class="callout-body">
            <?= escapeSiteVisualValue($reportImages[0]['noteText']) ?>
            <br><strong>Source:</strong>
            <?= escapeSiteVisualValue($reportImages[0]['source']) ?>
        </div>
    </div>
</div>

<?php foreach (array_slice($reportImages, 1) as $reportImage): ?>
    <pagebreak />
    <div class="report-page">
        <?= buildSiteVisualSectionHeading(
            $reportImage['title'],
            $reportImage['icon']
        ) ?>

        <div class="report-image-frame">
            <img
                src="<?= escapeSiteVisualValue($reportImage['path']) ?>"
                class="report-image"
                alt="<?= escapeSiteVisualValue($reportImage['title']) ?>"
            />
        </div>

        <div class="image-caption">
            <?= escapeSiteVisualValue($fullAddress) ?>
        </div>

        <div class="callout-box">
            <div class="callout-title">
                <?= escapeSiteVisualValue($reportImage['noteTitle']) ?>
            </div>
            <div class="callout-body">
                <?= escapeSiteVisualValue($reportImage['noteText']) ?>
                <br><strong>Source:</strong>
                <?= escapeSiteVisualValue($reportImage['source']) ?>
            </div>
        </div>

        <?php if ($reportImage['key'] === 'extendedContext'): ?>
            <table class="data-table" style="margin-top: 7px;">
                <tr>
                    <th>Driving Distance</th>
                    <td><?= escapeSiteVisualValue(formatSiteVisualDistance(
                        $resolvedDrivingDistanceMiles
                    )) ?></td>
                </tr>
                <tr>
                    <th>Estimated Driving Time</th>
                    <td><?= displaySiteVisualValue(
                        $extendedSettings['drivingDurationText'] ?? null
                    ) ?></td>
                </tr>
                <tr>
                    <th>Direct Distance</th>
                    <td><?= escapeSiteVisualValue(formatSiteVisualDistance(
                        $resolvedDirectDistanceMiles
                    )) ?></td>
                </tr>
            </table>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
<?php
$html = ob_get_clean();

#endregion

#region Section 5 — PDF Generation, Cleanup & Streaming

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

    $mpdf->SetTitle(
        'Site Visual Overview Report - ' . $fullAddress
    );

    $mpdf->SetAuthor('Steve Skye');
    $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->SetHTMLHeader($headerHtml);
    $mpdf->SetHTMLFooter($footerHtml);
    $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);

    $pdfBytes = $mpdf->Output(
        '',
        \Mpdf\Output\Destination::STRING_RETURN
    );
} catch (Throwable $error) {
    error_log(
        '[SITE VISUAL REPORT] PDF generation failed: '
        . $error->getMessage()
    );

    siteVisualReportError(
        500,
        'Unable to generate the Site Visual Overview PDF.'
    );
}

$pdfFilename = 'Site_Visual_Overview_'
    . $safeFileToken
    . '.pdf';

// ======================================================================
// Report Read Action Recording
// ======================================================================

$actorContactId = (int) (
    $_SESSION['SKYESOFT_contactId']
    ?? $_SESSION['contactId']
    ?? 0
);

// Preserve the server-bootstrap session as the canonical identifier
$reportActivitySessionId = defined('ACTIVITY_SESSION_ID')
    ? trim((string) ACTIVITY_SESSION_ID)
    : trim((string) session_id());

if ($reportActivitySessionId === 'no_session') {
    $reportActivitySessionId = trim((string) session_id());
}

if ($actorContactId > 0) {
    try {
        insertActionPrompt(
            [
                'actionTypeId'      => 11,
                'contactId'         => $actorContactId,
                'origin'            => 1,
                'activitySessionId' =>
                    $reportActivitySessionId,

                // User-facing action description
                'promptText' =>
                    'Open Site Visual Overview Report',

                'responseText' =>
                    'Displayed Site Visual Overview Report for '
                    . $fullAddress
                    . '.',

                // Canonical operation identity
                'intent' =>
                    'reports.siteVisualOverview.read',

                'intentConfidence' => 1.00,

                // Browser coordinates for action auditing
                'latitude'  => $reportActionLatitude,
                'longitude' => $reportActionLongitude,

                // Structured report request
                'actionPayloadData' => [
                    'operation'    =>
                        'reports.siteVisualOverview.read',
                    'reportType'   =>
                        'siteVisualOverview',
                    'address'      => $fullAddress,
                    'parcelNumber' => $parcelNumber,
                    'imageCount'   => count($reportImages)
                ],

                // Structured report result
                'actionResponseData' => [
                    'success'      => true,
                    'reportType'   =>
                        'siteVisualOverview',
                    'fileName'     => $pdfFilename,
                    'address'      => $fullAddress,
                    'parcelNumber' => $parcelNumber,
                    'imageCount'   => count($reportImages)
                ]
            ],
            getPDO()
        );
    } catch (Throwable $actionError) {
        // Preserve the successfully generated PDF
        error_log(
            '[siteVisualOverviewReport] '
            . 'Read-action recording failed: '
            . $actionError->getMessage()
        );
    }
}

// ======================================================================
// Authoritative Idle Activity Reset
// ======================================================================

if (
    session_status() === PHP_SESSION_ACTIVE
    && !empty($_SESSION['authenticated'])
) {
    $_SESSION['lastActivity'] = time();
}

// Commit session changes before streaming the PDF
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Delete temporary imagery only after mPDF has embedded every image
deleteSiteVisualArtifacts(
    $temporaryArtifactPaths,
    $artifactsDirectory
);

header('Content-Type: application/pdf');
header(
    'Content-Disposition: inline; filename="'
        . $pdfFilename
        . '"'
);
header('Content-Length: ' . strlen($pdfBytes));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

echo $pdfBytes;
exit;

#endregion