<?php
// Suppress mPDF internal warnings (harmless but noisy)
error_reporting(E_ERROR | E_PARSE);

require_once __DIR__ . '/../vendor/autoload.php';
use Mpdf\Mpdf;

// =====================================================
// CONFIG - GoDaddy Server Path
// =====================================================
define('ARTIFACTS_DIR', __DIR__ . '/../data/runtimeEphemeral/proposalArtifacts');

// =====================================================
// LOAD PROPOSAL SNAPSHOT (Single Source of Truth)
// =====================================================
$proposal = null;
$jsonPath = ARTIFACTS_DIR . '/PRP-0042.json';

if (file_exists($jsonPath)) {
    $proposal = json_decode(file_get_contents($jsonPath), true);
    echo "✅ Loaded proposal from: data/runtimeEphemeral/proposalArtifacts/PRP-0042.json\n";
} else {
    echo "⚠️ PRP-0042.json not found — using fallback data\n";
}

// =====================================================
// POPULATE DATA
// =====================================================
if ($proposal) {
    $data = $proposal['data'] ?? [];
    $meta = $proposal['proposal'] ?? [];

    $reportTitle          = $data['reportTitle']          ?? 'Proposed Contact Report (PC-3)';
    $entityName           = $data['entityName']           ?? 'Christy Signs';
    $contactName          = $data['contactName']          ?? 'Ms Susan Alderson';
    $contactTitle         = $data['contactTitle']         ?? 'Accounting';
    $contactPhone         = $data['contactPhone']         ?? '(602) 242-4488';
    $contactEmail         = $data['contactEmail']         ?? 'susan@christysigns.com';
    $locationAddress      = $data['locationAddress']      ?? '3145 N 33rd Ave';
    $locationCityStateZip = $data['locationCityStateZip'] ?? 'Phoenix, AZ 85017';
    $locationPlaceId      = $data['locationPlaceId']      ?? '';
    $confidence           = $data['confidence']           ?? 85;
    $pcCode               = $data['pcCode']               ?? 'PC-3';
    $resolutionStatus     = $data['resolutionStatus']     ?? 'multiple_parcels';
    $commitAllowed        = $data['commitAllowed']        ?? 'NO';
    $governanceNarrative  = $data['governanceNarrative']  ?? '';

    $entityAction   = $data['entityAction']   ?? 'reuse';
    $locationAction = $data['locationAction'] ?? 'reuse';
    $contactAction  = $data['contactAction']  ?? 'create';

    $parcelDetails = $data['location']['parcelDetails'] ?? [];
} else {
    // Fallback data
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

    $entityAction   = "reuse";
    $locationAction = "reuse";
    $contactAction  = "create";

    $parcelDetails = [
        ["apnRaw" => "10803009E", "apnDisplay" => "108-03-009E", "address" => "3145 N 33RD AVE", "city" => "PHOENIX", "owner" => "RONALD L REYNOLDS AND JACQUELINE S REYNOLDS FAMILY TRUST", "confidence" => 98],
        ["apnRaw" => "10803051",  "apnDisplay" => "108-03-051",  "address" => "3145 N 33RD AVE", "city" => "PHOENIX", "owner" => "J2 FLOWER LLC", "confidence" => 98]
    ];
}

// =====================================================
// AI REPORT SUMMARY NARRATIVE
// =====================================================
$reportSummaryNarrative = 'Narrative generation in progress...';

