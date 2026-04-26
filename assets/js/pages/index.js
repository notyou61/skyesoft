// ======================================================================
// Skyesoft — index.js
// Version: 1.0.1
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
// • activitySessionId is the single canonical session identifier
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

    const version = (siteMeta?.siteVersion && siteMeta.siteVersion !== 'unknown')
        ? siteMeta.siteVersion : '—';

    if (!siteMeta?.lastUpdateUnix) return `v${version}`;

    const TZ = 'America/Phoenix';
    const lastUpdateUnix = Number(siteMeta.lastUpdateUnix) || 0;
    const d = new Date(lastUpdateUnix * 1000);

    const dateStr = d.toLocaleDateString('en-US', {
        timeZone: TZ, month: '2-digit', day: '2-digit', year: '2-digit'
    });

    const timeStr = d.toLocaleTimeString('en-US', {
        timeZone: TZ, hour: 'numeric', minute: '2-digit', hour12: true
    });

    const now = Math.floor(Date.now() / 1000);
    let deltaSeconds = now - lastUpdateUnix;
    if (!Number.isFinite(deltaSeconds) || deltaSeconds < 0) deltaSeconds = 0;

    let agoStr;
    if (deltaSeconds < 60) agoStr = '<span class="version-now">just now</span>';
    else if (deltaSeconds < 3600) agoStr = `${Math.floor(deltaSeconds/60)} minute${Math.floor(deltaSeconds/60)===1?'':'s'} ago`;
    else if (deltaSeconds < 86400) {
        const hrs = Math.floor(deltaSeconds/3600);
        const mins = Math.floor((deltaSeconds%3600)/60);
        agoStr = `${hrs} hour${hrs===1?'':'s'}${mins?`, ${mins} minute${mins===1?'':'s'}`:''} ago`;
    } else if (deltaSeconds < 2592000) agoStr = `${Math.floor(deltaSeconds/86400)} day${Math.floor(deltaSeconds/86400)===1?'':'s'} ago`;
    else if (deltaSeconds < 31536000) {
        const months = Math.floor(deltaSeconds/2592000);
        const days = Math.floor((deltaSeconds%2592000)/86400);
        agoStr = `${months} month${months===1?'':'s'}${days?`, ${days} day${days===1?'':'s'}`:''} ago`;
    } else agoStr = `${Math.floor(deltaSeconds/31536000)} year${Math.floor(deltaSeconds/31536000)===1?'':'s'} ago`;

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

// #region 🌍 Global State Init (SSE Safe)
window.SkyIndex = window.SkyIndex || {};
window.SkyIndex.lastSSE = {};
// #endregion

