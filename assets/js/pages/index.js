// ======================================================================
// Skyesoft — index.js
// Version: 1.0.0
// Command / Portal Interface Controller
// ======================================================================
//
// Primary Responsibilities
// • Bootstrap the UI (Login → Command Interface)
// • Manage command execution and output surface
// • Project domain surfaces from SSE snapshots
// • Maintain client-side runtime state
//
// Architectural Principles
// • Header / Footer state driven exclusively by SSE
// • UI reacts to server projection (no local time authority)
// • Command surface remains stateless between sessions
//
// ======================================================================

// #region 📦 Canonical Domain Surface Dependencies
import { adaptStreamedDomain } from '/skyesoft/assets/js/domainAdapter.js';
import { renderOutline } from '/skyesoft/assets/js/outlineRenderer.js';

if (typeof adaptStreamedDomain !== 'function') {
    console.error('[SkyIndex] adaptStreamedDomain not loaded');
}
// #endregion

// #region ⏱️ Format Version Footer (canonical, shared behavior)
function formatVersionFooter(siteMeta) {

    // Version Fallback
    const version =
        (siteMeta?.siteVersion && siteMeta.siteVersion !== 'unknown')
            ? siteMeta.siteVersion
            : '—';

    if (!siteMeta?.lastUpdateUnix) {
        return `v${version}`;
    }

    const TZ = 'America/Phoenix';

    // Ensure numeric timestamp
    const lastUpdateUnix = Number(siteMeta.lastUpdateUnix) || 0;

    const d = new Date(lastUpdateUnix * 1000);

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

    // Compute real delta locally (do not trust SSE age)
    const now = Math.floor(Date.now() / 1000);

    // Guard against clock drift / future timestamps
    let deltaSeconds = now - lastUpdateUnix;
    if (!Number.isFinite(deltaSeconds) || deltaSeconds < 0) {
        deltaSeconds = 0;
    }

    let agoStr;

    // Delta Seconds Conditional
    if (deltaSeconds < 60) {

        agoStr = '<span class="version-now">just now</span>';

    }
    else if (deltaSeconds < 3600) {

        const mins = Math.floor(deltaSeconds / 60);
        agoStr = `${mins} minute${mins === 1 ? '' : 's'} ago`;

    }
    else if (deltaSeconds < 86400) {

        const hrs  = Math.floor(deltaSeconds / 3600);
        const mins = Math.floor((deltaSeconds % 3600) / 60);

        agoStr =
            `${hrs} hour${hrs === 1 ? '' : 's'}` +
            (mins ? `, ${mins} minute${mins === 1 ? '' : 's'}` : '') +
            ` ago`;

    }
    else if (deltaSeconds < 2592000) {

        const days = Math.floor(deltaSeconds / 86400);
        agoStr = `${days} day${days === 1 ? '' : 's'} ago`;

    }
    else if (deltaSeconds < 31536000) {

        const months = Math.floor(deltaSeconds / 2592000);
        const days   = Math.floor((deltaSeconds % 2592000) / 86400);

        agoStr =
            `${months} month${months === 1 ? '' : 's'}` +
            (days ? `, ${days} day${days === 1 ? '' : 's'}` : '') +
            ` ago`;

    }
    else {

        const years = Math.floor(deltaSeconds / 31536000);
        agoStr = `${years} year${years === 1 ? '' : 's'} ago`;

    }

    return `v${version} · ${dateStr} ${timeStr} (${agoStr})`;
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
    },
    // #endregion

    // #region 📦 SSE Snapshot Cache (authoritative)
    lastSSE: null,
    activeDomainKey: null,
    activeDomainModel: null,
    authState: null,
    commandSurfaceActive: false,
    idleState: null,
    // #endregion

    // #region 🛠️ Command Output Helpers

    // Appends a command line to the output thread
    appendSystemLine(text, role = 'system') {

        // Init output host
        if (!this.cardHost) return;
        const output = this.cardHost.querySelector('.commandOutput');
        if (!output) return;

        const safeText = (text === null || text === undefined)
            ? ''
            : String(text);

        const line = document.createElement('div');
        line.className = `commandLine ${role}`;

        // Icon
        const icon = document.createElement('img');
        icon.className = 'commandIcon';

        icon.src = role === 'user'
            ? '/skyesoft/assets/images/icons/user.png'
            : '/skyesoft/assets/images/icons/robot.png';

        icon.alt = role;

        // Message text
        const msg = document.createElement('span');
        msg.className = 'commandText';
        msg.textContent = safeText;

        line.appendChild(icon);
        line.appendChild(msg);

        output.appendChild(line);
        output.scrollTop = output.scrollHeight;
    },

    // Appends trusted HTML (governance surface only)
    appendSystemHtml(html) {

        // Init output host
        if (!this.cardHost) return;
        const output = this.cardHost.querySelector('.commandOutput');
        if (!output) return;

        const safeHtml = (html === null || html === undefined)
            ? ''
            : String(html);

        // Optional safety guard (render only known governance wrapper)
        const isGovernanceHtml =
            safeHtml.includes('gov-box') ||
            safeHtml.includes('gov-action') ||
            safeHtml.includes('gov-panel');
        // Is Governance HTML Conditional
        if (!isGovernanceHtml) {
            this.appendSystemLine(safeHtml);
            return;
        }

        const wrap = document.createElement('div');
        wrap.className = 'commandLine system html';
        wrap.innerHTML = safeHtml; // Trusted server-generated HTML only

        output.appendChild(wrap);
        output.scrollTop = output.scrollHeight;
    },

    // #endregion

    // #region 📦 Registry Loaders
    async loadRuntimeDomainRegistry() {
        try {
            const res = await fetch('/skyesoft/data/authoritative/runtimeDomainRegistry.json');

            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }

            const contentType = res.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                throw new Error('Registry did not return JSON');
            }

            const data = await res.json();

            if (!data || typeof data !== 'object' || !data.domains) {
                throw new Error('Registry missing required structure');
            }

            this.runtimeDomainRegistry = data;
            console.log('[SkyIndex] runtimeDomainRegistry loaded');

        } catch (err) {
            console.error('[SkyIndex] Failed to load runtimeDomainRegistry:', err);
            this.runtimeDomainRegistry = null;
        }
    },

    async loadIconMap() {
        try {
            const res = await fetch('/skyesoft/data/authoritative/iconMap.json');

            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }

            const contentType = res.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                throw new Error('IconMap did not return JSON');
            }

            this.iconMap = await res.json();
            console.log('[SkyIndex] iconMap loaded');

        } catch (err) {
            console.error('[SkyIndex] Failed to load iconMap:', err);
            this.iconMap = null;
        }
    },
    // #endregion

    // #region 🌍 Location Resolver (non-blocking, permission-aware)
    async getLocationSafe() {

        return new Promise((resolve) => {

            if (!navigator.geolocation) {
                resolve({ latitude: null, longitude: null });
                return;
            }

            navigator.geolocation.getCurrentPosition(

                // Success
                (pos) => resolve({
                    latitude: pos.coords.latitude,
                    longitude: pos.coords.longitude
                }),

                // Fail (permission denied, timeout, etc)
                () => resolve({
                    latitude: null,
                    longitude: null
                }),

                { timeout: 2500 } // keep UI snappy

            );
        });
    },
    // #endregion

    // #region ⏳ Thinking State
    setThinking(isThinking) {
        this.isThinking = isThinking;

        this.debugFooterWrite(
            'setThinking',
            isThinking ? 'ON → ⏳ Thinking…' : 'OFF → renderFooterStatus()'
        );

        this.renderFooterStatus();
    },
    // #endregion

    // #region 🛡️ Update Governance Footer (Sentinel-Driven)
    updateGovernanceFooter(sentinel) {
        this.currentSentinelState = sentinel || null;
        this.renderFooterStatus();
    },
    // #endregion

    // #region ⏱ Session Activity Ping

    startActivityPing() {

        if (this.activityPingTimer) return;

        this.activityPingTimer = setInterval(() => {

            if (!this.authState) return;

            fetch('/skyesoft/api/auth.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'touch' })
            }).catch(() => {});

        }, 5000);

    },

    stopActivityPing() {

        if (this.activityPingTimer) {
            clearInterval(this.activityPingTimer);
            this.activityPingTimer = null;
        }

    },
    // #endregion

    // #region 🧾 Footer Debug (Temporary)
    debugFooterWrite(source, text) {
        console.log(`[FOOTER] ${source}: ${text}`);
    },
    // #endregion

    // #region 🔐 Auth Resolver (Single Source of Truth)
    getAuthState() {

        const varClient = this.authState === true;
        const varSSE    = window.SkyeApp?.lastSSE?.auth?.authenticated;

        // 🔒 logout still wins
        if (varClient === false) return false;

        // ⚠ only trust SSE false AFTER it has ever been true
        if (this._sseConfirmed && varSSE === false) return false;

        // mark confirmation
        if (varSSE === true) this._sseConfirmed = true;

        return varClient === true || varSSE === true;
    },
    // #endregion

    // #region 🧾 Footer Status (Single Authority)
    renderFooterStatus() {

        // Context guard
        if (!this) {
            console.warn('[FOOTER] invalid context');
            return;
        }

        // Access authoritative state
        const app = window.SkyeApp;

        // Determine auth status
        const isAuthed = this.getAuthState();

        // Sentinel state
        const sentinel = this.currentSentinelState;

        let dot = document.querySelector('.footerDot');
        let textEl = document.querySelector('.footerText');

        // DOM guard
        if (!dot || !textEl) {

            requestAnimationFrame(() => {

                dot = document.querySelector('.footerDot');
                textEl = document.querySelector('.footerText');

                if (!dot || !textEl) return;

                this.renderFooterStatus();

            });

            return;
        }

        const render = (dotColor, text) => {
            dot.style.background = dotColor;
            textEl.textContent = text;
        };

        // 1️⃣ Thinking dominates
        if (this.isThinking === true) {
            dot.style.background = '#007aff';
            textEl.innerHTML = '⏳ Thinking<span class="ellipsis" aria-hidden="true"></span>';
            return;
        }

        // 2️⃣ Auth gate (ONLY place for auth message)
        if (!isAuthed) {
            render('#111', 'Authorization required to continue');
            return;
        }

        // 3️⃣ Governance (post-auth only)
        if (sentinel && typeof sentinel === 'object') {

            const hasIntegrityDrift = Boolean(sentinel.integrityMismatch);
            const structuralCount   = Number(sentinel.unresolvedViolations || 0);

            if (hasIntegrityDrift === true) {

                const text = structuralCount > 0
                    ? `Integrity Drift • ${structuralCount} Structural Deviations`
                    : `Codex Integrity Drift`;

                render('#ff3b30', text);
                return;
            }

            if (structuralCount > 0) {
                render('#ff9500', `Structural Deviations • ${structuralCount}`);
                return;
            }
        }

        // 4️⃣ Clean state
        render('#00c853', 'Authenticated • Ready');

        // 5️⃣ Version (always render if available)
        try {

            const versionEl = document.getElementById('versionFooter');
            const version = window.SkyeApp?.lastSSE?.siteMeta?.siteVersion;

            if (versionEl && version) {

                const display = `v${version}`;

                if (versionEl.textContent !== display) {
                    versionEl.textContent = display;
                }
            }

        } catch (err) {
            // silent fail
        }

    },
    // #endregion

    // #region 🧩 UI Action Registry — Command Surface Action Router
    uiActionRegistry: {
        // 🧹 Clear Session Surface
        clear_screen() {
            SkyIndex.clearSessionSurface();
        },
        // 🔓 Logout (Manual User Logout)
        logout() {

            // Inform the command thread
            SkyIndex.appendSystemLine('Logging out…');

            // Delegate to canonical logout handler
            SkyIndex.logout('command');

        },
        // #region 🛡 Governance Actions — Codex Compliance Operations
        
        // 🌳 Accept Merkle Snapshot
        accept_merkle: async () => {

            SkyIndex.appendSystemLine('Processing Merkle acceptance...');

            try {

                const res = await fetch('/skyesoft/scripts/merkleBuilder.php?mode=accept');

                if (!res.ok) {
                    const text = await res.text();
                    throw new Error(`HTTP ${res.status} — ${text.slice(0,200)}`);
                }

                const contentType = (res.headers.get('content-type') || '').toLowerCase();
                const text = await res.text();

                if (!contentType.includes('application/json')) {
                    throw new Error(`Non-JSON response — ${text.slice(0,200)}`);
                }

                const data = JSON.parse(text);

                if (!data?.success) {
                    throw new Error(data?.message || 'Merkle builder did not return success.');
                }

                const governedRoot = data.governedRoot ?? '(missing governed root)';
                const treeRoot     = data.treeRoot ?? '(missing tree root)';
                const leaves       = Number.isFinite(data.leaves) ? data.leaves : '(unknown)';
                const fixed        = Number.isFinite(data.violationsFixed) ? data.violationsFixed : 0;

                SkyIndex.appendSystemLine('✅ Merkle snapshot accepted.');
                SkyIndex.appendSystemLine(`🔐 Governed Root: ${governedRoot}`);
                SkyIndex.appendSystemLine(`🌳 Tree Root: ${treeRoot}`);
                SkyIndex.appendSystemLine(`ℹ Leaves: ${leaves}`);
                SkyIndex.appendSystemLine(`🛠 Violations Resolved: ${fixed}`);

            } catch (err) {

                console.error(err);
                SkyIndex.appendSystemLine(`❌ Merkle acceptance failed: ${err.message}`);

            }
        },

        // #endregion

        // #region 📦 Repository Inventory — Structural Integrity

        // 📦 Reconcile Repository Inventory
        // Rebuilds the repository inventory index and resolves discrepancies.
        reconcile_inventory: async () => {

            SkyIndex.appendSystemLine('Reconciling repository inventory...');

            try {

                const res = await fetch('/skyesoft/scripts/repositoryInventoryBuilder.php?mode=reconcile');

                if (!res.ok) {
                    const text = await res.text();
                    throw new Error(`HTTP ${res.status} — ${text.slice(0,200)}`);
                }

                const contentType = (res.headers.get('content-type') || '').toLowerCase();
                const text = await res.text();

                if (!contentType.includes('application/json')) {
                    throw new Error(`Non-JSON response — ${text.slice(0,200)}`);
                }

                const data = JSON.parse(text);

                if (!data?.success) {
                    throw new Error(data?.message || 'Inventory reconciliation failed.');
                }

                const count = Number.isFinite(data.filesIndexed) ? data.filesIndexed : '(unknown)';
                const fixed = Number.isFinite(data.violationsFixed) ? data.violationsFixed : 0;

                SkyIndex.appendSystemLine('✅ Repository inventory rebuilt.');
                SkyIndex.appendSystemLine(`📦 Files Indexed: ${count}`);
                SkyIndex.appendSystemLine(`🛠 Violations Resolved: ${fixed}`);

            } catch (err) {

                console.error(err);
                SkyIndex.appendSystemLine(`❌ Inventory reconciliation failed: ${err.message}`);

            }
        },

        // #endregion

        // 🔎 Review Unexpected Files
        review_unexpected: async () => {

            SkyIndex.appendSystemLine('Reviewing unexpected files…');

            try {

                // Future: repository anomaly inspection

            } catch (err) {

                console.error(err);
                SkyIndex.appendSystemLine(`❌ Unexpected review failed: ${err.message}`);

            }
        }

    },
    // #endregion

    // #region 🧠 Domain Config Resolver (Runtime-Authoritative)
    getDomainConfig(domainKey) {

        if (!this.runtimeDomainRegistry?.domains) {
            console.error('[SkyIndex] runtimeDomainRegistry not loaded');
            return null;
        }

        const domainConfig = this.runtimeDomainRegistry.domains[domainKey];

        if (!domainConfig) {
            console.warn(`[SkyIndex] Domain not declared in runtime registry: ${domainKey}`);
            return null;
        }

        return domainConfig;
    },
    // #endregion

    // #region 🚀 Page Init
    async init() {

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

        // #region 🧭 UI Action Registry (authoritative)
        this.uiActionRegistry = {
            // Clear Screen
            clear_screen: () => {
                this.clearScreen?.();
            },

            // Logout
            logout: () => {

                console.log('[UI] Logout handler fired');

                // Optional UX message
                SkyIndex.appendSystemLine('🔒 Ending session…');

                // ✅ Delegate to canonical system
                SkyIndex.logout('command');

            }
        };
        // #endregion

        // Load registries in order of preference
        await this.loadRuntimeDomainRegistry();
        await this.loadIconMap();

        // Default to locked until SSE tells us otherwise
        document.body.removeAttribute('data-auth');
        this.renderLoginCard();

        // #region 🧩 Outline CRUD Events
        document.addEventListener('outline:update', (e) => {
            const { nodeId, nodeType } = e.detail;
            console.log('[SkyIndex] Update requested:', nodeId, nodeType);
            this.openEditModal(nodeId, nodeType, 'update');
        });

        document.addEventListener('outline:delete', (e) => {
            const { nodeId, nodeType } = e.detail;
            console.log('[SkyIndex] Delete requested:', nodeId, nodeType);
            this.openEditModal(nodeId, nodeType, 'delete');
        });
        // #endregion

        // #region 👁 Inline Actions Toggle (Delegated)
        document.addEventListener('click', (e) => {

            const header = e.target.closest('.phase-header');
            if (!header) return;

            // Ignore clicks inside action links
            if (e.target.closest('.node-inlineActions')) return;

            const node = header.closest('.outline-phase');
            if (!node) return;

            const isOpen = node.classList.contains('showActions');

            // Close all others
            document.querySelectorAll('.outline-phase.showActions')
                .forEach(n => n.classList.remove('showActions'));

            if (!isOpen) {
                node.classList.add('showActions');
            }

        });
        // #endregion

        // #region 🛡 Governance Button Delegation (Dynamic .gov-box)
        document.addEventListener('click', (e) => {

            const btn = e.target.closest('.gov-box button');
            if (!btn) return;

            e.preventDefault(); // prevent accidental form/nav behavior

            const action = btn.dataset.action;
            if (!action) {
                console.warn('[SkyIndex] Governance button missing data-action');
                return;
            }

            console.log('[SkyIndex] Governance action:', action);

            const handler = this.uiActionRegistry?.[action];

            if (typeof handler === 'function') {
                handler();
            } else {
                console.warn('[SkyIndex] No handler registered for:', action);
            }

        });
        // #endregion

        // =====================================================
        // 📡 GLOBAL SSE START (AUTHORITATIVE)
        // =====================================================
        console.log('[INIT] Starting SSE globally');

        if (window.SkySSE) {
            window.SkySSE.start();
        } else {
            console.error('[INIT] SkySSE not found');
        }

    },
    // #endregion

    // #region 🧱 Card Rendering & Clearing
    clearCards() {
        if (this.cardHost) this.cardHost.innerHTML = '';
    },

    clearSessionSurface() {
        if (!this.cardHost) return;

        const output = this.cardHost.querySelector('.commandOutput');
        if (output) output.innerHTML = '';

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
                        <form class="loginForm d-flex flex-column align-items-center gap-2" autocomplete="on" novalidate>

                            <input
                                id="loginEmail"
                                name="email"
                                class="form-control"
                                type="email"
                                placeholder="Email address"
                                autocomplete="username"
                                aria-label="Email address"
                                required
                            >

                            <input
                                id="loginPassword"
                                name="password"
                                class="form-control"
                                type="password"
                                placeholder="Password"
                                autocomplete="current-password"
                                aria-label="Password"
                                required
                            >

                            <button class="btn" type="submit">
                                Sign In
                            </button>

                            <div class="loginError" id="loginError" hidden></div>

                        </form>
                    </div>
                </div>
            </div>

            <div class="cardFooterDivider"></div>

            <div class="cardFooter">
                <span class="footerDot"></span>
                <span class="footerText"></span>
            </div>
        `;

        // Append card
        this.cardHost.appendChild(card);

        // Bind DOM
        this.dom.footerDot  = card.querySelector('.footerDot');
        this.dom.footerText = card.querySelector('.footerText');

        // Attach form handler
        card.querySelector('.loginForm')?.addEventListener('submit', (e) => {
            e.preventDefault();
            this.handleLoginSubmit(e.currentTarget);
        });

        // Render footer state
        this.renderFooterStatus();
    },
    // #endregion

    // #region 🧠 Command Interface Card
    renderCommandInterfaceCard() {

        this.clearCards();

        const card = document.createElement('section');
        card.className = 'card card-command';

        // Static card structure
        card.innerHTML = `
            <div class="cardHeader">
                <h2>🧠 Skyesoft Command Interface</h2>
            </div>

            <div class="cardBodyDivider"></div>

            <div class="cardBody cardBody--command">

                <div class="cardContent cardContent--command">
                    <!-- 🧵 Command Thread -->
                    <div class="commandOutput"></div>
                </div>

                <!-- 🎛 Composer -->
                <div class="composer">
                    <div class="composerSurface">

                        <button class="composerBtn composerPlus"
                            type="button"
                            aria-label="Attach files">+</button>

                        <div class="composerPrimary">
                            <div class="composerInput"
                                contenteditable="true"
                                data-placeholder="Type a command..."
                                spellcheck="false"></div>
                        </div>

                        <button class="composerBtn composerSend"
                            type="button"
                            aria-label="Run command">⏎</button>

                        <input class="composerFile" type="file" multiple hidden>

                    </div>
                </div>

            </div>

            <div class="cardFooterDivider"></div>

            <div class="cardFooter">
                <span class="footerDot"></span>
                <span class="footerText"></span>
            </div>
        `;

        // Append Card
        this.cardHost.appendChild(card);

        // Bind footer elements for this card instance
        this.dom.commandOutput = card.querySelector('.commandOutput');
        this.dom.footerDot  = card.querySelector('.footerDot');
        this.dom.footerText = card.querySelector('.footerText');

        // Render footer state immediately
        this.renderFooterStatus();

        // #region 📎 File Attachment
        const attachBtn = card.querySelector('.composerPlus');
        const fileInput = card.querySelector('.composerFile');

        attachBtn?.addEventListener('click', () => {
            fileInput?.click();
        });

        fileInput?.addEventListener('change', () => {

            if (!fileInput.files?.length) return;

            const names = Array.from(fileInput.files)
                .map(f => f.name)
                .join(', ');

            this.appendSystemLine(`Attached file(s): ${names}`);

            fileInput.value = '';
        });
        // #endregion

        // #region ⌨️ Command Input
        const input   = card.querySelector('.composerInput');
        const sendBtn = card.querySelector('.composerSend');

        const submitCommand = () => {

            const text = input.textContent.trim();
            if (!text) return;

            input.textContent = '';
            this.handleCommand(text);
        };

        sendBtn?.addEventListener('click', submitCommand);

        input?.addEventListener('keydown', (e) => {

            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                submitCommand();
            }

        });

        input?.focus();
        // #endregion

        // #region 👋 Initial Greeting

        const name = this.authUser
            ? this.authUser.split('@')[0]
                .replace(/[._-]/g, ' ')
                .replace(/\b\w/g, c => c.toUpperCase())
            : 'User';

        let greeting = 'Hello';

        const hour =
        this.lastSSE?.timeDateArray?.currentUnixTime
            ? new Date(this.lastSSE.timeDateArray.currentUnixTime * 1000).getHours()
            : new Date().getHours();

        if (hour < 12) greeting = 'Good morning';
        else if (hour < 17) greeting = 'Good afternoon';
        else greeting = 'Good evening';

        this.appendSystemLine(`${greeting}, ${name}. Ready when you are.`);

        // #endregion

    },
    // #endregion

    // #region 🧠 Command Router
    async handleCommand(text) {

        this.appendSystemLine(text, 'user');

        const normalized = text.trim().toLowerCase();

        // ───────────────────────────────────────────────
        // Native Terminal Commands (Immediate)
        // ───────────────────────────────────────────────
        const nativeCommands = {
            cls: 'clear_screen',
            clear: 'clear_screen',
            reset: 'clear_screen',
            logout: 'logout',
            exit: 'logout'
        };

        if (nativeCommands[normalized]) {

            const action = nativeCommands[normalized];
            const handler = this.uiActionRegistry?.[action];

            if (typeof handler === 'function') {

                await handler();   // ensure async handlers complete
                return;

            }

        }

        // ───────────────────────────────────────────────
        // Otherwise defer to AI
        // ───────────────────────────────────────────────
        this.executeAICommand(text);

    },
    // #endregion

    // #region 🤖 AI Command Execution
    async executeAICommand(prompt) {
        this.setThinking(true);

        try {
            // 🌍 Resolve Location (non-blocking)
            const location = await Promise.race([
                this.getLocationSafe(),
                new Promise(resolve =>
                    setTimeout(() => resolve({ latitude: null, longitude: null }), 1500)
                )
            ]);
            // Console Log (Remove When Finished)
            console.log('[Geo]', location);
            // Fetch
            const res = await fetch('/skyesoft/api/askOpenAI.php?type=skyebot&ai=true', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    userQuery: prompt,
                    latitude: location.latitude,
                    longitude: location.longitude
                })
            });

            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            const data = await res.json();

            // ───────────────────────────────────────────────
            // UI Action (authoritative)
            // ───────────────────────────────────────────────
            if (data?.type === 'ui_action') {

                const actionMap = {
                    cls: 'clear_screen',
                    clear: 'clear_screen',
                    reset: 'clear_screen'
                };

                const action = data.action ?? data.response;

                const canonicalAction =
                    actionMap[action] ?? action;

                const handler = this.uiActionRegistry?.[canonicalAction];

                if (typeof handler === 'function') {
                    handler();
                    return;
                }

                this.appendSystemLine('⚠ Unhandled UI action.');
                return;
            }

            // ───────────────────────────────────────────────
            // Domain Intent (authoritative short-circuit)
            // Parse backend payload → route to domain handlers
            // ───────────────────────────────────────────────
            if (data?.type === 'domain_intent') {

                // #region 🧾 Normalize Payload (backend returns JSON string)
                let parsed = null;

                try {
                    parsed = typeof data.response === 'string'
                        ? JSON.parse(data.response)
                        : data.response;
                } catch (e) {
                    console.warn('[SkyIndex] Failed to parse domain intent response');
                }

                if (!parsed || typeof parsed.domain !== 'string') {
                    this.appendSystemLine('⚠ Invalid domain response.');
                    return;
                }
                // #endregion

                // #region 🧠 Extract Intent Components
                const domainKey = parsed.domain;
                const mode      = parsed.mode;
                // #endregion

                // #region 🔍 Resolve Domain Config
                const domainConfig = this.getDomainConfig(domainKey);

                if (!domainConfig) {
                    console.warn('[SkyIndex] Unknown domain:', domainKey);
                    this.appendSystemLine('⚠ Unknown domain.');
                    return;
                }
                // #endregion

                // #region 📖 Inquiry (read-only)
                if (mode === 'inquiry' && domainConfig.capabilities?.read === true) {
                    this.showDomain(domainKey);
                    return;
                }
                // #endregion

                // #region 🛠 Repair Request (planned capability)
                if (mode === 'repair_request' && domainConfig.capabilities?.repair === true) {
                    this.showDomainRepairPlan?.(domainKey);
                    return;
                }
                // #endregion

                // #region ⚙ Execute (planned capability)
                if (mode === 'execute' && domainConfig.capabilities?.execute === true) {
                    this.executeDomainAction?.(domainKey);
                    return;
                }
                // #endregion

                // #region ⚠ Fallback (unhandled mode)
                console.warn('[SkyIndex] Unhandled domain mode:', mode);
                return;
                // #endregion
            }

            // ───────────────────────────────────────────────
            // Text Response (Conversational fallback)
            // ───────────────────────────────────────────────
            if (typeof data?.response === 'string' && data.response.trim()) {

                // Detect HTML-style governance payloads
                const varLooksLikeHtml =
                    data.response.includes('<div') ||
                    data.response.includes('<a ') ||
                    data.response.includes('<button');

                if (varLooksLikeHtml) {
                    this.appendSystemHtml(data.response);
                } else {
                    this.appendSystemLine(data.response);
                }

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

    // #region 🔑 Login Logic (Server Auth)
    async handleLoginSubmit(form) {

        console.log('[AUTH 1] Login submit received');

        // Resolve ACTIVE PAGE INSTANCE (authoritative)
        const app  = window.SkyeApp;
        const page = app?.pageHandlers?.[app?.currentPage];

        if (!page) {
            console.error('[AUTH] No active page instance');
            return;
        }

        // Context correction (prevents shadow-instance bugs)
        if (this !== page) {
            console.warn('[AUTH] Redirecting to active page instance');
            return page.handleLoginSubmit(form);
        }

        const email = form.querySelector('input[type="email"]')?.value.trim();
        const pass  = form.querySelector('input[type="password"]')?.value.trim();
        const error = form.querySelector('.loginError');

        // #region 🔍 Validation
        if (!email || !pass) {

            console.log('[AUTH 2] Validation failed');

            error.textContent = 'Please enter email and password.';
            error.hidden = false;
            return;
        }
        // #endregion

        try {

            console.log('[AUTH 3] Sending login request');

            const res = await fetch('/skyesoft/api/auth.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'login',
                    username: email,
                    password: pass
                })
            });

            console.log('[AUTH 4] auth.php response received', {
                status: res.status
            });

            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }

            const data = await res.json();

            console.log('[AUTH 5] auth.php payload', data);

            // #region ❌ Auth Rejected
            if (!data.success) {

                console.log('[AUTH 6] Authentication rejected');

                error.textContent = data.message || 'Login failed.';
                error.hidden = false;
                return;
            }
            // #endregion

            console.log('[AUTH 7] Authentication accepted');

            error.hidden = true;

            // =====================================================
            // 🔐 VERIFY SESSION (authoritative confirmation)
            // =====================================================
            const check = await fetch('/skyesoft/api/auth.php?action=check', {
                credentials: 'include'
            });

            const session = await check.json();

            console.log('[AUTH 8] Session verified', session);

            if (session.authenticated === true) {

                // =====================================================
                // 🔥 APPLY STATE TO ACTIVE INSTANCE (CRITICAL FIX)
                // =====================================================
                page.authState = true;

                document.body.setAttribute("data-auth", "true");

                page.authUser = session.username ?? null;
                page.authRole = session.role ?? null;

                // =====================================================
                // 🎯 RENDER UI IMMEDIATELY (client authority)
                // =====================================================
                page.renderCommandInterfaceCard?.();
                page.commandSurfaceActive = true;

                page.renderFooterStatus?.();

                console.log('[AUTH 9] Command interface activated (authoritative)');

                page.startActivityPing?.();
            }
            
            // =====================================================
            // 🔁 RESTART SSE (secondary confirmation layer)
            // =====================================================
            window.SkySSE?.stop?.();
            window.SkySSE?.restart?.();

            console.log('[AUTH 10] SSE restart (delayed)');

            // 🔥 ENSURE footer re-renders after SSE reconnect
            setTimeout(() => {
                try {
                    const app = window.SkyeApp;
                    const page = app?.pageHandlers?.[app?.currentPage];

                    if (page?.renderFooterStatus) {
                        page.renderFooterStatus();
                    } else if (app?.renderFooterStatus) {
                        app.renderFooterStatus();
                    }
                } catch (err) {}
            }, 250);

        } catch (err) {

            console.error('[AUTH ERROR]', err);

            error.textContent = 'Connection error.';
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

        if (!event || typeof event !== 'object') return;

        // 🧠 Ignore stale SSE streams
        if (
            event.streamId !== undefined &&
            window.SkySSE?.streamId !== undefined &&
            event.streamId !== window.SkySSE.streamId
        ) {
            return;
        }

        // =====================================================
        // 🔐 AUTH PROJECTION (CLEAN + SINGLE AUTHORITY PATH)
        // =====================================================
        if ('auth' in event) {

            const varSSEAuth = Boolean(event.auth?.authenticated);

            const app  = window.SkyeApp;
            const page = app?.pageHandlers?.[app?.currentPage];

            const varClientAuth = page?.authState === true;

            // 🚫 DO NOT override a valid login (CRITICAL FIX)
            if (!varSSEAuth && !varClientAuth) {

                console.log('[SkyIndex] SSE → forcing logout UI');

                this.authState = false;

                document.body.removeAttribute('data-auth');

                this.renderLoginCard();
                this.commandSurfaceActive = false;

                this.renderFooterStatus();

                return;
            }

            // ✅ Promote auth if SSE confirms AND client not set
            if (varSSEAuth && !varClientAuth) {

                console.log('[SkyIndex] SSE → confirmed auth');

                page.authState = true;

                document.body.setAttribute('data-auth', 'true');

                page.authUser = event.auth.username ?? null;
                page.authRole = event.auth.role ?? null;

                page.renderCommandInterfaceCard?.();
                page.commandSurfaceActive = true;

                page.renderFooterStatus();
            }
        }

        // =====================================================
        // 📦 CACHE SSE SNAPSHOT (AUTHORITATIVE STORE)
        // =====================================================
        this.lastSSE = {
            ...this.lastSSE,
            ...event
        };

        // 🔁 Re-render footer after SSE update (authoritative)
        const app  = window.SkyeApp;
        const page = app?.pageHandlers?.[app?.currentPage];

        page?.renderFooterStatus?.();

        // =====================================================
        // ⏳ IDLE STATE (Normalized / Guarded)
        // =====================================================
        if ('idle' in event) {

            const r = Number(
                event.idle?.remainingSeconds ??
                event.idle?.remaining ??
                event.idle?.secondsRemaining
            );

            const t = Number(
                event.idle?.timeoutSeconds ??
                event.idle?.timeout ??
                event.idle?.maxSeconds
            );

            const isValidIdle =
                Number.isFinite(r) &&
                Number.isFinite(t) &&
                t > 0;

            if (isValidIdle) {

                this.idleState = {
                    ...event.idle,
                    remainingSeconds: r,
                    timeoutSeconds: t
                };

                console.log('[Idle SSE ✓]', this.idleState);

            } else {

                this.idleState = null;

                // Optional: only log once if you want to reduce noise
                // console.warn('[Idle SSE ✗] invalid → discarded', event.idle);

            }

            this.renderFooterStatus();
        }

        // =====================================================
        // 🕒 TIME
        // =====================================================
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

        // =====================================================
        // 🌤 WEATHER
        // =====================================================
        if (event.weather && this.dom?.weather) {

            const { temp, condition } = event.weather;

            if (temp != null && condition) {
                this.dom.weather.textContent =
                    `${temp}°F — ${condition}`;
            }
        }

        // =====================================================
        // ⏳ INTERVAL
        // =====================================================
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
                this.dom.interval.textContent =
                    `${label} - ${formatIntervalDHMS(secondsRemainingInterval)}`;
            } else {
                this.dom.interval.textContent = label;
            }
        }

        // =====================================================
        // 🛡️ SENTINEL GOVERNANCE
        // =====================================================
        if (event.sentinelMeta) {

            this.currentSentinelState = event.sentinelMeta;

            if (this.getAuthState?.()) {
                this.updateGovernanceFooter(event.sentinelMeta);
            } else {
                this.renderFooterStatus();
            }
        }
    },
    // #endregion

    // #region 🔓 Logout
    logout: function (source = 'manual') {

        if (this._loggingOut) return;
        this._loggingOut = true;

        console.log('[SkyIndex] Sending logout request');

        fetch('/skyesoft/api/auth.php', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'logout' })
        })
        .then(res => {

            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }

            console.log('[SkyIndex] Logout request accepted', { source });

            // #region 🔌 Stop SSE (authoritative reset)
            if (window.SkySSE) {
                window.SkySSE.stop();
            }
            // #endregion

            // #region 🔥 Stop Activity Tracking
            const app  = window.SkyeApp;
            const page = app?.pageHandlers?.[app?.currentPage];

            if (page) {
                page.stopActivityPing?.();
            }
            // #endregion

            // #region 🧠 Reset SSE memory
            window.SkyeApp.lastSSE = null;
            // #endregion

            // #region 🎨 Force UI logout state
            if (page) {

                console.log('[UI] forcing logout state (client-side)');

                // Hard auth reset
                page.authState = false;
                page.authUser  = null;
                page.authRole  = null;

                page.commandSurfaceActive = false;
                page.idleState = null;

                document.body.removeAttribute('data-auth');

                page.renderLoginCard?.();
                page.renderFooterStatus?.call(page);
            }
            // #endregion

        })
        .catch(err => {

            console.error('[SkyIndex] Logout error:', err);
            this.appendSystemLine('❌ Logout failed.');

        })
        .finally(() => {

            this._loggingOut = false;

        });

    },
    // #endregion

    // #region 📘 Canonical Domain Rendering
    updateDomainSurface(domainKey, domainData) {

        if (!domainKey || !domainData) return;

        // 🚫 Prevent redraw while modal is active
        if (
            window.SkyeModal?.modalEl &&
            window.SkyeModal.modalEl.style.display === 'block'
        ) {
            console.log('[SkyIndex] Render skipped (modal active)');
            return;
        }

        const adapted = adaptStreamedDomain(domainKey, domainData);
        if (!adapted) {
            console.error('[SkyIndex] Domain adaptation failed:', domainKey);
            return;
        }

        /* -------------------------------------------------
        🧠 Resolve Domain Config (Runtime Authoritative)
        ------------------------------------------------- */

        const domainConfig = this.getDomainConfig(domainKey);
        if (!domainConfig) return;

        const capabilities = domainConfig.capabilities ?? {};
        const canCreate = capabilities.create === true;
        const canRead   = capabilities.read === true;

        /* -------------------------------------------------
        🧵 Prepare Thread Surface
        ------------------------------------------------- */

        const thread = this.cardHost.querySelector('.commandOutput');
        if (!thread) return;

        let surface = thread.querySelector('.domainSurface');

        if (!surface) {
            surface = document.createElement('div');
            surface.className = 'domainSurface';
            thread.innerHTML = '';
            thread.appendChild(surface);
        }

        surface.innerHTML = `
            <div class="domainHeader" style="display:flex; align-items:center; gap:16px;">
                <h3 class="domainTitle" style="margin:0;"></h3>
                <span class="domain-action domain-create">Create</span>
                <span class="domain-action domain-read">Read</span>
            </div>
            <div class="domainBody"></div>
        `;

        const titleEl = surface.querySelector('.domainTitle');
        const bodyEl  = surface.querySelector('.domainBody');
        const createLink = surface.querySelector('.domain-create');
        const readLink   = surface.querySelector('.domain-read');

        titleEl.textContent = adapted.title ?? domainKey;

        /* -------------------------------------------------
        🧩 Capability-Gated Actions
        ------------------------------------------------- */

        if (!canCreate && createLink) createLink.style.display = 'none';
        if (!canRead   && readLink)   readLink.style.display   = 'none';

        if (canCreate && createLink) {
            createLink.addEventListener('click', e => {
                e.preventDefault();
                window.SkyeModal?.open({
                    domainKey,
                    mode: 'create'
                });
            });
        }

        if (canRead && readLink) {
            readLink.addEventListener('click', e => {
                e.preventDefault();
                console.log('[SkyIndex] Read requested');
            });
        }

        /* -------------------------------------------------
        🖼 Render Domain Body
        ------------------------------------------------- */

        if (typeof renderOutline !== 'function') {
            bodyEl.innerHTML =
                '<p style="color:#f33;padding:1rem;">Renderer unavailable</p>';
        } else {
            renderOutline(bodyEl, adapted, domainConfig, this.iconMap);
        }


        this.activeDomainKey   = domainKey;
        this.activeDomainModel = adapted;
    },
    // #endregion

    // #region 🔎 Recursive Node Lookup Helper
    findNodeRecursive(nodes, id) {
        for (const n of nodes ?? []) {
            if (n.id === id) return n;
            if (n.children?.length) {
                const found = this.findNodeRecursive(n.children, id);
                if (found) return found;
            }
        }
        return null;
    },
    // #endregion

    // #region 🪟 Open Edit Modal (CRUD-Aware)
    openEditModal(nodeId, nodeType, mode = 'update') {

        if (!this.activeDomainModel) {
            console.warn('[SkyIndex] No active domain model');
            return;
        }

        const node =
            this.activeDomainModel.nodes?.find(n => n.id === nodeId)
            ?? this.findNodeRecursive(this.activeDomainModel.nodes, nodeId);

        // For delete/read/update → node must exist
        if (mode !== 'create' && !node) {
            console.warn('[SkyIndex] Node not found:', nodeId);
            return;
        }

        console.log('[SkyIndex] Opening modal:', {
            nodeId,
            mode
        });

        window.SkyeModal?.open({
            node,
            domainKey: this.activeDomainKey,
            mode
        });
    },
    // #endregion

};
// #endregion

// #region 🧾 Page Registration
window.SkyeApp.registerPage('index', window.SkyIndex);
// #endregion