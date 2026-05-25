// @ts-nocheck
require('dotenv').config({
    path: require('path').resolve(__dirname, '../../secure/env.local')
});

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const sharp = require('sharp');

// =====================================================
// CONFIG
// =====================================================
const BROWSERLESS_TOKEN = process.env.BROWSERLESS_API_KEY;
const GOOGLE_MAPS_API_KEY = process.env.GOOGLE_MAPS_API_KEY;

console.log('🔑 GOOGLE_MAPS_API_KEY:', GOOGLE_MAPS_API_KEY ? 'Present' : 'Missing');

const RUNTIME_ROOT = path.resolve(__dirname, '../../data/runtimeEphemeral');
const ARTIFACTS_DIR = path.join(RUNTIME_ROOT, 'proposalArtifacts');

if (!fs.existsSync(ARTIFACTS_DIR)) {
    fs.mkdirSync(ARTIFACTS_DIR, { recursive: true });
}

// =====================================================
// PROPOSAL PAYLOAD
// =====================================================
let proposalPacket = {
    "data": {
        "reportTitle": "Proposed Contact Report (PC-3)",
        "entityName": "Christy Signs",
        "contactName": "Ms Susan Alderson",
        "contactTitle": "Accounting",
        "contactPhone": "(602) 242-4488",
        "contactEmail": "susan@christysigns.com",
        "locationAddress": "3145 N 33rd Ave",
        "locationCityStateZip": "Phoenix, AZ 85017",
        "locationPlaceId": "ChIJeTvhT3ATK4cRpfapSIlCjFw",
        "locationLatitude": 33.4848,
        "locationLongitude": -112.1288,
        "confidence": 85,
        "pcCode": "PC-3",
        "resolutionStatus": "multiple_parcels",
        "commitAllowed": "NO",
        "governanceNarrative": "This proposal references an existing operational location. Review: Multiple parcel candidates were found at this address and user selection is required before commit.",
        "entityAction": "reuse",
        "locationAction": "reuse",
        "contactAction": "create",
        "location": {
            "parcelDetails": [
                { "apnRaw": "10803009E", "apnDisplay": "108-03-009E", "address": "3145 N 33RD AVE", "city": "PHOENIX", "owner": "RONALD L REYNOLDS AND JACQUELINE S REYNOLDS FAMILY TRUST", "confidence": 98, "lat": 33.4847790, "lng": -112.1287620 },
                { "apnRaw": "10803051", "apnDisplay": "108-03-051", "address": "3145 N 33RD AVE", "city": "PHOENIX", "owner": "J2 FLOWER LLC", "confidence": 98, "lat": 33.485107, "lng": -112.128783 }
            ]
        }
    },

    "proposal": {
        "proposalCode": "PRP-0042",
        "proposalType": "contact",
        "proposalStatus": "pending",
        "proposalCreatedOn": Math.floor(Date.now() / 1000),
        "proposalReason": "multiple_parcels",
        "artifactRegistry": {
            "artifactRuntimeRoot": "/data/runtimeEphemeral/proposalArtifacts/",
            "artifacts": []
        }
    }
};

// =====================================================
// HELPERS
// =====================================================
function addArtifact(code, category, type, filename) {
    proposalPacket.proposal.artifactRegistry.artifacts.push({
        "artifactCode": code,
        "artifactCategory": category,
        "artifactType": type,
        "artifactFilename": filename,
        "artifactStatus": "runtime",
        "generatedOn": Math.floor(Date.now() / 1000)
    });
}

async function processParcelImage(rawPath, finalPath) {
    try {
        await sharp(rawPath)
            .extract({ left: 0, top: 0, width: 720, height: 600 })
            .png()
            .toFile(finalPath);
        console.log(`   ✓ Processed: ${finalPath}`);
    } catch (err) {
        console.error('   ❌ Sharp failed:', err.message);
    }
}

