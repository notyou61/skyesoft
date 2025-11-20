<?php
// testParcelLookup.php
// Quick test harness for Maricopa Assessor parcel lookup with status flag

if ($argc < 2) {
    die("Usage: php testParcelLookup.php \"<address>\"\n");
}

$inputAddress = $argv[1];

// Normalize to uppercase
$normalized = strtoupper($inputAddress);

// Extract ZIP (5-digit only)
preg_match('/\b(\d{5})(?:-\d{4})?$/', $normalized, $zipMatch);
$zip = $zipMatch[1] ?? null;

// Extract house number for comparison
preg_match('/\b(\d{3,5})\b/', $normalized, $numMatch);
$houseNum = $numMatch[1] ?? null;

// Extract street portion
$shortAddress = preg_replace('/,.*$/', '', $normalized);

// --- Helper function for requests ---
function runQuery($where, $label) {
    $url = "https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query"
        . "?f=json&where=" . urlencode($where)
        . "&outFields=APN,PHYSICAL_ADDRESS,OWNER_NAME,PHYSICAL_ZIP&returnGeometry=false&outSR=4326";

    fwrite(STDERR, "Querying ($label): $url\n");
    $resp = json_decode(@file_get_contents($url), true);
    return $resp['features'] ?? [];
}

$features = [];
$status = "none";

// Step 1: Full address + ZIP
$where1 = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . $shortAddress . "%')";
if ($zip) $where1 .= " AND PHYSICAL_ZIP = '$zip'";
$features = runQuery($where1, "Step 1: full address+ZIP");
if (!empty($features)) $status = "exact";

// Step 2: Relax suffix (drop Blvd/Rd/Dr/etc.)
if (empty($features)) {
    $relaxed = preg_replace('/\s(BLVD|ROAD|RD|DR|DRIVE|STREET|ST|AVE|AVENUE)\b/i', '', $shortAddress);
    $where2 = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . $relaxed . "%')";
    if ($zip) $where2 .= " AND PHYSICAL_ZIP = '$zip'";
    $features = runQuery($where2, "Step 2: relaxed street+ZIP");
    if (!empty($features)) $status = "exact";
}

// Step 3: Fuzzy street match (drop numbers, keep street + ZIP)
if (empty($features) && $zip) {
    $streetOnly = trim(preg_replace('/^\d+/', '', $shortAddress));
    $where3 = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . $streetOnly . "%') AND PHYSICAL_ZIP = '$zip'";
    $features = runQuery($where3, "Step 3: fuzzy street+ZIP");
    if (!empty($features)) $status = "fuzzy";
}

// Step 4: Last resort — full address no ZIP
if (empty($features)) {
    $where4 = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . $shortAddress . "%')";
    $features = runQuery($where4, "Step 4: full address no ZIP");
    if (!empty($features)) $status = "fuzzy";
}

// Build output
$output = [
    "input" => $inputAddress,
    "parcelStatus" => $status,
    "matches" => []
];

foreach ($features as $f) {
    $a = $f['attributes'];
    $output["matches"][] = [
        "apn"   => $a['APN'],
        "situs" => trim($a['PHYSICAL_ADDRESS']),
        "zip"   => $a['PHYSICAL_ZIP']
    ];
}

// Print JSON
echo json_encode($output, JSON_PRETTY_PRINT) . "\n";
