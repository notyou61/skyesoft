<?php
// =====================================================
// Test Page: Check & Display Parcel Map Images
// =====================================================
$testParcels = [
    ['apn' => '10803009E', 'label' => 'Parcel 1'],
    ['apn' => '10803051',  'label' => 'Parcel 2']
];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Test: Parcel Map Images</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 30px; background: #f8f9fa; }
        .card {
            border: 2px solid #14377C;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            max-width: 700px;
            background: white;
        }
        .success { background: #d4edda; padding: 12px; border-radius: 6px; }
        .warning { background: #fff3cd; padding: 12px; border-radius: 6px; }
        img { max-width: 100%; border: 1px solid #ccc; border-radius: 6px; margin-top: 10px; }
        h1 { color: #14377C; }
    </style>
</head>
<body>

<h1>Test: Parcel Map Images</h1>
<p>This page checks for existing map images. Images should be generated using the Playwright script.</p>

<?php foreach ($testParcels as $parcel): ?>
    <?php
        $imagePath = __DIR__ . "/parcel_{$parcel['apn']}.png";
        $exists = file_exists($imagePath);
    ?>

    <div class="card">
        <h3><?= $parcel['label'] ?> — APN: <?= $parcel['apn'] ?></h3>

        <?php if ($exists): ?>
            <div class="success">
                <strong>✅ Image found</strong>
            </div>
            <img src="parcel_<?= $parcel['apn'] ?>.png" alt="Map for <?= $parcel['apn'] ?>">
        <?php else: ?>
            <div class="warning">
                <strong>⚠️ Image not found</strong><br>
                Run the Playwright script to generate <code>parcel_<?= $parcel['apn'] ?>.png</code>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

</body>
</html>