try {
    $payload = [
        'type'       => 'proposalNarrative',
        'promptFile' => 'proposedContactReportSummary.prompt',
        'proposalData' => $proposal
    ];

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => json_encode($payload),
            'timeout' => 60
        ]
    ]);

    $rawResponse = file_get_contents(
        'https://skyelighting.com/skyesoft/api/askOpenAI.php',
        false,
        $context
    );

    if ($rawResponse === false) {
        throw new Exception('Failed to connect to askOpenAI.php');
    }

    $responseData = json_decode($rawResponse, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON response from AI service: ' . json_last_error_msg());
    }

    if (isset($responseData['summaryNarrative']) && trim($responseData['summaryNarrative']) !== '') {
        $reportSummaryNarrative = trim($responseData['summaryNarrative']);
    } else {
        throw new Exception('No summaryNarrative returned in response');
    }

} catch (Exception $e) {
    error_log("[PDF] Narrative Error: " . $e->getMessage());

    $reportSummaryNarrative = 
        "🔴 AI NARRATIVE ERROR\n\n" .
        "Error: " . htmlspecialchars($e->getMessage()) . "\n\n" .
        "Raw Response Preview:\n" . htmlspecialchars(substr($rawResponse ?? '[NO RESPONSE]', 0, 800)) . "\n\n" .
        "Check the php-error.log file in skyesoft/api/logs/ for more details.";
}

// =====================================================
// HELPER: Find artifact image
// =====================================================
function getArtifactImage(string $filename): ?string
{
    $path = ARTIFACTS_DIR . '/' . $filename;
    return file_exists($path) ? $path : null;
}

// =====================================================
// HEADER
// =====================================================
$headerHtml = '
<div style="border-bottom: 3px solid #14377C; padding-bottom: 6px;">
    <table style="width:100%; border:none;">
        <tr>
            <td style="width:78px; padding-right:10px; vertical-align:middle;">
                <img src="https://skyelighting.com/skyesoft/assets/images/christyLogo.png" 
                     style="width:72px; height:auto;" alt="Christy Signs">
            </td>
            <td>
                <div style="font-size:14pt; font-weight:700; color:#14377C;">' . htmlspecialchars($reportTitle) . '</div>
                <div style="font-size:9pt; color:#555;">Skyesoft Operational Intelligence | Report Date: 05/25/26</div>
            </td>
        </tr>
    </table>
</div>
';

// =====================================================
// FOOTER
// =====================================================
$footerHtml = '
<div style="border-top: 3px solid #14377C; padding-top: 5px; font-size:7.5pt; color:#555; text-align:center;">
    <div style="font-weight:600;">Christy Signs &nbsp;|&nbsp; 3145 N 33rd Ave, Phoenix, AZ 85017 &nbsp;|&nbsp; (602) 242-4488</div>
    <div style="font-size:7pt; color:#666;">© 2026 Christy Signs — Confidential Internal Operational Document &nbsp;•&nbsp; Page {PAGENO} of {nbpg}</div>
</div>
';

// =====================================================
// PARCEL VISUAL REVIEW SECTION
// =====================================================
$parcelCount = count($parcelDetails);
$parcelSectionHtml = '
<div class="section">
    <table class="sectionHeaderTable">
        <tr>
            <td class="sectionIconCell"><img src="https://skyelighting.com/skyesoft/assets/images/icons/compass.png" class="sectionIcon"></td>
            <td class="sectionTitleCell"><div class="sectionTitle">Parcel Candidates – Visual Review</div></td>
        </tr>
    </table>
    <p style="font-size:10.5pt; color:#333; margin-bottom:12px;">
        <strong>' . $parcelCount . ' parcel(s)</strong> found for this address.
    </p>
';