// #region 🧩 SkyeApp Page Object
window.SkyIndex = {
    
    // #region 🧠 Cached DOM State
    dom: null,
    cardHost: null,
    // #endregion

    // #region 🌍 Location cache (used by getContacts.php + AI commands)
    lastLocation: { latitude: null, longitude: null },
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

        getOutputHost() {
            return this.dom?.commandOutput || null;
        },

        scrollOutputToBottom(output) {
            if (output) output.scrollTop = output.scrollHeight;
        },

        // Appends a command line
        appendSystemLine(text, role = 'system') {

            const output = this.getOutputHost();
            if (!output) return;

            const safeText = (text === null || text === undefined)
                ? ''
                : String(text);

            const line = document.createElement('div');
            line.className = `commandLine ${role}`;

            const icon = document.createElement('img');
            icon.className = 'commandIcon';

            icon.src = role === 'user'
                ? '/skyesoft/assets/images/icons/user.png'
                : '/skyesoft/assets/images/icons/robot.png';

            icon.alt = role;

            const msg = document.createElement('span');
            msg.className = 'commandText';
            msg.textContent = safeText;

            line.appendChild(icon);
            line.appendChild(msg);

            output.appendChild(line);
            this.scrollOutputToBottom(output);
        },

        // Appends trusted HTML
        appendSystemHtml(html) {

            const output = this.getOutputHost();
            if (!output) return;

            const safeHtml = (html === null || html === undefined)
                ? ''
                : String(html);

            const isGovernanceHtml =
                safeHtml.includes('gov-box') ||
                safeHtml.includes('gov-action') ||
                safeHtml.includes('gov-panel') ||
                safeHtml.includes('contact-card'); // ✅ ADD THIS

            if (!isGovernanceHtml) {
                this.appendSystemLine('[Unsupported HTML content]');
                return;
            }

            const wrap = document.createElement('div');
            wrap.className = 'commandLine system html';
            wrap.innerHTML = safeHtml;

            output.appendChild(wrap);
            this.scrollOutputToBottom(output);
        },

        // Appends code block
        appendCodeBlock(html) {

            const output = this.getOutputHost();
            if (!output) return;

            const wrapper = document.createElement('div');
            wrapper.className = 'commandLine code';

            wrapper.innerHTML = html;

            output.appendChild(wrapper);
            this.scrollOutputToBottom(output);
        },

        // Appends multiple lines as a block (e.g. for multi-line system messages)
        appendSystemBlock(lines = []) {

            const output = this.dom?.commandOutput;
            if (!output) return;

            const wrapper = document.createElement('div');
            wrapper.className = 'commandLine system';

            const icon = document.createElement('img');
            icon.className = 'commandIcon';
            icon.src = '/skyesoft/assets/images/icons/robot.png';

            const content = document.createElement('div');
            content.className = 'commandText';

            lines.forEach((line, index) => {
                const row = document.createElement('div');
                row.textContent = line;
                content.appendChild(row);
            });

            wrapper.appendChild(icon);
            wrapper.appendChild(content);

            output.appendChild(wrapper);
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

    // #region 🌍 Location Resolver (non-blocking, permission-aware)
    async getLocationSafe() {

        return new Promise((resolve) => {

            if (!navigator.geolocation) {
                resolve({ latitude: null, longitude: null });
                return;
            }

            navigator.geolocation.getCurrentPosition(

                (pos) => resolve({
                    latitude: pos.coords.latitude,
                    longitude: pos.coords.longitude
                }),

                () => resolve({
                    latitude: null,
                    longitude: null
                }),

                { timeout: 2500 }
            );
        });
    },
    // #endregion

    // #region 🔑 Get canonical activitySessionId from cookie
    getActivitySessionId() {
        try {
            const cookies = document.cookie.split(';');
            for (let cookie of cookies) {
                const [name, value] = cookie.trim().split('=');
                if (name === 'SKYESOFTSESSID' || name === 'PHPSESSID') {
                    console.log('✅ activitySessionId found:', value);
                    return value;
                }
            }
        } catch (e) {
            console.warn('⚠️ Could not read session cookie');
        }
        return 'no_session';
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

        if (!this) {
            console.warn('[FOOTER] invalid context');
            return;
        }

        const isAuthed = this.getAuthState();
        const sentinel = this.currentSentinelState;
        const idle = this.lastSSE?.idle;

        let dot = document.querySelector('.footerDot');
        let textEl = document.querySelector('.footerText');

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

        // 0️⃣ Version
        try {
            const meta = window.SkyeApp?.lastSSE?.siteMeta;
            const versionEl = this.dom?.version || document.getElementById('versionFooter');

            if (versionEl && meta) {
                const newHTML = formatVersionFooter(meta);
                if (versionEl.innerHTML !== newHTML) {
                    versionEl.innerHTML = newHTML;
                }
            }
        } catch (err) {}

        // 1️⃣ Thinking dominates
        if (this.isThinking === true) {
            dot.style.background = '#007aff';
            textEl.innerHTML = '⏳ Thinking<span class="ellipsis" aria-hidden="true"></span>';
            return;
        }

        // 2️⃣ Auth gate
        if (!isAuthed) {
            render('#111', 'Authorization required to continue');
            return;
        }

        // 3️⃣ 🆕 IDLE STATE (NEW LAYER)
        if (idle) {

            const auth = this.lastSSE?.auth || {};

            const first = auth.firstName || '';
            const last  = auth.lastName || '';

            let name =
                `${first} ${last}`.trim() ||
                auth.username ||
                this.authUser ||
                'User';

            // Optional: clean email → display name
            if (name.includes('@')) {
                name = name
                    .split('@')[0]
                    .replace(/[._-]/g, ' ')
                    .replace(/\b\w/g, c => c.toUpperCase());
            }

            const seconds = idle.remainingSeconds ?? 0;
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            const time = `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;

            if (idle.state === "expired") {
                render('#ff3b30', `Session Expired · ${name} · 00:00 remaining`);
                return;
            }

            if (idle.state === "warning") {
                render('#ff9500', `Session Expiring · ${name} · ${time} remaining`);
                return;
            }

            if (idle.state === "active") {
                // allow governance to override active state if needed
                render('#00c853', `Session Active · ${name} · ${time} remaining`);
            }
        }

        // 4️⃣ Governance (can override active idle)
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

        // 5️⃣ Clean fallback (only if no idle)
        if (!idle) {
            render('#00c853', 'Authenticated • Ready');
        }
    },
    // #endregion

    // #region 🧩 UI Action Registry — Command Surface Action Router
    uiActionRegistry: {
        // 🧹 Clear Session Surface
        clear_screen() {
            this.clearSessionSurface();
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
                this.clearSessionSurface();
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

            console.log('[AUTH] delaying SSE restart...');

            setTimeout(() => {
                console.log('[AUTH] starting SSE (delayed)');
                window.SkySSE.start();
            }, 300); // 🔥 300–500ms is ideal

        } else {
            console.error('[INIT] SkySSE not found');
        }

    },
    // #endregion

    // #region 🧱 Card Rendering & Clearing

    // 🔥 Full UI reset (cards + output)
    clearCards() {

        if (!this.cardHost) return;

        this.cardHost.innerHTML = '';

        console.log('[SkyIndex] All cards cleared');
    },

    // 🔥 Output-only reset (used by commands like contacts)
    clearOutput() {

        if (!this.cardHost) return;

        const output = this.cardHost.querySelector('.commandOutput');
        if (output) output.innerHTML = '';

        console.log('[SkyIndex] Output cleared');
    },

    // 🔥 Session reset (user-visible reset)
    clearSessionSurface() {

        if (!this.cardHost) return;

        this.clearOutput();

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

    // #region 📄 Code Reader
    async readCodeFile(file) {

        try {

            const res = await fetch(`/skyesoft/api/codeReader.php?file=${encodeURIComponent(file)}`);
            const data = await res.json();

            if (data.error) {
                this.appendSystemLine(`❌ ${data.error}`);
                return;
            }

            this.appendSystemLine(`📄 ${file}`);

            // Truncate large files for UI safety
            const content = data.content.length > 5000
                ? data.content.slice(0, 5000) + '\n\n... (truncated)'
                : data.content;

            // Render as formatted code block
            this.appendCodeBlock(`
                <div class="codeBlock">
                    <pre>${this.escapeHtml(content)}</pre>
                </div>
            `);

        } catch (err) {

            console.error('[CodeReader]', err);
            this.appendSystemLine('❌ Failed to read file');

        }
    },
    // #endregion

    // #region 🔐 Helpers — Safe Rendering
    escapeHtml(str) {
        return str
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;");
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

    // #region 🚀 Command Router 
    async handleCommand(text) {

        const activitySessionId = this.getActivitySessionId();

        console.log('🔑 Command using activitySessionId:', activitySessionId);

        this.appendSystemLine(text, 'user');

        const normalized = (text || '').toString().trim().toLowerCase();

        // ───────────────────────────────────────────────
        // 📄 Code Reader Command
        // ───────────────────────────────────────────────
        if (normalized.startsWith('read ') || normalized.startsWith('open ')) {
            const file = normalized
                .replace(/^read\s+/, '')
                .replace(/^open\s+/, '')
                .trim();

            await this.readCodeFile(file);
            return;
        }

        // ───────────────────────────────────────────────
        // 📇 Add Contact Command
        // ───────────────────────────────────────────────
        if (normalized.startsWith('add ')) {

            this.clearOutput();
            this.appendSystemLine('📇 Creating contact...');

            try {
                const activitySessionId = this.getActivitySessionId();

                const createRes = await fetch('/skyesoft/api/createContact.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify({ 
                        input: text,
                        activitySessionId: activitySessionId
                    })
                });

                const createData = await createRes.json();
                console.log('[CREATE RESPONSE]', createData);

                // ────── SUCCESS PATH ──────
                if (createData.success && createData.contact) {

                    this.appendSystemLine('✅ Contact successfully added to the database.');

                    // Render the full contact card (same as "show" command)
                    this.renderContactDetail(createData.contact);

                    // Store for "last contact" command
                    this.lastContactId = createData.contact.contactId || createData.contactId;

                } 
                // ────── NEEDS PARCEL ──────
                else if (createData.status === 'needs_parcel') {
                    this.appendSystemLine(`⚠️ ${createData.message || 'Parcel number required for Maricopa County.'}`, 'warning');
                    if (createData.location) {
                        this.appendSystemLine(`📍 ${createData.location.address}, ${createData.location.city} ${createData.location.state}`);
                    }
                } 
                // ────── ERROR STATES ──────
                else if (createData.status === 'reject' || createData.status === 'partial') {
                    this.appendSystemLine(`❌ ${createData.reason || createData.message || 'Creation failed'}`, 'warning');
                } 
                else {
                    this.appendSystemLine('⚠️ Contact processed but no details returned.');
                }

            } catch (err) {
                console.error('[ADD CONTACT ERROR]', err);
                this.appendSystemLine('❌ Contact creation failed. Please try again.', 'error');
            }

            return;
        }

        // ───────────────────────────────────────────────
        // 📇 Last Contact
        // ───────────────────────────────────────────────
        if (normalized === 'last contact') {
            if (!this.lastContactId) {
                this.appendSystemLine('No recent contact available.');
                return;
            }
            return await this.handleCommand(`show ${this.lastContactId}`);
        }

        // ───────────────────────────────────────────────
        // 📇 Show / List Contact
        // ───────────────────────────────────────────────
        if (normalized.startsWith('show ') || normalized.startsWith('list ')) {

            this.clearOutput();
            console.log('[CONTACT QUERY]', text);

            try {
                let location = this.lastLocation || { latitude: null, longitude: null };

                if (location.latitude === null || location.longitude === null) {
                    location = await this.getLocationSafe();
                    this.lastLocation = location;
                }

                const res = await fetch('/skyesoft/api/getContacts.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify({
                        query: text,
                        latitude: location.latitude,
                        longitude: location.longitude,
                        activitySessionId: activitySessionId
                    })
                });

                const data = await res.json();
                console.log('[CONTACT RESPONSE]', data);

                if (!data?.success || !Array.isArray(data.contacts) || data.contacts.length === 0) {
                    return await this.executeAICommand(text, activitySessionId);
                }

                if (data.mode === 'single' && data.contacts.length > 0) {
                    this.renderContactDetail(data.contacts[0]);
                } else {
                    this.appendSystemLine(`📇 ${data.contacts.length} contact(s) found`);
                    this.renderContactsList(data.contacts);
                }
            } catch (err) {
                console.error('[CONTACT FETCH ERROR]', err);
                return await this.executeAICommand(text, activitySessionId);
            }
            return;
        }

        // ───────────────────────────────────────────────
        // UI Actions
        // ───────────────────────────────────────────────
        let canonicalAction = null;
        if (['cls', 'clear', 'reset'].includes(normalized)) canonicalAction = 'clear_screen';
        else if (['logout', 'exit'].includes(normalized)) canonicalAction = 'logout';

        if (canonicalAction) {
            const handler = this.uiActionRegistry?.[canonicalAction];
            if (typeof handler === 'function') {
                await handler.call(this);
                return;
            }
        }

        // ───────────────────────────────────────────────
        // AI Fallback
        // ───────────────────────────────────────────────
        await this.executeAICommand(text, activitySessionId);
    },
    // #endregion

    // #region 📇 Contact Result Renderer
    renderContactResult(data) {

        console.log('[CONTACT RESULT]', data);

        if (!data || typeof data !== 'object') {
            this.appendSystemLine('⚠ Invalid contact response.');
            return;
        }

        switch (data.status) {

            case 'resolved_new':

                this.appendSystemLine('✔ Contact Created');

                if (data.contact?.name && data.entity?.name) {
                    this.appendSystemLine(
                        `${data.contact.name} · ${data.entity.name}`
                    );
                }

                if (data.location?.city && data.location?.state) {
                    this.appendSystemLine(
                        `${data.location.city}, ${data.location.state}`
                    );
                }

                break;

            case 'resolved_duplicate':

                this.appendSystemLine('⚠ Duplicate Detected');

                if (data.contact?.name && data.entity?.name) {
                    this.appendSystemLine(
                        `${data.contact.name} · ${data.entity.name}`
                    );
                }

                break;

            case 'reject':

                this.appendSystemLine(`❌ ${data.reason || 'Contact rejected.'}`);
                break;

            case 'conflict':

                this.appendSystemLine('⚠ Conflict Detected');
                this.appendSystemLine('Multiple possible matches.');
                break;

            default:

                this.appendSystemLine('⚠ Unknown contact response.');
                console.warn('[CONTACT] Unknown status:', data.status);
        }
    },
    // #endregion

    // #region 📇 Contact List Renderer
    renderContactsList(contacts) {

        if (!Array.isArray(contacts) || contacts.length === 0) {
            this.appendSystemLine('No contacts found.');
            return;
        }

        this.appendSystemLine(`📇 ${contacts.length} contact(s) found`);

        contacts.forEach((c, index) => {

            const name =
                `${c.contactFirstName || ''} ${c.contactLastName || ''}`.trim()
                || 'Unnamed Contact';

            const company = c.entityName || 'Unknown Entity';

            const phone = c.contactPrimaryPhone || '';
            const email = c.contactEmail || '';

            // 🔹 Main line (identity)
            this.appendSystemLine(`${index + 1}. ${name} · ${company}`);

            // 🔹 Inline actionable info (only if exists)
            let detailLine = [];

            if (phone) detailLine.push(`📞 ${phone}`);
            if (email) detailLine.push(`✉️ ${email}`);

            if (detailLine.length) {
                this.appendSystemLine(`   ${detailLine.join('  ')}`);
            }

        });
    },
    // #endregion

    // #region 📇 Contact Detail Renderer
    renderContactDetail(contact) {
        if (!contact) {
            this.appendSystemLine('Contact not found.');
            return;
        }

        const fullName = [
            contact.contactSalutation,
            contact.contactFirstName,
            contact.contactLastName
        ].filter(Boolean).join(' ').trim() || 'Unnamed Contact';

        const title = contact.contactTitle ? `, ${contact.contactTitle}` : '';

        const html = `
            <div class="contact-card">
                <div class="contact-header">
                    <span class="contact-icon">👤</span>
                    <div class="contact-name">${fullName}${title}</div>
                </div>

                ${contact.entityName ? `
                <div class="contact-company">
                    <span class="contact-icon">🏢</span> ${contact.entityName}
                </div>` : ''}

                ${contact.contactPrimaryPhone ? `
                <div class="contact-line">📞 ${contact.contactPrimaryPhone}</div>` : ''}

                ${contact.contactEmail ? `
                <div class="contact-line">✉️ ${contact.contactEmail}</div>` : ''}

                ${contact.locationAddress ? `
                <div class="contact-line">📍 ${contact.locationAddress}, ${contact.locationCity || ''} ${contact.locationState || ''} ${contact.locationZip || ''}</div>` : ''}

                <div class="contact-actions">
                    <span class="contact-link" onclick="SkyIndex.showFullContact(${contact.contactId})">
                        View full profile →
                    </span>
                </div>
            </div>
        `;

        this.appendSystemHtml(html);
    },
    // #endregion

    // #region 🤖 AI Command Execution
    async executeAICommand(prompt, incomingActivitySessionId = null) {

        this.setThinking(true);

        try {
            // Use passed ID or fetch fresh
            const activitySessionId = incomingActivitySessionId || this.getActivitySessionId();

            console.log('🤖 AI using activitySessionId:', activitySessionId);

            // 🌍 Resolve Location (cached + non-blocking)
            let location = this.lastLocation || { latitude: null, longitude: null };

            if (!location || location.latitude === null || location.longitude === null) {
                location = await Promise.race([
                    this.getLocationSafe(),
                    new Promise(resolve =>
                        setTimeout(() => resolve({ latitude: null, longitude: null }), 1500)
                    )
                ]);

                this.lastLocation = location;
            }

            console.log('[AI GEO]', location);

            const res = await fetch('/skyesoft/api/askOpenAI.php?type=skyebot&ai=true', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    userQuery: prompt,
                    latitude: location.latitude,
                    longitude: location.longitude,
                    activitySessionId: activitySessionId
                })
            });

            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            const data = await res.json();

            // ───────────────────────────────────────────────
            // UI Action (authoritative)
            // ───────────────────────────────────────────────
            if (data?.type === 'ui_action') {
                // ... existing UI action logic unchanged ...
                return;
            }

            // ───────────────────────────────────────────────
            // Domain Intent
            // ───────────────────────────────────────────────
            if (data?.type === 'domain_intent') {
                // ... existing domain intent logic unchanged ...
                return;
            }

            // ───────────────────────────────────────────────
            // Text / HTML Response
            // ───────────────────────────────────────────────
            if (typeof data?.response === 'string' && data.response.trim()) {
                const looksLikeHtml = data.response.includes('<div') ||
                                    data.response.includes('<a ') ||
                                    data.response.includes('<button');

                if (looksLikeHtml) {
                    if (data.response.includes('codeBlock')) {
                        this.appendCodeBlock(data.response);
                    } else {
                        this.appendSystemHtml(data.response);
                    }
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

    // #region 🔐 Login Logic (Server Auth)
    async handleLoginSubmit(form) {

        console.log('[AUTH 1] Login submit received');

        const email = form.querySelector('input[type="email"]')?.value.trim();
        const pass  = form.querySelector('input[type="password"]')?.value.trim();
        const error = form.querySelector('.loginError');

        if (!email || !pass) {
            error.textContent = 'Please enter email and password.';
            error.hidden = false;
            return;
        }

        try {
            const activitySessionId = this.getActivitySessionId();
            console.log('[AUTH] Using activitySessionId:', activitySessionId);

            console.log('[AUTH 2] Resolving location...');

            let location = this.lastLocation || { latitude: null, longitude: null };

            if (location.latitude === null || location.longitude === null) {
                try {
                    location = await Promise.race([
                        this.getLocationSafe(),
                        new Promise(resolve =>
                            setTimeout(() => resolve({ latitude: null, longitude: null }), 4000)
                        )
                    ]);

                    if (location.latitude !== null && location.longitude !== null) {
                        this.lastLocation = location;
                    }
                } catch (geoErr) {
                    console.warn('[AUTH GEO] failed or timed out', geoErr);
                }
            }

            const res = await fetch('/skyesoft/api/auth.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'login',
                    username: email,
                    password: pass,
                    latitude: location.latitude,
                    longitude: location.longitude,
                    activitySessionId: activitySessionId
                })
            });

            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            const data = await res.json();
            console.log('[AUTH] Response:', data);

            if (!data.success) {
                error.textContent = data.message || 'Login failed.';
                error.hidden = false;
                return;
            }

            // Success path
            error.hidden = true;

            const check = await fetch('/skyesoft/api/auth.php?action=check', {
                credentials: 'include'
            });

            const session = await check.json();

            if (session.authenticated === true) {
                this.authState = true;
                document.body.setAttribute("data-auth", "true");
                this.authUser = session.username ?? null;
                this.authRole = session.role ?? null;

                this.renderCommandInterfaceCard?.();
                this.commandSurfaceActive = true;
                this.renderFooterStatus?.();
                this.startActivityPing?.();
            }

            window.SkySSE?.stop?.();
            setTimeout(() => window.SkySSE?.start?.(), 300);

        } catch (err) {
            console.error('[AUTH ERROR]', err);
            error.textContent = 'Connection error. Please try again.';
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

        // 🔁 Re-render footer (authoritative page instance)
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

        const activitySessionId = this.getActivitySessionId();

        console.log('[LOGOUT] Using activitySessionId:', activitySessionId, '| Source:', source);

        fetch('/skyesoft/api/auth.php', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                action: 'logout',
                activitySessionId: activitySessionId
            })
        })
        .then(res => {
            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            console.log('[SkyIndex] Logout request accepted', { source, activitySessionId });

            window.SkySSE?.stop?.();

            const app  = window.SkyeApp;
            const page = app?.pageHandlers?.[app?.currentPage];

            page?.stopActivityPing?.();

            if (window.SkyeApp) window.SkyeApp.lastSSE = null;

            if (page) {
                page.authState = false;
                page.authUser  = null;
                page.authRole  = null;
                page.commandSurfaceActive = false;
                page.idleState = null;
                page._logoutHandled = true;

                document.body.removeAttribute('data-auth');
                page.renderLoginCard?.();
                page.renderFooterStatus?.call(page);
            }

            setTimeout(() => window.SkySSE?.start?.(), 100);
        })
        .catch(err => {
            console.error('[SkyIndex] Logout error:', err);
            this.appendSystemLine?.('❌ Logout failed.');
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