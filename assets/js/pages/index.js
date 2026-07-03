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

// Safe Base64 that handles Unicode (em-dashes, smart quotes, etc.)
function safeBase64Encode(str) {
    return btoa(unescape(encodeURIComponent(str)));
}

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
    currentProposal: null,
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
            if (!output) return null; // Ensure we handle missing hosts gracefully

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

            // RETURN FIX: Return the node instance so we can modify its text later
            return line;
        },

        // Appends system HTML (with safety checks)
        appendSystemHtml(html) {

            const output = this.getOutputHost();
            if (!output) return;

            const safeHtml = (html === null || html === undefined)
                ? ''
                : String(html);

            // Universal allowed patterns
            const isAllowedHtml =
                safeHtml.includes('result-card') ||           // ← New unified class
                safeHtml.includes('property-review-card') ||
                safeHtml.includes('streetview-card') ||
                safeHtml.includes('contact-card') ||
                safeHtml.includes('parcel-review-card') ||
                safeHtml.includes('gov-box') ||
                safeHtml.includes('gov-action') ||
                safeHtml.includes('gov-panel') ||
                safeHtml.includes('Primary Parcel') ||
                safeHtml.includes('Parcel Review') ||
                safeHtml.includes('📸 Location Imagery');

            if (!isAllowedHtml) {
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

    // Define clean display names for user commands
    intentRegistry: {
        property_review: {
            display: 'Property Review',
            icon: '🏠',
            processing: 'Resolving Property...'
        },
        street_view: {
            display: 'Google Street View',
            icon: '📸',
            processing: 'Loading Street View...'
        },
        location_review: {
            display: 'Location Review',
            icon: '📍',
            processing: 'Resolving Location...'
        },
        contact_proposal: {
            display: 'Proposed Contact',
            icon: '👤',
            processing: 'Analyzing Contact...'
        }
    },

    // #endregion

    // #region 🧠 Command Router
    async handleCommand(text) {
        if (!text || !text.trim()) return;

        const activitySessionId = this.getActivitySessionId();
        console.log('[COMMAND]', text, '| session:', activitySessionId);

        const normalized = text.toString().trim().toLowerCase();

        // Parse lines once (shared by all routers)
        const lines = text.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
        const firstLine = lines[0]?.toLowerCase() || '';

        // --------------------------------------------------
        // 🎛 Fast UI Actions Interceptor
        // --------------------------------------------------
        let canonicalAction = null;
        if (['cls', 'clear', 'reset'].includes(normalized)) {
            canonicalAction = 'clear_screen';
        } else if (['logout', 'exit'].includes(normalized)) {
            canonicalAction = 'logout';
        }
        if (canonicalAction) {
            const handler = this.uiActionRegistry?.[canonicalAction];
            if (typeof handler === 'function') {
                await handler.call(this);
                return;
            }
        }

        // --------------------------------------------------
        // 📸 Explicit Workflows (Higher Priority)
        // --------------------------------------------------
        const isExplicitCommand = 
            firstLine.startsWith('street view') ||
            firstLine.startsWith('property review') ||
            firstLine.startsWith('parcel review') ||
            firstLine.startsWith('recorder') ||
            firstLine.startsWith('treasurer') ||
            firstLine.startsWith('tax');

        if (isExplicitCommand) {
            const streetViewIntent = await this.isStreetViewIntent(text);
            if (streetViewIntent) {
                const userLineNode = this.appendSystemLine(text, 'user');
                this.suppressRawIntentEcho();
                this.renderStreetViewProcessingState();

                (async () => {
                    let cleanAddress = text.trim();
                    try {
                        const parseRes = await fetch('/skyesoft/api/askOpenAI.php', {
                            method: 'POST',
                            credentials: 'include',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ type: "parseIntent", userQuery: text })
                        });

                        if (parseRes.ok) {
                            const parsed = await parseRes.json();
                            if (parsed.cleanAddress) cleanAddress = parsed.cleanAddress;
                        }
                    } catch (e) { console.error('[Intent Parse Error]', e); }

                    if (userLineNode) {
                        const textSpan = userLineNode.querySelector('.commandText');
                        if (textSpan) textSpan.textContent = `Google Street View - ${cleanAddress}`;
                    }

                    await this.executeStreetViewWorkflow(text, activitySessionId, cleanAddress);
                })();

                return;
            }
        }

        // --------------------------------------------------
        // 📇 Unified Proposal Router (PC-1 through PC-5)
        // --------------------------------------------------
        if (lines.length >= 2) {
            const hasEmail         = /@\S+/.test(text);
            const hasStrictEmail   = /@\S+\.\S{2,}/.test(text);
            const hasPhone         = /\b\d{3}[-.\s]?\d{3}[-.\s]?\d{4}\b/.test(text);
            const hasName          = /[A-Z][a-z]+ [A-Z][a-z]+/.test(text);
            const hasZip           = /\b\d{5}(-\d{4})?\b/.test(text); 
            const hasStreetAddress = /\b\d{1,6}\s+\S+/.test(text);

            const looksLikeContactProposal = (hasStrictEmail || hasPhone) && hasName;
            const looksLikeLocationProposal = hasStreetAddress && hasZip && !hasEmail && !hasPhone;

            if (looksLikeContactProposal) {
                console.log('[Proposal Router] Routing to CONTACT workflow');
                const nameMatch = text.match(/([A-Z][a-z]+ [A-Z][a-z]+)/);
                const normalizedName = nameMatch ? nameMatch[1] : 'New Contact';

                this.appendSystemLine(`Proposed Contact - ${normalizedName}`, 'user');
                this.suppressRawContactEcho();
                this.renderContactProcessingState();

                await this.executeContactProposalWorkflow(text, activitySessionId);
                return;
            }

            if (looksLikeLocationProposal) {
                console.log('[Proposal Router] Routing to LOCATION workflow');

                let calibratedLines = [...lines];
                if (calibratedLines.length === 3) {
                    const lastLine = calibratedLines[2];
                    const zipMatch = lastLine.match(/(.*?\b(?:Rd|St|Ave|Blvd|Dr|Lane|Way|Plaza|Pkwy|Rd\.)?)\s*([A-Za-z\s]+,\s*[A-Z]{2}\s+\d{5}.*)/i);
                    if (zipMatch) {
                        calibratedLines[2] = zipMatch[1].trim();
                        calibratedLines.push(zipMatch[2].trim());
                    }
                }

                const entityName = calibratedLines[0] || 'Proposed Location';
                this.appendSystemLine(`Processing Location Proposal — ${entityName}`, 'user');
                this.suppressRawContactEcho();
                
                if (typeof this.renderLocationProcessingState === 'function') {
                    this.renderLocationProcessingState();
                } else {
                    this.renderContactProcessingState();
                }

                await this.executeLocationProposalWorkflow(text, activitySessionId, calibratedLines);
                return;
            }
        }

        // --------------------------------------------------
        // AI Fallback
        // --------------------------------------------------
        this.appendSystemLine(text, 'user');   
        await this.executeAICommand(text, activitySessionId);
    },
    // #endregion

    // #region 📇 Contact Proposal Workflow
    async executeContactProposalWorkflow(input, activitySessionId) {
        try {
            console.log('🚀 Executing dedicated contact proposal workflow');

            const res = await fetch('/skyesoft/api/processProposedContact.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    input: input,
                    activitySessionId: activitySessionId,
                    mode: 'propose'
                })
            });

            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }

            const proposal = await res.json();
            this.handleContactProposal(proposal);

        } catch (err) {
            console.error('❌ Contact proposal workflow failed:', err);
            this.appendSystemLine('❌ Failed to process contact proposal. Falling back to AI...');

            // Safe fallback
            await this.executeAICommand(input, activitySessionId);
        }
    },
    // #endregion

    // #region 📸 Street View Workflow Helpers

        // Global internal reference store to safely maintain runtime Map & Panorama instances across steps
        _streetViewWorkspace: {
            map: null,
            panorama: null,
            marker: null
        },

        // Helper to determine if intent matches Street View data
        async isStreetViewIntent(text) {
            try {
                // If the incoming text is already a JSON string from your parser:
                if (text.trim().startsWith('{')) {
                    const parsed = JSON.parse(text);
                    if (parsed.workflow === 'street_view') return parsed;
                }
                
                // Otherwise, insert your local regex or LLM checking logic here
                return null;
            } catch (e) {
                return null;
            }
        },

        // Suppresses the raw text from printing out in the UI chat frame
        suppressRawIntentEcho() {
            // Logic to clear or prevent the raw text block from mounting
            console.log("Raw intent text presentation suppressed.");
        },

        // Handles executing the actual downstream dual view maps frame
        async executeStreetViewWorkflow(text, activitySessionId, normalizedAddress) {
            console.log(`Executing workflow downstream for: ${normalizedAddress}`);
            // Your logic to invoke initializeDualView() or coordinate with your backend system goes here.
        },

        renderStreetViewProcessingState() {
            const output = this.getOutputHost();
            if (!output) return;

            // Always clear any existing processing element first
            document.querySelectorAll('#streetViewProcessing').forEach(el => el.remove());

            const processing = document.createElement('div');
            processing.id = 'streetViewProcessing';
            processing.className = 'commandLine system processing';
            processing.innerHTML = `
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span style="font-size: 1.5em; animation: spin 1.3s linear infinite;">📸</span>
                    <div>
                        <strong>📍 Initializing Selection Workspace...</strong><br>
                        <span style="font-size: 0.92em;">Address validation • Checking parcel coordinates • Building map interface</span>
                    </div>
                </div>
            `;

            output.appendChild(processing);
            this.scrollOutputToBottom(output);

            this._currentStreetViewProcessingEl = processing;
        },

        replaceStreetViewProcessingWithResult() {
            // Aggressive cleanup — removes all instances
            document.querySelectorAll('#streetViewProcessing').forEach(el => el.remove());
            this._currentStreetViewProcessingEl = null;
        },

        async executeStreetViewWorkflow(text, activitySessionId, address) {
            // Always clean any previous processing state first
            this.replaceStreetViewProcessingWithResult();

            this.renderStreetViewProcessingState();
            this.setThinking(true);

            // CORRECTED: Strips "streetview", then strips any leading hyphens, colons, or spaces
            const finalAddress = (address || text)
                .replace(/street\s*view/ig, '')
                .replace(/^[\s\-\:]+/, '') // Removes leading spaces, hyphens, and colons
                .replace(/\r?\n/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();

            try {
                const res = await fetch('/skyesoft/api/getStreetView.php', {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        address: finalAddress,
                        activitySessionId: activitySessionId
                    })
                });

                if (!res.ok) throw new Error(`HTTP ${res.status}`);

                const data = await res.json();

                // Clean processing state immediately after successful response
                this.replaceStreetViewProcessingWithResult();

                if (data.success) {
                    // CORRECTED: Ensure the UI card display address is overridden 
                    // with our clean address if the backend returned a dirty string
                    if (!data.address || data.address.includes('-')) {
                        data.address = finalAddress;
                    }
                    
                    this.renderStreetViewResult(data);
                } else {
                    this.appendSystemLine(`❌ ${data.message || 'Street View failed.'}`, 'error');
                }

            } catch (err) {
                console.error('[StreetView Workflow Error]', err);
                this.replaceStreetViewProcessingWithResult();
                this.appendSystemLine('❌ Street View request failed.', 'error');
            } finally {
                this.setThinking(false);
            }
        },

        renderStreetViewResult(data) {
            const fullAddress = data.fullAddress 
                            || data.address 
                            || 'Location';

            const imageType = (data.imageType || 'streetview').toUpperCase();
            const imageSrc = data.imagePath || '';

            const dataPayloadAttr = safeBase64Encode(JSON.stringify(data));

            const html = `
                <div class="commandLine system html">
                    <div class="result-card">
                        <div class="result-header">
                            <span class="result-icon">📸</span>
                            <strong class="result-title">Location Imagery</strong>
                        </div>

                        <div class="result-body" style="padding:10px 18px 8px;">
                            <small style="color:#555; display:block; margin-bottom:6px; font-size:0.95em;">${this.escapeHtml(fullAddress)}</small>

                            ${imageSrc ? `
                            <div style="margin:6px 0 10px; text-align:center;">
                                <img src="${imageSrc}" alt="${imageType}" 
                                    style="max-width:100%; max-height:200px; border-radius:6px; box-shadow:0 2px 6px rgba(0,0,0,0.1);">
                            </div>` : ''}

                            <div style="font-size:0.93em;"><strong>Image Type:</strong> <span style="color:#006400;">${imageType}</span></div>
                        </div>

                        <div class="result-actions">
                            <button onclick="SkyIndex.openInteractiveStreetView(JSON.parse(atob('${dataPayloadAttr}')))" 
                                    class="btn btn-success" style="flex:1;">
                                ✏️ Edit View (Workspace)
                            </button>
                        </div>
                    </div>
                </div>
            `;

            this.appendSystemHtml(html);
        },

        renderParcelReviewResult(data) {
            const addr = data.inputAddress || 'Address';
            const summary = data.summary || '';
            const parcel = data.parcel?.primaryParcel || {};
            const gov = data.governance || {};
            const jurisdiction = data.jurisdiction?.governingJurisdiction || parcel.jurisdiction || 'Unknown';
            const actionId = data.actionId || '';

            const html = `
                <div class="commandLine system html">
                    <div class="parcel-review-card" style="background:#f8f9fa; padding:20px; border-radius:8px; border-left:6px solid #007aff; max-width:720px;">
                        <div style="display:flex; align-items:center; gap:12px; margin-bottom:16px;">
                            <span style="font-size:2.2em;">🏠</span>
                            <div>
                                <strong style="font-size:1.15em;">Property Review</strong><br>
                                <small style="color:#555;">${addr}</small>
                            </div>
                        </div>

                        <div style="background:white; padding:16px; border-radius:6px; margin-bottom:16px; line-height:1.5;">
                            ${summary}
                        </div>

                        <div style="display:grid; grid-template-columns: auto 1fr; gap:8px 16px; font-size:0.95em; margin-bottom:16px;">
                            ${parcel.parcelNumber ? `<div><strong>Parcel</strong></div><div>${parcel.parcelNumber}</div>` : ''}
                            <div><strong>Jurisdiction</strong></div>
                            <div>${jurisdiction}</div>
                            <div><strong>Governance</strong></div>
                            <div>${gov.rsCode || 'RS-0'} — ${gov.parcelStatus || 'Single Parcel Found'}</div>
                        </div>

                        ${actionId ? `
                        <div style="text-align:right; margin-top:12px;">
                            <a href="/skyesoft/api/generateReports.php?reportType=property&actionId=${actionId}" 
                            target="_blank" 
                            style="color:#007aff; text-decoration:underline; font-weight:500;">
                                📄 Generate Full Property Report
                            </a>
                        </div>` : ''}
                    </div>
                </div>
            `;

            this.appendSystemHtml(html);
        },

        openInteractiveStreetView(data) {

            // Clean up any lingering processing indicator
            this.replaceStreetViewProcessingWithResult();

            console.log('[OPEN WORKSPACE MODAL]', data);

            const lat = parseFloat(data.latitude);
            const lng = parseFloat(data.longitude);
            const apiKey = data.apiKey;

            if (!lat || !lng || !apiKey) {
                alert("Workspace load failed: Missing coordinate mappings or API restriction keys.");
                return;
            }

            this.closeStreetViewModal();

            // 1. Setup layout canvas workspace instead of old static iframe container
            const modal = document.createElement('div');
            modal.id = 'streetViewModal';
            modal.className = 'modal-backdrop';
            modal.style.cssText = `
                position:fixed; top:0; left:0; width:100%; height:100%; 
                background:rgba(0,0,0,0.85); z-index:99999; display:flex; 
                align-items:center; justify-content:center; font-family:sans-serif;
            `;

            modal.innerHTML = `
                <div style="background:#fff; padding:20px; border-radius:12px; width:95%; max-width:1150px; box-shadow:0 15px 45px rgba(0,0,0,0.6);">
                    <div style="display:flex; justify-content:space-between; margin-bottom:12px; align-items:center;">
                        <div>
                            <strong style="font-size:1.15em; color:#222; display:block; margin-bottom:2px;">Location Imagery Selection Workspace</strong>
                            <small style="color:#666; font-size:0.9em; display:block;">📍 ${data.address || ''}</small>
                        </div>
                        <button onclick="SkyIndex.closeStreetViewModal()" 
                                style="background:none; border:none; font-size:32px; cursor:pointer; color:#bbb; line-height:1;">&times;</button>
                    </div>
                    
                    <div style="display:flex; gap:16px; height:500px; margin-bottom:16px;">
                        <div id="workspaceMapCanvas" style="flex:1; background:#f0f0f0; border-radius:8px; border:1px solid #ddd;"></div>
                        <div id="workspacePanCanvas" style="flex:1; background:#f0f0f0; border-radius:8px; border:1px solid #ddd;"></div>
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:12px; border-top:1px solid #eee; padding-top:14px;">
                        <button onclick="SkyIndex.closeStreetViewModal()" 
                                style="background:#f1f3f5; color:#495057; border:none; padding:11px 20px; border-radius:6px; font-weight:600; cursor:pointer;">
                            Cancel
                        </button>
                        <button onclick="SkyIndex.captureCurrentView()" 
                                style="background:#28a745; color:white; border:none; padding:11px 26px; border-radius:6px; font-weight:700; cursor:pointer; box-shadow:0 2px 8px rgba(40,167,69,0.3);">
                            📸 Use This View
                        </button>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);

            // 2. Asynchronously load SDK library script and render elements map canvas inside workspace
            this._loadGoogleMapsSdk(apiKey, () => {
                this._initializeDualPaneWorkspace(lat, lng);
            });
        },

        _loadGoogleMapsSdk(apiKey, callback) {
            if (typeof google === 'object' && typeof google.maps === 'object') {
                callback();
                return;
            }

            window._googleMapsSdkCallback = () => {
                delete window._googleMapsSdkCallback;
                callback();
            };

            const script = document.createElement('script');
            script.type = 'text/javascript';
            script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(apiKey)}&callback=_googleMapsSdkCallback`;
            script.async = true;
            script.defer = true;
            script.onerror = () => alert("Failed to fetch Google Maps JS engine layout modules.");
            document.head.appendChild(script);
        },

        _initializeDualPaneWorkspace(lat, lng) {
            const centerPoint = { lat: lat, lng: lng };

            // Initialize overhead map canvas context
            this._streetViewWorkspace.map = new google.maps.Map(document.getElementById('workspaceMapCanvas'), {
                center: centerPoint,
                zoom: 19,
                mapTypeId: 'satellite',
                streetViewControl: false,
                mapTypeControl: false,
                tilt: 0
            });

            // Initialize active panorama framework viewport
            this._streetViewWorkspace.panorama = new google.maps.StreetViewPanorama(
                document.getElementById('workspacePanCanvas'), {
                    position: centerPoint,
                    pov: { heading: 105, pitch: 8 },
                    zoom: 1,
                    visible: true
                }
            );

            this._streetViewWorkspace.map.setStreetView(this._streetViewWorkspace.panorama);

            // Mount movable focal target pin marker
            this._streetViewWorkspace.marker = new google.maps.Marker({
                position: centerPoint,
                map: this._streetViewWorkspace.map,
                draggable: true,
                title: "Selected Frame Perspective Centerpoint"
            });

            const mapObj = this._streetViewWorkspace.map;
            const panObj = this._streetViewWorkspace.panorama;
            const markerObj = this._streetViewWorkspace.marker;

            // Interactive Hook A: Clicking on map shifts focal positioning layout immediately
            mapObj.addListener('click', (event) => {
                const point = event.latLng;
                markerObj.setPosition(point);
                panObj.setPosition(point);
            });

            // Interactive Hook B: Manual marker drag-drops update panorama viewpoints
            markerObj.addListener('dragend', () => {
                const point = markerObj.getPosition();
                panObj.setPosition(point);
            });

            // Interactive Hook C: Navigating within Street View updates satellite map pins automatically
            panObj.addListener('position_changed', () => {
                const position = panObj.getPosition();
                mapObj.setCenter(position);
                markerObj.setPosition(position);
            });
        },

        closeStreetViewModal() {
            const modal = document.getElementById('streetViewModal');
            if (modal) modal.remove();

            // Flush reference pointers to avoid garbage collector block arrays leaks
            this._streetViewWorkspace.map = null;
            this._streetViewWorkspace.panorama = null;
            this._streetViewWorkspace.marker = null;
        },

        captureCurrentView() {
            const panInstance = this._streetViewWorkspace.panorama;
            if (!panInstance) return;

            const currentPos = panInstance.getPosition();
            const currentPov = panInstance.getPov();

            const selectionPayload = {
                lat: currentPos.lat(),
                lng: currentPos.lng(),
                heading: currentPov.heading,
                pitch: currentPov.pitch,
                zoom: panInstance.getZoom()
            };

            console.log("🎯 Exact Proposal Workspace Coordinates Captured:", selectionPayload);
            this.appendSystemLine(`📸 View Saved: Lat ${selectionPayload.lat.toFixed(5)}, Heading ${selectionPayload.heading.toFixed(0)}°`, 'success');
            
            // TODO: Pass 'selectionPayload' downstream to your proposal document generator.

            this.closeStreetViewModal();
        },

    // #endregion

    // #region 📍 Location Proposal Workflow (Clean + No Duplicates)
    async executeLocationProposalWorkflow(text, activitySessionId, parsedLines) {
        if (parsedLines.length < 2) {
            this.appendSystemLine('❌ Invalid location format.', 'error');
            return;
        }

        const entity = (parsedLines[0] || '').trim();
        const locationName = (parsedLines[1] || '').trim();
        const rawAddress = (parsedLines[2] || '').trim();
        const cityLine = (parsedLines[3] || '').trim();

        const city = cityLine.split(',')[0]?.trim() || '';
        const stateZipPart = cityLine.split(',').slice(1).join(',').trim();
        const stateParts = stateZipPart.split(/\s+/);
        const state = stateParts[0] || 'AZ';
        const zip = stateParts[stateParts.length - 1] || '';

        //console.log('[Location Proposal Payload]', { entity, locationName, rawAddress, city, state, zip });

        // Prevent duplicate processing UI
        this.renderLocationProcessingState();

        try {
            const payload = {
                input: text,
                proposalType: "location",
                activitySessionId: activitySessionId || this.getActivitySessionId(),
                inputData: {
                    mode: "propose",
                    actionTypeId: 13,
                    entity: { entityName: entity },
                    location: {
                        locationName: locationName,
                        locationAddress: rawAddress,
                        locationCity: city,
                        locationState: state,
                        locationZip: zip
                    }
                }
            };

           // console.log('[Location] Full payload being sent:', payload);

            const response = await fetch('/skyesoft/api/processProposedContact.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const responseText = await response.text();
            //console.log('[Location] Raw response text:', responseText);

            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseErr) {
                throw new Error(`Invalid JSON response: ${responseText.substring(0, 300)}`);
            }

            //console.log('[Location] Parsed result:', result);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${result.message || 'Unknown server error'}`);
            }

            // Clean UI (including any duplicates)
            document.querySelectorAll('#locationProcessing, #streetViewProcessing, .processing').forEach(el => el.remove());

            if (result.success === true) {
                console.log('[Location] Success - handing to renderer');
                result.inputAddress = text;
                this.handleContactProposal(result);
            } else {
                this.appendSystemLine(`❌ ${result.message || result.summary || 'Failed to process location proposal.'}`, 'error');
            }

        } catch (e) {
            console.error('[Location Engine Full Error]', e);
            document.querySelectorAll('#locationProcessing, #streetViewProcessing, .processing').forEach(el => el.remove());
            this.appendSystemLine(`Failed to process location proposal: ${e.message}`, 'system-error');
        }
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

    // #region 📇 Unified Governance & Identity Proposal Component Engine (v2)
    renderProposedContact(data) {
        const payload = data || {};
        const proposal = payload.data || {};
        const pcm      = payload.pcm || {};
        
        // Target Identity & Payload Structuring
        const entity   = proposal.entity || {};
        const contact  = proposal.contact || {};
        const location = proposal.location || {};
        const commit   = payload.commitPlan || {};

        const pcCode = pcm.pc || 'PC-?';
        const rsCodes = pcm.rs || [];
        const proposalKind = payload.proposalKind || 'contact';

        // 1. Resolve Active Context Code (Prioritize Exception RS flags over base PC layouts)
        let activeStatusKey = pcCode;
        if (rsCodes.length > 0) {
            // Pick highest severity exception flag present in the batch pipeline
            const criticalExceptions = ['RS-8', 'RS-3', 'RS-7', 'RS-6', 'RS-5'];
            const match = criticalExceptions.find(code => rsCodes.includes(code));
            if (match) activeStatusKey = match;
        }

        // 2. Extensible Theme Matrix Map (Decoupled Status Styling Language)
        const THEMES = {
            'PC-0': {
                borderLeft: '#17a2b8',
                badge: 'background: rgba(23, 162, 184, 0.12); color: #117a8b; border: 1px solid rgba(23, 162, 184, 0.25);',
                summaryBg: 'rgba(23, 162, 184, 0.03)',
                summaryBorder: 'rgba(23, 162, 184, 0.2)',
                summaryText: '#117a8b',
                icon: '✓',
                title: 'Existing Identity Verified',
                subtitle: 'All records already exist — No action required'
            },
            'PC-1': {
                borderLeft: '#28a745',
                badge: 'background: rgba(40, 167, 69, 0.1); color: #28a745; border: 1px solid rgba(40, 167, 69, 0.2);',
                summaryBg: 'rgba(40, 167, 69, 0.03)',
                summaryBorder: 'rgba(40, 167, 69, 0.15)',
                summaryText: '#1e7e34',
                icon: '📇',
                title: 'Proposed Identity Link',
                subtitle: 'Create new Entity, Location and Contact'
            },
            'PC-2': {
                borderLeft: '#28a745',
                badge: 'background: rgba(40, 167, 69, 0.1); color: #28a745; border: 1px solid rgba(40, 167, 69, 0.2);',
                summaryBg: 'rgba(40, 167, 69, 0.03)',
                summaryBorder: 'rgba(40, 167, 69, 0.15)',
                summaryText: '#1e7e34',
                icon: '📇',
                title: 'Proposed Identity Link',
                subtitle: 'Create new Location and Contact'
            },
            'PC-3': {
                borderLeft: '#28a745',
                badge: 'background: rgba(40, 167, 69, 0.1); color: #28a745; border: 1px solid rgba(40, 167, 69, 0.2);',
                summaryBg: 'rgba(40, 167, 69, 0.03)',
                summaryBorder: 'rgba(40, 167, 69, 0.15)',
                summaryText: '#1e7e34',
                icon: '📇',
                title: 'Proposed Identity Link',
                subtitle: 'Create new Contact'
            },
            'PC-4': {
                borderLeft: '#007aff',
                badge: 'background: rgba(0, 122, 255, 0.1); color: #007aff; border: 1px solid rgba(0, 122, 255, 0.2);',
                summaryBg: 'rgba(0, 122, 255, 0.03)',
                summaryBorder: 'rgba(0, 122, 255, 0.15)',
                summaryText: '#007aff',
                icon: '📍',
                title: 'Location Proposal',
                subtitle: 'Create new Location'
            },
            'PC-5': {
                borderLeft: '#28a745',
                badge: 'background: rgba(40, 167, 69, 0.1); color: #28a745; border: 1px solid rgba(40, 167, 69, 0.2);',
                summaryBg: 'rgba(40, 167, 69, 0.03)',
                summaryBorder: 'rgba(40, 167, 69, 0.15)',
                summaryText: '#1e7e34',
                icon: '📇',
                title: 'Proposed Identity Link',
                subtitle: 'Create new Entity and Location'
            },
            'RS-3': {
                borderLeft: '#ffc107',
                badge: 'background: rgba(255, 193, 7, 0.15); color: #b58100; border: 1px solid rgba(255, 193, 7, 0.3);',
                summaryBg: '#fffdf6',
                summaryBorder: '#ffeaa7',
                summaryText: '#d35400',
                icon: '⚠️',
                title: 'Proposed Contact',
                subtitle: 'Proposed Contact Incomplete — Missing required fields'
            },
            'RS-5': {
                borderLeft: '#0056b3',
                badge: 'background: rgba(0, 86, 179, 0.1); color: #0056b3; border: 1px solid rgba(0, 86, 179, 0.25);',
                summaryBg: 'rgba(0, 86, 179, 0.02)',
                summaryBorder: 'rgba(0, 86, 179, 0.15)',
                summaryText: '#004085',
                icon: '👥',
                title: 'Duplicate Record Warning',
                subtitle: 'Matching identity records detected in master dataset'
            },
            'RS-6': {
                borderLeft: '#fd7e14',
                badge: 'background: rgba(253, 126, 20, 0.12); color: #fd7e14; border: 1px solid rgba(253, 126, 20, 0.25);',
                summaryBg: 'rgba(253, 126, 20, 0.02)',
                summaryBorder: 'rgba(253, 126, 20, 0.15)',
                summaryText: '#ba5200',
                icon: '🗺️',
                title: 'Parcel Conflict Exception',
                subtitle: 'Multiple properties map to coordinates — Choice required'
            },
            'RS-7': {
                borderLeft: '#dc3545',
                badge: 'background: rgba(220, 53, 69, 0.1); color: #dc3545; border: 1px solid rgba(220, 53, 69, 0.2);',
                summaryBg: 'rgba(220, 53, 69, 0.02)',
                summaryBorder: 'rgba(220, 53, 69, 0.15)',
                summaryText: '#bd2130',
                icon: '🛑',
                title: 'Unresolved Address Point',
                subtitle: 'Regional parcel mapping bounds could not be validated'
            },
            'RS-8': {
                borderLeft: '#ffc107',
                badge: 'background: rgba(255, 193, 7, 0.15); color: #b58100; border: 1px solid rgba(255, 193, 7, 0.3);',
                summaryBg: '#fffdf6',
                summaryBorder: '#ffeaa7',
                summaryText: '#d35400',
                icon: '⚠️',
                title: 'Proposed Contact',
                subtitle: 'Validation Exception Notice'
            }
        };

        // Complete structural alignment template fallback if unknown status codes process
        const theme = THEMES[activeStatusKey] || {
            borderLeft: '#6c757d',
            badge: 'background: #e9ecef; color: #495057; border: 1px solid #ced4da;',
            summaryBg: '#fafafa',
            summaryBorder: '#eee',
            summaryText: '#333',
            icon: '📋',
            title: proposalKind === 'location' ? 'Location Proposal' : 'Proposed Record',
            subtitle: 'Evaluating entry logic and transaction properties'
        };

        // 3. String Compilation Handlers
        let contactIdentity = '—';
        if (proposalKind !== 'location') {
            const fullName = [
                contact.contactFirstName || contact.firstName,
                contact.contactLastName || contact.lastName
            ].filter(Boolean).join(' ');
            const titleStr = contact.contactTitle || contact.title || '';
            contactIdentity = [fullName, titleStr].filter(Boolean).join(' — ') || '—';
        }

        const locationName = location.locationName || location.name || '';
        const addressLine = [
            location.locationAddress || location.address,
            location.locationCity || location.city,
            location.locationState || location.state,
            location.locationZip || location.zip
        ].filter(Boolean).join(', ');

        // 4. Extract Operational Intent Checklists Directly from Commit Plan
        let commitPlanMarkup = '';
        if (activeStatusKey === 'PC-0') {
            commitPlanMarkup = `<span style="color:#6c757d;">No structural changes required</span>`;
        } else if (commit && (commit.createEntity || commit.createLocation || commit.createContact)) {
            const checklist = [];
            if (commit.createEntity) checklist.push('✓ Entity');
            if (commit.createLocation) checklist.push('✓ Location');
            if (commit.createContact) checklist.push('✓ Contact');
            commitPlanMarkup = `<span style="color: #666; font-weight: 500; margin-right: 4px;">Creates:</span> <span style="font-family: monospace; color: #28a745; font-weight: bold;">${checklist.join(' &nbsp; ')}</span>`;
        } else {
            // Implicit execution path display fallback
            commitPlanMarkup = `<span style="color: #666; font-weight: 500; margin-right: 4px;">Action:</span> <span style="color: #444;">${theme.subtitle}</span>`;
        }

        const dataPayloadAttr = safeBase64Encode(JSON.stringify(payload));

        const html = `
            <div class="commandLine system html">
                <div class="result-card" style="border-left: 5px solid ${theme.borderLeft}; background: #fff; width: 100%; max-width: 100%;">
                    
                    <div class="result-header" style="display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 12px 16px;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span class="result-icon">${theme.icon}</span>
                            <div style="display: flex; flex-direction: column;">
                                <strong class="result-title" style="color: #222;">${theme.title}</strong>
                                <small style="color: #666; font-size: 0.78em; line-height: 1.2; display: block; margin-top: 1px;">${this.escapeHtml(theme.subtitle)}</small>
                            </div>
                        </div>
                        <span style="${theme.badge} padding: 3px 8px; border-radius: 4px; font-family: monospace; font-size: 0.85em; font-weight: bold; white-space: nowrap; display: inline-block;">
                            ${this.escapeHtml(activeStatusKey)}
                        </span>
                    </div>

                    <div class="result-body" style="padding: 16px;">
                        <div class="result-grid" style="display: grid; grid-template-columns: minmax(70px, auto) 1fr; row-gap: 6px; column-gap: 16px; font-size: 0.92em; line-height: 1.3;">
                            
                            <span style="color: #666; font-weight: 600;">Entity:</span> 
                            <span style="color: #222;">${this.escapeHtml(entity.entityName || entity.name || '—')}</span>
                            
                            ${proposalKind !== 'location' ? `
                            <span style="color: #666; font-weight: 600;">Contact:</span> 
                            <span style="color: #222;">${this.escapeHtml(contactIdentity)}</span>
                            ` : ''}
                            
                            ${locationName ? `
                            <span style="color: #666; font-weight: 600;">Location:</span> 
                            <span style="color: #222; font-weight: 600;">${this.escapeHtml(locationName)}</span>
                            <span style="color: #666; font-weight: 600;">Address:</span> 
                            <span style="color: #222;">${this.escapeHtml(addressLine || '—')}</span>
                            ` : `
                            <span style="color: #666; font-weight: 600;">Location:</span> 
                            <span style="color: #222;">${this.escapeHtml(addressLine || '—')}</span>
                            `}
                            
                            ${proposalKind !== 'location' ? `
                            <span style="color: #666; font-weight: 600;">Phone:</span> 
                            <span style="color: #222;">${this.escapeHtml(contact.contactPrimaryPhone || contact.primaryPhone || '—')}</span>
                            <span style="color: #666; font-weight: 600;">Email:</span> 
                            <span style="color: #222;">${this.escapeHtml(contact.contactEmail || contact.email || '—')}</span>
                            ` : ''}
                        </div>
                    </div>

                    <div style="padding: 8px 16px; background: #fafafa; border-top: 1px solid #f0f0f0; font-size: 0.85em; display: flex; align-items: center; gap: 4px;">
                        ${commitPlanMarkup}
                    </div>

                    <div style="padding: 12px 16px; background: ${theme.summaryBg}; border-top: 1px dashed ${theme.summaryBorder}; font-size: 0.9em; line-height: 1.35; color: ${theme.summaryText};">
                        <strong style="font-size: 0.95em; display: block; margin-bottom: 2px;">Proposal Summary</strong>
                        ${this.escapeHtml(payload.narratives?.ui || payload.governance?.reason || 'Proposal parsing complete.')}
                    </div>

                    <div class="result-actions" style="padding: 12px 16px; border-top: 1px solid #eee; background: #fff; display: flex; gap: 8px;">
                        ${activeStatusKey === 'PC-0' ? 
                            `<button class="btn btn-secondary" style="flex: 2; padding: 4px 10px; font-size: 0.85em; background: #e9ecef; color: #6c757d; border: 1px solid #ced4da; cursor: not-allowed;" disabled>✓ Already Exists</button>` :
                            (activeStatusKey.startsWith('RS-')) ?
                            `<button class="btn btn-warning" style="flex: 2; padding: 4px 10px; font-size: 0.85em; background: #ffc107; color: #212529; border: 1px solid #d39e00;" onclick="SkyIndex.revalidateProposal()">✏️ Edit &amp; Resubmit</button>` :
                            `<button class="btn btn-success" style="flex: 2; padding: 4px 10px; font-size: 0.85em; background: #28a745; color: #fff; border: 1px solid #218838;" onclick="SkyIndex.acceptEditedProposal()">✔ Accept &amp; Save</button>`
                        }
                        <button class="btn btn-secondary" style="flex: 1; padding: 4px 10px; font-size: 0.85em; background: #6c757d; color: #fff; border: 1px solid #545b62;" onclick="SkyIndex.revalidateProposal()">↻ Revalidate</button>
                        <button class="btn btn-danger" style="flex: 1; padding: 4px 10px; font-size: 0.85em; background: #dc3545; color: #fff; border: 1px solid #bd2130;" onclick="SkyIndex.handleProposalAction('decline')">✕ Decline</button>
                    </div>

                </div>
            </div>
        `;

        this.appendSystemHtml(html);
    },
    // #endregion

    // #region 📇 RS-8 (Incomplete) Renderer — Matches PC Style
    renderIncompleteProposal(data) {
        const payload = data || {};
        const proposal = payload.data || {};
        const entity = proposal.entity || {};
        const contact = proposal.contact || {};
        const location = proposal.location || {};

        const governance = payload.governance || {};
        const pcm = payload.pcm || {};
        const rsLabel = pcm.rs?.[0] || 'RS-8';

        // B: Unified logic identity strings parsing exactly matching structural layouts
        const fullName = [
            contact.contactFirstName || contact.firstName,
            contact.contactLastName || contact.lastName
        ].filter(Boolean).join(' ');

        const titleStr = contact.contactTitle || contact.title || '';
        const contactIdentity = [fullName, titleStr].filter(Boolean).join(' — ');

        const addressLine = [
            location.locationAddress || location.address,
            location.locationCity || location.city,
            location.locationState || location.state,
            location.locationZip || location.zip
        ].filter(Boolean).join(', ');

        const errorReason = payload.narratives?.ui || governance.reason || 'Address parcel validation failure.';

        const html = `
            <div class="commandLine system html">
                <div class="result-card" style="border-left: 5px solid #ffc107; background: #fff; width: 100%; max-width: 100%;">
                    <div class="result-header" style="display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 12px 16px;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span class="result-icon" style="color:#e67e22;">⚠️</span>
                            <div style="display: flex; flex-direction: column;">
                                <strong class="result-title" style="color:#e67e22;">Proposed Contact</strong>
                                <small style="color:#666; font-size: 0.78em; line-height: 1.2; display: block; margin-top: 1px;">Validation Exception Notice</small>
                            </div>
                        </div>
                        <span style="background:#ffeaa7; color:#e67e22; padding:3px 8px; border-radius:4px; font-family:monospace; font-size:0.85em; font-weight:bold; border: 1px solid rgba(230,126,34,0.3); white-space: nowrap; display: inline-block;">
                            ${this.escapeHtml(rsLabel)}
                        </span>
                    </div>

                    <div class="result-body" style="padding:16px;">
                        <div class="result-grid" style="display: grid; grid-template-columns: minmax(70px, auto) 1fr; row-gap: 6px; column-gap: 16px; font-size: 0.92em; line-height: 1.3;">
                            <span style="color:#555; font-weight:600;">Entity:</span>
                            <span style="color:#222;">${this.escapeHtml(entity.entityName || entity.name || '—')}</span>

                            <span style="color:#555; font-weight:600;">Contact:</span>
                            <span style="color:#222;">${this.escapeHtml(contactIdentity || '—')}</span>

                            <span style="color:#555; font-weight:600;">Location:</span>
                            <span style="color:#222;">${this.escapeHtml(location.locationName || addressLine || '—')}</span>

                            <span style="color:#555; font-weight:600;">Phone:</span>
                            <span style="color:#222;">${this.escapeHtml(contact.contactPrimaryPhone || contact.primaryPhone || '—')}</span>

                            <span style="color:#555; font-weight:600;">Email:</span>
                            <span style="color:#222;">${this.escapeHtml(contact.contactEmail || contact.email || '—')}</span>
                        </div>
                    </div>

                    <div style="padding:12px 16px; background:#fffdf6; border-top:1px dashed #ffeaa7; font-size:0.9em; line-height: 1.35; color:#d35400;">
                        <strong style="font-size: 0.95em; display: block; margin-bottom: 2px;">Proposal Summary</strong>
                        ${this.escapeHtml(errorReason)}
                    </div>

                    <div class="result-actions" style="padding:12px 16px; border-top: 1px solid #eee; background: #fff; display:flex; gap:8px;">
                        <button class="btn btn-warning" style="flex:2; padding: 4px 10px; font-size: 0.85em; background: #ffc107; color: #212529; border: 1px solid #d39e00;" onclick="SkyIndex.revalidateProposal()">✏️ Edit & Resubmit</button>
                        <button class="btn btn-secondary" style="flex:1; padding: 4px 10px; font-size: 0.85em; background: #6c757d; color: #fff; border: 1px solid #545b62;" onclick="SkyIndex.revalidateProposal()">↻ Revalidate</button>
                        <button class="btn btn-danger" style="flex:1; padding: 4px 10px; font-size: 0.85em; background: #dc3545; color: #fff; border: 1px solid #bd2130;" onclick="SkyIndex.handleProposalAction('decline')">✕ Decline</button>
                    </div>
                </div>
            </div>
        `;

        this.appendSystemHtml(html);
    },
    // #endregion

    // #region 📇 View Contact Report — PDF Generation
    viewContactReport() {
        const prop = this.currentProposal || this.lastProposal || {};
        if (!prop?.data) {
            alert("No active proposal to view report.");
            return;
        }

        const d = prop.data || {};
        const loc = d.location || {};
        const cont = d.contact || {};
        const ent = d.entity || {};
        const res = prop.resolution || {};
        const pers = prop.persistence || {};

        // Optional loading feedback
        const link = document.querySelector('a[onclick*="viewContactReport"]');
        const originalText = link ? link.textContent : 'View Full Report (PDF)';
        if (link) link.textContent = 'Generating PDF...';

        // Build Contact Info
        const contactFirstLast = `${cont.contactFirstName || ''} ${cont.contactLastName || ''}`.trim();
        const contactName = contactFirstLast || 'Unknown Contact';
        const contactTitle = cont.contactTitle || '';
        const entityName = ent.entityName || 'Unknown Entity';

        // === Clean Report Title (for PDF header) ===
        const reportTitle = 'Proposed Contact Report';

        // === Professional Filename (for download) ===
        let reportFilename = `Proposed Contact Report: ${contactName}`;
        if (contactTitle) {
            reportFilename += `, ${contactTitle}`;
        }
        reportFilename += ` - ${entityName}`;

        // Jurisdiction normalization
        const rawJurisdiction = loc.locationJurisdiction || loc.parcelDetails?.[0]?.jurisdiction || "";

        const locationJurisdiction = !rawJurisdiction || 
            rawJurisdiction.toUpperCase() === "NO CITY/TOWN"
                ? "Maricopa County"
                : rawJurisdiction.toLowerCase().replace(/\b\w/g, char => char.toUpperCase());

        const payload = {
            reportType: "contact_proposal",
            reportTitle: reportTitle,                    // ← Clean title for PDF

            entityName: entityName,
            entityAction: pers.entity?.action || "",

            contactName: contactName,
            contactTitle: contactTitle,
            contactPhone: cont.contactPrimaryPhone || "",
            contactEmail: cont.contactEmail || "",
            contactAction: pers.contact?.action || "",

            locationAddress: loc.locationAddress || "",
            locationCityStateZip: `${loc.locationCity || ''}, ${loc.locationState || ''} ${loc.locationZip || ''}`.trim(),
            locationPlaceId: loc.locationPlaceId || "",
            locationCounty: loc.locationCounty || "",
            locationCountyFips: loc.locationCountyFips || "",
            locationJurisdiction: locationJurisdiction,

            // =====================================================
            // ADD THESE TWO LINES
            // =====================================================
            latitude: loc.latitude || loc.locationLatitude || null,
            longitude: loc.longitude || loc.locationLongitude || null,

            governanceNarrative: res.narratives?.decision?.[0] || "",
            confidence: prop.confidence || 85,
            pc_code: res.pc?.code || "",
            resolutionStatus: res.pc?.status || "",
            commitAllowed: pers.commitAllowed ? "YES" : "NO",

            parcelDetails: loc.parcelDetails || [],

            // Critical: Custom filename for download
            reportFilename: reportFilename
        };

        // === FORM POST ===
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/skyesoft/api/generateReports.php';
        form.target = '_blank';

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'payload';
        input.value = JSON.stringify(payload);

        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);

        // Restore link text
        if (link) {
            setTimeout(() => {
                link.textContent = originalText;
            }, 2000);
        }
    },
    // #endregion

    // #region 📇 Contact Intake UX — Clean Workflow States

    suppressRawContactEcho() {
        const output = this.getOutputHost();
        if (!output) return;
        const userLines = Array.from(output.querySelectorAll('.commandLine.user'));
        const lastUser = userLines[userLines.length - 1];
        if (!lastUser) return;
        const content = (lastUser.textContent || '').trim();
        // Use the exact same fast-path validation rules to ensure consistent UI tracking behavior
        if (typeof this.isObviousContactSignature === 'function' && this.isObviousContactSignature(content)) {
            lastUser.style.display = 'none';
            lastUser.dataset.suppressed = 'true';
            console.log('[UX] Suppressed raw signature');
        }
    },

    renderContactProcessingState() {
        const output = this.getOutputHost();
        if (!output) return;
        const processing = document.createElement('div');
        processing.className = 'commandLine system processing';
        processing.innerHTML = `
            <div style="display: flex; align-items: center; gap: 12px;">
                <span style="font-size: 1.5em; animation: spin 1.3s linear infinite;">⏳</span>
                <div>
                    <strong>📇 Processing contact signature...</strong><br>
                    <span style="font-size: 0.92em;">AI extraction • Address validation • Parcel lookup • PCM review</span>
                </div>
            </div>
        `;
        output.appendChild(processing);
        this.scrollOutputToBottom(output);
        this._currentContactProcessingEl = processing;
    },

    replaceProcessingWithProposal() {

        // --------------------------------------------------
        // 📇 Contact Proposal Processing
        // --------------------------------------------------
        if (this._currentContactProcessingEl) {
            this._currentContactProcessingEl.remove();
            this._currentContactProcessingEl = null;
        }

        // --------------------------------------------------
        // 📍 Location Proposal Processing
        // --------------------------------------------------
        if (this._currentLocationProcessingEl) {
            this._currentLocationProcessingEl.remove();
            this._currentLocationProcessingEl = null;
        }

        // --------------------------------------------------
        // 🧹 Safety Cleanup
        // --------------------------------------------------
        document
            .querySelectorAll('#streetViewProcessing')
            .forEach(el => el.remove());
    },

    // #endregion

    // #region 📇 Proposal Action Handler + Accept Flow
    handleProposalAction(action) {
        if (!this.currentProposal) {
            this.appendSystemLine('⚠️ No active proposal found.', 'warning');
            return;
        }

        switch (action) {
            case 'decline':
                this.appendSystemLine('❌ Contact proposal declined.', 'system');
                this.currentProposal = null;
                break;

            case 'edit':
                this.appendSystemLine('✏️ Edit mode coming soon...', 'system');
                // Future: this.renderEditableProposal();
                break;

            case 'accept':
                this.acceptProposedContact();
                break;

            default:
                console.warn('[Proposal] Unknown action:', action);
        }
    },

    async acceptProposedContact() {
        const prop = this.currentProposal;
        if (!prop?.parsed) {
            this.appendSystemLine('⚠️ No proposal data to save.', 'error');
            return;
        }

        this.appendSystemLine('💾 Saving contact...', 'system');

        try {
            // --------------------------------------------------
            // 🔹 Map proposal → DB schema
            // --------------------------------------------------
            const payload = this.mapProposalToDBSchema(prop.parsed);

            // --------------------------------------------------
            // 🚨 Required field validation (client-side guard)
            // --------------------------------------------------
            const missing = [];

            if (!payload.contactFirstName)  missing.push('First Name');
            if (!payload.contactLastName)   missing.push('Last Name');
            if (!payload.contactPrimaryPhone) missing.push('Phone');
            if (!payload.contactEmail)      missing.push('Email');

            if (missing.length) {
                this.appendSystemLine(
                    `⚠️ Missing required fields: ${missing.join(', ')}`,
                    'warning'
                );
                return;
            }

            // --------------------------------------------------
            // 📡 Send to backend
            // --------------------------------------------------
            const res = await fetch('/skyesoft/api/createContact.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    ...payload,
                    activitySessionId: this.getActivitySessionId()
                })
            });

            // --------------------------------------------------
            // 🔍 Safe JSON parsing (prevents "<" crash)
            // --------------------------------------------------
            let result;
            const text = await res.text();

            try {
                result = JSON.parse(text);
            } catch (e) {
                console.error('[Invalid JSON Response]', text);
                this.appendSystemLine('❌ Server returned invalid response.', 'error');
                return;
            }

            // --------------------------------------------------
            // ✅ Success handling
            // --------------------------------------------------
            if (result.success === true) {

                this.appendSystemLine('✔ Contact Created', 'success');

                this.renderContactResult(result);

                // Clear proposal state
                this.currentProposal = null;

                // Track last contact
                if (result.contact?.contactId) {
                    this.lastContactId = result.contact.contactId;
                }

                return;
            }

            // --------------------------------------------------
            // ⚠️ Known failure responses
            // --------------------------------------------------
            if (result.status === 'resolved_duplicate') {
                this.renderContactResult(result);
                this.currentProposal = null;
                return;
            }

            if (result.status === 'reject') {
                this.appendSystemLine(`⚠️ ${result.reason || 'Rejected'}`, 'warning');
                return;
            }

            // --------------------------------------------------
            // ❌ Generic failure
            // --------------------------------------------------
            this.appendSystemLine(
                `❌ ${result.message || 'Save failed'}`,
                'error'
            );

        } catch (err) {
            console.error('[Accept Contact Error]', err);

            this.appendSystemLine(
                '❌ Failed to save contact. Check connection or server.',
                'error'
            );
        }
    },

    mapProposalToDBSchema(parsed) {
        const c = parsed.contact || {};
        const e = parsed.entity || {};
        const l = parsed.location || {};

        return {
            contactFirstName:    c.firstName,
            contactLastName:     c.lastName,
            contactSalutation: c.salutation || null,
            contactSalutationInferred: c.salutationInferred ?? null,
            contactTitle:        c.title,
            contactPrimaryPhone: c.primaryPhone,
            contactEmail:        c.email,
            entityName:          e.name,
            locationAddress:     l.address,
            locationCity:        l.city,
            locationState:       (l.state || 'AZ').toUpperCase(),
            locationZip:         l.zip,
        };
    },
    // #endregion

    // #region 📇 Contact Proposal Pipeline (Client)

    // ───────────────────────────────────────────────
    // INTENT DETECTION LAYER
    // ───────────────────────────────────────────────

    async isContactCreationIntent(text, normalized) {
        if (!text || typeof text !== 'string' || text.trim().length < 20) return false;

        const lower = text.toLowerCase().trim();
        const original = text.trim();

        // 1. Explicit intent words
        if (
            lower.startsWith('add ') ||
            lower.startsWith('create ') ||
            lower.includes('new contact') ||
            lower.includes('add contact') ||
            lower.includes('here is a contact') ||
            lower.includes('proposed contact') ||
            lower.includes('contact signature')
        ) {
            return true;
        }

        // 2. Strong signature signals (this should catch "John Smith" example)
        const hasStrongSignature = 
            // Common titles / roles
            /\b(Operations|Manager|Director|CEO|Owner|President|Account|Sales|Coordinator|Supervisor|Engineer|Consultant|VP)\b/i.test(original) ||
            // Phone
            /\b\d{3}[-.\s]?\d{3}[-.\s]?\d{4}\b/.test(original) ||
            // Email
            /@\S+\.\S{2,}/.test(original) ||
            // Company legal suffixes
            /\b(LLC|Inc|Corp|Corporation|Group|Company|LLP)\b/i.test(original) ||
            // Multi-line name pattern (very common in signatures)
            (original.split(/\r?\n/).length >= 3 && 
            /[A-Z][a-z]+ [A-Z][a-z]+/.test(original));

        if (hasStrongSignature) {
            console.log('[Contact Intent] Strong signature detected');
            return true;
        }

        // 3. Fallback to your quick hint
        if (this.isObviousContactSignature(text)) {
            return true;
        }

        return false;
    },

    async isLocationWorkflowIntent(text, normalized) {
        if (!text || typeof text !== 'string' || text.length < 8) return false;

        const lower = text.toLowerCase().trim();

        if (
            lower.includes('location only') ||
            lower.includes('add location only') ||
            lower.includes('create location only') ||
            lower.includes('new location only')
        ) {
            return { mode: 'location_only', confidence: 'high' };
        }

        const hasLocationName = /\b(The |A |At |[A-Z][a-z]+ [A-Z][a-z]+)\b/.test(text) &&
            (/\d{1,5}\s+[A-Za-z]/.test(text) || /Phoenix|Glendale|Chandler|Scottsdale|Tempe|Buckeye|Mesa|Gilbert|Queen Creek|AZ\b/i.test(text));

        if (hasLocationName) {
            return { mode: 'location_only', confidence: 'medium' };
        }

        return false;
    },

    async isStreetViewIntent(text) {
        if (!text || typeof text !== 'string') return null;
        
        const clean = text.trim();
        const lower = clean.toLowerCase();

        // Detect Street View intent
        if (lower.includes('streetview') || lower.includes('street view')) {
            
            // Clean the address by removing common prefixes and junk
            let addressPart = clean
                .replace(/^(street\s*view|streetview)[\s\-\:]+/i, '')   // Remove leading "street view" + separators
                .replace(/[\s\-\:]+$/, '')                               // Remove trailing separators
                .trim();

            if (!addressPart) return null;

            return {
                workflow: 'street_view',
                address: addressPart,           // clean address for echo + workflow
                confidence: 0.95
            };
        }

        return null;
    },

    async isPropertyWorkflowIntent(text, normalized) {
        if (!text || typeof text !== 'string' || text.length < 8) return false;

        const lower = text.toLowerCase().trim();

        if (lower.includes('property review') || lower.includes('review property') ||
            lower.includes('parcel review') || lower.includes('review parcel') ||
            lower.includes('zoning at') || lower.includes('sign code for') || 
            lower.includes('ordinance for') || lower.includes('parcel for')) {
            return { object: "property", workflow: "property_review", confidence: "high" };
        }

        const hasAddressPattern = /\b\d{1,5}\s+[A-Za-z0-9#.,\s-]+(?:Ave|St|Rd|Blvd|Ln|Dr|Way|Central)\b/i.test(text);
        const hasCityState = /Phoenix|Glendale|Chandler|Scottsdale|Tempe|Buckeye|Green Valley|AZ\b/i.test(text);

        if (hasAddressPattern && hasCityState && !this.isObviousContactSignature(text)) {
            return { object: "property", workflow: "property_review", confidence: "high" };
        }

        return false;
    },

    // ───────────────────────────────────────────────
    // PROCESSING UI STATES
    // ───────────────────────────────────────────────

    renderPropertyProcessingState() {
        const output = this.getOutputHost();
        if (!output) return;

        const processing = document.createElement('div');
        processing.className = 'commandLine system processing';
        processing.innerHTML = `
            <div style="display: flex; align-items: center; gap: 12px;">
                <span style="font-size: 1.5em; animation: spin 1.3s linear infinite;">🏠</span>
                <div>
                    <strong>Resolving Property...</strong><br>
                    <span style="font-size: 0.92em;">Address validation • Parcel resolution • Jurisdiction lookup • Preparing property review</span>
                </div>
            </div>
        `;

        output.appendChild(processing);
        this.scrollOutputToBottom(output);
        this._currentPropertyProcessingEl = processing;
    },

    renderLocationProcessingState() {
        // Always clean previous instances first
        document.querySelectorAll('#streetViewProcessing, .processing, .commandLine.processing').forEach(el => el.remove());

        const output = this.getOutputHost();
        if (!output) return;

        const processing = document.createElement('div');
        processing.className = 'commandLine system processing';
        processing.id = 'locationProcessing';
        processing.innerHTML = `
            <div style="display: flex; align-items: center; gap: 12px;">
                <span style="font-size: 1.5em; animation: spin 1.3s linear infinite;">📍</span>
                <div>
                    <strong>Processing Location...</strong><br>
                    <span style="font-size: 0.92em;">Entity + Address review</span>
                </div>
            </div>
        `;

        output.appendChild(processing);
        this.scrollOutputToBottom(output);
        this._currentLocationProcessingEl = processing;
    },

    // ───────────────────────────────────────────────
    // WORKFLOW EXECUTION
    // ───────────────────────────────────────────────

    async executePropertyWorkflow(text, activitySessionId) {
        this.cleanupPropertyProcessing();

        this.setThinking(true);

        try {
            // Note: renderPropertyProcessingState() is called by the intent router
            // before entering this method. We only clean up here.

            const res = await fetch('/skyesoft/api/askOpenAI.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    userQuery: text,
                    intent: "property_review",
                    activitySessionId: activitySessionId
                })
            });

            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            const data = await res.json();
            //console.log('[Property Response]', data);

            this.dispatchPropertyWorkflowResponse(data);

        } catch (err) {
            console.error('[Property Workflow Error]', err);
            this.appendSystemLine('❌ Property review request failed.', 'error');
        } finally {
            this.setThinking(false);
        }
    },

    // ───────────────────────────────────────────────
    // WORKFLOW DISPATCHER (Property-specific)
    // ───────────────────────────────────────────────

    /**
     * Supported workflowState values:
     * - property_valid
     * - invalid_address
     * 
     * Future states:
     * - multiple_parcels
     * - streetview_required
     * - report_ready
     */
    dispatchPropertyWorkflowResponse(data) {
        if (!data || typeof data !== 'object') {
            this.appendSystemLine('❌ Invalid response from server.', 'error');
            this.cleanupPropertyProcessing();
            return;
        }

        const workflowState = data.workflowState;

        if (!workflowState) {
            this.appendSystemLine('❌ Missing workflowState in server response.', 'error');
            this.cleanupPropertyProcessing();
            return;
        }

        switch (workflowState) {
            case 'property_valid':
                this.renderPropertyReviewResult(data);
                break;

            case 'invalid_address':
                this.renderInvalidPropertyResult(data);
                break;

            default:
                this.appendSystemLine(`❌ Unknown workflow state: ${workflowState}`, 'error');
                this.cleanupPropertyProcessing();
                break;
        }
    },

    // ───────────────────────────────────────────────
    // RENDERERS
    // ───────────────────────────────────────────────

    renderPropertyReviewResult(data) {
        this.cleanupPropertyProcessing();

        let summaryHtml = '';
        if (data.summary) {
            // First escape, then convert <br> variants to real HTML breaks
            summaryHtml = this.escapeHtml(data.summary)
                .replace(/&lt;br\s*\/?&gt;/gi, '<br>')
                .replace(/&lt;br\/&gt;/gi, '<br>');
        }

        const html = `
            <div class="commandLine system html">
                <div class="result-card">
                    <div class="result-header">
                        <span class="result-icon">✅</span>
                        <strong class="result-title">Property Review Complete</strong>
                    </div>

                    <div class="result-body">
                        ${summaryHtml ? `<p>${summaryHtml}</p>` : ''}

                        <div class="result-grid">
                            <div><strong>Address</strong></div>
                            <div>${this.escapeHtml(data.inputAddress || '—')}</div>

                            ${data.parcel?.primaryParcel?.parcelNumber ? `
                            <div><strong>Parcel</strong></div>
                            <div>${this.escapeHtml(data.parcel.primaryParcel.parcelNumber)}</div>` : ''}

                            <div><strong>Jurisdiction</strong></div>
                            <div>${this.escapeHtml(data.jurisdiction?.governingJurisdiction || '—')}</div>

                            <div><strong>Governance</strong></div>
                            <div>${this.escapeHtml(data.governance?.rsCode || 'RS-0')} — ${this.escapeHtml(data.governance?.parcelStatus || 'Single Parcel Found')}</div>
                        </div>
                    </div>

                    ${data.actionId ? `
                    <div class="result-actions">
                        <a href="/skyesoft/api/generateReports.php?reportType=property&actionId=${data.actionId}" 
                           target="_blank" class="btn btn-primary">
                            📄 Generate Full PDF Report
                        </a>
                    </div>` : ''}
                </div>
            </div>
        `;

        this.appendSystemHtml(html);
    },

    renderInvalidPropertyResult(data) {
        this.cleanupPropertyProcessing();

        const rsCode = data.governance?.rsCode || 'RS-8';
        const status = data.governance?.parcelStatus || 'Invalid Address';

        const html = `
            <div class="commandLine system html">
                <div class="result-card">
                    <div class="result-header">
                        <span class="result-icon">❌</span>
                        <strong class="result-title">Property Review</strong>
                    </div>

                    <div class="result-body">
                        <p><strong>${this.escapeHtml(data.summary || 'The supplied address could not be validated.')}</strong></p>
                        
                        <div class="result-grid">
                            <div><strong>Address</strong></div>
                            <div>${this.escapeHtml(data.inputAddress || '—')}</div>

                            <div><strong>Governance</strong></div>
                            <div>${rsCode} — ${status}</div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        this.appendSystemHtml(html);
    },

    // ───────────────────────────────────────────────
    // WORKFLOW UTILITIES
    // ───────────────────────────────────────────────

    cleanupPropertyProcessing() {
        if (this._currentPropertyProcessingEl) {
            this._currentPropertyProcessingEl.remove();
            this._currentPropertyProcessingEl = null;
        }
    },

    suppressRawIntentEcho() {
        const output = this.getOutputHost();
        if (!output) return;

        const userLines = Array.from(output.querySelectorAll('.commandLine.user'));
        const lastUser = userLines[userLines.length - 1];

        if (!lastUser) return;

        const content = (lastUser.textContent || '').trim();

        if (content.length > 50 && 
            (/\d{3}[-.\s]?\d{3}/.test(content) || 
             /@\S+\.\S+/.test(content) || 
             /\b\d{1,5}\s+[A-Za-z]/.test(content))) {
            
            lastUser.style.display = 'none';
            lastUser.dataset.suppressed = 'true';
            console.log('[UX] Suppressed raw workflow input echo');
        }
    },

    // --------------------------------------------------
    // Signature Detection
    // --------------------------------------------------
    isObviousContactSignature(query) {
        const text = (query || '').trim();
        if (!text) {
            return false;
        }
        // Check for standard digital communication patterns
        const hasEmail = /@\S+\.\S{2,}/.test(text);
        const hasPhone = /\b\d{3}[-.\s]?\d{3}[-.\s]?\d{4}\b/.test(text);
        // Fallback block detection: checks lines and structure if common delimiters are present
        const lineCount = text.split('\n').filter(l => l.trim().length > 0).length;
        const hasCommaSeparator = text.includes(',') && lineCount >= 3;
        // Fast-path evaluation route
        if ((hasEmail && hasPhone) || (hasEmail && lineCount >= 3) || (hasPhone && hasCommaSeparator)) {
            return true;
        }
        return false;
    },

    // Global query router tracking application intent workflow execution
    handleInputRouting(query) {
        error_log("[ROUTER] Processing input dispatch pipeline");
        // Intercept and bypass AI routing tokens if an obvious contact structure is detected
        if (this.isObviousContactSignature(query)) {
            error_log("[ROUTER] Fast-path routing triggered: contact_proposal engine");
            return this.executeWorkflow("contact_proposal", { query: query });
        }
        // Fall back to semantic classification when payload structure is ambiguous
        error_log("[ROUTER] Ambiguous payload structural signature: dispatching to Semantic Responder");
        return this.callSemanticResponder(query).then(response => {
            const intent = response?.intent || "general_chat";
            error_log("[ROUTER] Semantic Responder determined classification intent: " + intent);
            return this.executeWorkflow(intent, response?.payload || { query: query });
        });
    },

    // Contact Proposal Handler
    handleContactProposal(data) {
        this.replaceProcessingWithProposal();

        const output = this.getOutputHost();
        if (output) {
            output.querySelectorAll('.commandLine.user[data-suppressed="true"]')
                .forEach(el => el.remove());
        }

        this.currentProposal = data;

        if (data.status === 'error') {
            this.appendSystemLine(`❌ ${data.message || 'Processing error'}`, 'error');
            return;
        }

        if (data.status === 'incomplete') {
            this.renderIncompleteProposal(data);
            return;
        }

        if (data.status === 'reject') {
            this.appendSystemLine(`⚠️ ${data.message || 'Not recognized as proposal data.'}`, 'warning');
            return;
        }

        if (
            data.status === 'proposed' ||
            data.status === 'partial' ||
            data.ui?.proposalStatus === 'proposed'
        ) {
            // Unified renderer (Contact + Location)
            this.renderProposedContact(data);
            return;
        }

        this.appendSystemLine(
            data.message || 'Could not process proposal information.',
            'warning'
        );
    },

    retryProposal() {
        this.clearOutput();
        this.appendSystemLine('🔄 Ready for new input.', 'system');
    },

    async revalidateProposal() {
        const contactIdentity = document.getElementById('contactIdentity')?.value || '';
        const entityName = document.getElementById('entityName')?.value || '';
        const street = document.getElementById('locationAddress')?.value || '';
        const city = document.getElementById('locationCity')?.value || '';
        const state = document.getElementById('locationState')?.value || '';
        const zip = document.getElementById('locationZip')?.value || '';
        const phone = document.getElementById('primaryPhone')?.value || '';
        const email = document.getElementById('email')?.value || '';

        const cityStateZip = [city, state, zip]
            .filter(Boolean)
            .join(', ');

        const rawLines = [
            contactIdentity,
            entityName,
            street,
            cityStateZip,
            phone,
            email
        ];

        const payload = {
            input: rawLines.filter(Boolean).join('\n'),
            activitySessionId: this.getActivitySessionId(),
            mode: 'propose'
        };

        this.renderContactProcessingState();

        const res = await fetch('/skyesoft/api/processProposedContact.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify(payload)
        });

        const result = await res.json();
        this.handleContactProposal(result);
    },
    // #endregion

    // #region 📇 Contact Result Renderer
    renderContactResult(data) {

        //console.log('[CONTACT RESULT]', data);

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
            const activitySessionId = incomingActivitySessionId || this.getActivitySessionId();

            console.log('🤖 AI using activitySessionId:', activitySessionId);

            let location = this.lastLocation || { latitude: null, longitude: null };

            if (!location || location.latitude === null || location.longitude === null) {
                location = await Promise.race([
                    this.getLocationSafe(),
                    new Promise(resolve => setTimeout(() => resolve({ latitude: null, longitude: null }), 1500))
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

            // --------------------------------------------------
            // 📇 CONTACT PROPOSAL HANDLING
            // --------------------------------------------------
            if (data?.status === 'incomplete') {
                console.log('📇 Incomplete proposal received');
                this.handleContactProposal(data);   // ← This will call renderIncompleteProposal
                return;
            }

            if (data?.status === 'proposed' || data?.status === 'partial') {
                console.log('📇 Complete proposal received');
                this.handleContactProposal(data);
                return;
            }

            // --------------------------------------------------
            // 📇 Bridge fallback
            // --------------------------------------------------
            if (data?.type === 'contact_proposal' && data?.input) {
                console.log('📇 Bridge detected → calling proposal engine');

                const res2 = await fetch('/skyesoft/api/processProposedContact.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify({
                        input: data.input,
                        activitySessionId: data.activitySessionId || activitySessionId,
                        mode: 'propose'
                    })
                });

                const proposal = await res2.json();
                this.handleContactProposal(proposal);
                return;
            }

            // ───────────────────────────────────────────────
            // UI Action / Domain Intent / Normal Response
            // ───────────────────────────────────────────────
            if (data?.type === 'ui_action') {
                // ... existing UI action logic
                return;
            }

            if (data?.type === 'domain_intent') {
                // ... existing domain intent logic
                return;
            }

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
            //console.log('[AUTH] Response:', data);

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

// #region 🗺️ Google Maps Dual View Initializer (Map + Street View)
let map, panorama;

function initializeDualView(lat, lng) {
    const mapDiv = document.getElementById('mapContainer');
    const panoDiv = document.getElementById('panoContainer');

    if (!mapDiv || !panoDiv) {
        console.error('[DualView] Container elements not found');
        return;
    }

    // ─────────────────────────────────────────
    // Satellite Map (Left Pane)
    // ─────────────────────────────────────────
    map = new google.maps.Map(mapDiv, {
        center: { lat: lat, lng: lng },
        zoom: 19,
        mapTypeId: 'satellite'
    });

    // ─────────────────────────────────────────
    // Street View Panorama (Right Pane)
    // ─────────────────────────────────────────
    panorama = new google.maps.StreetViewPanorama(panoDiv, {
        position: { lat: lat, lng: lng },
        pov: { heading: 105, pitch: 8 },
        visible: true
    });

    // ─────────────────────────────────────────
    // Click on Map → Update Street View
    // ─────────────────────────────────────────
    map.addListener('click', (e) => {
        if (panorama) {
            panorama.setPosition(e.latLng);
        }
    });
}
// #endregion

// #region 🧾 Page Registration
window.SkyeApp.registerPage('index', window.SkyIndex);
// #endregion