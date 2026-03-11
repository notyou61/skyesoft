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

    // Prevent page handler from overriding authenticated UI
    if (payload?.auth?.authenticated === true && handler.renderLoginCard) {
        if (document.body.getAttribute("data-auth") === "true") {
            return;
        }
    }

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

    const prevAuth = this.lastSSE?.auth?.authenticated ?? false;
    const newAuth  = payload?.auth?.authenticated ?? false;

    // Update authoritative SSE snapshot
    this.lastSSE = payload;

    // 🔐 Auth transition detection
    if (!prevAuth && newAuth) {

        console.log('[SkyIndex] Authenticated → Command Interface');

        document.body.setAttribute('data-auth', 'true');

        if (this.pageHandlers?.index) {

            const page = this.pageHandlers.index;

            page.authState = true;
            page.authUser  = payload?.auth?.username ?? null;
            page.authRole  = payload?.auth?.role ?? null;

            if (page.transitionToCommandInterface) {
                page.transitionToCommandInterface();
            }

            // Ensure footer updates AFTER card exists
            page.renderFooterStatus();
        }
    }

    if (prevAuth && !newAuth) {

        console.log('[SkyIndex] Auth lost → Login Interface');

        document.body.removeAttribute('data-auth');

        if (this.pageHandlers?.index) {

            const page = this.pageHandlers.index;

            page.authState = false;
            page.authUser  = null;
            page.authRole  = null;

            if (page.renderLoginCard) {
                page.renderLoginCard();
            }

            page.renderFooterStatus();
        }
    }

    try {
        this.updateHSB(payload);
    } catch (err) {
        console.error("❌ updateHSB failed:", err);
    }

    // 🚫 Prevent page handler from overriding authenticated UI
    if (!payload?.auth?.authenticated) {

        try {
            this.routeSSEToPage(payload);
        } catch (err) {
            console.error("❌ routeSSEToPage failed:", err);
        }

    }

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