<?php
// =====================================================
// Maricopa Parcel Address Lookup + Governance Test
// =====================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

$rawAddress = isset($_POST['address']) ? trim($_POST['address']) : '';

$results = null;
$parcelCount = 0;
$message = '';
$rsCode = '';
$parcelStatus = 'UNKNOWN';

// Normalization helper
function normalizeParcelSearchAddress($address) {
    $address = strtoupper(trim($address));
    $address = preg_replace('/\s+(#|STE|SUITE|UNIT|APT)\s*[A-Z0-9\-]+/i', '', $address);
    $address = preg_replace('/[^A-Z0-9\s]/', ' ', $address);
    $address = preg_replace('/\s+/', ' ', $address);
    return trim($address);
}

// Build multiple search terms for robustness
function buildParcelSearchTerms($address) {
    $normalized = normalizeParcelSearchAddress($address);
    $terms = [];

    $terms[] = $normalized; // Full

    // Street number + name only
    if (preg_match('/^(\d+\s+[NSEW]?\s*[A-Z0-9]+)/', $normalized, $matches)) {
        $terms[] = trim($matches[1]);
    }

    // Without suffixes
    $terms[] = preg_replace('/\b(RD|ROAD|ST|STREET|AVE|AVENUE|BLVD|DR|DRIVE|LN|LANE|WAY|PKWY)\b/', '', $normalized);

    $terms = array_unique(array_filter(array_map('trim', $terms)));
    return $terms;
}

// Lookup function (unchanged core logic)
function lookupMaricopaParcels($address) {
    $searchTerms = buildParcelSearchTerms($address);

    foreach ($searchTerms as $term) {
        $where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" .
            str_replace("'", "''", $term) . "%')";

        $params = http_build_query([
            'where'          => $where,
            'outFields'      => 'APN,PHYSICAL_ADDRESS,PHYSICAL_CITY,JURISDICTION,OWNER_NAME',
            'returnGeometry' => 'false',
            'f'              => 'json'
        ]);

        $url = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query?' . $params;

        $context = stream_context_create(['http' => ['timeout' => 20]]);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) continue;

        $data = json_decode($response, true);
        if (!empty($data['features'])) {
            return [
                'term'     => $term,
                'features' => $data['features']
            ];
        }
    }

    return ['term' => null, 'features' => []];
}

// Process request
if ($rawAddress !== '') {
    $results = lookupMaricopaParcels($rawAddress);
    $parcelCount = count($results['features'] ?? []);

    // =====================================================
    // Parcel Governance Classification (RS)
    // =====================================================
    if ($parcelCount === 0) {
        $rsCode = 'RS-7';
        $parcelStatus = 'Unresolved Parcel';
        $message = 'No parcel available for this address.';
    } elseif ($parcelCount === 1) {
        $rsCode = 'RS-0';
        $parcelStatus = 'Single Parcel Found';
    } else {
        $rsCode = 'RS-6';
        $parcelStatus = 'Multiple Parcels Found';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Maricopa Parcel Lookup + Governance Test</title>
<style>
    body { font-family: Arial, sans-serif; margin: 30px; }
    input[type="text"] { width: 520px; padding: 10px; font-size: 15px; }
    button { padding: 10px 16px; font-size: 15px; cursor: pointer; }
    .result { margin-top: 30px; padding: 15px; border: 1px solid #ddd; background: #f9f9f9; }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; }
    th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
    th { background: #f2f2f2; }
    .notice { color: #d32f2f; font-weight: bold; }
    .success { color: #388e3c; }
</style>
</head>
<body>

<h2>Maricopa Parcel Lookup + Governance Test</h2>

<form method="post">
    <input type="text" name="address" value="<?php echo htmlspecialchars($rawAddress); ?>" 
           placeholder="e.g. 3145 N 33rd Ave Phoenix AZ 85017" style="width:600px;">
    <button type="submit">Search Parcels</button>
</form>

<?php if ($rawAddress !== ''): ?>
<div class="result">
    <h3>Address</h3>
    <p><strong><?php echo htmlspecialchars($rawAddress); ?></strong></p>

    <h3>Parcel Count</h3>
    <p><strong><?php echo $parcelCount; ?></strong></p>

    <h3>Resolution Status</h3>
    <p><strong><?php echo $rsCode; ?></strong> — <?php echo $parcelStatus; ?></p>

    <?php if ($parcelCount === 0): ?>
        <div class="notice"><?php echo htmlspecialchars($message); ?></div>
    <?php else: ?>
        <p><strong>Matched Term:</strong> <?php echo htmlspecialchars($results['term'] ?? ''); ?></p>

        <table>
            <thead>
                <tr>
                    <th>APN</th>
                    <th>Physical Address</th>
                    <th>City</th>
                    <th>Jurisdiction</th>
                    <th>Owner</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results['features'] as $feature): 
                    $a = $feature['attributes'] ?? [];
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($a['APN'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($a['PHYSICAL_ADDRESS'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($a['PHYSICAL_CITY'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($a['JURISDICTION'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($a['OWNER_NAME'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h3>Governance Summary (for Proposed Contact Engine)</h3>

    <table style="width:500px;">
        <tr>
            <td><strong>Parcel Count</strong></td>
            <td><?php echo $parcelCount; ?></td>
        </tr>

        <tr>
            <td><strong>RS Code</strong></td>
            <td><?php echo htmlspecialchars($rsCode); ?></td>
        </tr>

        <tr>
            <td><strong>Parcel Status</strong></td>
            <td><?php echo htmlspecialchars($parcelStatus); ?></td>
        </tr>
    </table>
</div>
<?php endif; ?>

</body>
</html>