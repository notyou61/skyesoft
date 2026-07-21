/* Skyesoft — app.js
   Tier-4 Global Front-End Controller
   Responsibilities:
   • Page detection
   • Page registration
   • Page initialization
   • SSE routing
   • lastSSE storage
   • Header updates (HSB-X)
   • Version footer updates
   • Auth transition helpers (handleLogin / handleLogout)
   • Idle countdown (self-contained + page-handler friendly)
*/

/* #region PAGE STATE */
window.SkyeApp = {
    currentPage: null,
    pageHandlers: {},
    lastSSE: null,
    hasReceivedFirstSSE: false
};
/* #endregion */

/* #region PAGE DETECTION */
window.SkyeApp.detectPage = function () {
    const page = document.body.getAttribute("data-page");
    if (!page) {
        console.warn("⚠ No data-page attribute found; cannot route.");
        return;
    }
    this.currentPage = page;
};
/* #endregion */

/* #region INTENT RECOGNITION (STEP 0) */
window.SkyeApp.handleUserInput = async function (inputText) {

    // 🔒 Basic guard
    if (!inputText || typeof inputText !== "string") return;

    try {

        // 🔄 Immediate UX feedback
        this.showSystemMessage?.("Analyzing input...");

        // 🔗 Backend endpoint (GoDaddy / PHP)
        const res = await fetch("/skyesoft/api/askOpenAI.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                mode: "intent",
                prompt: inputText
            })
        });

        // 🔒 Safe response handling
        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }

        const intentResult = await res.json();

        console.log("[Intent]", intentResult);

        const intent = intentResult?.intent;
        const confidence = intentResult?.confidence ?? 0;

        // ─────────────────────────────────────────
        // CONTACT PROPOSE DETECTED
        // ─────────────────────────────────────────
        if (intent === "contact_propose") {

            // 🟢 HIGH CONFIDENCE → AUTO PROCEED
            if (confidence >= 0.90) {
                this.showSystemMessage?.("✅ Contact Signature Recognized");
                this.proposeContact(inputText);
                return;
            }

            // 🟡 MEDIUM CONFIDENCE → CONFIRM
            if (confidence >= 0.70) {
                this.showConfirmation?.(
                    "Contact information detected. Create new contact?",
                    () => this.proposeContact(inputText),
                    () => console.log("[Intent] user declined contact creation")
                );
                return;
            }

            // 🔴 LOW CONFIDENCE → FALLBACK
            console.log("[Intent] low confidence → fallback to chat");
        }

        // ─────────────────────────────────────────
        // FALLBACK → NORMAL CHAT FLOW
        // ─────────────────────────────────────────
        this.handleStandardChat?.(inputText);

    } catch (err) {

        console.error("[Intent Error]", err);

        // 🔴 HARD FAILSAFE → NEVER BLOCK USER
        this.showSystemMessage?.("⚠ Unable to analyze input — continuing...");
        this.handleStandardChat?.(inputText);
    }
};
/* #endregion */

/* #region PAGE REGISTRATION */
window.SkyeApp.registerPage = function (pageName, handlerObj) {
    this.pageHandlers[pageName] = handlerObj;
};
/* #endregion */

/* #region PAGE INITIALIZATION */
window.SkyeApp.initPage = async function () {
    if (!this.currentPage) return;

    const handler = this.pageHandlers[this.currentPage];
    if (!handler) {
        console.warn("⚠ No handler for page:", this.currentPage);
        return;
    }

    if (typeof handler.init === "function") {
        await handler.init();
    }
};
/* #endregion */

/* #region SSE ROUTING */
window.SkyeApp.routeSSEToPage = function (payload) {

    const handler = this.pageHandlers[this.currentPage];
    if (!handler || typeof handler.onSSE !== "function") return;

    handler.onSSE(payload);

};
/* #endregion */

/* #region HEADER STATUS BLOCK (HSB-X) */
window.SkyeApp.updateHSB = function (payload) {

    /* WEATHER */
    if (payload.weather) {
        const w = payload.weather;
        const tempF = Math.round(w.temp ?? 0);
        const cond = w.condition
            ? w.condition.replace(/^\w/, c => c.toUpperCase())
            : "--";

        const el = document.getElementById("headerWeather");
        if (el) el.textContent = `${tempF}°F — ${cond}`;
    }

    /* TIME */
    if (payload.timeDateArray) {
        const el = document.getElementById("headerTime");
        if (el) el.textContent = payload.timeDateArray.currentLocalTime ?? "--:--:--";
    }

    /* INTERVAL */
    if (payload.currentInterval) {
        const iv = payload.currentInterval;
        const el = document.getElementById("headerInterval");
        if (!el) return;

        let sec = iv.secondsRemainingInterval ?? 0;

        const days = Math.floor(sec / 86400); sec %= 86400;
        const hrs  = Math.floor(sec / 3600);  sec %= 3600;
        const mins = Math.floor(sec / 60);
        const secs = sec % 60;

        const parts = [];
        if (days > 0) parts.push(`${days}d`);
        if (days > 0 || hrs > 0) parts.push(`${String(hrs).padStart(2, "0")}h`);
        if (days > 0 || hrs > 0 || mins > 0) parts.push(`${String(mins).padStart(2, "0")}m`);
        parts.push(`${String(secs).padStart(2, "0")}s`);

        const remainingStr = parts.join(" ");

        const label = iv.name ?? "Interval";

        el.textContent = `${label} ${remainingStr}`;
    }
};
/* #endregion */