// =====================================================
// PARCEL GENERATION
// =====================================================
async function generateParcelArtifacts() {
    if (!BROWSERLESS_TOKEN) {
        console.log('⚠️ No BROWSERLESS_API_KEY — skipping real parcels');
        proposalPacket.data.location.parcelDetails.forEach((p, i) => {
            const num = (i + 1).toString().padStart(2, '0');
            addArtifact(`IMG-PRP0042-PARCEL-${num}`, 'image', 'parcel_candidate', `IMG-PRP0042-PARCEL-${num}.png`);
        });
        return;
    }

    console.log('🌐 Generating Parcel Maps...');
    const browser = await chromium.connectOverCDP(`wss://production-sfo.browserless.io/chromium?token=${BROWSERLESS_TOKEN}`);

    for (let i = 0; i < proposalPacket.data.location.parcelDetails.length; i++) {
        const parcel = proposalPacket.data.location.parcelDetails[i];
        const num = (i + 1).toString().padStart(2, '0');
        const filename = `IMG-PRP0042-PARCEL-${num}.png`;
        const finalPath = path.join(ARTIFACTS_DIR, filename);
        const rawPath = path.join(ARTIFACTS_DIR, `raw_${parcel.apnRaw}.png`);

        try {
            const context = await browser.newContext({ viewport: { width: 1600, height: 1000 } });
            const page = await context.newPage();

            const params = new URLSearchParams({
                '1': parcel.lat,
                '2': parcel.lng,
                'a': parcel.address + " Phoenix AZ 85017"
            });
            const url = `https://maps.mcassessor.maricopa.gov/ipa.aspx?${params.toString()}`;

            await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
            await page.waitForTimeout(12000);

            await page.screenshot({ path: rawPath });
            await processParcelImage(rawPath, finalPath);

            if (fs.existsSync(rawPath)) fs.unlinkSync(rawPath);

            addArtifact(`IMG-PRP0042-PARCEL-${num}`, 'image', 'parcel_candidate', filename);
            console.log(`   ✅ Parcel ${num} completed`);
        } catch (error) {
            console.error(`   ❌ Parcel ${num} error:`, error.message);
        }
    }

    await browser.close();
}

// =====================================================
// STREET VIEW
// =====================================================
async function generateStreetViewArtifact() {
    const filename = `IMG-PRP0042-STREET-01.jpg`;
    const fullPath = path.join(ARTIFACTS_DIR, filename);

    console.log('🌐 Capturing Street View...');

    if (!BROWSERLESS_TOKEN) {
        console.log('   ⚠️ No BROWSERLESS_API_KEY — skipping Street View');
        addArtifact('IMG-PRP0042-STREET-01', 'image', 'street_view', filename);
        return;
    }

    try {
        const browser = await chromium.connectOverCDP(`wss://production-sfo.browserless.io/chromium?token=${BROWSERLESS_TOKEN}`);
        const context = await browser.newContext({ viewport: { width: 1280, height: 720 } });
        const page = await context.newPage();

        const streetUrl = "https://www.google.com/maps/@33.4847459,-112.1292137,3a,75y,90t/data=!3m7!1e1!3m5!1sGrL67v9XGEnroxpnqZlAog!2e0!6shttps:%2F%2Fstreetviewpixels-pa.googleapis.com%2Fv1%2Fthumbnail%3Fcb_client%3Dmaps_sv.tactile%26w%3D900%26h%3D600%26pitch%3D0%26panoid%3DGrL67v9XGEnroxpnqZlAog%26yaw%3D0!7i16384!8i8192";

        await page.goto(streetUrl, { waitUntil: 'domcontentloaded', timeout: 45000 });
        await page.waitForTimeout(8000);

        await page.screenshot({ path: fullPath });
        console.log(`   ✅ Street View captured and saved: ${filename}`);

        await browser.close();
    } catch (error) {
        console.error('   ❌ Street View capture failed:', error.message);
    }

    addArtifact('IMG-PRP0042-STREET-01', 'image', 'street_view', filename);
}