foreach ($parcelDetails as $index => $parcel) {
    $parcelNum = $index + 1;
    $parcelImageFile = 'IMG-PRP0042-PARCEL-' . str_pad($parcelNum, 2, '0', STR_PAD_LEFT) . '.png';
    $parcelImagePath = getArtifactImage($parcelImageFile);

    $parcelSectionHtml .= '
    <div class="parcel-block">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; flex-wrap:wrap; gap:6px;">
            <div>
                <span style="font-size:14pt; font-weight:700; color:#14377C;">Parcel ' . $parcelNum . '</span>
                <span style="font-size:12.5pt; font-weight:600; margin-left:12px;">APN: ' . htmlspecialchars($parcel['apnDisplay'] ?? $parcel['apnRaw']) . '</span>
            </div>
            <div style="background:#e6f0ff; color:#14377C; padding:4px 14px; border-radius:5px; font-size:10pt; font-weight:600;">
                Confidence: ' . ($parcel['confidence'] ?? 98) . '%
            </div>
        </div>

        <table class="dataTable" style="margin-bottom:10px;">
            <tr><th style="width:20%;">Owner</th><td>' . htmlspecialchars($parcel['owner'] ?? '—') . '</td></tr>
            <tr><th>Address</th><td>' . htmlspecialchars($parcel['address'] ?? '') . ', ' . htmlspecialchars($parcel['city'] ?? '') . '</td></tr>
        </table>';

    if ($parcelImagePath) {
        $parcelSectionHtml .= '
        <div style="text-align:center; margin:8px 0 4px 0;">
            <img src="' . $parcelImagePath . '" 
                 style="max-width:100%; max-height:360px; height:auto; border:1px solid #bbb; border-radius:6px; box-shadow:0 2px 6px rgba(0,0,0,0.1);">
            <div style="font-size:9pt; color:#555; margin-top:5px; font-weight:500;">
                Maricopa County Aerial Imagery • Highlighted Building Footprint
            </div>
        </div>';
    } else {
        $parcelSectionHtml .= '
        <div style="padding:14px; background:#fff3cd; border:1px solid #ffc107; border-radius:6px; text-align:center; margin:10px 0;">
            <small>Parcel imagery not available.</small>
        </div>';
    }

    $parcelSectionHtml .= '</div>';
}
$parcelSectionHtml .= '</div>';

// =====================================================
// GOOGLE SATELLITE OVERVIEW
// =====================================================
$googleMapSection = '';
$googleMapPath = getArtifactImage('IMG-PRP0042-GMAP-01.png');
if ($googleMapPath) {
    $googleMapSection = '
    <div class="section">
        <table class="sectionHeaderTable">
            <tr>
                <td class="sectionIconCell"><img src="https://skyelighting.com/skyesoft/assets/images/icons/pin.png" class="sectionIcon"></td>
                <td class="sectionTitleCell"><div class="sectionTitle">Location Overview — Satellite Context</div></td>
            </tr>
        </table>
        <div style="border: 2px solid #14377C; border-radius: 8px; padding: 12px; background:#f8f9fa; page-break-inside: avoid;">
            <div style="text-align:center;">
                <img src="' . $googleMapPath . '" style="max-width:100%; height:auto; border:1px solid #ccc; border-radius:6px; box-shadow:0 2px 8px rgba(0,0,0,0.12);">
            </div>
            <div style="text-align:center; margin-top:8px; font-size:9.5pt; color:#444;">
                Google Satellite View • Red marker shows resolved location
            </div>
        </div>
    </div>';
}

// =====================================================
// STREET VIEW VERIFICATION
// =====================================================
$streetViewSection = '';
$streetViewPath = getArtifactImage('IMG-PRP0042-STREET-01.jpg');
if ($streetViewPath) {
    $streetViewSection = '
    <div class="section">
        <table class="sectionHeaderTable">
            <tr>
                <td class="sectionIconCell"><img src="https://skyelighting.com/skyesoft/assets/images/icons/property.png" class="sectionIcon"></td>
                <td class="sectionTitleCell"><div class="sectionTitle">Street View Verification</div></td>
            </tr>
        </table>
        <div style="border: 2px solid #14377C; border-radius: 8px; padding: 12px; background:#f8f9fa; page-break-inside: avoid;">
            <div style="text-align:center;">
                <img src="' . $streetViewPath . '" style="max-width:100%; height:auto; border:1px solid #ccc; border-radius:6px; box-shadow:0 2px 8px rgba(0,0,0,0.12);">
            </div>
            <div style="text-align:center; margin-top:8px; font-size:9.5pt; color:#444;">
                Google Street View • ' . htmlspecialchars($locationAddress) . ', ' . htmlspecialchars($locationCityStateZip) . '
            </div>
        </div>
    </div>';
}

