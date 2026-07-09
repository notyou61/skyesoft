// /home/notyou64/public_html/skyesoft/api/node-services/renderPlatMap.js
const playwright = require('playwright'); // Or your internal wrapper that connects to Browserless
const sharp = require('sharp');

const [,, rawPayload] = process.argv;
if (!rawPayload) {
    console.error("Missing execution payload arguments.");
    process.exit(1);
}

(async () => {
    let browser;
    try {
        const job = JSON.parse(rawPayload);
        
        // Connect to your existing Playwright / Browserless setup
        browser = await playwright.chromium.launch({
            args: ['--no-sandbox', '--disable-setuid-sandbox']
        });
        
        const context = await browser.newContext({
            viewport: { width: 1400, height: 900 }
        });
        const page = await context.newPage();

        // 1. Navigate directly to the Maricopa Assessor viewer URL
        await page.goto(job.mapUrl, { waitUntil: 'networkidle' });

        // 2. Wait a moment for the native browser PDF plugin layer to completely draw
        await page.waitForTimeout(2500); 

        // 3. Take a clean buffer snapshot of the rendered view
        const screenshotBuffer = await page.screenshot({ type: 'png' });

        // 4. Pass the clean screenshot directly to Sharp to force consistent 1200x800 bounds
        await sharp(screenshotBuffer)
            .resize(1200, 800, {
                fit: 'cover',
                position: 'center'
            })
            .toFormat('png')
            .toFile(job.outputPath);

        console.log(`[NODE-PLAYWRIGHT] ✅ Cleanly captured map view into: ${job.outputPath}`);
        process.exit(0);

    } catch (err) {
        console.error(`[NODE-PLAYWRIGHT] ❌ Execution error: ${err.message}`);
        process.exit(1);
    } finally {
        if (browser) await browser.close();
    }
})();