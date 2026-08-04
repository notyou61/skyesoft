<?php
declare(strict_types=1);

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
// 3. Data Formatting & Helpers
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

// -------------------------------------------------------------------------
// 4. CSS & HTML Layout (Magnolia Archetype Standards)
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

    /* Unverified Fallback Text */
    .unverified {
        color: #888888;
        font-style: italic;
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
            <th>Sign Code Jurisdiction</th>
            <td><?= displayValue($loc['locationJurisdiction']) ?></td>
        </tr>
        <tr>
            <th>Applicable Code / Section</th>
            <td><?= displayValue(null) ?></td>
        </tr>
        <tr>
            <th>Max Allowable Area</th>
            <td><?= displayValue(null) ?></td>
        </tr>
        <tr>
            <th>Max Height / Projection</th>
            <td><?= displayValue(null) ?></td>
        </tr>
        <tr>
            <th>Illumination Rules</th>
            <td><?= displayValue(null) ?></td>
        </tr>
        <tr>
            <th>Permit Requirements</th>
            <td><?= displayValue(null) ?></td>
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
            <li>Verify zoning designation and sign ordinance requirements with municipal staff or GIS resources.</li>
            <li>Confirm whether any site-specific Master Sign Plan or overlay restrictions exist for this parcel.</li>
            <li>Document verified zoning, applicable code sections, and sign allowances as the location review progresses.</li>
        </ol>
    </div>
</div>

<!-- 5. Sources and Disclaimers -->
<div class="section-block" style="margin-top: 10px;">
    <p style="font-size: 7.5pt; color: #666666; line-height: 1.25; margin: 0;">
        <strong>Sources &amp; Review Qualifications:</strong> Information shown is based on the current Skyesoft location record together with parcel information stored in the Skyesoft parcel database. Zoning and sign-code requirements should be verified with the governing jurisdiction before design or permitting.
    </p>
</div>
<?php
$html = ob_get_clean();

// -------------------------------------------------------------------------
// 5. Render PDF with mPDF (Letter + Expanded Body & Header Clearance)
// -------------------------------------------------------------------------
try {
    $mpdf = new \Mpdf\Mpdf([
        'mode'          => 'utf-8',
        'format'        => 'Letter',
        'margin_left'   => 8.5,   // ~0.33 in (expands body width)
        'margin_right'  => 8.5,   // ~0.33 in
        'margin_top'    => 33,    // Increased clearance so body starts below header
        'margin_bottom' => 14,    // Clearance for footer
        'margin_header' => 6,     // Moves header closer to top edge
        'margin_footer' => 6      // Moves footer closer to bottom edge
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