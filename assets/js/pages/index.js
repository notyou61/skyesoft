/* Skyesoft — index.js
   🧠 Command / Portal Interface Controller
   Phase 1: Card-Based Bootstrap (Login → Command Interface)
   Header / Footer driven exclusively by SSE
*/

// #region 📦 Canonical Domain Surface Dependencies
// ------------------------------------------------------------
// ES Module Imports — Streamed Domain Rendering Pipeline
// 
// adaptStreamedDomain:
//   Transforms authoritative SSE domain payloads into the
//   normalized outline model consumed by the UI.
//
// renderOutline:
//   Canonical renderer for Streamed List Surfaces.
//   Responsible for DOM projection, node structure,
//   edit-link injection, and presentationRegistry compliance.
//
// Module Integrity Check (Development Safeguard):
//   In ES module mode, missing imports should fail at load time.
//   This guard exists for transitional debugging during rebuild
//   phases and may be removed once module stability is confirmed.
// ------------------------------------------------------------

import { adaptStreamedDomain } from '/skyesoft/assets/js/domainAdapter.js';
import { renderOutline } from '/skyesoft/assets/js/outlineRenderer.js';

if (typeof adaptStreamedDomain !== 'function') {
    console.error('[SkyIndex] adaptStreamedDomain not loaded');
}
// #endregion

// #region ⏱️ Format Version Footer (canonical, shared behavior)
function formatVersionFooter(siteMeta) {
    if (!siteMeta?.siteVersion || !siteMeta?.lastUpdateUnix) {
        return `v${siteMeta?.siteVersion ?? '—'}`;
    }

    const TZ = 'America/Phoenix';

    // Absolute time (display only)
    const d = new Date(siteMeta.lastUpdateUnix * 1000);

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

    // ✅ SERVER-AUTHORITATIVE AGE
    const deltaSeconds = siteMeta.lastUpdateAgeSeconds ?? 0;

    let agoStr;
    if (deltaSeconds < 3600) {
        agoStr = `${String(Math.floor(deltaSeconds / 60)).padStart(2,'0')} min ago`;
    } else if (deltaSeconds < 86400) {
        const hrs  = Math.floor(deltaSeconds / 3600);
        const mins = Math.floor((deltaSeconds % 3600) / 60);
        agoStr = `${String(hrs).padStart(2,'0')} hrs ${String(mins).padStart(2,'0')} min ago`;
    } else {
        agoStr = `${String(Math.floor(deltaSeconds / 86400)).padStart(2,'0')} days ago`;
    }

    // Return
    return `v${siteMeta.siteVersion} · ${dateStr} ${timeStr} (${agoStr})`;
}
// #endregion

// #region ⏳ Interval Formatter (DHMS, canonical)
function formatIntervalDHMS(totalSeconds) {
    const pad = n => String(n).padStart(2, '0');

    const days = Math.floor(totalSeconds / 86400);
    const hrs  = Math.floor((totalSeconds % 86400) / 3600);
    const mins = Math.floor((totalSeconds % 3600) / 60);
    const secs = totalSeconds % 60;

    const parts = [];

    if (days > 0) parts.push(`${pad(days)}d`);
    if (hrs  > 0 || parts.length) parts.push(`${pad(hrs)}h`);
    if (mins > 0 || parts.length) parts.push(`${pad(mins)}m`);
    parts.push(`${pad(secs)}s`);

    return parts.join(' ');
}
// #endregion

