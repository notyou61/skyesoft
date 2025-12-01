/* Skyesoft — officeBoard.js
   Office Bulletin Board Controller (Static Card Version)
   Fully matches officeBoard.html (<section> based layout)
*/

/* #region SMART INTERVAL FORMATTER (STF) */
function formatSmartInterval(totalSeconds) {
    let sec = Math.max(0, totalSeconds);

    const days = Math.floor(sec / 86400);
    sec %= 86400;

    const hours = Math.floor(sec / 3600);
    sec %= 3600;

    const minutes = Math.floor(sec / 60);
    const seconds = sec % 60;

    const parts = [];

    if (days > 0) {
        parts.push(`${days}d`);
        parts.push(`${String(hours).padStart(2, "0")}h`);
        parts.push(`${String(minutes).padStart(2, "0")}m`);
        parts.push(`${String(seconds).padStart(2, "0")}s`);
        return parts.join(" ");
    }

    if (hours > 0) {
        parts.push(`${hours}h`);
        parts.push(`${String(minutes).padStart(2, "0")}m`);
        parts.push(`${String(seconds).padStart(2, "0")}s`);
        return parts.join(" ");
    }

    parts.push(`${minutes}m`);
    parts.push(`${String(seconds).padStart(2, "0")}s`);
    return parts.join(" ");
}
/* #endregion */


/* #region PAGE CONTROLLER */
window.SkyOfficeBoard = {

    /* #region DOM CACHE */
    dom: {
        weatherDisplay: null,
        timeDisplay: null,
        intervalDisplay: null,
        versionFooter: null,
        permitTableBody: null,

        // Card wrapper elements
        cardActivePermits: null,
        cardHighlights: null,
        cardKPI: null,
        cardAnnouncements: null
    },
    /* #endregion */


    /* #region ROTATION CONFIG */
    rotation: {
        index: 0,
        duration: 30000,
        timer: null,
        cards: ["activePermits", "highlights", "kpi", "announcements"]
    },
    /* #endregion */


    /* #region INIT */
    init: function () {

        console.log("📋 OfficeBoard initialized (STATIC CARD VERSION)");

        // Header fields
        this.dom.weatherDisplay  = document.getElementById("headerWeather");
        this.dom.timeDisplay     = document.getElementById("headerTime");
        this.dom.intervalDisplay = document.getElementById("headerInterval");
        this.dom.versionFooter   = document.getElementById("versionFooter");

        // Static card containers
        this.dom.cardActivePermits = document.getElementById("cardActivePermits");
        this.dom.cardHighlights    = document.getElementById("cardHighlights");
        this.dom.cardKPI           = document.getElementById("cardKPI");
        this.dom.cardAnnouncements = document.getElementById("cardAnnouncements");

        // Table body
        this.dom.permitTableBody = document.getElementById("permitTableBody");

        // Start card rotation
        this.showCard(0);
        this.startRotation();
    },
    /* #endregion */


    /* #region CARD ROTATION */
    startRotation: function () {

        if (this.rotation.timer)
            clearInterval(this.rotation.timer);

        this.rotation.timer = setInterval(() => {
            this.rotation.index =
                (this.rotation.index + 1) % this.rotation.cards.length;

            this.showCard(this.rotation.index);
        }, this.rotation.duration);
    },

    showCard: function (index) {

        const key = this.rotation.cards[index];

        // Hide all first
        this.dom.cardActivePermits.style.display = "none";
        this.dom.cardHighlights.style.display    = "none";
        this.dom.cardKPI.style.display           = "none";
        this.dom.cardAnnouncements.style.display = "none";

        switch (key) {
            case "activePermits":
                this.dom.cardActivePermits.style.display = "block";
                break;

            case "highlights":
                this.dom.cardHighlights.style.display = "block";
                break;

            case "kpi":
                this.dom.cardKPI.style.display = "block";
                break;

            case "announcements":
                this.dom.cardAnnouncements.style.display = "block";
                break;
        }
    },
    /* #endregion */


    /* #region HEADER UPDATES */
    updateHeader: function (payload) {

        // TIME
        if (payload.timeDateArray && this.dom.timeDisplay) {
            this.dom.timeDisplay.textContent =
                payload.timeDateArray.currentLocalTime ?? "--:--:--";
        }

        // INTERVAL
        if (payload.currentInterval && this.dom.intervalDisplay) {
            const iv = payload.currentInterval;
            const formatted = formatSmartInterval(iv.secondsRemainingInterval);

            const pretty = (iv.key ?? "")
                .toLowerCase()
                .replace(/^\w/, c => c.toUpperCase());

            this.dom.intervalDisplay.textContent = `${pretty} • ${formatted}`;
        }

        // WEATHER
        if (payload.weather && this.dom.weatherDisplay) {
            const w = payload.weather;
            const icon = `https://openweathermap.org/img/wn/${w.icon}.png`;

            this.dom.weatherDisplay.innerHTML = `
                <img src="${icon}" class="hsb-weather-icon" alt="">
                <span>${Math.round(w.temp)}°F</span>
            `;
        }
    },
    /* #endregion */


    /* #region PERMIT TABLE RENDER */
    updatePermitTable: function (activePermits) {

        this._latestActivePermits = activePermits;
        const body = this.dom.permitTableBody;

        if (!body) return;

        body.innerHTML = "";

        if (!activePermits || activePermits.length === 0) {
            body.innerHTML = `
                <tr><td colspan="5" style="text-align:center;color:#777;">
                    No active permits
                </td></tr>
            `;
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

        body.appendChild(frag);
    },
    /* #endregion */


    /* #region ANNOUNCEMENTS RENDER */
    updateAnnouncements: function (arr) {

        const list = document.getElementById("announcementList");
        if (!list) return;

        if (!arr || arr.length === 0) {
            list.innerHTML = `<li>No announcements posted.</li>`;
            return;
        }

        list.innerHTML = arr
            .map(a => `<li><strong>${a.title}</strong>: ${a.message}</li>`)
            .join("");
    },
    /* #endregion */


    /* #region FOOTER */
    updateFooter: function (payload) {
        if (payload.siteMeta && this.dom.versionFooter) {
            this.dom.versionFooter.textContent =
                `v${payload.siteMeta.siteVersion ?? "0.0.0"}`;
        }
    },
    /* #endregion */


    /* #region SSE ROUTING */
    onSSE: function (payload) {

        this.updateHeader(payload);

        // Correct Active Permits feed
        if (payload.activePermitsFull && payload.activePermitsFull.activePermits) {
            this.updatePermitTable(payload.activePermitsFull.activePermits);
        }

        if (payload.announcementsFull) {
            this.updateAnnouncements(payload.announcementsFull.announcements);
        }

        this.updateFooter(payload);
    }
    /* #endregion */

};
/* #endregion */


/* #region REGISTER PAGE */
window.SkyeApp.registerPage("officeBoard", window.SkyOfficeBoard);
/* #endregion */
