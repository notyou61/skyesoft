/* Skyesoft — index.js
   🧠 Command / Portal Interface Controller
   Phase 1: Card-Based Bootstrap (Login → Command Interface)
   Header / Footer driven exclusively by SSE
*/

// #region 🧩 SkyeApp Page Object
window.SkyIndex = {

    // #region 🧠 Cached DOM State
    dom: null,
    cardHost: null,
    // #endregion

    // #region 🚀 Page Init (called by app.js)
    init() {

        console.log('[SkyIndex] init() fired');

        this.dom = {
            time:     document.getElementById('headerTime'),
            weather:  document.getElementById('headerWeather'),
            interval: document.getElementById('headerInterval'),
            year:     document.getElementById('footerYear'),
            version:  document.getElementById('footerVersion')
        };

        this.cardHost = document.getElementById('boardCardHost');

        if (!this.cardHost) {
            console.error('[SkyIndex] Missing #boardCardHost — index.html shell invalid');
            return;
        }

        // Default state: Login
        this.renderLoginCard();
    },
    // #endregion

    // #region 🧱 Card Rendering
    clearCards() {
        this.cardHost.innerHTML = '';
    },

    renderLoginCard() {
        if (!this.cardHost) return;
        this.clearCards();

        const card = document.createElement('section');
        card.className = 'card card-portal-auth';

        card.innerHTML = `
            <div class="cardHeader">
                <h2>🔐 Authentication Required</h2>
            </div>

            <div class="cardBodyDivider"></div>

            <div class="cardBody">
                <div class="cardContent">

                    <p class="loginIntro">
                        Please sign in to access the Skyesoft Portal.
                    </p>

                    <div class="loginCard">
                        <form class="loginForm">
                            <input type="text" placeholder="Email address" />
                            <input type="password" placeholder="Password" />
                            <button class="crud1 loginButton">Sign In</button>
                            <div class="loginError" hidden></div>
                        </form>
                    </div>

                </div>
            </div>

            <div class="cardFooterDivider"></div>

            <div class="cardFooter">
                🔒 Authentication required to continue
            </div>
        `;

        this.cardHost.appendChild(card);
    },

    renderCommandInterfaceCard() {
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

    // #region 📡 SSE Event Handling
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
window.SkyeApp.registerPage('index', window.SkyIndex);
// #endregion