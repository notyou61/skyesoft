<?php
require_once __DIR__ . '/../vendor/autoload.php';

define('SCREENSHOTONE_ACCESS_KEY', '1wKb3PuLgAJB_Q');

// =====================================================
// Test Parcels
// =====================================================
$testParcels = [
    [
        'apn'       => '10803009E',
        'viewerUrl' => 'https://maps.mcassessor.maricopa.gov/?esearch=10803009E&slayer=0&exprnum=0'
    ],
    [
        'apn'       => '10803051',
        'viewerUrl' => 'https://maps.mcassessor.maricopa.gov/?esearch=10803051&slayer=0&exprnum=0'
    ]
];

// =====================================================
// Function to fetch or return cached map image
// =====================================================
/**
 * Attempts to generate or retrieve a cached aerial map image using ScreenshotOne.
 *
 * @param string $apn
 * @param string $viewerUrl
 * @return array
 */
function fetchMapImage(string $apn, string $viewerUrl): array
{
    $imagePath = __DIR__ . "/parcel_{$apn}.png";

    // Return cached image if available
    if (file_exists($imagePath)) {
        return [
            'status' => 'cached',
            'path'   => $imagePath,
            'apn'    => $apn
        ];
    }

    // Build ScreenshotOne request
    $params = [
        'access_key'           => SCREENSHOTONE_ACCESS_KEY,
        'url'                  => $viewerUrl,
        'format'               => 'png',
        'block_ads'            => 'true',
        'block_cookie_banners' => 'true',
        'block_trackers'       => 'true',
        'delay'                => '3000',           // Give time for modal + map load
        'viewport_width'       => '1280',
        'viewport_height'      => '900',
        'full_page'            => 'false',
    ];

    $apiUrl = 'https://api.screenshotone.com/take?' . http_build_query($params);

    $context = stream_context_create([
        'http' => ['timeout' => 60]
    ]);

    $imageData = @file_get_contents($apiUrl, false, $context);

    if ($imageData === false || strlen($imageData) < 2000) {
        return [
            'status' => 'failed',
            'error'  => 'Failed to retrieve image from ScreenshotOne (possible modal or timeout)',
            'apn'    => $apn
        ];
    }

    // Save the image
    file_put_contents($imagePath, $imageData);

    return [
        'status' => 'generated',
        'path'   => $imagePath,
        'apn'    => $apn
    ];
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Test: Parcel Map Image Generation</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 30px; background: #f8f9fa; }
        .card {
            border: 2px solid #14377C;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            max-width: 750px;
            background: white;
        }
        .success { background: #d4edda; padding: 12px; border-radius: 6px; }
        .error { background: #f8d7da; padding: 12px; border-radius: 6px; }
        img { max-width: 100%; border: 1px solid #ccc; border-radius: 6px; margin-top: 10px; }
        h1 { color: #14377C; }
    </style>
</head>
<body>

<h1>Test Page: Generate Parcel Map Images</h1>
<p>This page tests dynamic generation of aerial maps using ScreenshotOne.</p>

<?php foreach ($testParcels as $parcel): ?>
    <?php $result = fetchMapImage($parcel['apn'], $parcel['viewerUrl']); ?>

    <div class="card">
        <h3>Parcel: <?= htmlspecialchars($parcel['apn']) ?></h3>

        <?php if ($result['status'] === 'cached'): ?>
            <div class="success">
                <strong>✅ Using cached image</strong>
            </div>
            <img src="parcel_<?= $parcel['apn'] ?>.png" alt="Map for <?= $parcel['apn'] ?>">

        <?php elseif ($result['status'] === 'generated'): ?>
            <div class="success">
                <strong>✅ New image successfully generated!</strong>
            </div>
            <img src="parcel_<?= $parcel['apn'] ?>.png" alt="Map for <?= $parcel['apn'] ?>">

        <?php else: ?>
            <div class="error">
                <strong>❌ Failed to generate image</strong><br>
                <?= htmlspecialchars($result['error']) ?>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

</body>
</html>