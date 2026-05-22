const path = require('path');

require('dotenv').config({
    path: path.resolve(__dirname, '../../secure/env.local')
});

const puppeteer = require('puppeteer-core');

// =====================================================
// 🔐 SECURE TOKEN LOADING
// =====================================================

const token =
    process.env.BROWSERLESS_API_KEY;

if (!token) {

    console.error(
        '❌ BROWSERLESS_API_KEY is not set in environment'
    );

    process.exit(1);
}

// =====================================================
// 🌐 BROWSERLESS WEBSOCKET ENDPOINT
// =====================================================

const browserWSEndpoint =
    `wss://production-sfo.browserless.io/chromium?token=${token}`;

// =====================================================
// 🧪 BROWSERLESS CONNECTION TEST
// =====================================================

async function testBrowserlessConnection() {

    console.log('========================================');

    console.log(
        '🧪 Skyesoft Browserless Connection Test'
    );

    console.log('========================================\n');

    let browser;

    try {

        console.log(
            '→ Connecting to Browserless (WebSocket)...'
        );

        browser =
            await puppeteer.connect({

                browserWSEndpoint:
                    browserWSEndpoint
            });

        console.log(
            '✅ Successfully connected to Browserless\n'
        );

        // -------------------------------------------------
        // Create page
        // -------------------------------------------------

        const page =
            await browser.newPage();

        // -------------------------------------------------
        // Set viewport
        // -------------------------------------------------

        await page.setViewport({

            width: 1280,

            height: 800
        });

        // -------------------------------------------------
        // Navigate
        // -------------------------------------------------

        console.log(
            '→ Navigating to example.com...'
        );

        await page.goto(

            'https://example.com',

            {
                waitUntil: 'domcontentloaded'
            }
        );

        // -------------------------------------------------
        // Page title
        // -------------------------------------------------

        const title =
            await page.title();

        console.log(
            '✅ Page loaded successfully'
        );

        console.log(
            `   Title: "${title}"\n`
        );

        console.log(
            '✅ Browserless test PASSED\n'
        );

    } catch (error) {

        console.error(
            '❌ Browserless connection failed'
        );

        console.error(
            'Error:',
            error.message
        );

        process.exitCode = 1;

    } finally {

        if (browser) {

            await browser.disconnect();

            console.log(
                '🔌 Disconnected from Browserless'
            );
        }
    }

    console.log(
        '========================================'
    );
}

// =====================================================
// 🚀 RUN TEST
// =====================================================

testBrowserlessConnection();