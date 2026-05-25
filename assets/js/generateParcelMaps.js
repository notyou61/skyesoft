require('dotenv').config({
    path: require('path').resolve(__dirname, '../../secure/env.local')
});

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

// =====================================================
// 🔐 CONFIG
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
// 🗺️ PARCEL DATA
// =====================================================
const parcelDetails = [
    { apnRaw: '10803009E', apnDisplay: '108-03-009E' },
    { apnRaw: '10803051',  apnDisplay: '108-03-051' }
];

const viewerUrl = 'https://maps.mcassessor.maricopa.gov/';

// =====================================================
// 🧠 DISMISS DOJO SPLASH MODAL
// =====================================================
async function dismissDojoModal(page) {
    try {
        await page.waitForSelector('.splash-container', { timeout: 15000 });

        await page.evaluate(() => {
            const checkbox = document.querySelector('#jimu_dijit_CheckBox_0 .checkbox');
            if (checkbox) checkbox.click();
        });
        await page.waitForTimeout(1400);

        await page.evaluate(() => {
            const okButton = document.querySelector('[data-dojo-attach-point="okNode"]');
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

        await page.waitForSelector('.splash-container', { state: 'hidden', timeout: 15000 });
        console.log('   ✓ Splash modal dismissed');

    } catch (error) {
        console.log('   ⚠ Modal issue:', error.message);
    }
}

// =====================================================
// 🛰️ SWITCH TO AERIAL BASEMAP
// =====================================================
async function switchToAerialBasemap(page) {
    try {
        await page.waitForSelector('#dijit__WidgetBase_1', { timeout: 15000 });

        await page.evaluate(() => {
            const btn = document.querySelector('#dijit__WidgetBase_1');
            if (btn) btn.click();
        });
        await page.waitForTimeout(2800);

        await page.waitForSelector('#galleryNode_basemap_1 a', { timeout: 15000 });

        await page.evaluate(() => {
            const aerialLink = document.querySelector('#galleryNode_basemap_1 a');
            if (aerialLink) aerialLink.click();
        });
        console.log('   ✓ Aerial basemap selected');

        await page.waitForTimeout(14000);

    } catch (error) {
        console.log('   ⚠ Basemap switch issue:', error.message);
    }
}

// =====================================================
// 🔍 SEARCH FOR PARCEL
// =====================================================
async function searchParcel(page, parcel) {
    try {
        console.log(`   → Searching APN: ${parcel.apnDisplay}`);

        await page.waitForSelector('#dijit__WidgetBase_0', { timeout: 15000 });
        await page.evaluate(() => {
            const widget = document.querySelector('#dijit__WidgetBase_0');
            if (widget) widget.click();
        });
        await page.waitForTimeout(2000);

        const input = await page.$('#dijit_form_TextBox_0');
        await input.click();
        await page.keyboard.down('Control');
        await page.keyboard.press('A');
        await page.keyboard.up('Control');
        await page.keyboard.press('Backspace');
        await page.keyboard.type(parcel.apnDisplay, { delay: 100 });

        console.log('   ✓ APN entered');
        await page.waitForTimeout(1200);

        await page.waitForSelector('[data-dojo-attach-point="btnSearch"]', { timeout: 15000 });
        await page.evaluate(() => {
            const searchBtn = document.querySelector('[data-dojo-attach-point="btnSearch"]');
            if (searchBtn) {
                const clickEvent = new MouseEvent('click', {
                    view: window,
                    bubbles: true,
                    cancelable: true,
                    buttons: 1
                });
                searchBtn.dispatchEvent(clickEvent);
            }
        });

        console.log('   ✓ Search executed');
        await page.waitForTimeout(10000);

    } catch (error) {
        console.log('   ⚠ Search issue:', error.message);
    }
}

// =====================================================
// 🖱️ SELECT PARCEL (Shows left info panel + cyan highlight)
// =====================================================
async function selectParcel(page) {
    try {
        console.log('   → Selecting parcel...');
        await page.mouse.click(850, 520);
        await page.waitForTimeout(3500);
        console.log('   ✓ Parcel selected');
    } catch (error) {
        console.log('   ⚠ Select parcel issue:', error.message);
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
            console.log(`→ Processing: ${parcel.apnDisplay}`);

            context = await browser.newContext({
                viewport: { width: 1600, height: 1000 }
            });

            page = await context.newPage();

            await page.goto(viewerUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
            await page.waitForTimeout(4500);

            await dismissDojoModal(page);
            await switchToAerialBasemap(page);
            await searchParcel(page, parcel);

            // Click parcel to show left panel + cyan highlight (NO ZOOM)
            await selectParcel(page);

            // Take screenshot
            console.log('   → Taking screenshot...');

            if (page.isClosed()) {
                throw new Error('Page closed before screenshot');
            }

            const filename = path.join(outputDir, `parcel_${parcel.apnRaw}.png`);

            await page.screenshot({
                path: filename,
                clip: { x: 40, y: 80, width: 1500, height: 850 }
            });

            console.log(`   ✓ Saved: ${filename}\n`);

        } catch (error) {
            console.error(`❌ Error on ${parcel.apnDisplay}:`, error.message);
        } finally {
            if (page && !page.isClosed()) {
                await page.close().catch(() => {});
            }
            if (context) {
                await context.close().catch(() => {});
            }
        }
    }

    await browser.close();
    console.log('✅ All parcel maps generated successfully!');
}

// =====================================================
// 🚀 RUN
// =====================================================
generateParcelImages().catch(error => {
    console.error('❌ Fatal error:', error);
    process.exit(1);
});