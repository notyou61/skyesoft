const { chromium } = require('playwright');

async function generateParcelImages() {
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
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });

    for (const parcel of parcelDetails) {
        const page = await context.newPage();
        console.log(`→ Processing: ${parcel.apnDisplay}`);

        await page.goto(parcel.viewerUrl, { waitUntil: 'domcontentloaded' });

        // Handle acknowledgment modal
        try {
            await page.waitForSelector('text=Welcome to the Maricopa County', { timeout: 7000 });

            // Click checkbox
            await page.check('input[type="checkbox"]').catch(() => {});
            await page.waitForTimeout(300);

            // Click OK button
            await page.click('button:has-text("OK")').catch(() => {});
            console.log('   ✓ Acknowledgment modal dismissed');

            await page.waitForTimeout(2500); // Wait for map to load
        } catch {
            console.log('   ℹ No modal or already accepted');
        }

        // Final wait for map rendering
        await page.waitForTimeout(3000);

        const filename = `parcel_${parcel.apnRaw}.png`;
        await page.screenshot({
            path: filename,
            clip: { x: 280, y: 70, width: 950, height: 780 }
        });

        console.log(`   ✓ Saved: ${filename}`);
        await page.close();
    }

    await browser.close();
    console.log("\n✅ All parcel maps generated successfully!");
}

generateParcelImages();