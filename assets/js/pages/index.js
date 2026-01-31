/* Skyesoft — index.js
   🧠 Command / Portal Interface Controller
   Phase 1: Card-Based Bootstrap (Login → Command Interface)
   Header / Footer driven exclusively by SSE
*/

// #region 🧩 SkyeApp Page Object
window.SkyIndex = {

    // #region 🧠 Cached DOM State
    // Centralized element references captured at init()
    // Prevents repeated DOM queries and stabilizes render flow
    dom: null,

    // Primary host for all dynamically injected cards (login / command)
    cardHost: null,
    // #endregion

    // #region 🚀 Lifecycle Methods
    start() {
        this.init();
        this.renderLoginCard();
    },

    init() {
        this.dom = {
            time:     document.getElementById('headerTime'),
            weather:  document.getElementById('headerWeather'),
            interval: document.getElementById('headerInterval'),
            year:     document.getElementById('footerYear'),
            version:  document.getElementById('footerVersion')
        };

        this.cardHost = document.getElementById('boardCardHost');

        // Development safety check
        if (!this.cardHost) {
            console.error('[SkyIndex] Missing #boardCardHost — index.html shell invalid');
        }
    },
    // #endregion

    // #region 🧱 Card Rendering
    clearCards() {
        if (this.cardHost) {
            this.cardHost.innerHTML = '';
        }
    },

    renderLoginCard() {
        if (!this.cardHost) return;

        this.clearCards();

        const card = document.createElement('div');
        card.className = 'card';

        card.innerHTML = `
            <div class="cardHeader">
                <h2>🔒 Authentication Required</h2>
            </div>
            <div class="cardBody">
                <div class="cardContent">
                    <p style="text-align:center; font-size:1.1em; color:#555; margin-top:40px;">
                        Please sign in to access the Skyesoft Command Interface.
                    </p>

                    <div style="max-width:360px; margin:40px auto 0;">
                        <input type="text" placeholder="Username"
                               style="width:100%; padding:10px; margin-bottom:12px;" />

                        <input type="password" placeholder="Password"
                               style="width:100%; padding:10px; margin-bottom:16px;" />

                        <button class="crud1" style="width:100%;">
                            Sign In
                        </button>
                    </div>
                </div>
            </div>
        `;

        this.cardHost.appendChild(card);
    },

    renderCommandInterfaceCard() {
        if (!this.cardHost) return;

        this.clearCards();

        const card = document.createElement('div');
        card.className = 'card';

        card.innerHTML = `
            <div class="cardHeader">
                <h2>🧠 Skyesoft Command Interface</h2>
            </div>
            <div class="cardBody">
                <div class="cardContent">
                    <p style="text-align:center; font-size:1.1em; color:#666; margin-top:40px;">
                        Command environment initialized.
                    </p>
                </div>
            </div>
        `;

        this.cardHost.appendChild(card);
    },
    // #endregion

    // #region 📡 SSE Event Handling (Display Only)
    onSSE(event) {
        if (!event?.type) return;

        switch (event.type) {

            case 'time:update':
                this.dom?.time && (this.dom.time.textContent = event.payload.display);
                break;

            case 'weather:update':
                this.dom?.weather && (this.dom.weather.textContent = event.payload.summary);
                break;

            case 'interval:update':
                this.dom?.interval && (this.dom.interval.textContent = event.payload.label);
                break;

            case 'meta:update':
                if (event.payload.year && this.dom?.year) {
                    this.dom.year.textContent = event.payload.year;
                }
                if (event.payload.version && this.dom?.version) {
                    this.dom.version.textContent = event.payload.version;
                }
                break;
        }
    }
    // #endregion
};
// #endregion

// #region 🧾 Page Registration
window.SkyeApp?.registerPage?.('index', window.SkyIndex);
// #endregion