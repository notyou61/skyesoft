/* Skyesoft — index.js
   🧠 Command / Portal Interface Controller
   Phase 1: Card-Based Bootstrap (Login → Command Interface)
   Header / Footer driven exclusively by SSE
*/

// #region ⏱️ Format Version Footer (canonical, shared behavior)
function formatVersionFooter(siteMeta, referenceUnix) {
    if (!siteMeta?.siteVersion || !siteMeta?.lastUpdateUnix) {
        return `v${siteMeta?.siteVersion ?? '—'}`;
    }

    const updatedUnix = siteMeta.lastUpdateUnix;
    const refUnix = referenceUnix ?? Math.floor(Date.now() / 1000);

    // Absolute date/time
    const d = new Date(updatedUnix * 1000);
    const TZ = 'America/Phoenix';

    const dateStr = d.toLocaleDateString('en-US', {
        timeZone: TZ,
        month: '2-digit',
        day: '2-digit',
        year: '2-digit'
    });

    const timeStr = d.toLocaleTimeString('en-US', {
        timeZone: TZ,
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
    });

    // Relative age (hrs + min, no seconds)
    const deltaSeconds = Math.max(0, refUnix - updatedUnix);

    let agoStr = '';

    if (deltaSeconds < 3600) {
        const mins = Math.floor(deltaSeconds / 60);
        agoStr = `${String(mins).padStart(2, '0')} min ago`;
    } else if (deltaSeconds < 86400) {
        const hrs  = Math.floor(deltaSeconds / 3600);
        const mins = Math.floor((deltaSeconds % 3600) / 60);
        agoStr = `${String(hrs).padStart(2, '0')} hrs ${String(mins).padStart(2, '0')} min ago`;
    } else if (deltaSeconds < 2592000) {
        const days = Math.floor(deltaSeconds / 86400);
        agoStr = `${String(days).padStart(2, '0')} days ago`;
    } else if (deltaSeconds < 31536000) {
        const mos = Math.floor(deltaSeconds / 2592000);
        agoStr = `${String(mos).padStart(2, '0')} mos ago`;
    } else {
        const yrs = Math.floor(deltaSeconds / 31536000);
        agoStr = `${String(yrs).padStart(2, '0')} yrs ago`;
    }

    return `v${siteMeta.siteVersion} · ${dateStr} ${timeStr} (${agoStr})`;
}
// #endregion

// #region 🔔 Version Update Indicator Controller
window.SkyVersion = {

    timeoutId: null,

    show(durationMs = 60000) {
        const el = document.getElementById('versionFooter');
        if (!el) {
            console.warn('[SkyVersion] #versionFooter not found');
            return;
        }

        el.classList.add('hasUpdate');

        if (this.timeoutId) {
            clearTimeout(this.timeoutId);
        }

        this.timeoutId = setTimeout(() => {
            this.hide();
        }, durationMs);
    },

    hide() {
        const el = document.getElementById('versionFooter');
        if (el) {
            el.classList.remove('hasUpdate');
        }
        this.timeoutId = null;
    }
};
// #endregion

