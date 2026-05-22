<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Mpdf\Mpdf;

// =====================================================
// CONFIG
// =====================================================
define('SCREENSHOTONE_ACCESS_KEY', '1wKb3PuLgAJB_Q');

// =====================================================
// SAMPLE DATA
// =====================================================
$reportTitle          = "Proposed Contact Report (PC-3)";
$entityName           = "Christy Signs";
$contactName          = "Ms Susan Alderson";
$contactTitle         = "Accounting";
$contactPhone         = "(602) 242-4488";
$contactEmail         = "susan@christysigns.com";
$locationAddress      = "3145 N 33rd Ave";
$locationCityStateZip = "Phoenix, AZ 85017";
$locationPlaceId      = "ChIJeTvhT3ATK4cRpfapSIlCjFw";
$confidence           = 85;
$pcCode               = "PC-3";
$resolutionStatus     = "multiple_parcels";
$commitAllowed        = "NO";
$governanceNarrative  = "This proposal references an existing operational location. Review: Multiple parcel candidates were found at this address and user selection is required before commit.";

// Persistence values (were missing)
$entityAction         = "reuse";
$locationAction       = "reuse";
$contactAction        = "create";

// =====================================================
// PARCEL DATA
// =====================================================
$parcelDetails = [
    [
        "apnRaw"     => "10803009E",
        "apnDisplay" => "108-03-009E",
        "address"    => "3145 N 33RD AVE",
        "city"       => "PHOENIX",
        "owner"      => "RONALD L REYNOLDS AND JACQUELINE S REYNOLDS FAMILY TRUST",
        "confidence" => 98,
        "viewerUrl"  => "https://maps.mcassessor.maricopa.gov/?esearch=10803009E&slayer=0&exprnum=0"
    ],
    [
        "apnRaw"     => "10803051",
        "apnDisplay" => "108-03-051",
        "address"    => "3145 N 33RD AVE",
        "city"       => "PHOENIX",
        "owner"      => "J2 FLOWER LLC",
        "confidence" => 98,
        "viewerUrl"  => "https://maps.mcassessor.maricopa.gov/?esearch=10803051&slayer=0&exprnum=0"
    ]
];

// Header
$headerHtml = '
<div style="border-bottom: 3px solid #14377C; padding-bottom: 6px;">
    <table style="width:100%; border:none;">
        <tr>
            <td style="width:78px; padding-right:10px; vertical-align:middle;">
                <img src="https://skyelighting.com/skyesoft/assets/images/christyLogo.png" 
                     style="width:72px; height:auto; vertical-align:middle;" 
                     alt="Christy Signs">
            </td>
            <td>
                <div style="font-size:14pt; font-weight:700; color:#14377C; line-height:1.1;">' . $reportTitle . '</div>
                <div style="font-size:9pt; color:#555;">Skyesoft Operational Intelligence | Report Date: 05/22/26</div>
            </td>
        </tr>
    </table>
</div>
';

// Footer
$footerHtml = '
<div style="border-top: 3px solid #14377C; padding-top: 5px; font-size:7.5pt; color:#555; text-align:center;">
    <div style="font-weight:600; margin-bottom:2px;">
        Christy Signs &nbsp;|&nbsp; 3145 N 33rd Ave, Phoenix, AZ 85017 &nbsp;|&nbsp; (602) 242-4488
    </div>
    <div style="font-size:7pt; color:#666;">
        © 2026 Christy Signs — Confidential Internal Operational Document &nbsp;&nbsp;•&nbsp;&nbsp; Page {PAGENO} of {nbpg}
    </div>
</div>
';

// =====================================================
// HELPER: Get or Fetch Map Image via ScreenshotOne
// =====================================================
/**
 * Fetches or returns cached aerial map image for a parcel.
 *
 * @param string $apn
 * @param string $viewerUrl
 * @return string|null
 */
function getParcelMapImage(string $apn, string $viewerUrl): ?string
{
    $imagePath = __DIR__ . "/parcel_{$apn}.png";

    if (file_exists($imagePath)) {
        return $imagePath;
    }

    $params = [
        'access_key'           => SCREENSHOTONE_ACCESS_KEY,
        'url'                  => $viewerUrl,
        'format'               => 'png',
        'block_ads'            => 'true',
        'block_cookie_banners' => 'true',
        'block_trackers'       => 'true',
        'delay'                => '2500',
        'viewport_width'       => '1280',
        'viewport_height'      => '900',
        'full_page'            => 'false',
    ];

    $apiUrl = 'https://api.screenshotone.com/take?' . http_build_query($params);
    $imageData = @file_get_contents($apiUrl);

    if ($imageData === false) {
        return null;
    }

    file_put_contents($imagePath, $imageData);
    return $imagePath;
}

