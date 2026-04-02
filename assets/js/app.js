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

/* #region GLOBAL SSE HANDLER - FIXED FOR IDLE COUNTDOWN */
window.SkyeApp.handleSSE = function (payload) {

    if (!payload || typeof payload !== 'object') return;

    const page = this.pageHandlers?.[this.currentPage];
    if (!page) return;

    // ─────────────────────────────────────────
    // 🔥 FORCE LOGOUT (Idle Timeout from Server)
    // ─────────────────────────────────────────
    if (payload?.forceLogout === true) {

        console.log('[SSE] forceLogout received → UI-only logout');

        // 🔥 ALWAYS enforce logout state (no conditions)
        page.authState = false;
        page.authUser  = null;
        page.authRole  = null;
        page.idleState = null;

        // 🔥 CRITICAL — kill command UI
        page.commandSurfaceActive = false;

        document.body.removeAttribute('data-auth');

        // 🔥 ALWAYS force UI transition
        page.renderLoginCard?.();
        page.renderFooterStatus?.call(page);

        // 🔒 mark handled AFTER UI reset
        page._logoutHandled = true;

        return;
    }

    const newAuth = payload?.auth?.authenticated === true;

    // Always commit the latest authoritative snapshot
    this.lastSSE = payload;

    // ─────────────────────────────────────────
    // 🔄 ALWAYS UPDATE IDLE STATE (CRITICAL FIX)
    // ─────────────────────────────────────────
    if (page && payload?.idle) {
        page.idleState = payload.idle;

        // 🔄 FORCE UI UPDATE (THIS IS THE FIX)
        page.renderFooterStatus?.call(page);
    }

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
            page.renderFooterStatus?.call(page);
        }

        this.updateHSB?.(payload);
        this.routeSSEToPage?.(payload);
        page?.renderFooterStatus?.call(page);
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
                page.transitionToCommandInterface?.();
            } else {
                page.authState = false;
                page.authUser = null;
                page.authRole = null;

                document.body.removeAttribute('data-auth');

                // 🔒 UI only (no logic trigger)
                page.renderLoginCard?.();
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