// =====================================================
// FULL HTML
// =====================================================
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11pt; color: #222; line-height: 1.3; margin:0; padding:0; }
        .sectionHeaderTable { width:100%; border-collapse:collapse; border:none; margin-top:4px; margin-bottom:6px; border-bottom:1.5px solid #888; }
        .sectionIconCell { width:22px; border:none; padding:0 8px 5px 0; vertical-align:middle; }
        .sectionTitleCell { border:none; padding:0 0 5px 0; vertical-align:middle; }
        .sectionIcon { width:17px; height:17px; object-fit:contain; display:block; }
        .sectionTitle { font-size:12pt; font-weight:700; color:#14377C; line-height:1.1; margin:0; }
        .dataTable { width:100%; table-layout:fixed; border-collapse:collapse; margin:4px 0 10px 0; page-break-inside:avoid; }
        .dataTable th, .dataTable td { border:1px solid #ccc; padding:5px 7px; text-align:left; vertical-align:top; }
        .dataTable th { background:#e8e8e8; width:26%; font-weight:700; color:#333; }
        .dataTable tr:nth-child(even) td { background:#f8f8f8; }
        .dataTable tr:nth-child(odd) td { background:#ffffff; }
        .highlight { background:#f0f7ff; border-left:5px solid #14377C; padding:10px 12px; margin:6px 0; page-break-inside:avoid; font-size:10.5pt; }
        .section { page-break-inside:avoid; margin-top:8px; margin-bottom:12px; }

        /* Parcel Block - Page break handling */
        .parcel-block {
            border: 2px solid #14377C;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 18px;
            background: #fafafa;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        /* Report Summary Styles */
        .summaryNarrative {
            border: 1px solid #d8d8d8;
            background: #f8f8f8;
            padding: 14px;
            font-size: 10pt;
            line-height: 1.55;
            margin-bottom: 12px;
            border-radius: 6px;
        }
        .summaryMetaTable {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        .summaryMetaTable td {
            padding: 6px 10px;
            border: 1px solid #ddd;
            background: #f0f0f0;
            font-size: 10pt;
            text-align: center;
        }
    </style>
</head>
<body>

    <!-- REPORT SUMMARY -->
    <div class="section">
        <table class="sectionHeaderTable">
            <tr>
                <td class="sectionIconCell"><img src="https://skyelighting.com/skyesoft/assets/images/icons/clipboard.png" class="sectionIcon"></td>
                <td class="sectionTitleCell"><div class="sectionTitle">Report Summary</div></td>
            </tr>
        </table>
        <div class="summaryNarrative">
            ' . nl2br(htmlspecialchars($reportSummaryNarrative)) . '
        </div>
        <table class="summaryMetaTable">
            <tr>
                <td><strong>Proposal Code:</strong> ' . htmlspecialchars($pcCode) . '</td>
                <td><strong>Confidence:</strong> ' . $confidence . '%</td>
                <td><strong>Status:</strong> ' . htmlspecialchars($resolutionStatus) . '</td>
                <td><strong>Commit Ready:</strong> <span style="color:#c00;">' . htmlspecialchars($commitAllowed) . '</span></td>
            </tr>
        </table>
    </div>

    <!-- ENTITY -->
    <div class="section">
        <table class="sectionHeaderTable">
            <tr><td class="sectionIconCell"><img src="https://skyelighting.com/skyesoft/assets/images/icons/property.png" class="sectionIcon"></td>
                <td class="sectionTitleCell"><div class="sectionTitle">Entity Information</div></td></tr>
        </table>
        <table class="dataTable"><tr><th>Entity Name</th><td>' . htmlspecialchars($entityName) . '</td></tr></table>
    </div>

    <!-- CONTACT -->
    <div class="section">
        <table class="sectionHeaderTable">
            <tr><td class="sectionIconCell"><img src="https://skyelighting.com/skyesoft/assets/images/icons/users.png" class="sectionIcon"></td>
                <td class="sectionTitleCell"><div class="sectionTitle">Contact Information</div></td></tr>
        </table>
        <table class="dataTable">
            <tr><th>Contact Name</th><td>' . htmlspecialchars($contactName) . '</td></tr>
            <tr><th>Title</th><td>' . htmlspecialchars($contactTitle) . '</td></tr>
            <tr><th>Phone</th><td>' . htmlspecialchars($contactPhone) . '</td></tr>
            <tr><th>Email</th><td>' . htmlspecialchars($contactEmail) . '</td></tr>
        </table>
    </div>

    <!-- LOCATION -->
    <div class="section">
        <table class="sectionHeaderTable">
            <tr><td class="sectionIconCell"><img src="https://skyelighting.com/skyesoft/assets/images/icons/pin.png" class="sectionIcon"></td>
                <td class="sectionTitleCell"><div class="sectionTitle">Location Information</div></td></tr>
        </table>
        <table class="dataTable">
            <tr><th>Full Address</th><td>' . htmlspecialchars($locationAddress) . '</td></tr>
            <tr><th>City, State ZIP</th><td>' . htmlspecialchars($locationCityStateZip) . '</td></tr>
            <tr><th>Place ID</th><td>' . htmlspecialchars($locationPlaceId) . '</td></tr>
        </table>
    </div>

    <!-- GOOGLE SATELLITE OVERVIEW -->
    ' . $googleMapSection . '

    <!-- PARCEL VISUAL REVIEW -->
    ' . $parcelSectionHtml . '

    <!-- STREET VIEW VERIFICATION -->
    ' . $streetViewSection . '

    <!-- GOVERNANCE NARRATIVE -->
    <div class="section">
        <table class="sectionHeaderTable">
            <tr><td class="sectionIconCell"><img src="https://skyelighting.com/skyesoft/assets/images/icons/scales.png" class="sectionIcon"></td>
                <td class="sectionTitleCell"><div class="sectionTitle">Governance &amp; Operational Narrative</div></td></tr>
        </table>
        <div class="highlight">' . nl2br(htmlspecialchars($governanceNarrative)) . '</div>
    </div>

    <!-- PERSISTENCE / STAGING STATE -->
    <div class="section">
        <table class="sectionHeaderTable">
            <tr><td class="sectionIconCell"><img src="https://skyelighting.com/skyesoft/assets/images/icons/puzzle.png" class="sectionIcon"></td>
                <td class="sectionTitleCell"><div class="sectionTitle">Persistence / Staging State</div></td></tr>
        </table>
        <table class="dataTable">
            <tr><th>Entity Action</th><td>' . htmlspecialchars($entityAction) . '</td></tr>
            <tr><th>Location Action</th><td>' . htmlspecialchars($locationAction) . '</td></tr>
            <tr><th>Contact Action</th><td>' . htmlspecialchars($contactAction) . '</td></tr>
            <tr><th>Commit Allowed</th><td><strong style="color:#c00;">' . htmlspecialchars($commitAllowed) . '</strong></td></tr>
        </table>
    </div>

</body>
</html>
';

// =====================================================
// GENERATE PDF
// =====================================================
$mpdf = new Mpdf([
    'format'        => 'Letter',
    'margin_left'   => 12,
    'margin_right'  => 12,
    'margin_top'    => 28,
    'margin_bottom' => 24,
]);

$mpdf->SetHTMLHeader($headerHtml);
$mpdf->SetHTMLFooter($footerHtml);

$mpdf->WriteHTML($html);
$mpdf->Output('Proposed_Contact_Report_PC3_ChristySigns_Enhanced.pdf', 'I');

echo "\n✅ PDF generated: Proposed_Contact_Report_PC3_ChristySigns_Enhanced.pdf\n";