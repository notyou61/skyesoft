/* Skyesoft — officeBoard.js
   Office Bulletin Board Page Controller (Tier-4 Documentation)
   DOM Bindings • Header Status UI • Permit Table Renderer • SSE Handler
*/

// #region SMART INTERVAL FORMATTER (STF)
function formatSmartInterval(totalSeconds) {
    let sec = Math.max(0, totalSeconds);

    const days = Math.floor(sec / 86400);
    sec %= 86400;

    const hours = Math.floor(sec / 3600);
    sec %= 3600;

    const minutes = Math.floor(sec / 60);
    const seconds = sec % 60;

    const out = [];

    // CASE 1 — Days present → show all units, even 00h
    if (days > 0) {
        out.push(`${days}d`);
        out.push(`${String(hours).padStart(2,"0")}h`);
        out.push(`${String(minutes).padStart(2,"0")}m`);
        out.push(`${String(seconds).padStart(2,"0")}s`);
        return out.join(" ");
    }

    // CASE 2 — Hours present → show h, mm, ss
    if (hours > 0) {
        out.push(`${hours}h`);
        out.push(`${String(minutes).padStart(2,"0")}m`);
        out.push(`${String(seconds).padStart(2,"0")}s`);
        return out.join(" ");
    }

    // CASE 3 — <1 hour → mm ss
    out.push(`${minutes}m`);
    out.push(`${String(seconds).padStart(2,"0")}s`);
    return out.join(" ");
}
// #endregion

// #region PAGE CONTROLLER
window.SkyOfficeBoard = {

    // #region DOM CACHE
    dom: {
        weatherDisplay: null,
        timeDisplay: null,
        intervalDisplay: null,
        permitTableBody: null,
        versionFooter: null
    },
    // #endregion

    // #region INIT
    init: function () {
        console.log("📋 OfficeBoard initialized");

        this.dom.weatherDisplay  = document.getElementById("headerWeather");
        this.dom.timeDisplay     = document.getElementById("headerTime");
        this.dom.intervalDisplay = document.getElementById("headerInterval");

        this.dom.permitTableBody = document.getElementById("permitTableBody");
        this.dom.versionFooter   = document.getElementById("versionFooter");
    },
    // #endregion
    

    // #region HEADER RENDER
    updateHeader: function (payload) {

        // ---------------------------------------------------------
        // TIME
        // ---------------------------------------------------------
        if (payload.timeDateArray && this.dom.timeDisplay) {
            const t = payload.timeDateArray.currentLocalTime ?? "--:--:--";
            this.dom.timeDisplay.textContent = t;
        }

        // ---------------------------------------------------------
        // INTERVAL — Smart Interval Format
        // ---------------------------------------------------------
        if (payload.currentInterval && this.dom.intervalDisplay) {
            const iv = payload.currentInterval;

            const name = iv.name ?? iv.key ?? "";
            const totalSeconds = iv.secondsRemainingInterval ?? 0;

            // STF Format
            const formatted = formatSmartInterval(totalSeconds);

            // Title-case interval name
            const prettyName = name
                .toString()
                .toLowerCase()
                .replace(/^\w/, c => c.toUpperCase());

            this.dom.intervalDisplay.textContent =
                `${prettyName} • ${formatted}`;
        }

        // ---------------------------------------------------------
        // WEATHER
        // ---------------------------------------------------------
        if (payload.weather && this.dom.weatherDisplay) {
            const w      = payload.weather;
            const tempF  = Math.round(w.temp ?? 0);
            const icon   = w.icon ?? "";
            const cond   = w.condition ?? "";
            const iconUrl = `https://openweathermap.org/img/wn/${icon}.png`;

            this.dom.weatherDisplay.innerHTML = `
                <img src="${iconUrl}" alt="${cond}"
                    class="hsb-weather-icon"
                    style="height:26px;vertical-align:middle;margin-right:6px;">
                <span>${tempF}°F</span>
            `;
        }
    },
    // #endregion


    // #region PERMIT TABLE RENDER
    updatePermitTable: function (activePermits) {

        if (!this.dom.permitTableBody) return;
        this.dom.permitTableBody.innerHTML = "";

        if (!activePermits || activePermits.length === 0) {
            this.dom.permitTableBody.innerHTML =
                `<tr><td colspan="5"
                    style="text-align:center;color:#777">
                    No active permits</td></tr>`;
            return;
        }

        const frag = document.createDocumentFragment();

        activePermits.forEach(p => {
            const tr = document.createElement("tr");
            tr.innerHTML = `
                <td>${p.wo ?? ""}</td>
                <td>${p.customer ?? ""}</td>
                <td>${p.jobsite ?? ""}</td>
                <td>${p.jurisdiction ?? ""}</td>
                <td>${p.status ?? ""}</td>
            `;
            frag.appendChild(tr);
        });

        this.dom.permitTableBody.appendChild(frag);
    },
    // #endregion


    // #region FOOTER RENDER
    updateFooter: function (payload) {
        if (!this.dom.versionFooter || !payload.siteMeta) return;

        this.dom.versionFooter.textContent =
            `v${payload.siteMeta.siteVersion ?? "0.0.0"}`;
    },
    // #endregion


    // #region SSE ROUTING
    onSSE: function (payload) {
        this.updateHeader(payload);

        if (payload.activePermits)
            this.updatePermitTable(payload.activePermits);

        this.updateFooter(payload);
    }
    // #endregion

};
// #endregion

// #region REGISTER PAGE
window.SkyeApp.registerPage("officeBoard", window.SkyOfficeBoard);
// #endregion