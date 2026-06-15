<?php

// =====================================================
// Maricopa Parcel Address Lookup Test
// =====================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

$rawAddress = isset($_POST['address'])
    ? trim($_POST['address'])
    : '';

$results = null;
$parcelCount = 0;
$message = '';

function normalizeParcelSearchAddress($address) {
    $address = strtoupper(trim($address));

    // Remove suite/unit fragments for parcel search.
    $address = preg_replace('/\s+(#|STE|SUITE|UNIT|APT)\s*[A-Z0-9\-]+/i', '', $address);

    // Remove punctuation.
    $address = preg_replace('/[^A-Z0-9\s]/', ' ', $address);

    // Normalize spaces.
    $address = preg_replace('/\s+/', ' ', $address);

    return trim($address);
}

function buildParcelSearchTerms($address) {
    $normalized = normalizeParcelSearchAddress($address);

    $terms = array();

    // Full normalized address.
    $terms[] = $normalized;

    // Remove city/state/zip by keeping street number + direction + street name.
    if (preg_match('/^(\d+\s+[NSEW]?\s*[A-Z0-9]+)/', $normalized, $matches)) {
        $terms[] = trim($matches[1]);
    }

    // Remove street suffixes.
    $terms[] = preg_replace('/\b(RD|ROAD|ST|STREET|AVE|AVENUE|BLVD|DR|DRIVE|LN|LANE|WAY|PKWY)\b/', '', $normalized);

    // Deduplicate.
    $terms = array_unique(array_filter(array_map('trim', $terms)));

    return $terms;
}

function lookupMaricopaParcels($address) {
    $searchTerms = buildParcelSearchTerms($address);

    foreach ($searchTerms as $term) {

        $where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" .
            str_replace("'", "''", $term) .
            "%')";

        $params = http_build_query(array(
            'where'          => $where,
            'outFields'      => 'APN,PHYSICAL_ADDRESS,PHYSICAL_CITY,JURISDICTION,OWNER_NAME',
            'returnGeometry' => 'false',
            'f'              => 'json'
        ));

        $url = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query?' . $params;

        $context = stream_context_create(array(
            'http' => array(
                'timeout' => 20
            )
        ));

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            continue;
        }

        $data = json_decode($response, true);

        if (!empty($data['features'])) {
            return array(
                'term'     => $term,
                'features' => $data['features']
            );
        }
    }

    return array(
        'term'     => null,
        'features' => array()
    );
}

if ($rawAddress !== '') {
    $results = lookupMaricopaParcels($rawAddress);
    $parcelCount = count($results['features']);

    if ($parcelCount === 0) {
        $message = 'No parcel available for this address.';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Maricopa Parcel Lookup</title>
<style>
body {
    font-family: Arial, Helvetica, sans-serif;
    margin: 30px;
    color: #222;
}

input[type="text"] {
    width: 520px;
    padding: 8px;
    font-size: 14px;
}

button {
    padding: 8px 14px;
    font-size: 14px;
    cursor: pointer;
}

.result {
    margin-top: 25px;
}

table {
    border-collapse: collapse;
    width: 100%;
    margin-top: 15px;
}

th, td {
    border: 1px solid #ccc;
    padding: 8px;
    text-align: left;
    font-size: 13px;
}

th {
    background: #f2f2f2;
}

.notice {
    margin-top: 20px;
    font-weight: bold;
}
</style>
</head>
<body>

<h2>Maricopa Parcel Lookup</h2>

<form method="post">
    <input type="text" name="address" value="<?php echo htmlspecialchars($rawAddress); ?>" placeholder="Enter address">
    <button type="submit">Search Parcels</button>
</form>

<?php if ($rawAddress !== ''): ?>
<div class="result">

    <h3>Address</h3>
    <p><?php echo htmlspecialchars($rawAddress); ?></p>

    <h3>Parcel Count</h3>
    <p><?php echo (int)$parcelCount; ?></p>

    <?php if ($parcelCount === 0): ?>

        <div class="notice">
            <?php echo htmlspecialchars($message); ?>
        </div>

    <?php else: ?>

        <p><strong>Matched Search Term:</strong> <?php echo htmlspecialchars($results['term']); ?></p>

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
                <?php foreach ($results['features'] as $feature): ?>
                    <?php $a = isset($feature['attributes']) ? $feature['attributes'] : array(); ?>
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

</div>
<?php endif; ?>

</body>
</html>