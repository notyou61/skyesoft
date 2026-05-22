const path = require('path');

const fs = require('fs');

require('dotenv').config({
    path: path.resolve(
        __dirname,
        '../../secure/env.local'
    )
});

const { chromium } =
    require('playwright');

// =====================================================
// 🔐 SECURE TOKEN LOADING
// =====================================================

const token =
    process.env.BROWSERLESS_API_KEY;

if (!token) {

    console.error(
        '❌ Missing Browserless API key'
    );

    process.exit(1);
}

// =====================================================
// 🌐 BROWSERLESS WEBSOCKET ENDPOINT
// =====================================================

const browserWSEndpoint =
    `wss://production-sfo.browserless.io/chromium?token=${token}`;

// =====================================================
// 📁 OUTPUT DIRECTORY
// =====================================================

const outputDir =
    path.resolve(
        __dirname,
        '../../assets/runtime/parcelMaps'
    );

if (!fs.existsSync(outputDir)) {

    fs.mkdirSync(outputDir, {
        recursive: true
    });
}

// =====================================================
// 🗺️ GENERATE PARCEL MAP IMAGES
// =====================================================

async function generateParcelImages() {

    // -------------------------------------------------
    // Sample parcel data
    // -------------------------------------------------

    const parcelDetails = [

        {
            apnRaw: '10803009E',

            apnDisplay: '108-03-009E',

            viewerUrl:
                'https://maps.mcassessor.maricopa.gov/?esearch=10803009E&slayer=0&exprnum=0'
        },

        {
            apnRaw: '10803051',

            apnDisplay: '108-03-051',

            viewerUrl:
                'https://maps.mcassessor.maricopa.gov/?esearch=10803051&slayer=0&exprnum=0'
        }
    ];

    // -------------------------------------------------
    // Connect to Browserless
    // -------------------------------------------------

    console.log(
        '🌐 Connecting to Browserless...'
    );

    const browser =
        await chromium.connectOverCDP(
            browserWSEndpoint
        );

    console.log(
        '✅ Browserless connection established\n'
    );

    // -------------------------------------------------
    // Create browser context
    // -------------------------------------------------

    const context =
        await browser.newContext({

            viewport: {

                width: 1280,

                height: 900
            }
        });

    // -------------------------------------------------
    // Process parcels
    // -------------------------------------------------

    for (const parcel of parcelDetails) {

        const page =
            await context.newPage();

        console.log(
            `→ Processing: ${parcel.apnDisplay}`
        );

        // ---------------------------------------------
        // Navigate to viewer
        // ---------------------------------------------

        await page.goto(

            parcel.viewerUrl,

            {
                waitUntil: 'domcontentloaded'
            }
        );

        // ---------------------------------------------
        // Handle acknowledgment modal
        // ---------------------------------------------

        try {

            await page.waitForSelector(

                'text=Welcome to the Maricopa County',

                {
                    timeout: 7000
                }
            );

            // -----------------------------------------
            // Checkbox
            // -----------------------------------------

            await page
                .check('input[type="checkbox"]')
                .catch(() => {});

            await page.waitForTimeout(300);

            // -----------------------------------------
            // OK button
            // -----------------------------------------

            await page
                .click('button:has-text("OK")')
                .catch(() => {});

            console.log(
                '   ✓ Acknowledgment modal dismissed'
            );

            // -----------------------------------------
            // Wait for GIS render
            // -----------------------------------------

            await page.waitForTimeout(2500);

        } catch {

            console.log(
                '   ℹ No modal or already accepted'
            );
        }

        // ---------------------------------------------
        // Final render wait
        // ---------------------------------------------

        await page.waitForTimeout(3000);

        // ---------------------------------------------
        // Screenshot path
        // ---------------------------------------------

        const filename =
            path.join(

                outputDir,

                `parcel_${parcel.apnRaw}.png`
            );

        // ---------------------------------------------
        // Capture screenshot
        // ---------------------------------------------

        await page.screenshot({

            path: filename,

            clip: {

                x: 280,

                y: 70,

                width: 950,

                height: 780
            }
        });

        console.log(
            `   ✓ Saved: ${filename}`
        );

        await page.close();
    }

    // -------------------------------------------------
    // Close browser
    // -------------------------------------------------

    await browser.close();

    console.log(
        '\n✅ All parcel maps generated successfully!'
    );
}

// =====================================================
// 🚀 RUN GENERATOR
// =====================================================

generateParcelImages();