// =====================================================
// PARCEL VISUAL REVIEW SECTION
// =====================================================
$parcelCount = count($parcelDetails);

$parcelSectionHtml = '
<div class="section">
    <table class="sectionHeaderTable">
        <tr>
            <td class="sectionIconCell">
                <img src="https://skyelighting.com/skyesoft/assets/images/icons/compass.png" class="sectionIcon">
            </td>
            <td class="sectionTitleCell">
                <div class="sectionTitle">Parcel Candidates – Visual Review</div>
            </td>
        </tr>
    </table>

    <p style="font-size:10pt; color:#555; margin-bottom:12px;">
        <strong>' . $parcelCount . ' parcel(s)</strong> found for this address.
    </p>
';

foreach ($parcelDetails as $index => $parcel) {
    $parcelNum = $index + 1;
    $mapImage = getParcelMapImage($parcel['apnRaw'], $parcel['viewerUrl']);

    $parcelSectionHtml .= '
    <div style="border: 2px solid #14377C; border-radius: 8px; padding: 14px; margin-bottom: 16px; page-break-inside: avoid;">
        
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
            <div>
                <span style="font-size:13pt; font-weight:700; color:#14377C;">Parcel ' . $parcelNum . '</span>
                <span style="font-size:12pt; font-weight:600; margin-left:10px;">APN: ' . $parcel['apnDisplay'] . '</span>
            </div>
            <div style="background:#e6f0ff; color:#14377C; padding:3px 10px; border-radius:4px; font-size:9pt;">
                Confidence: ' . $parcel['confidence'] . '%
            </div>
        </div>

        <table class="dataTable" style="margin-bottom:10px;">
            <tr><th style="width:22%;">Owner</th><td>' . $parcel['owner'] . '</td></tr>
            <tr><th>Address</th><td>' . $parcel['address'] . ', ' . $parcel['city'] . '</td></tr>
        </table>';

    if ($mapImage) {
        $parcelSectionHtml .= '
        <div style="text-align:center; margin:10px 0;">
            <img src="parcel_' . $parcel['apnRaw'] . '.png" 
                 style="max-width:92%; height:auto; border:1px solid #ccc; border-radius:6px; background:#f8f8f8;">
            <div style="font-size:8.5pt; color:#666; margin-top:4px;">Maricopa County Aerial Reference</div>
        </div>';
    } else {
        $parcelSectionHtml .= '
        <div style="padding:16px; background:#fff3cd; border:1px solid #ffc107; border-radius:6px; text-align:center; margin:10px 0;">
            <small>Could not generate aerial map image.</small>
        </div>';
    }

    $parcelSectionHtml .= '
        <div style="text-align:center; margin-top:8px;">
            <a href="' . $parcel['viewerUrl'] . '" target="_blank" 
               style="background:#14377C; color:white; padding:9px 18px; border-radius:6px; text-decoration:none; font-size:10.5pt;">
                🗺️ View Full Interactive Map
            </a>
        </div>
    </div>';
}

$parcelSectionHtml .= '</div>';

