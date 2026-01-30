/* Skyesoft — index.js
   Command Interface Controller
   Phase 0: Authentication Gate Only – Header/Footer Display Driver
*/

// #region SkyeApp Page Object
window.SkyIndex = {
    dom: null,  // Cached DOM references – set in init()
    // #endregion

    // #region Lifecycle Methods
    start() {
        this.init();
    },

    init() {
        this.dom = {
            time:     document.getElementById('headerTime'),
            weather:  document.getElementById('headerWeather'),
            interval: document.getElementById('headerInterval'),
            year:     document.getElementById('footerYear'),
            version:  document.getElementById('footerVersion')
        };

        // Early DOM sanity check (useful during development / HTML changes)
        if (!this.dom.time || !this.dom.weather) {
            console.warn('[SkyIndex] Missing one or more header/footer DOM elements');
        }

        // Note: No local timers or SSE setup – SSE is handled globally via sse.js
    },
    // #endregion

    // #region SSE Event Handling
    onSSE(event) {
        if (!event?.type) return;

        switch (event.type) {
            case 'time:update':
                this.updateTime(event.payload);
                break;

            case 'weather:update':
                this.updateWeather(event.payload);
                break;

            case 'interval:update':
                this.updateInterval(event.payload);
                break;

            case 'meta:update':
                this.updateMeta(event.payload);
                break;

            default:
                // Optional debug line – uncomment during development if needed
                // console.debug('[SkyIndex] Ignored unknown SSE type:', event.type);
                break;
        }
    },
    // #endregion

    // #region Display Update Methods
    updateTime(data) {
        if (this.dom?.time && data?.display) {
            this.dom.time.textContent = data.display;
        }
    },

    updateWeather(data) {
        if (this.dom?.weather && data?.summary) {
            this.dom.weather.textContent = data.summary;
        }
    },

    updateInterval(data) {
        if (this.dom?.interval && data?.label) {
            this.dom.interval.textContent = data.label;
        }
    },

    updateMeta(data) {
        if (!data) return;

        if (data.year && this.dom?.year) {
            this.dom.year.textContent = data.year;
        }

        if (data.version && this.dom?.version) {
            this.dom.version.textContent = data.version;
        }
    },
    // #endregion
};

// #region Page Registration
window.SkyeApp?.registerPage?.('index', window.SkyIndex);
// #endregion