// #region 🧩 SkyeApp Page Object
window.SkyIndex = {

    // #region 🧠 Cached DOM State
    dom: null,
    cardHost: null,
    // #endregion
    
    // #region 🛠️ Command Output Helpers
    appendSystemLine(text) {
        if (!this.cardHost) return; // future-proof
        const output = this.cardHost.querySelector('.commandOutput');
        if (!output) return;

        const line = document.createElement('p');
        line.className = 'commandLine system';
        line.textContent = text;

        output.appendChild(line);
        output.scrollTop = output.scrollHeight;
    },
    // #endregion

    // #region ⏳ Thinking State (UI-only, non-transcript)
    setThinking(isThinking) {
        const footer = this.cardHost?.querySelector('.cardFooter');
        if (!footer) return;

        footer.textContent = isThinking
            ? '⏳ Thinking…'
            : '🟢 Authenticated • Ready';
    },
    // #endregion

    // #region 🧩 UI Action Registry (SERVER-AUTHORITATIVE)
    uiActionRegistry: {

        clear_screen() {
            SkyIndex.clearSessionSurface();
        },

        logout() {
            SkyIndex.appendSystemLine('Logging out…');
            setTimeout(() => SkyIndex.logout('ui_action'), 300);
        }

    },
    // #endregion

    // #region 🚀 Page Init (called by app.js)
    init() {

        console.log('[SkyIndex] init() fired');

        this.dom = {
            time:     document.getElementById('headerTime'),
            weather:  document.getElementById('headerWeather'),
            interval: document.getElementById('headerInterval'),
            year:     document.getElementById('footerYear'),
            version:  document.getElementById('versionFooter')
        };

        this.cardHost = document.getElementById('boardCardHost');

        if (!this.cardHost) {
            console.error('[SkyIndex] Missing #boardCardHost — index.html shell invalid');
            return;
        }

        // Restore auth state
        if (this.isAuthenticated()) {
            document.body.setAttribute('data-auth', 'true');
            this.renderCommandInterfaceCard();
        } else {
            document.body.removeAttribute('data-auth');
            this.renderLoginCard();
        }
    },
    // #endregion

    // #region 🔐 Auth State
    isAuthenticated() {
        return sessionStorage.getItem('skyesoft.auth') === 'true';
    },

    setAuthenticated() {
        sessionStorage.setItem('skyesoft.auth', 'true');
        document.body.setAttribute('data-auth', 'true');
        this.transitionToCommandInterface();
    },
    // #endregion

    // #region 🧱 Card Rendering
    clearCards() {
        this.cardHost.innerHTML = '';
    },
    // #endregion

    // #region 🧹 Session Surface Control
    clearSessionSurface() {
        if (!this.cardHost) return;

        const output = this.cardHost.querySelector('.commandOutput');
        if (output) {
            output.innerHTML = '';
        }

        // Subtle Easter egg (1 in 10)
        if (Math.random() < 0.1) {
            this.appendSystemLine('✨ The sky is clear.');
        } else {
            this.appendSystemLine('🟢 Skyesoft ready.');
        }

        console.log('[SkyIndex] Command output cleared');
    },
    // #endregion

    // #region 🔐 Login Card
    renderLoginCard() {
        this.clearCards();

        const card = document.createElement('section');
        card.className = 'card card-portal-auth';

        card.innerHTML = `
            <div class="cardHeader">
                <h2>🔐 Authentication Required</h2>
            </div>

            <div class="cardBodyDivider"></div>

            <div class="cardBody">
                <div class="cardContent cardContent--centered">

                    <p class="loginIntro">
                        Please sign in to access the Skyesoft Portal.
                    </p>

                    <div class="loginCard">
                        <form class="loginForm d-flex flex-column align-items-center gap-2">
                            <input class="form-control" type="email" placeholder="Email address" required>
                            <input class="form-control" type="password" placeholder="Password" required>
                            <button class="btn" type="submit">Sign In</button>
                            <div class="loginError" hidden></div>
                        </form>
                    </div>

                </div>
            </div>

            <div class="cardFooterDivider"></div>

            <div class="cardFooter">
                <img
                    src="https://www.skyelighting.com/skyesoft/assets/images/live-streaming.gif"
                    alt="Live"
                    style="width:24px;height:24px;vertical-align:middle;margin-right:8px;"
                >
                🔒 Authentication required to continue
            </div>
        `;

        this.cardHost.appendChild(card);

        const form = card.querySelector('.loginForm');
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            this.handleLoginSubmit(form);
        });
    },
    // #endregion

    // #region 🧠 Command Interface Card
    renderCommandInterfaceCard() {
        this.clearCards();

        const card = document.createElement('section');
        card.className = 'card card-command';

        card.innerHTML = `
            <div class="cardHeader">
                <h2>🧠 Skyesoft Command Interface</h2>
            </div>

            <div class="cardBodyDivider"></div>

            <div class="cardBody cardBody--command">

                <div class="cardContent cardContent--command">
                    <div class="commandOutput"></div>
                </div>
                <!-- Command Prompt -->
                <div class="composer">
                    <div class="composerSurface">

                        <button class="composerBtn composerPlus" type="button" aria-label="Attach files">+</button>

                        <div class="composerPrimary">
                        <div class="composerInput" contenteditable="true"
                            data-placeholder="Type a command..."
                            spellcheck="false"></div>
                        </div>

                        <button class="composerBtn composerSend" type="button" aria-label="Run command">⏎</button>

                        <input class="composerFile" type="file" multiple hidden>
                    </div>
                </div>

            </div> <!-- /cardBody -->

            <div class="cardFooterDivider"></div>

            <div class="cardFooter">
                🟢 Authenticated • Ready
            </div>
        `;

        this.cardHost.appendChild(card);

        // Attach file handler
        const attachBtn = card.querySelector('.composerPlus');
        const fileInput = card.querySelector('.composerFile');

        if (!attachBtn || !fileInput) {
            console.warn('[SkyIndex] Composer file controls not found');
            return;
        }

        attachBtn.addEventListener('click', () => {
            fileInput.click();
        });

        fileInput.addEventListener('change', () => {
            if (!fileInput.files.length) return;

            const files = Array.from(fileInput.files)
                .map(f => f.name)
                .join(', ');

            this.appendSystemLine(`Attached file(s): ${files}`);

            // Reset so same file can be re-selected
            fileInput.value = '';
        });

        const input = card.querySelector('.composerInput');
        const sendBtn = card.querySelector('.composerSend');

        const submitCommand = () => {
            const text = input.textContent.trim();
            if (!text) return;

            input.textContent = '';
            this.handleCommand(text);
        };

        sendBtn.addEventListener('click', submitCommand);

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                submitCommand();
            }
        });

        // Autofocus prompt
        card.querySelector('.composerInput')?.focus();
    },
    // #endregion

    // #region 🧠 Command Router
    handleCommand(text) {
        this.appendSystemLine(`> ${text}`);
        this.executeAICommand(text);
    },
    // #endregion

    // #region 🤖 AI Command Execution
    async executeAICommand(prompt) {

        this.setThinking(true);

        try {
            const res = await fetch(
                `/skyesoft/api/askOpenAI.php?ai=true&type=skyebot&userQuery=${encodeURIComponent(prompt)}`
            );

            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }

            const data = await res.json();

            // 🧠 UI ACTION SHORT-CIRCUIT
            if (data?.type === 'ui_action') {
                const handler = this.uiActionRegistry?.[data.action];

                if (typeof handler === 'function') {
                    handler();
                    return;
                }

                this.appendSystemLine('⚠ Unhandled UI action.');
                return;
            }

            // 🤖 Normal AI response
            if (typeof data?.response === 'string' && data.response.trim() !== '') {
                this.appendSystemLine(data.response);
                return;
            }

            this.appendSystemLine('⚠ No response from AI.');

        } catch (err) {
            console.error('[SkyIndex] AI error:', err);
            this.appendSystemLine('❌ AI request failed.');

        } finally {
            // ✅ SINGLE, GUARANTEED CLEANUP
            this.setThinking(false);
        }
    },
    // #endregion

    // #region 🔑 Login Logic (Faux)
    handleLoginSubmit(form) {
        const email = form.querySelector('input[type="email"]').value.trim();
        const pass  = form.querySelector('input[type="password"]').value.trim();
        const error = form.querySelector('.loginError');

        if (email === 'steve@christysigns.com' && pass === 'password123') {
            error.hidden = true;
            this.setAuthenticated();
        } else {
            error.textContent = 'Invalid email or password';
            error.hidden = false;
        }
    },
    // #endregion

    // #region 🔁 Transition
    transitionToCommandInterface() {
        this.cardHost.style.opacity = '0';

        setTimeout(() => {
            this.renderCommandInterfaceCard();
            this.cardHost.style.opacity = '1';
        }, 180);
    },
    // #endregion

    // #region 📡 SSE Event Handling
    onSSE(event) {
        // Sanity check
        if (!event) return;

        // ⏰ Time — HH:MM:SS AM (always 2 digits)
        if (event.timeDateArray?.currentUnixTime && this.dom?.time) {
            const d = new Date(event.timeDateArray.currentUnixTime * 1000);

            const hh = d.getHours();
            const mm = d.getMinutes();
            const ss = d.getSeconds();

            const hour12 = hh % 12 || 12;
            const ampm   = hh >= 12 ? 'PM' : 'AM';

            const pad = n => String(n).padStart(2, '0');

            this.dom.time.textContent =
                `${pad(hour12)}:${pad(mm)}:${pad(ss)} ${ampm}`;
        }

        // 🌤 Weather — temp + condition (e.g. 63°F — Clear sky)
        if (event.weather && this.dom?.weather) {
            const { temp, condition } = event.weather;

            if (temp !== null && condition) {
                this.dom.weather.textContent = `${temp}°F — ${condition}`;
            }
        }

        // ⏳ Interval — label + remaining time (e.g. Before Work • 02h 08m 12s)
        if (event.currentInterval && this.dom?.interval) {
            const { key, secondsRemainingInterval } = event.currentInterval;

            const labelMap = {
                beforeWork: 'Before Work',
                worktime:   'Worktime',
                afterWork:  'After Work',
                weekend:    'Weekend',
                holiday:    'Holiday'
            };

            const label = labelMap[key] ?? key;
            const pad = n => String(n).padStart(2, '0');

            if (typeof secondsRemainingInterval === 'number') {
                const total = secondsRemainingInterval;

                const hrs  = Math.floor(total / 3600);
                const mins = Math.floor((total % 3600) / 60);
                const secs = total % 60;

                let timeStr;

                if (hrs > 0) {
                    timeStr = `${pad(hrs)}h ${pad(mins)}m ${pad(secs)}s`;
                } else if (mins > 0) {
                    timeStr = `${pad(mins)}m ${pad(secs)}s`;
                } else {
                    timeStr = `${pad(secs)}s`;
                }

                this.dom.interval.textContent = `${label} - ${timeStr}`;
            } else {
                // Fallback: just the label
                this.dom.interval.textContent = label;
            }
        }

        // 🔭 SENTINEL META — authoritative runtime signal
        const sentinel = event.sentinelMeta;

        // 📦 Site Version Footer (canonical, shared)
        if (this.dom?.version && event.siteMeta) {
            const nowUnix =
            event?.timeDateArray?.currentUnixTime ??
            Math.floor(Date.now() / 1000);
            this.dom.version.textContent =
                formatVersionFooter(event.siteMeta, nowUnix);
        }

        // 🔭 Sentinel Meta — runtime health + deploy signal
        if (!sentinel || sentinel.status === "offline") {
            // Unknown or offline → hide version update indicator
            window.SkyVersion?.hide();
            return;
        }

        // 🚀 Update indicator — explicit update + fresh sentinel
        if (event.siteMeta?.updateOccurred === true) {
            window.SkyVersion?.show(60000);
        } else {
            window.SkyVersion?.hide();
        }


    },
    // #endregion

    // #region 🔓 Logout
    logout(reason = 'User requested logout') {
        console.log('[SkyIndex] Logout:', reason);

        sessionStorage.removeItem('skyesoft.auth');
        document.body.removeAttribute('data-auth');

        this.clearCards();
        this.renderLoginCard();
    },
// #endregion
};
// #endregion

// #region 🧾 Page Registration
window.SkyeApp.registerPage('index', window.SkyIndex);
// #endregion