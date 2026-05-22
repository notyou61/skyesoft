const { chromium } = require('playwright');
const fs = require('fs');

async function generateParcelImages() {
    // =====================================================
    // Hardcoded parcel data (from your prototype JSON)
    // =====================================================
    const parcelDetails = [
        {
            apnRaw: "10803009E",
            apnDisplay: "108-03-009E",
            viewerUrl: "https://maps.mcassessor.maricopa.gov/?esearch=10803009E&slayer=0&exprnum=0"
        },
        {
            apnRaw: "10803051",
            apnDisplay: "108-03-051",
            viewerUrl: "https://maps.mcassessor.maricopa.gov/?esearch=10803051&slayer=0&exprnum=0"
        }
    ];

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: { width: 1280, height: 900 }
    });

    for (const parcel of parcelDetails) {
        const page = await context.newPage();

        console.log(`→ Generating map for APN: ${parcel.apnDisplay}`);

        await page.goto(parcel.viewerUrl, { waitUntil: 'networkidle' });

        // Wait for map to fully render
        await page.waitForTimeout(3000);

        // Optional: Try to close any welcome/popover modals
        try {
            await page.click('button[aria-label="Close"]', { timeout: 1500 });
        } catch (e) {}

        // Take screenshot focused on the map area
        const filename = `parcel_${parcel.apnRaw}.png`;
        await page.screenshot({
            path: filename,
            clip: {
                x: 280,
                y: 70,
                width: 950,
                height: 780
            }
        });

        console.log(`   ✓ Saved: ${filename}`);
        await page.close();
    }

    await browser.close();
    console.log("\n✅ All parcel map images generated successfully!");
}

generateParcelImages();