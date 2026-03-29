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

    console.log('[SSE PAYLOAD AUTH]', payload?.auth);

    const page = this.pageHandlers?.[this.currentPage];
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

    // ─────────────────────────────────────────
    // 🔐 AUTHORITATIVE LOGOUT (SERVER OVERRIDE)
    // ─────────────────────────────────────────
    if (payload?.auth?.authenticated === false && hasPrev && prevAuth === true) {

        if (page && page.authState === true) {

            // 🔥 GUARD FIRST
            if (page._logoutHandled === true) return;
            page._logoutHandled = true;

            console.log('[AUTH] idle logout → UI reset');

            // Reset auth state
            page.authState = false;
            page.authUser  = null;
            page.authRole  = null;

            // Reset DOM
            document.body.removeAttribute('data-auth');

            // 🔥 STOP SSE (important)
            //window.SkySSE?.stop?.();

            // Render UI
            page.renderLoginCard?.();

            page._lastRenderedAuth = false;

            page.renderFooterStatus?.call(page);
        }

        return;
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

    if (page) {

        const clientAuth = page.authState === true;

        // Promote only; do not downgrade active client auth
        if (newAuth && !clientAuth) {
            page.authState = true;
            page.authUser  = payload?.auth?.username ?? null;
            page.authRole  = payload?.auth?.role ?? null;
            document.body.setAttribute('data-auth', 'true');
        }

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