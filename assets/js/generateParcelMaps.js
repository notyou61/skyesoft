require('dotenv').config({
    path: require('path').resolve(
        __dirname,
        '../../secure/env.local'
    )
});

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

// =====================================================
// 🔐 CONFIG
// =====================================================

const token = process.env.BROWSERLESS_API_KEY;

const outputDir = path.resolve(
    __dirname,
    '../../assets/runtime/parcelMaps'
);

if (!token) {
    console.error('❌ BROWSERLESS_API_KEY not found');
    process.exit(1);
}

if (!fs.existsSync(outputDir)) {
    fs.mkdirSync(outputDir, { recursive: true });
}

// =====================================================
// 🗺️ PARCEL TEST DATA
// =====================================================

const parcelDetails = [
    {
        apnRaw: '10803009E',
        apnDisplay: '108-03-009E'
    },
    {
        apnRaw: '10803051',
        apnDisplay: '108-03-051'
    }
];

// =====================================================
// 🌐 BASE VIEWER URL
// =====================================================

const viewerUrl = 'https://maps.mcassessor.maricopa.gov/';

// =====================================================
// 🧠 DISMISS DOJO SPLASH MODAL
// =====================================================

async function dismissDojoModal(page) {

    try {

        console.log('   → Waiting for splash modal...');

        await page.waitForSelector(
            '.splash-container',
            { timeout: 15000 }
        );

        console.log('   ✓ Splash modal detected');

        // Click checkbox
        await page.evaluate(() => {

            const checkbox = document.querySelector(
                '#jimu_dijit_CheckBox_0 .checkbox'
            );

            if (checkbox) {
                checkbox.click();
            }

        });

        console.log('   ✓ Checkbox clicked');

        await page.waitForTimeout(1500);

        // Enable + click OK button
        await page.evaluate(() => {

            const okButton = document.querySelector(
                '[data-dojo-attach-point="okNode"]'
            );

            if (okButton) {

                okButton.classList.remove('disable-btn');

                const clickEvent = new MouseEvent('click', {
                    view: window,
                    bubbles: true,
                    cancelable: true,
                    buttons: 1
                });

                okButton.dispatchEvent(clickEvent);

            }

        });

        console.log('   ✓ OK button force-clicked');

        await page.waitForSelector(
            '.splash-container',
            {
                state: 'hidden',
                timeout: 15000
            }
        );

        console.log('   ✓ Splash modal dismissed\n');

    } catch (error) {

        console.log(
            '   ⚠ Modal dismissal issue:',
            error.message,
            '\n'
        );

    }

}

// =====================================================
// 🛰️ SWITCH TO AERIAL BASEMAP
// =====================================================

async function switchToAerialBasemap(page) {

    try {

        console.log('   → Switching to aerial basemap...');

        // Open basemap widget
        await page.waitForSelector(
            '#dijit__WidgetBase_1',
            { timeout: 15000 }
        );

        await page.evaluate(() => {

            const basemapButton = document.querySelector(
                '#dijit__WidgetBase_1'
            );

            if (basemapButton) {
                basemapButton.click();
            }

        });

        console.log('   ✓ Basemap gallery opened');

        await page.waitForTimeout(3000);

        // Click 2025 aerial option
        await page.waitForSelector(
            '#galleryNode_basemap_1 a',
            { timeout: 15000 }
        );

        await page.evaluate(() => {

            const aerialLink = document.querySelector(
                '#galleryNode_basemap_1 a'
            );

            if (aerialLink) {
                aerialLink.click();
            }

        });

        console.log('   ✓ 2025 aerial basemap selected');

        // Allow imagery tiles to render
        await page.waitForTimeout(12000);

        console.log('   ✓ Aerial imagery rendered\n');

    } catch (error) {

        console.log(
            '   ⚠ Basemap switch issue:',
            error.message,
            '\n'
        );

    }

}

// =====================================================
// 🔍 SEARCH FOR PARCEL APN
// =====================================================

async function searchParcel(page, parcel) {

    try {

        console.log(`   → Searching APN: ${parcel.apnDisplay}`);

        // Open Search widget
        await page.waitForSelector(
            '#dijit__WidgetBase_0',
            { timeout: 15000 }
        );

        await page.evaluate(() => {

            const searchWidget = document.querySelector(
                '#dijit__WidgetBase_0'
            );

            if (searchWidget) {
                searchWidget.click();
            }

        });

        console.log('   ✓ Search widget opened');

        await page.waitForTimeout(2500);

        // Wait for APN textbox
        await page.waitForSelector(
            '#dijit_form_TextBox_0',
            { timeout: 15000 }
        );

        // =====================================================
        // REAL KEYBOARD INPUT (DOJO SAFE)
        // =====================================================

        const apnInput = await page.$(
            '#dijit_form_TextBox_0'
        );

        await apnInput.click();

        // Clear existing value
        await page.keyboard.down('Control');
        await page.keyboard.press('A');
        await page.keyboard.up('Control');

        await page.keyboard.press('Backspace');

        // Type like a real user
        await page.keyboard.type(
            parcel.apnDisplay,
            { delay: 120 }
        );

        console.log('   ✓ APN entered');

        await page.waitForTimeout(1200);

        // =====================================================
        // CLICK SEARCH BUTTON
        // =====================================================

        await page.getByText('Search').click({
            force: true
        });

        console.log('   ✓ Search executed');

        // =====================================================
        // WAIT FOR PARCEL TO LOAD
        // =====================================================

        await page.waitForTimeout(15000);

        console.log('   ✓ Parcel rendered\n');

    } catch (error) {

        console.log(
            '   ⚠ Parcel search issue:',
            error.message,
            '\n'
        );

    }

}

// =====================================================
// 📸 GENERATE PARCEL IMAGES
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

            console.log(
                `→ Processing: ${parcel.apnDisplay}`
            );

            context = await browser.newContext({
                viewport: {
                    width: 1600,
                    height: 1000
                }
            });

            page = await context.newPage();

            // Open viewer ONLY
            await page.goto(
                viewerUrl,
                { waitUntil: 'domcontentloaded' }
            );

            await page.waitForTimeout(5000);

            // Step 1 - dismiss modal
            await dismissDojoModal(page);

            // Step 2 - switch basemap
            await switchToAerialBasemap(page);

            // Step 3 - search APN
            await searchParcel(page, parcel);

            // Final render wait
            await page.waitForLoadState('networkidle')
                .catch(() => {});

            await page.waitForTimeout(5000);

            // Screenshot
            const filename = path.join(
                outputDir,
                `parcel_${parcel.apnRaw}.png`
            );

            await page.screenshot({
                path: filename,
                clip: {
                    x: 40,
                    y: 80,
                    width: 1500,
                    height: 850
                }
            });

            console.log(
                `   ✓ Saved: ${filename}\n`
            );

        } catch (error) {

            console.error(
                `❌ Error on ${parcel.apnDisplay}:`,
                error.message,
                '\n'
            );

        } finally {

            if (page) {
                await page.close().catch(() => {});
            }

            if (context) {
                await context.close().catch(() => {});
            }

        }

    }

    await browser.close();

    console.log(
        '✅ All parcel maps generated successfully!'
    );

}

// =====================================================
// 🚀 RUN
// =====================================================

generateParcelImages().catch(error => {

    console.error(
        '❌ Fatal error:',
        error
    );

    process.exit(1);

});