/* #region IDLE COUNTDOWN (self-contained) */
/**
 * Renders idle countdown.
 * 1. Calls page.renderFooterStatus() if the page handler provides it.
 * 2. Always performs a direct DOM write to any known idle element so the
 *    countdown never disappears when the page handler is incomplete.
 *
 * Expected payload shape from sse.php:
 *   idle: { state: "warning"|"ok"|"critical", remainingSeconds: N, timeoutSeconds: N }
 */
window.SkyeApp.renderIdleCountdown = function (page) {

    // ---- 1. Let the page handler paint first (preserves any custom styling) ----
    if (page && typeof page.renderFooterStatus === 'function') {
        try {
            page.renderFooterStatus.call(page);
        } catch (err) {
            console.warn('[SkyeApp] page.renderFooterStatus error', err);
        }
    }

    // ---- 2. Direct DOM fallback (guarantees visible countdown) ----
    const idle = (page && page.idleState) || (this.lastSSE && this.lastSSE.idle) || null;

    // Try every ID that has ever been used in Skyesoft footers
    const ids = [
        'footerIdle',
        'idleCountdown',
        'footer-idle',
        'idleTimer',
        'footerStatusIdle',
        'hsbIdle',
        'footer_idle',
        'idle-remaining'
    ];

    let el = null;
    for (const id of ids) {
        el = document.getElementById(id);
        if (el) break;
    }
    if (!el) {
        el = document.querySelector('[data-idle-countdown], [data-role="idle-countdown"]');
    }

    if (!el) {
        // No element in the DOM yet – nothing more we can do from app.js
        return;
    }

    const isAuth = (page && page.authState === true) ||
                   document.body.hasAttribute('data-auth');

    if (isAuth && idle && Number.isFinite(Number(idle.remainingSeconds))) {
        const remaining = Math.max(0, Math.floor(Number(idle.remainingSeconds)));
        const m = Math.floor(remaining / 60);
        const s = remaining % 60;
        el.textContent = `Idle ${m}:${String(s).padStart(2, '0')}`;
        el.hidden = false;
        el.removeAttribute('hidden');
        el.style.display = '';
        // Optional visual state
        el.dataset.idleState = idle.state || '';
        el.classList.remove('idle-ok', 'idle-warning', 'idle-critical');
        if (idle.state) el.classList.add('idle-' + idle.state);
    } else {
        el.textContent = '';
        el.hidden = true;
        el.dataset.idleState = '';
        el.classList.remove('idle-ok', 'idle-warning', 'idle-critical');
    }
};
/* #endregion */

/* #region AUTH TRANSITION HELPERS */
/**
 * Called when SSE (or other code) detects a successful login.
 * Page handlers can override / extend via their own transitionToCommandInterface.
 */
window.SkyeApp.handleLogin = function () {
    console.log('[SkyeApp] handleLogin');

    const page = this.pageHandlers?.[this.currentPage];
    if (!page) return;

    page.authState = true;
    document.body.setAttribute('data-auth', 'true');

    // Prefer page-specific transition if available
    if (typeof page.transitionToCommandInterface === 'function') {
        page.transitionToCommandInterface();
    }

    // Refresh footer + idle
    this.renderIdleCountdown(page);
};

/**
 * Central logout entry point.
 * reason examples: 'idle_timeout', 'sse', 'user', 'force'
 * NOTE: Does NOT stop the SSE stream — Skyesoft uses a persistent-stream architecture.
 */
window.SkyeApp.handleLogout = function (reason = 'unknown') {
    console.log('[SkyeApp] handleLogout →', reason);

    const page = this.pageHandlers?.[this.currentPage];

    // Clear local authentication state
    if (page) {
        page.authState = false;
        page.authUser  = null;
        page.authRole  = null;
        page.idleState = null;
        page.commandSurfaceActive = false;
        page._logoutHandled = true;
    }

    document.body.removeAttribute('data-auth');

    // Mirror authentication state
    window.SkyState = window.SkyState || {};
    window.SkyState.authenticated = false;

    // Clear countdown immediately
    this.renderIdleCountdown(page);

    // Render logged-out interface
    if (page && typeof page.renderLoginCard === 'function') {
        page.renderLoginCard();
        page.renderFooterStatus?.call(page);
    } else {
        window.location.reload();
    }
};
/* #endregion */

