require('dotenv').config({
    path: require('path').resolve(__dirname, '../../secure/env.local')
});

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const token = process.env.BROWSERLESS_API_KEY;
const outputDir = path.resolve(__dirname, '../../assets/runtime/parcelMaps');

if (!token) {
    console.error('❌ BROWSERLESS_API_KEY not found in secure/env.local');
    process.exit(1);
}

if (!fs.existsSync(outputDir)) {
    fs.mkdirSync(outputDir, { recursive: true });
}

const parcelDetails = [
    {
        apnRaw: '10803009E',
        apnDisplay: '108-03-009E',
        viewerUrl: 'https://maps.mcassessor.maricopa.gov/?esearch=10803009E&slayer=0&exprnum=0'
    },
    {
        apnRaw: '10803051',
        apnDisplay: '108-03-051',
        viewerUrl: 'https://maps.mcassessor.maricopa.gov/?esearch=10803051&slayer=0&exprnum=0'
    }
];

async function dismissModal(page) {
    try {
        // Wait for modal to potentially appear
        await page.waitForTimeout(2000);

        const checkbox = page.locator('input[type="checkbox"]');
        const okButton = page.locator('button:has-text("OK")');

        // Try up to 2 times
        for (let attempt = 1; attempt <= 2; attempt++) {
            const hasCheckbox = await checkbox.count() > 0;
            const hasOkButton = await okButton.count() > 0;

            if (!hasCheckbox && !hasOkButton) {
                return true; // Modal probably not present
            }

            console.log(`   → Attempting to dismiss modal (try ${attempt})...`);

            if (hasCheckbox) {
                await checkbox.click({ force: true }).catch(() => {});
                await page.waitForTimeout(500);
            }

            if (hasOkButton) {
                await okButton.click({ force: true }).catch(() => {});
                await page.waitForTimeout(2000);
            }

            // Check if modal is gone
            const stillHasCheckbox = await checkbox.count() > 0;
            const stillHasOkButton = await okButton.count() > 0;

            if (!stillHasCheckbox && !stillHasOkButton) {
                console.log('   ✓ Modal successfully dismissed');
                return true;
            }

            await page.waitForTimeout(1000);
        }

        console.log('   ⚠ Modal may still be present after attempts');
        return false;

    } catch (err) {
        console.log('   ℹ Modal dismissal error (continuing anyway)');
        return false;
    }
}

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
                viewport: { width: 1280, height: 900 }
            });

            page = await context.newPage();

            await page.goto(parcel.viewerUrl, { waitUntil: 'domcontentloaded' });

            // Dismiss modal first (most important step)
            await dismissModal(page);

            // Try to switch to latest aerial basemap
            try {
                await page.locator('text=Basemaps').click({ timeout: 5000 });
                await page.waitForTimeout(600);
                await page.locator('text=2026 Aerials').click({ force: true }).catch(() => {});
                await page.waitForTimeout(1200);
                console.log('   ✓ Switched to latest aerial basemap');
            } catch {
                console.log('   ℹ Could not change basemap');
            }

            // Wait for map to render
            await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
            await page.waitForTimeout(2000);

            const filename = path.join(outputDir, `parcel_${parcel.apnRaw}.png`);
            await page.screenshot({
                path: filename,
                clip: { x: 280, y: 70, width: 950, height: 780 }
            });

            console.log(`   ✓ Saved: ${filename}\n`);

        } catch (err) {
            console.error(`❌ Error processing ${parcel.apnDisplay}:`, err.message);
        } finally {
            if (page) await page.close().catch(() => {});
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