// =====================================================
// FULL HTML BODY
// =====================================================
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11pt; color: #222; line-height: 1.25; margin:0; padding:0; }
        .sectionHeaderTable { width:100%; border-collapse:collapse; border:none; margin-top:0; margin-bottom:3px; border-bottom:1.5px solid #888; }
        .sectionIconCell { width:20px; border:none; padding:0 8px 4px 0; vertical-align:middle; }
        .sectionTitleCell { border:none; padding:0 0 4px 0; vertical-align:middle; }
        .sectionIcon { width:16px; height:16px; object-fit:contain; display:block; }
        .sectionTitle { font-size:11.5pt; font-weight:700; color:#14377C; line-height:1.0; margin:0; }
        .dataTable { width:100%; table-layout:fixed; border-collapse:collapse; margin:2px 0 8px 0; page-break-inside:avoid; }
        .dataTable th, .dataTable td { border:1px solid #ccc; padding:4px 6px; text-align:left; vertical-align:top; }
        .dataTable th { background:#e8e8e8; width:28%; font-weight:700; color:#333; }
        .dataTable tr:nth-child(even) td { background:#f8f8f8; }
        .dataTable tr:nth-child(odd) td { background:#ffffff; }
        .highlight { background:#f0f7ff; border-left:4px solid #14377C; padding:8px 10px; margin:6px 0; page-break-inside:avoid; }
        .section { page-break-inside:avoid; page-break-before:auto; margin-top:10px; margin-bottom:10px; }
    </style>
</head>
<body>

    <!-- RESOLUTION SUMMARY -->
    <div class="section">
        <table class="sectionHeaderTable">
            <tr>
                <td class="sectionIconCell"><img src="https://skyelighting.com/skyesoft/assets/images/icons/clipboard.png" class="sectionIcon"></td>
                <td class="sectionTitleCell"><div class="sectionTitle">Resolution Summary</div></td>
            </tr>
        </table>
        <table class="dataTable">
            <tr><th>Report Title</th><td>' . $reportTitle . '</td></tr>
            <tr><th>Confidence</th><td>' . $confidence . '%</td></tr>
            <tr><th>PC Code</th><td>' . $pcCode . '</td></tr>
            <tr><th>Status</th><td>' . $resolutionStatus . '</td></tr>
            <tr><th>Commit Ready</th><td><strong>' . $commitAllowed . '</strong></td></tr>
        </table>
    </div>

    <!-- ENTITY -->
    <div class="section">
        <table class="sectionHeaderTable">
            <tr>
                <td class="sectionIconCell"><img src="https://skyelighting.com/skyesoft/assets/images/icons/property.png" class="sectionIcon"></td>
                <td class="sectionTitleCell"><div class="sectionTitle">Entity Information</div></td>
            </tr>
        </table>
        <table class="dataTable"><tr><th>Entity Name</th><td>' . $entityName . '</td></tr></table>
    </div>

    <!-- CONTACT -->
    <div class="section">
        <table class="sectionHeaderTable">
            <tr>
                <td class="sectionIconCell"><img src="https://skyelighting.com/skyesoft/assets/images/icons/users.png" class="sectionIcon"></td>
                <td class="sectionTitleCell"><div class="sectionTitle">Contact Information</div></td>
            </tr>
        </table>
        <table class="dataTable">
            <tr><th>Contact Name</th><td>' . $contactName . '</td></tr>
            <tr><th>Title</th><td>' . $contactTitle . '</td></tr>
            <tr><th>Phone</th><td>' . $contactPhone . '</td></tr>
            <tr><th>Email</th><td>' . $contactEmail . '</td></tr>
        </table>
    </div>

    <!-- LOCATION -->
    <div class="section">
        <table class="sectionHeaderTable">
            <tr>
                <td class="sectionIconCell"><img src="https://skyelighting.com/skyesoft/assets/images/icons/pin.png" class="sectionIcon"></td>
                <td class="sectionTitleCell"><div class="sectionTitle">Location Information</div></td>
            </tr>
        </table>
        <table class="dataTable">
            <tr><th>Full Address</th><td>' . $locationAddress . '</td></tr>
            <tr><th>City, State ZIP</th><td>' . $locationCityStateZip . '</td></tr>
            <tr><th>Place ID</th><td>' . $locationPlaceId . '</td></tr>
        </table>
    </div>

    <!-- PARCEL VISUAL REVIEW -->
    ' . $parcelSectionHtml . '

    <!-- GOVERNANCE NARRATIVE -->
    <div class="section">
        <table class="sectionHeaderTable">
            <tr>
                <td class="sectionIconCell"><img src="https://skyelighting.com/skyesoft/assets/images/icons/scales.png" class="sectionIcon"></td>
                <td class="sectionTitleCell"><div class="sectionTitle">Governance &amp; Operational Narrative</div></td>
            </tr>
        </table>
        <div class="highlight">' . $governanceNarrative . '</div>
    </div>

    <!-- PERSISTENCE / STAGING STATE -->
    <div class="section">
        <table class="sectionHeaderTable">
            <tr>
                <td class="sectionIconCell"><img src="https://skyelighting.com/skyesoft/assets/images/icons/puzzle.png" class="sectionIcon"></td>
                <td class="sectionTitleCell"><div class="sectionTitle">Persistence / Staging State</div></td>
            </tr>
        </table>
        <table class="dataTable">
            <tr><th>Entity Action</th><td>' . $entityAction . '</td></tr>
            <tr><th>Location Action</th><td>' . $locationAction . '</td></tr>
            <tr><th>Contact Action</th><td>' . $contactAction . '</td></tr>
            <tr><th>Commit Allowed</th><td><strong>' . $commitAllowed . '</strong></td></tr>
        </table>
    </div>

</body>
</html>
';

// =====================================================
// Generate PDF
// =====================================================
$mpdf = new Mpdf([
    'format'        => 'Letter',
    'margin_left'   => 10,
    'margin_right'  => 10,
    'margin_top'    => 26,
    'margin_bottom' => 22,
]);

$mpdf->SetHTMLHeader($headerHtml);
$mpdf->SetHTMLFooter($footerHtml);

$mpdf->WriteHTML($html);
$mpdf->Output('Proposed_Contact_Report_PC3_ChristySigns.pdf', 'I');