/* #region GLOBAL SSE HANDLER - FIXED FOR IDLE COUNTDOWN */
window.SkyeApp.handleSSE = function (payload) {

    if (!payload || typeof payload !== 'object') return;

    const page = this.pageHandlers?.[this.currentPage];
    if (!page) return;

    // ─────────────────────────────────────────
    // 🔥 FORCE LOGOUT (Idle Timeout from Server)
    // ─────────────────────────────────────────
    if (payload?.forceLogout === true) {

        console.log('[SSE] forceLogout received → Applying logged-out state');

        // Delegate to the single authoritative logout path
        // SSE stream stays alive (persistent-stream architecture)
        this.handleLogout('idle_timeout');
        return;
    }

    const newAuth = payload?.auth?.authenticated === true;

    // Always commit the latest authoritative snapshot
    this.lastSSE = payload;

    // ─────────────────────────────────────────
    // 🔄 ALWAYS UPDATE IDLE STATE + RENDER COUNTDOWN
    // ─────────────────────────────────────────
    if (payload?.idle) {
        page.idleState = payload.idle;
    }

    // Render on every tick — this is what makes the countdown live
    this.renderIdleCountdown(page);

    // ─────────────────────────────────────────
    // FIRST SSE MESSAGE (Initialization)
    // ─────────────────────────────────────────
    const isFirstMessage = !this.hasReceivedFirstSSE;
    if (isFirstMessage) {
        this.hasReceivedFirstSSE = true;

        if (page) {
            page.authUser = newAuth ? payload?.auth?.username ?? null : null;
            page.authRole = newAuth ? payload?.auth?.role ?? null : null;

            document.body.toggleAttribute('data-auth', newAuth);

            if (page.authState !== true) {
                page.authState = newAuth;
            }

            // Only render logout UI if client is NOT already authenticated
            if (newAuth) {
                page.transitionToCommandInterface?.();
            } else if (page.authState !== true) {
                console.log('[SSE INIT] passive logout UI (safe)');
                page.renderLoginCard?.();
            }

            page._lastRenderedAuth = page.getAuthState?.() === true;
        }

        this.updateHSB?.(payload);
        this.routeSSEToPage?.(payload);
        this.renderIdleCountdown(page);
        return;
    }

    // ─────────────────────────────────────────
    // SUBSEQUENT MESSAGES – Authoritative Sync
    // ─────────────────────────────────────────
    if (page) {
        const prevAuth = page.authState;

        // Authoritative auth sync (server wins)
        page.authState = newAuth;
        page.authUser  = newAuth ? payload?.auth?.username ?? null : null;
        page.authRole  = newAuth ? payload?.auth?.role ?? null : null;

        document.body.toggleAttribute('data-auth', newAuth);

        // Only re-render on actual auth change
        if (prevAuth !== newAuth) {
            console.log('[SSE] Auth state changed:', { from: prevAuth, to: newAuth });

            if (newAuth) {
                // Login path
                this.handleLogin();
            } else {
                // Logout path (SSE detected session end)
                this.handleLogout('sse');
            }
        }
    }

    // Route to page-specific handlers and update other UI
    this.updateHSB?.(payload);
    this.routeSSEToPage?.(payload);
};
/* #endregion */

/* #region FOOTER BOOTSTRAP */
window.SkyeApp.initFooter = function () {

    /* Dynamic Year */
    const yearEl = document.getElementById("footerYear");
    if (yearEl) {
        yearEl.textContent = new Date().getFullYear();
    }

};
/* #endregion */

/* #region DOM READY BOOTSTRAP */
document.addEventListener("DOMContentLoaded", function () {

    window.SkyeApp.detectPage();
    window.SkyeApp.initPage();

    /* Footer (non-SSE, static bootstraps) */
    window.SkyeApp.initFooter();

});
/* #endregion */

// #region Propose Contact
window.SkyeApp.proposeContact = async function (rawInput) {
    try {
        const res = await fetch('/api/proposeContact.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ rawInput })
        });

        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }

        const data = await res.json();

        console.log('[Propose]', data);

        this.lastProposal = data;

        this.showSystemMessage?.("📥 Contact proposal submitted");

        this.parseContact?.({
            proposalId: data.proposalId,
            rawInput: rawInput
        });

    } catch (err) {
        console.error('[Propose Error]', err);
        this.showSystemMessage?.("⚠ Failed to submit contact");
    }
};
// #endregion

// #region Parse Contact
window.SkyeApp.parseContact = async function (proposalData) {
    try {
        const res = await fetch('/api/parseContact.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                proposalId: proposalData.proposalId,
                rawInput: proposalData.rawInput
            })
        });

        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }

        const parsed = await res.json();

        console.log('[Parsed Contact]', parsed);

        this.showSystemMessage?.("🧠 Contact parsed successfully");

        this.lastParsedContact = parsed;

        // future → validation step
    } catch (err) {
        console.error('[Parse Error]', err);
        this.showSystemMessage?.("⚠ Failed to parse contact");
    }
};
// #endregion
