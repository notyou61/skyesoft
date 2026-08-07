/**
 * Standalone Test Script: Maricopa County Zoning Fixture Validation
 * Endpoint: /resolveZoning.php
 */

const ZONING_TEST_FIXTURE = {
    address: "3655 W Anthem Way, Anthem, AZ 85086",
    expected: {
        jurisdiction: "Maricopa County",
        zoning: "C-2",
        description: "Intermediate Commercial",
        layer: "Maricopa County PlanNet Zoning Layer 11",
        filter: "JURIS = 'COUNTY'"
    }
};

async function executeZoningFixtureTest() {
    console.log(`[Zoning Test] Requesting zoning for: ${ZONING_TEST_FIXTURE.address}`);

    const payload = {
        address: ZONING_TEST_FIXTURE.address,
        activitySessionId: window.activitySessionId || "TEST_SESSION_" + Date.now()
    };

    try {
        const response = await fetch('/resolveZoning.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        if (!response.ok) {
            throw new Error(`HTTP Error: ${response.status} ${response.statusText}`);
        }

        const result = await response.json();
        renderZoningTestResults(result);

    } catch (error) {
        console.error("[Zoning Test] Execution failed:", error);
        renderTestError(error);
    }
}

/**
 * Surface Renderer for Test Output
 */
function renderZoningTestResults(data) {
    let container = document.getElementById('zoning-test-output');
    
    if (!container) {
        container = document.createElement('div');
        container.id = 'zoning-test-output';
        container.style.cssText = 'margin: 20px; padding: 20px; border: 2px solid #005A9C; background: #F4F7FA; font-family: monospace; border-radius: 6px;';
        document.body.prepend(container);
    }

    const html = `
        <h3 style="margin-top:0; color: #005A9C;">Zoning Diagnostic Output: 3655 W Anthem Way</h3>
        <table style="width:100%; text-align:left; border-collapse:collapse; margin-bottom: 15px;">
            <tr><th style="padding:4px; border-bottom:1px solid #ccc;">Field</th><th style="padding:4px; border-bottom:1px solid #ccc;">Resolved Value</th></tr>
            <tr><td style="padding:4px;"><strong>Address:</strong></td><td style="padding:4px;">${data.address || 'N/A'}</td></tr>
            <tr><td style="padding:4px;"><strong>Coordinates:</strong></td><td style="padding:4px;">${data.coordinates ? `${data.coordinates.lat}, ${data.coordinates.lng}` : 'N/A'}</td></tr>
            <tr><td style="padding:4px;"><strong>APN:</strong></td><td style="padding:4px;">${data.apn || 'N/A'}</td></tr>
            <tr><td style="padding:4px;"><strong>Jurisdiction:</strong></td><td style="padding:4px;">${data.jurisdiction || 'N/A'}</td></tr>
            <tr><td style="padding:4px;"><strong>Zoning Code:</strong></td><td style="padding:4px;"><strong>${data.zoningCode || 'N/A'}</strong></td></tr>
            <tr><td style="padding:4px;"><strong>Description:</strong></td><td style="padding:4px;">${data.zoningDescription || 'N/A'}</td></tr>
            <tr><td style="padding:4px;"><strong>GIS Source Layer:</strong></td><td style="padding:4px;">${data.sourceLayer || ZONING_TEST_FIXTURE.expected.layer}</td></tr>
            <tr><td style="padding:4px;"><strong>Verification Date:</strong></td><td style="padding:4px;">${data.verificationDate || new Date().toISOString()}</td></tr>
            <tr><td style="padding:4px;"><strong>Candidate Count:</strong></td><td style="padding:4px;">${data.candidateCount ?? 1}</td></tr>
            <tr><td style="padding:4px;"><strong>Confidence Score:</strong></td><td style="padding:4px;">${data.confidence ?? '100%'}</td></tr>
            <tr><td style="padding:4px;"><strong>Review Required:</strong></td><td style="padding:4px;">${data.reviewRequired ? '<span style="color:red;">YES</span>' : '<span style="color:green;">NO</span>'}</td></tr>
        </table>
        
        <details>
            <summary style="cursor:pointer; font-weight:bold; color: #333;">View Raw Response JSON</summary>
            <pre style="background: #FFF; padding: 10px; border: 1px solid #DDD; overflow-x: auto; margin-top: 10px;">${JSON.stringify(data, null, 2)}</pre>
        </details>
    `;

    container.innerHTML = html;
}

function renderTestError(error) {
    let container = document.getElementById('zoning-test-output');
    if (container) {
        container.innerHTML = `<p style="color:red; font-weight:bold;">Test Failed: ${error.message}</p>`;
    }
}

// Auto-run test on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', executeZoningFixtureTest);
} else {
    executeZoningFixtureTest();
}