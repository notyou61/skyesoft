<?php
// =====================================================
// Test Page: Generate & Manage Parcel Map Images
// =====================================================

$parcels = [
    [
        'apn'        => '10803009E',
        'apnDisplay' => '108-03-009E',
        'viewerUrl'  => 'https://maps.mcassessor.maricopa.gov/?esearch=10803009E&slayer=0&exprnum=0'
    ],
    [
        'apn'        => '10803051',
        'apnDisplay' => '108-03-051',
        'viewerUrl'  => 'https://maps.mcassessor.maricopa.gov/?esearch=10803051&slayer=0&exprnum=0'
    ]
];

function imageExists($apn) {
    return file_exists(__DIR__ . "/parcel_{$apn}.png");
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Test: Generate Parcel Map Images</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 30px; background: #f4f6f8; }
        h1 { color: #14377C; }
        .card {
            background: white;
            border: 2px solid #14377C;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            max-width: 800px;
        }
        .status-ok { background: #d4edda; padding: 10px; border-radius: 6px; }
        .status-missing { background: #fff3cd; padding: 10px; border-radius: 6px; }
        .btn {
            display: inline-block;
            background: #14377C;
            color: white;
            padding: 10px 18px;
            border-radius: 6px;
            text-decoration: none;
            margin-top: 10px;
        }
        pre {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            overflow-x: auto;
        }
        img { max-width: 100%; border: 1px solid #ccc; border-radius: 6px; margin-top: 10px; }
    </style>
</head>
<body>

<h1>Test: Generate Parcel Map Images</h1>
<p>This page helps you generate and verify aerial map images for parcels.</p>

<?php foreach ($parcels as $parcel): ?>
    <div class="card">
        <h3>Parcel <?= $parcel['apnDisplay'] ?> (APN: <?= $parcel['apn'] ?>)</h3>

        <?php if (imageExists($parcel['apn'])): ?>
            <div class="status-ok">
                <strong>✅ Image exists</strong>
            </div>
            <img src="parcel_<?= $parcel['apn'] ?>.png" alt="Map for <?= $parcel['apn'] ?>">
        <?php else: ?>
            <div class="status-missing">
                <strong>⚠️ Image not found</strong><br>
                Run the Playwright script below to generate it.
            </div>
        <?php endif; ?>

        <p><strong>Viewer URL:</strong><br>
            <a href="<?= $parcel['viewerUrl'] ?>" target="_blank"><?= $parcel['viewerUrl'] ?></a>
        </p>
    </div>
<?php endforeach; ?>

<!-- Instructions -->
<div class="card">
    <h3>How to Generate Images</h3>
    <p>Since ScreenshotOne cannot bypass the acknowledgment modal, use the Playwright script below:</p>

    <h4>1. Create <code>generateParcelMaps.js</code></h4>
    <pre><code>const { chromium } = require('playwright');

async function generateParcelImages() {
    const parcels = [
        { apn: "10803009E", url: "https://maps.mcassessor.maricopa.gov/?esearch=10803009E&slayer=0&exprnum=0" },
        { apn: "10803051",  url: "https://maps.mcassessor.maricopa.gov/?esearch=10803051&slayer=0&exprnum=0" }
    ];

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });

    for (const p of parcels) {
        const page = await context.newPage();
        await page.goto(p.url, { waitUntil: 'domcontentloaded' });

        try {
            await page.waitForSelector('text=Welcome to the Maricopa County', { timeout: 7000 });
            await page.check('input[type="checkbox"]');
            await page.click('button:has-text("OK")');
            await page.waitForTimeout(3000);
        } catch (e) {}

        await page.waitForTimeout(3500);
        await page.screenshot({
            path: `parcel_${p.apn}.png`,
            clip: { x: 280, y: 70, width: 950, height: 780 }
        });
        await page.close();
    }
    await browser.close();
    console.log("Done!");
}
generateParcelImages();</code></pre>

    <h4>2. Run it</h4>
    <pre>node generateParcelMaps.js</pre>

    <h4>3. Upload the generated PNG files</h4>
    <p>Upload <code>parcel_10803009E.png</code> and <code>parcel_10803051.png</code> to this folder.</p>

    <p><strong>Then refresh this page</strong> to see the images.</p>
</div>

</body>
</html>