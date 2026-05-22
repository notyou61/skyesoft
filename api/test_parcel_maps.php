<?php
require_once __DIR__ . '/../vendor/autoload.php';

define('SCREENSHOTONE_ACCESS_KEY', '1wKb3PuLgAJB_Q');

// Test parcels
$testParcels = [
    [
        'apn' => '10803009E',
        'viewerUrl' => 'https://maps.mcassessor.maricopa.gov/?esearch=10803009E&slayer=0&exprnum=0'
    ],
    [
        'apn' => '10803051',
        'viewerUrl' => 'https://maps.mcassessor.maricopa.gov/?esearch=10803051&slayer=0&exprnum=0'
    ]
];

function fetchMapImage($apn, $viewerUrl) {
    $imagePath = __DIR__ . "/parcel_{$apn}.png";

    if (file_exists($imagePath)) {
        return ['status' => 'cached', 'path' => $imagePath];
    }

    $params = [
        'access_key'           => SCREENSHOTONE_ACCESS_KEY,
        'url'                  => $viewerUrl,
        'format'               => 'png',
        'block_ads'            => 'true',
        'block_cookie_banners' => 'true',
        'block_trackers'       => 'true',
        'delay'                => '3000',           // Increased delay for modal
        'viewport_width'       => '1280',
        'viewport_height'      => '900',
        'full_page'            => 'false',
    ];

    $apiUrl = 'https://api.screenshotone.com/take?' . http_build_query($params);
    
    $context = stream_context_create([
        'http' => ['timeout' => 60]
    ]);

    $imageData = @file_get_contents($apiUrl, false, $context);

    if ($imageData === false) {
        return ['status' => 'failed', 'error' => 'Failed to fetch from ScreenshotOne'];
    }

    // Basic check if we got an actual image
    if (strlen($imageData) < 1000) {
        return ['status' => 'failed', 'error' => 'Response too small (likely error page)'];
    }

    file_put_contents($imagePath, $imageData);
    return ['status' => 'generated', 'path' => $imagePath];
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Test Parcel Map Images</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .card { border: 2px solid #14377C; border-radius: 8px; padding: 15px; margin-bottom: 20px; max-width: 700px; }
        .success { background: #d4edda; padding: 10px; border-radius: 5px; }
        .error { background: #f8d7da; padding: 10px; border-radius: 5px; }
        img { max-width: 100%; border: 1px solid #ccc; margin-top: 10px; }
    </style>
</head>
<body>

<h1>Test: Generate Parcel Map Images</h1>
<p>This page tests dynamic generation of aerial maps using ScreenshotOne.</p>

<?php foreach ($testParcels as $parcel): ?>
    <div class="card">
        <h3>Parcel: <?= $parcel['apn'] ?></h3>
        <p><strong>URL:</strong> <?= htmlspecialchars($parcel['viewerUrl']) ?></p>

        <?php
        $result = fetchMapImage($parcel['apn'], $parcel['viewerUrl']);
        ?>

        <?php if ($result['status'] === 'cached'): ?>
            <div class="success">
                <strong>Using cached image</strong><br>
                <img src="parcel_<?= $parcel['apn'] ?>.png" alt="Map for <?= $parcel['apn'] ?>">
            </div>

        <?php elseif ($result['status'] === 'generated'): ?>
            <div class="success">
                <strong>New image generated successfully!</strong><br>
                <img src="parcel_<?= $parcel['apn'] ?>.png" alt="Map for <?= $parcel['apn'] ?>">
            </div>

        <?php else: ?>
            <div class="error">
                <strong>Failed to generate image</strong><br>
                <?= htmlspecialchars($result['error'] ?? 'Unknown error') ?>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

</body>
</html>