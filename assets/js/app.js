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

    if (!payload?.auth) return;

    const prevAuth = this.lastSSE?.auth?.authenticated === true;
    const newAuth  = payload.auth.authenticated === true;

    // 🔐 AUTH STATE TRANSITION DETECTION
    const page = this.pageHandlers?.index;

    // 🔄 Synchronize page auth state from SSE projection
    if (page) {
        page.authState = newAuth;
        page.authUser  = newAuth ? payload?.auth?.username ?? null : null;
        page.authRole  = newAuth ? payload?.auth?.role ?? null : null;
    }

    // 🧠 Login transition detected
    if (!prevAuth && newAuth) {

        console.log('[SkyIndex] Authenticated → Command Interface');

        // 🔐 Reflect auth state in DOM
        document.body.setAttribute('data-auth', 'true');

        if (page) {

            // 🪟 Switch UI from login → command interface
            page.transitionToCommandInterface?.();

            // 🧾 Refresh footer status after UI transition
            requestAnimationFrame(() => {
                page.renderFooterStatus?.call(page);
            });
        }
    }

    // 🧠 Logout transition detected
    if (prevAuth && !newAuth) {

        console.log('[SkyIndex] Auth lost → Login Interface');

        // 🔐 Remove DOM auth marker
        document.body.removeAttribute('data-auth');

        if (page) {

            // 🪟 Switch UI from command interface → login card
            page.renderLoginCard?.();

            // 🧾 Update footer status
            page.renderFooterStatus?.call(page);
        }
    }

    // 🔄 Commit authoritative SSE snapshot
    this.lastSSE = payload;

    // 📊 Update Header Status Block (HSB)
    try {
        this.updateHSB(payload);
    } catch (err) {
        console.error("❌ updateHSB failed:", err);
    }

    // 📡 Route SSE updates only when authenticated
    if (newAuth) {
        try {
            this.routeSSEToPage(payload);
        } catch (err) {
            console.error("❌ routeSSEToPage failed:", err);
        }
    }

    // 🧾 Refresh footer status (single authority)
    const pageHandler = this.pageHandlers?.index;
    pageHandler?.renderFooterStatus?.call(pageHandler);
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