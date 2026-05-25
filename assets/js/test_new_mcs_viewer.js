// @ts-nocheck
require('dotenv').config({
    path: require('path').resolve(__dirname, '../../secure/env.local')
});

const { chromium } = require('playwright');
const sharp = require('sharp');
const fs = require('fs');
const path = require('path');

// =====================================================
// CONFIG
// =====================================================
const token = process.env.BROWSERLESS_API_KEY;
const outputDir = path.resolve(__dirname, '../../assets/runtime/parcelMaps');

if (!token) {
    console.error('❌ BROWSERLESS_API_KEY not found');
    process.exit(1);
}

if (!fs.existsSync(outputDir)) {
    fs.mkdirSync(outputDir, { recursive: true });
}

// =====================================================
// PARCEL DATA
// =====================================================
const parcelDetails = [
    {
        apnRaw: '10803009E',
        apnDisplay: '108-03-009E',
        lat: 33.4847790,
        lng: -112.1287620,
        address: '3145 N 33rd Ave Phoenix AZ 85017'
    },
    {
        apnRaw: '10803051',
        apnDisplay: '108-03-051',
        lat: 33.485107,
        lng: -112.128783,
        address: '3145 N 33RD AVE PHOENIX AZ 85017'
    }
];

// =====================================================
// BUILD URL
// =====================================================
function buildIpaUrl(parcel) {
    const params = new URLSearchParams({
        '1': parcel.lat,
        '2': parcel.lng,
        'a': parcel.address
    });
    return `https://maps.mcassessor.maricopa.gov/ipa.aspx?${params.toString()}`;
}

// =====================================================
// PROCESS IMAGE WITH SHARP (Fixed Crop)
// =====================================================
async function processParcelImage(rawPath, finalPath) {
    try {
        await sharp(rawPath)
            .extract({
                left: 0,
                top: 0,
                width: 720,
                height: 600          // Increased to include bottom address text
            })
            .png()
            .toFile(finalPath);

        console.log(`   ✓ Processed and saved: ${finalPath}`);
    } catch (err) {
        console.error('   ❌ Sharp processing failed:', err.message);
        throw err;
    }
}

// =====================================================
// CAPTURE + PROCESS
// =====================================================
async function captureIpaScreenshot(page, parcel) {
    const url = buildIpaUrl(parcel);
    console.log(`   → Loading: ${url}`);

    const rawPath = path.join(outputDir, `raw_${parcel.apnRaw}.png`);
    const finalPath = path.join(outputDir, `parcel_${parcel.apnRaw}.png`);

    [rawPath, finalPath].forEach(f => {
        if (fs.existsSync(f)) fs.unlinkSync(f);
    });

    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(12000);

    await page.screenshot({ path: rawPath });
    console.log('   ✓ Raw screenshot captured');

    await processParcelImage(rawPath, finalPath);

    if (fs.existsSync(rawPath)) fs.unlinkSync(rawPath);
}

// =====================================================
// MAIN
// =====================================================
async function generateParcelImages() {
    console.log('🌐 Connecting to Browserless...');

    const browser = await chromium.connectOverCDP(
        `wss://production-sfo.browserless.io/chromium?token=${token}`
    );
    console.log('✅ Connected to Browserless\n');

    for (const parcel of parcelDetails) {
        let context;
        let page;

        try {
            console.log(`→ Processing: ${parcel.apnDisplay}`);

            context = await browser.newContext({
                viewport: { width: 1600, height: 1000 }
            });

            page = await context.newPage();

            await captureIpaScreenshot(page, parcel);

        } catch (error) {
            console.error(`❌ Error on ${parcel.apnDisplay}:`, error.message);
        } finally {
            if (page && !page.isClosed()) await page.close().catch(() => {});
            if (context) await context.close().catch(() => {});
        }
    }

    await browser.close();
    console.log('✅ All parcel maps generated successfully!');
}

generateParcelImages().catch(err => {
    console.error('❌ Fatal error:', err);
    process.exit(1);
});