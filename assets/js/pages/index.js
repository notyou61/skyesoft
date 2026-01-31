/* Skyesoft — index.js
   🧠 Portal Interface Controller
   Phase 1: Card-Based Bootstrap (Login → Command Interface)
   Header / Footer driven exclusively by SSE
*/

console.log('[SkyIndex] index.js loaded');

// #region 🧩 SkyeApp Page Object
window.SkyIndex = {

    // #region 🧠 Cached DOM & State
    // Centralized DOM references and lightweight view state
    dom: null,

    // Primary host for dynamically injected portal cards
    cardHost: null,

    // Portal view state: 'login' | 'command'
    state: 'login',
    // #endregion

    // #region 🚀 Lifecycle Methods
    start() {
        console.log('[SkyIndex] start() called');
        this.init();
        this.renderLoginCard()
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

        // Development sanity check
        if (!this.cardHost) {
            console.error('[SkyIndex] Missing #boardCardHost — index.html shell invalid');
        }
    },
    // #endregion

    // #region 🔁 View Transitions
    showLogin() {
        this.state = 'login';
        this.renderLoginCard();
    },

    showCommandInterface() {
        this.state = 'command';
        this.renderCommandInterfaceCard();
    },
    // #endregion

    // #region 🧱 Card Rendering Utilities
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
                <h2>🔒 Login</h2>
            </div>

            <div class="cardBody">
                <div class="cardContent loginCard">
                    <div class="loginIntro">
                        Please sign in to access the Skyesoft Portal.
                    </div>
                    <form id="loginForm" class="loginForm" autocomplete="off">
                        <input
                            type="text"
                            id="loginUsername"
                            placeholder="Username"
                            required
                        />
                        <input
                            type="password"
                            id="loginPassword"
                            placeholder="Password"
                            required
                        />
                        <button type="submit" class="crud1 loginButton">
                            Sign In
                        </button>

                        <div id="loginError" class="loginError" hidden>
                            Invalid username or password.
                        </div>
                    </form>
                </div>
            </div>
        `;

        this.cardHost.appendChild(card);

        // Stub handler (will be replaced with real auth)
        const form = card.querySelector('#loginForm');
        form?.addEventListener('submit', (e) => {
            e.preventDefault();

            // TEMP: simulate successful login
            this.showCommandInterface();
        });
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