// #region 🔔 Version Update Indicator Controller
window.SkyVersion = {

    // Timeout ID
    timeoutId: null,
    // Show
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
    // Hide
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

    // #region 📘 Domain Surface Control
    showDomain(domainKey) {
        const sse = window.SkyeApp?.lastSSE;
        const domainData = sse?.[domainKey];

        if (!domainData) {
            console.warn('[SkyIndex] No streamed data for domain:', domainKey);
            return;
        }

        this.updateDomainSurface(domainKey, domainData);

        if (this.dom?.domainSurface) {
            this.dom.domainSurface.hidden = false;
        }
    },
    // #endregion

    // #region 📦 SSE Snapshot Cache (authoritative)
    lastSSE: null,
    activeDomainKey: null,
    activeDomainModel: null,
    // #endregion

    // #region 🛠️ Command Output Helpers
    appendSystemLine(text) {
        if (!this.cardHost) return;
        const output = this.cardHost.querySelector('.commandOutput');
        if (!output) return;

        const line = document.createElement('p');
        line.className = 'commandLine system';
        line.textContent = text;

        output.appendChild(line);
        output.scrollTop = output.scrollHeight;
    },
    // #endregion

    // #region 📦 Registry Loader
    async loadPresentationRegistry() {
        try {
            const res = await fetch('/skyesoft/data/authoritative/presentationRegistry.json')
            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            this.presentationRegistry = await res.json();
            console.log('[SkyIndex] presentationRegistry loaded');

        } catch (err) {
            console.error('[SkyIndex] Failed to load presentationRegistry:', err);
            this.presentationRegistry = null;
        }
    },
    // #endregion

    // #region 🎨 Icon Map Loader
    async loadIconMap() {
        try {
            const res = await fetch('/skyesoft/data/authoritative/iconMap.json');
            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            this.iconMap = await res.json();
            console.log('[SkyIndex] iconMap loaded');

        } catch (err) {
            console.error('[SkyIndex] Failed to load iconMap:', err);
            this.iconMap = null;
        }
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
        
        // Clear Screen
        clear_screen() {
            SkyIndex.clearSessionSurface();
        },
        // Logout
        logout() {
            SkyIndex.appendSystemLine('Logging out…');
            setTimeout(() => SkyIndex.logout('ui_action'), 300);
        }
    },
    // #endregion

    // #region 🚀 Page Init
    async init() {
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
            console.error('[SkyIndex] Missing #boardCardHost');
            return;
        }

        // 🔥 LOAD REGISTRY BEFORE USE
        await this.loadPresentationRegistry();
        await this.loadIconMap();

        if (!this.presentationRegistry) {
            console.warn('[SkyIndex] presentationRegistry not available');
        }

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

        this.appendSystemLine('🟢 Skyesoft ready.');

        console.log('[SkyIndex] Session surface cleared');
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
                <img src="https://www.skyelighting.com/skyesoft/assets/images/live-streaming.gif"
                     alt="Live" style="width:24px;height:24px;vertical-align:middle;margin-right:8px;">
                🔒 Authentication required to continue
            </div>
        `;

        this.cardHost.appendChild(card);

        card.querySelector('.loginForm')?.addEventListener('submit', (e) => {
            e.preventDefault();
            this.handleLoginSubmit(e.currentTarget);
        });
    },
    // #endregion

    // #region 🧠 Command Interface Card
    renderCommandInterfaceCard() {
        this.clearCards();

        const card = document.createElement('section');
        card.className = 'card card-command';
        // Card content is mostly static HTML.
        // Domain surfaces are now injected dynamically into the command thread.
        card.innerHTML = `
            <div class="cardHeader">
                <h2>🧠 Skyesoft Command Interface</h2>
            </div>

            <div class="cardBodyDivider"></div>

            <div class="cardBody cardBody--command">
                <div class="cardContent cardContent--command">

                    <!-- 🧵 Command Thread (chronological, authoritative) -->
                    <div class="commandOutput"></div>

                </div>

                <!-- 🎛 Composer -->
                <div class="composer">
                    <div class="composerSurface">
                        <button class="composerBtn composerPlus" type="button" aria-label="Attach files">+</button>

                        <div class="composerPrimary">
                            <div class="composerInput"
                                contenteditable="true"
                                data-placeholder="Type a command..."
                                spellcheck="false"></div>
                        </div>

                        <button class="composerBtn composerSend" type="button" aria-label="Run command">⏎</button>
                        <input class="composerFile" type="file" multiple hidden>
                    </div>
                </div>
            </div>

            <div class="cardFooterDivider"></div>

            <div class="cardFooter">
                🟢 Authenticated • Ready
            </div>
        `;

        this.cardHost.appendChild(card);

        // File attach
        const attachBtn = card.querySelector('.composerPlus');
        const fileInput = card.querySelector('.composerFile');

        attachBtn?.addEventListener('click', () => fileInput?.click());

        fileInput?.addEventListener('change', () => {
            if (!fileInput.files?.length) return;
            const names = Array.from(fileInput.files).map(f => f.name).join(', ');
            this.appendSystemLine(`Attached file(s): ${names}`);
            fileInput.value = '';
        });

        // Command input
        const input   = card.querySelector('.composerInput');
        const sendBtn = card.querySelector('.composerSend');

        const submit = () => {
            const text = input.textContent.trim();
            if (!text) return;
            input.textContent = '';
            this.handleCommand(text);
        };

        sendBtn?.addEventListener('click', submit);

        input?.addEventListener('keydown', e => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                submit();
            }
        });

        input?.focus();
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

            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            const data = await res.json();

            if (data?.type === 'ui_action') {
                const handler = this.uiActionRegistry?.[data.action];
                if (typeof handler === 'function') {
                    handler();
                    return;
                }
                this.appendSystemLine('⚠ Unhandled UI action.');
                return;
            }

            if (typeof data?.intent === 'string' && data.intent.startsWith('show_')) {

                const domainKey = data.intent.replace('show_', '');

                const registry = this.presentationRegistry;

                if (!registry?.meta?.streamedDomains) {
                    console.warn('[SkyIndex] streamedDomains registry missing');
                }

                const isStreamDomain =
                    registry?.meta?.streamedDomains?.includes(domainKey) === true;

                if (isStreamDomain) {
                    this.showDomain(domainKey);
                    return;
                }

                console.warn('[SkyIndex] Intent mapped to non-streamed domain:', domainKey);
            }

            if (typeof data?.response === 'string' && data.response.trim()) {
                this.appendSystemLine(data.response);
                return;
            }

            this.appendSystemLine('⚠ No response from AI.');

        } catch (err) {
            console.error('[SkyIndex] AI error:', err);
            this.appendSystemLine('❌ AI request failed.');
        } finally {
            this.setThinking(false);
        }
    },
    // #endregion

    // #region 🔑 Login Logic (Faux)
    handleLoginSubmit(form) {
        const email = form.querySelector('input[type="email"]')?.value.trim();
        const pass  = form.querySelector('input[type="password"]')?.value.trim();
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
        this.lastSSE = event;
        //console.log('[SSE] cached keys:', Object.keys(event || {}));

        if (!event) return;

        // Time
        if (event.timeDateArray?.currentUnixTime && this.dom?.time) {
            const d = new Date(event.timeDateArray.currentUnixTime * 1000);
            const hh = d.getHours();
            const mm = d.getMinutes();
            const ss = d.getSeconds();
            const hour12 = hh % 12 || 12;
            const ampm   = hh >= 12 ? 'PM' : 'AM';
            const pad    = n => String(n).padStart(2, '0');
            this.dom.time.textContent = `${pad(hour12)}:${pad(mm)}:${pad(ss)} ${ampm}`;
        }

        // Weather
        if (event.weather && this.dom?.weather) {
            const { temp, condition } = event.weather;
            if (temp != null && condition) {
                this.dom.weather.textContent = `${temp}°F — ${condition}`;
            }
        }

        // Interval
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
            if (typeof secondsRemainingInterval === 'number') {
                this.dom.interval.textContent = `${label} - ${formatIntervalDHMS(secondsRemainingInterval)}`;
            } else {
                this.dom.interval.textContent = label;
            }
        }

        // Version footer
        if (this.dom?.version && event.siteMeta) {
            this.dom.version.textContent = formatVersionFooter(event.siteMeta);
        }

        // Sentinel / update indicator
        const sentinel = event.sentinelMeta;
        if (!sentinel || sentinel.status === "offline") {
            window.SkyVersion?.hide();
            return;
        }

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

    // #region 📘 Canonical Domain Rendering
    updateDomainSurface(domainKey, domainData) {

        if (!domainKey || !domainData) return;

        const adapted = adaptStreamedDomain(domainKey, domainData);
        if (!adapted) {
            console.error('[SkyIndex] Domain adaptation failed:', domainKey);
            return;
        }

        const presentation = this.presentationRegistry?.domains?.[domainKey] ?? null;

        // 🔥 Create a domain panel dynamically
        const surface = document.createElement('div');
        surface.className = 'domainSurface';

        surface.innerHTML = `
            <div class="domainHeader">
                <h3 class="domainTitle"></h3>
            </div>
            <div class="domainBody"></div>
        `;

        const titleEl = surface.querySelector('.domainTitle');
        const bodyEl  = surface.querySelector('.domainBody');

        titleEl.textContent = adapted.title ?? domainKey;

        if (typeof renderOutline !== 'function') {
            bodyEl.innerHTML = '<p style="color:#f33;padding:1rem;">Renderer unavailable</p>';
        } else {
            renderOutline(bodyEl, adapted, presentation, this.iconMap);
        }

        // 🔥 Append into command thread (chronological placement)
        const thread = this.cardHost.querySelector('.commandOutput');
        if (thread) {
            thread.appendChild(surface);
            thread.scrollTop = thread.scrollHeight;
        }

        this.activeDomainKey   = domainKey;
        this.activeDomainModel = adapted;
    }
    // #endregion
    };
// #endregion

// #region 🧾 Page Registration
window.SkyeApp.registerPage('index', window.SkyIndex);
// #endregion