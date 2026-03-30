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
*/

/* #region PAGE STATE */
window.SkyeApp = {
    currentPage: null,
    pageHandlers: {},
    lastSSE: null
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

/* #region GLOBAL SSE HANDLER */
window.SkyeApp.handleSSE = function (payload) {

    // Route to page-specific handler first for non-auth projections
    const page = this.pageHandlers?.[this.currentPage];

    // 🔥 FORCE LOGOUT MECHANISM (IDLE — SERVER ALREADY LOGGED)
    if (payload?.forceLogout === true) {

        if (page?._logoutHandled === true) return;
        page._logoutHandled = true;

        console.log('[SSE] forceLogout received → UI-only logout (no API call)');

        // Stop stale authenticated stream first
        window.SkySSE?.stop?.();

        // 🔥 Mark logout source (for safety / future use)
        page._logoutSource = payload?.logoutSource ?? 'idle_timeout';

        // Force local logout state immediately
        page.authState = false;
        page.authUser  = null;
        page.authRole  = null;
        page.idleState = null;
        page._lastRenderedAuth = false;

        // Reset DOM
        document.body.removeAttribute('data-auth');

        // Render login UI
        page.renderLoginCard?.();
        page.renderFooterStatus?.call(page);

        // 🔥 Clear stale SSE snapshot (prevents re-auth flicker)
        window.SkyeApp.lastSSE = null;

        // 🔒 Prevent multiple restart attempts
        if (window.SkySSE?.isRestarting) return;
        window.SkySSE.isRestarting = true;

        // 🔁 Restart SSE safely (delayed)
        setTimeout(() => {
            window.SkySSE?.start?.();
            window.SkySSE.isRestarting = false;
        }, 100);

        // Return
        return;
    }

    const newAuth = payload?.auth?.authenticated === true;

    const hasPrev = this.lastSSE !== null;
    const prevAuth = hasPrev
        ? this.lastSSE?.auth?.authenticated === true
        : null;

    // Commit authoritative SSE snapshot first
    this.lastSSE = payload;

    // Keep non-auth projections only
    if (page && payload?.idle) {
        page.idleState = payload.idle;
    }

    // First SSE frame: initialize UI carefully
    if (!hasPrev) {

        if (page) {

            page.authUser = newAuth ? payload?.auth?.username ?? null : null;
            page.authRole = newAuth ? payload?.auth?.role ?? null : null;

            document.body.toggleAttribute('data-auth', newAuth);

            // Only set authState from SSE on first frame if client has not already authenticated
            if (page.authState !== true) {
                page.authState = newAuth;
            }

            if (newAuth) {
                page.transitionToCommandInterface?.();
            } else if (page.authState !== true) {
                console.log('[SSE INIT] forcing logout UI');
                page.renderLoginCard?.();
            }

            page._lastRenderedAuth = page.getAuthState?.() === true;
            page.renderFooterStatus?.call(page);
        }

        try {
            this.updateHSB(payload);
        } catch (err) {
            console.error("❌ updateHSB failed:", err);
        }

        try {
            this.routeSSEToPage(payload);
        } catch (err) {
            console.error("❌ routeSSEToPage failed:", err);
        }

        page?.renderFooterStatus?.call(page);
        return;
    }
    // Subsequent SSE frames: sync authoritative auth state, but only if it changes (server wins)
    if (page) {

        // ─────────────────────────────────────────
        // 🔐 AUTHORITATIVE AUTH SYNC (SERVER WINS)
        // ─────────────────────────────────────────
        page.authState = newAuth;
        page.authUser  = newAuth ? payload?.auth?.username ?? null : null;
        page.authRole  = newAuth ? payload?.auth?.role ?? null : null;

        document.body.toggleAttribute('data-auth', newAuth);

        const resolvedAuth = page.getAuthState?.() === true;
        const prevRenderedAuth = page._lastRenderedAuth;

        if (prevRenderedAuth !== resolvedAuth) {

            console.log('[UI STATE CHANGE]', {
                from: prevRenderedAuth,
                to: resolvedAuth
            });

            if (resolvedAuth) {

                if (page._logoutHandled === true) {
                    console.log('[AUTH] resetting logout guard');
                }

                page._logoutHandled = false; // 🔥 RESET HERE

                page.transitionToCommandInterface?.();
            } else {
                page.authState = false;
                page.authUser = null;
                page.authRole = null;
                document.body.removeAttribute('data-auth');
                page.renderLoginCard?.();
            }

            page._lastRenderedAuth = resolvedAuth;
        }
    }
    // Clear idle state on any auth change
    if (page && !newAuth) {
        page.idleState = null;
    }

    try {
        this.updateHSB(payload);
    } catch (err) {
        console.error("❌ updateHSB failed:", err);
    }

    try {
        this.routeSSEToPage(payload);
    } catch (err) {
        console.error("❌ routeSSEToPage failed:", err);
    }

    page?.renderFooterStatus?.call(page);
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