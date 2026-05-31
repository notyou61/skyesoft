<?php
// =============================================
// test-report.php - Full Pipeline Test
// =============================================

echo "<h1>Full Report Pipeline Test</h1>";

$payload = [
    "reportType"          => "contact_proposal",
    "reportTitle"         => "Proposed Contact Report (PC-3)",
    "entityName"          => "Christy Signs",
    "entityAction"        => "reuse",
    "contactName"         => "Susan Alderson",
    "contactTitle"        => "Accounting Manager",
    "contactPhone"        => "(602) 555-0182",
    "contactEmail"        => "susan@christysigns.com",
    "contactAction"       => "create",
    "locationAddress"     => "3145 N 33rd Ave",
    "locationCityStateZip"=> "Phoenix, AZ 85017",
    "locationPlaceId"     => "ChIJeTvhT3ATK4cRpfapSIlCjFw",
    "locationCounty"      => "Maricopa",
    "governanceNarrative" => "This proposal references an existing operational location.",
    "confidence"          => 85,
    "pc_code"             => "PC-3",
    "resolutionStatus"    => "existing_location",
    "commitAllowed"       => "YES",
    "parcelDetails"       => [
        ["apnRaw" => "10803009E", "apnDisplay" => "10803009E", "address" => "3145 N 33RD AVE PHOENIX 85017", "city" => "PHOENIX", "owner" => "RONALD L REYNOLDS AND JACQUELINE S REYNOLDS FAMILY TRUST", "confidence" => 98],
        ["apnRaw" => "10803051",  "apnDisplay" => "108-03-051",  "address" => "3145 N 33RD AVE PHOENIX 85017", "city" => "PHOENIX", "owner" => "J2 FLOWER LLC", "confidence" => 98]
    ]
];

echo "<h2>1. Loading generator...</h2>";

// Try both common paths
$generatorPath = null;
$possible = [__DIR__ . '/api/reports/contactProposalReport.php', __DIR__ . '/reports/contactProposalReport.php'];
foreach ($possible as $p) {
    if (file_exists($p)) {
        $generatorPath = $p;
        break;
    }
}

if (!$generatorPath) {
    die("❌ Could not find contactProposalReport.php");
}

require_once $generatorPath;
echo "<p>✓ Loaded generator</p>";

$report = generateContactProposalReport($payload);
echo "<p>✓ Report object created</p>";

echo "<h2>2. Loading renderer...</h2>";

$rendererPath = null;
$possibleRenderer = [__DIR__ . '/api/reports/templates/baseReport.php', __DIR__ . '/reports/templates/baseReport.php'];
foreach ($possibleRenderer as $p) {
    if (file_exists($p)) {
        $rendererPath = $p;
        break;
    }
}

if (!$rendererPath) {
    die("❌ Could not find baseReport.php");
}

require_once $rendererPath;
echo "<p>✓ Loaded baseReport.php</p>";

echo "<h2>3. Generating PDF...</h2>";

$pdfContent = renderReport($report);

$fileName = __DIR__ . '/test-output.pdf';
file_put_contents($fileName, $pdfContent);

echo "<h2>✅ SUCCESS! PDF Generated</h2>";
echo "<p><strong>Saved as:</strong> test-output.pdf (" . number_format(strlen($pdfContent)) . " bytes)</p>";
echo "<p><a href='test-output.pdf' target='_blank'>Open Generated PDF</a></p>";