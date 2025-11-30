/* Skyesoft — app.js
   Global Front-End Controller (Tier-4 Documentation)
   Detects page • Handles SSE • Updates Header/Footers • Routes SSE to page logic
*/

// #region PAGE STATE
window.SkyeApp = {
    currentPage: null,
    pageHandlers: {},
// #endregion

// #region PAGE DETECTION
    detectPage: function () {
        const page = document.body.getAttribute("data-page");
        if (!page) {
            console.warn("⚠ No data-page attribute found; cannot route.");
            return;
        }
        this.currentPage = page;
    },
// #endregion

// #region PAGE REGISTRATION
    registerPage: function (pageName, handlerObj) {
        this.pageHandlers[pageName] = handlerObj;
    },
// #endregion

// #region PAGE INITIALIZATION
    initPage: function () {
        if (!this.currentPage) return;

        const handler = this.pageHandlers[this.currentPage];
        if (!handler) {
            console.warn("⚠ No handler for page:", this.currentPage);
            return;
        }

        if (typeof handler.init === "function") {
            handler.init();
        }
    },
// #endregion

// #region SSE → PAGE ROUTING
    routeSSEToPage: function (payload) {
        const handler = this.pageHandlers[this.currentPage];
        if (!handler || typeof handler.onSSE !== "function") return;
        handler.onSSE(payload);
    },
// #endregion

// #region HEADER STATUS BLOCK (Weather • Time • Interval)
updateHSB: function (payload) {

    // -------- WEATHER --------
    if (payload.weather) {
        const w = payload.weather;
        const tempF = Math.round(w.temp ?? 0);
        const cond = w.condition
            ? w.condition.replace(/\b\w/g, c => c.toUpperCase()) // capitalized
            : "--";

        const el = document.getElementById("headerWeather");
        if (el) el.textContent = `${tempF}°F — ${cond}`;
    }

    // -------- TIME --------
    const t = payload.timeDateArray;
    if (t) {
        const el = document.getElementById("headerTime");
        if (el) el.textContent = t.currentLocalTime ?? "--:--:--";
    }

    // -------- INTERVAL --------
    if (payload.currentInterval) {

        const iv = payload.currentInterval;
        const el = document.getElementById("headerInterval");
        if (!el) return;

        const remaining = iv.secondsRemainingInterval ?? 0;

        // Format remaining time
        let sec = remaining;
        const days = Math.floor(sec / 86400); sec %= 86400;
        const hrs  = Math.floor(sec / 3600);  sec %= 3600;
        const mins = Math.floor(sec / 60);
        const secs = sec % 60;

        // Build components, skipping leading zeros
        const parts = [];
        if (days > 0) parts.push(`${days}d`);
        if (days > 0 || hrs > 0) parts.push(`${String(hrs).padStart(2, "0")}h`);
        if (days > 0 || hrs > 0 || mins > 0) parts.push(`${String(mins).padStart(2, "0")}m`);
        parts.push(`${String(secs).padStart(2, "0")}s`);

        const remainingStr = parts.join(" ");

        // Safe interval label
        const label = iv.name ?? "Interval";

        // Output
        el.textContent = `${label} • ${remainingStr}`;
    }


},
// #endregion

// #region VERSION FOOTER
    updateVersionFooter: function (payload) {
        const el = document.getElementById("versionFooter");
        if (!el) return;

        if (payload.siteMeta?.siteVersion) {
            el.textContent = "v" + payload.siteMeta.siteVersion;
        }
    },
// #endregion

// #region GLOBAL SSE FINAL HANDLER
    handleSSE: function (payload) {
        this.updateHSB(payload);
        this.routeSSEToPage(payload);
        this.updateVersionFooter(payload);
    }

};
// end window.SkyeApp
// #endregion

// #region DOM READY BOOTSTRAP
document.addEventListener("DOMContentLoaded", function () {
    window.SkyeApp.detectPage();
    window.SkyeApp.initPage();

    if (window.SkySSE?.start) {
        window.SkySSE.start();
    } else {
        console.error("❌ SSE engine missing: SkySSE.start not found");
    }
});
// #endregion