// =====================================================
// GOOGLE MAP ARTIFACT (UPDATED)
// =====================================================
async function generateGoogleMapArtifact() {
    const filename = `IMG-PRP0042-GMAP-01.png`;
    const fullPath = path.join(ARTIFACTS_DIR, filename);

    console.log('🌐 Generating Google Map Artifact...');

    if (!GOOGLE_MAPS_API_KEY || !BROWSERLESS_TOKEN) {
        console.log('   ⚠️ Missing GOOGLE_MAPS_API_KEY or BROWSERLESS_TOKEN — skipping Google Map image');
        addArtifact('IMG-PRP0042-GMAP-01', 'image', 'google_map', filename);
        return;
    }

    let lat = proposalPacket.data.locationLatitude;
    let lng = proposalPacket.data.locationLongitude;
    let source = 'fallback (stored coordinates)';

    try {
        const placeId = proposalPacket.data.locationPlaceId;

        const placeDetailsUrl =
            `https://maps.googleapis.com/maps/api/place/details/json` +
            `?place_id=${placeId}` +
            `&fields=geometry` +
            `&key=${GOOGLE_MAPS_API_KEY}`;

        console.log(`   🔍 Fetching Place Details for PlaceID: ${placeId}`);

        const response = await fetch(placeDetailsUrl);
        const placeData = await response.json();

        if (placeData.status === 'OK' && placeData.result?.geometry?.location) {
            lat = placeData.result.geometry.location.lat;
            lng = placeData.result.geometry.location.lng;
            source = 'Place Details API';
        } else {
            console.log(`   ⚠️ Place Details returned ${placeData.status} — using fallback coordinates`);
        }
    } catch (err) {
        console.log(`   ⚠️ Place Details error — using fallback coordinates`);
    }

    // Generate the map using either resolved or fallback coordinates
    try {
        const mapUrl =
            `https://maps.googleapis.com/maps/api/staticmap` +
            `?center=${lat},${lng}` +
            `&zoom=19` +
            `&scale=2` +
            `&size=1200x700` +
            `&maptype=satellite` +
            `&markers=color:red|${lat},${lng}` +
            `&key=${GOOGLE_MAPS_API_KEY}`;

        const browser = await chromium.connectOverCDP(
            `wss://production-sfo.browserless.io/chromium?token=${BROWSERLESS_TOKEN}`
        );

        const context = await browser.newContext({ viewport: { width: 1200, height: 700 } });
        const page = await context.newPage();

        await page.goto(mapUrl, { waitUntil: 'networkidle', timeout: 45000 });
        await page.screenshot({ path: fullPath });

        console.log(`   ✅ Google Map saved: ${filename} (${source})`);
        await browser.close();

    } catch (error) {
        console.error('   ❌ Google Map screenshot failed:', error.message);
    }

    addArtifact('IMG-PRP0042-GMAP-01', 'image', 'google_map', filename);
}

// =====================================================
// MAIN
// =====================================================
async function runProposalPacketFlow() {
    console.log(`🚀 Starting Proposal Packet Flow → ${proposalPacket.proposal.proposalCode}\n`);

    await generateParcelArtifacts();
    await generateStreetViewArtifact();
    await generateGoogleMapArtifact();

    const snapshotPath = path.join(
        RUNTIME_ROOT,
        `${proposalPacket.proposal.proposalCode}.json`
    );

    fs.writeFileSync(
        snapshotPath,
        JSON.stringify(proposalPacket, null, 2)
    );

    console.log(`💾 Proposal Snapshot saved: ${snapshotPath}`);
    console.log(`   Total Artifacts: ${proposalPacket.proposal.artifactRegistry.artifacts.length}`);
    console.log('\n✅ Proposal Packet Complete!');
}

runProposalPacketFlow().catch(console.error);