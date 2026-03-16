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

    const page = this.pageHandlers?.[this.currentPage];

    const newAuth = payload?.auth?.authenticated === true;
    const prevAuth = this.lastSSE?.auth?.authenticated === true;

    // ───────────────────────────────────────────────
    // First SSE Frame → Initialize Authoritative State
    // ───────────────────────────────────────────────
    if (this.lastSSE === null) {

        this.lastSSE = payload;

        if (page) {

            // Auth projection
            page.authState = newAuth;
            page.authUser  = newAuth ? payload?.auth?.username ?? null : null;
            page.authRole  = newAuth ? payload?.auth?.role ?? null : null;

            // Idle projection
            if (payload?.idle) {
                page.idleState = payload.idle;
            }

            document.body.toggleAttribute('data-auth', newAuth);

            if (newAuth) {
                page.transitionToCommandInterface?.();
            } else {
                page.renderLoginCard?.();
            }

            page.renderFooterStatus?.call(page);
        }

        return;
    }

    // ───────────────────────────────────────────────
    // State Projection (Auth + Idle)
    // ───────────────────────────────────────────────
    if (page) {

        page.authState = newAuth;
        page.authUser  = newAuth ? payload?.auth?.username ?? null : null;
        page.authRole  = newAuth ? payload?.auth?.role ?? null : null;

        if (payload?.idle) {
            page.idleState = payload.idle;
        }
    }

    // ───────────────────────────────────────────────
    // Login Transition
    // ───────────────────────────────────────────────
    if (!prevAuth && newAuth) {

        console.log('[LOGIN TRANSITION DETECTED]', {
            prevAuth,
            newAuth,
            page: !!page,
            currentPage: this.currentPage
        });

        document.body.setAttribute('data-auth', 'true');

        if (page) {

            console.log('[TRANSITIONING TO COMMAND INTERFACE]');

            page.transitionToCommandInterface?.();

            requestAnimationFrame(() => {

                console.log('[FOOTER REFRESH AFTER LOGIN]', {
                    authState: page.authState
                });

                page.renderFooterStatus?.call(page);
            });
        }
    }

    // ───────────────────────────────────────────────
    // Logout Transition
    // ───────────────────────────────────────────────
    if (prevAuth && !newAuth) {

        document.body.removeAttribute('data-auth');

        if (page) {
            page.renderLoginCard?.();
            page.renderFooterStatus?.call(page);
        }
    }

    // ───────────────────────────────────────────────
    // Commit authoritative snapshot
    // ───────────────────────────────────────────────
    this.lastSSE = payload;

    // ───────────────────────────────────────────────
    // Update Header Status Block
    // ───────────────────────────────────────────────
    try {
        this.updateHSB(payload);
    } catch (err) {
        console.error("❌ updateHSB failed:", err);
    }

    // ───────────────────────────────────────────────
    // Route SSE to page modules
    // ───────────────────────────────────────────────
    try {
        this.routeSSEToPage(payload);
    } catch (err) {
        console.error("❌ routeSSEToPage failed:", err);
    }

    // ───────────────────────────────────────────────
    // Final footer refresh
    // ───────────────────────────────────────────────
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

    if (window.SkySSE?.start) {
        window.SkySSE.start();
    } else {
        console.error("❌ SSE engine missing: SkySSE.start not found");
    }
});
/* #endregion */