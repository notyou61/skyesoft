/* Skyesoft — index.js
   Command Interface Controller
   Phase 0: Authentication Gate Only
*/
// SkyeApp
window.SkyIndex = {
    // Page lifecycle entry
    start() {
        this.init();
    },

    // Initialization (display-only, no authority)
    init() {
        // Document Dom
        this.dom = {
            time: document.getElementById('headerTime'),
            weather: document.getElementById('headerWeather'),
            interval: document.getElementById('headerInterval'),
            year: document.getElementById('footerYear'),
            version: document.getElementById('footerVersion')
        };
        // No clocks
        // No SSE connection
        // No derived state
        // Header/footer react to SSE only
    },

    // SSE event router (read-only reactions)
    onSSE(event) {
        if (!event || !event.type) return; // Guard invalid events

        switch (event.type) {

            // Time update (display-only)
            case 'time:update':
                this.updateTime(event.payload);
                break;

            // Weather update (display-only)
            case 'weather:update':
                this.updateWeather(event.payload);
                break;

            // Interval / phase update
            case 'interval:update':
                this.updateInterval(event.payload);
                break;

            // Meta update (version, year)
            case 'meta:update':
                this.updateMeta(event.payload);
                break;
        }
    },
    // Update header time display
    updateTime(data) {
        const el = document.getElementById('headerTime');
        if (el && data?.display) el.textContent = data.display;
    },
    // Update header weather display
    updateWeather(data) {
        const el = document.getElementById('headerWeather');
        if (el && data?.summary) el.textContent = data.summary;
    },
    // Update header interval display
    updateInterval(data) {
        const el = document.getElementById('headerInterval');
        if (el && data?.label) el.textContent = data.label;
    },
    // Update footer metadata
    updateMeta(data) {
        // Footer year
        if (data?.year) {
            const y = document.getElementById('footerYear');
            if (y) y.textContent = data.year;
        }
        // Footer version
        if (data?.version) {
            const v = document.getElementById('footerVersion');
            if (v) v.textContent = data.version;
        }
    }
};
// Register page with SkyeApp lifecycle
window.SkyeApp.registerPage('index', window.SkyIndex);