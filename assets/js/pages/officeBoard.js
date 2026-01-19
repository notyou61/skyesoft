/* Skyesoft — officeBoard.js
   Office Bulletin Board Controller (Static Card Version)
   Fully matches officeBoard.html (<section> based layout)
*/

/* #region SMART INTERVAL FORMATTER (STF-X Compliant) */
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

        if (hours > 0) {
            parts.push(`${String(hours).padStart(2, "0")}h`);
        }

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

    if (minutes > 0) {
        parts.push(`${minutes}m`);
        parts.push(`${String(seconds).padStart(2, "0")}s`);
        return parts.join(" ");
    }

    return `${seconds}s`;
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
        permitScroll: null,

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

    /* #region AUTOSCROLL ENGINE */
    autoScroll: {
        timer: null,
        currentEl: null,
        isRunning: false,
        FPS: 60,

        start(el, totalDurationMs = 30000) {
            if (!el) return;

            this.stop();

            const scrollHeight = el.scrollHeight;
            const clientHeight = el.clientHeight;
            const distance = scrollHeight - clientHeight;

            if (distance <= 0) {
                console.log("ASC-V: No scroll needed (content fits).");
                return;
            }

            const P = 0.95;
            const scrollTimeMs = totalDurationMs * P;

            const frames = Math.max(
                1,
                Math.round(scrollTimeMs / (1000 / this.FPS))
            );

            const speed = Math.max(0.5, distance / frames);

            el.scrollTop = 0;

            this.currentEl = el;
            this.isRunning = true;

            const step = () => {
                if (!this.isRunning) return;

                const currentMax = el.scrollHeight - el.clientHeight;

                if (el.scrollTop + speed >= currentMax) {
                    el.scrollTop = currentMax;
                    this.isRunning = false;
                    return;
                }

                el.scrollTop += speed;
                this.timer = requestAnimationFrame(step);
            };

            this.timer = requestAnimationFrame(step);
        },

        stop() {
            if (this.timer) cancelAnimationFrame(this.timer);
            this.timer = null;
            this.isRunning = false;
        }
    },
    /* #endregion */

    /* #region CARD DISPLAY */
    showCard: function (index) {
        this.autoScroll.stop();

        const key = this.rotation.cards[index];

        this.dom.cardActivePermits.style.display = "none";
        this.dom.cardHighlights.style.display    = "none";
        this.dom.cardKPI.style.display           = "none";
        this.dom.cardAnnouncements.style.display = "none";

        switch (key) {
            case "activePermits":
                this.dom.cardActivePermits.style.display = "block";

                requestAnimationFrame(() => {
                    if (this.dom.permitScroll) {
                        this.autoScroll.start(this.dom.permitScroll, this.rotation.duration);
                    }
                });
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

    /* #region INIT */
    init: function () {

        this.dom.weatherDisplay  = document.getElementById("headerWeather");
        this.dom.timeDisplay     = document.getElementById("headerTime");
        this.dom.intervalDisplay = document.getElementById("headerInterval");
        this.dom.versionFooter   = document.getElementById("versionFooter");

        this.dom.cardActivePermits  = document.getElementById("cardActivePermits");
        this.dom.cardHighlights     = document.getElementById("cardHighlights");
        this.dom.cardKPI            = document.getElementById("cardKPI");
        this.dom.cardAnnouncements  = document.getElementById("cardAnnouncements");

        this.dom.permitTableBody = document.getElementById("permitTableBody");
        this.dom.permitScroll    = document.getElementById("permitScrollWrap");

        this.showCard(0);
        this.startRotation();

        this._scrollStarted = false;
        this._hasPermitData = false;
        this._dataChanged = false;
        this.prevPermitLength = 0;

        if (window.SkyeApp.lastSSE) {
            this.onSSE(window.SkyeApp.lastSSE);
        }
    },
    /* #endregion */

    /* #region ROTATION */
    startRotation: function () {
        if (this.rotation.timer) {
            clearInterval(this.rotation.timer);
        }

        this.rotation.timer = setInterval(() => {
            this.rotation.index =
                (this.rotation.index + 1) % this.rotation.cards.length;

            this.showCard(this.rotation.index);
        }, this.rotation.duration);
    },
    /* #endregion */

    /* #region HEADER UPDATES */
    updateHeader: function (payload) {
        if (!payload) return;

        try {
            if (payload.timeDateArray && this.dom.timeDisplay) {
                this.dom.timeDisplay.textContent =
                    payload.timeDateArray.currentLocalTime ?? "--:--:--";
            }
        } catch {}

        try {
            if (payload.currentInterval && this.dom.intervalDisplay) {
                const iv = payload.currentInterval;
                const formatted = formatSmartInterval(iv.secondsRemainingInterval);

                const labels = {
                    beforeWork: "Workday begins in",
                    worktime:   "Workday ends in",
                    afterWork:  "Next workday begins in",
                    weekend:    "Workday resumes in",
                    holiday:    "Workday resumes after holiday in"
                };

                const label = labels[iv.key] ?? "Interval ends in";
                this.dom.intervalDisplay.textContent = `${label} ${formatted}`;
            }
        } catch {}

        try {
            if (payload.weather && this.dom.weatherDisplay) {
                const w = payload.weather;
                const temp = Math.round(w.temp ?? 0);
                const cond = w.condition
                    ? w.condition.replace(/^\w/, c => c.toUpperCase())
                    : "--";

                this.dom.weatherDisplay.textContent = `${temp}°F – ${cond}`;
            }
        } catch {}
    },
    /* #endregion */

    /* #region PERMIT TABLE */
    updatePermitTable: function (activePermits) {

        const body   = this.dom.permitTableBody;
        const footer = document.getElementById("permitFooter");

        if (!body) return;

        // ---- CHANGE DETECTION (prevents flashing) ----
        const signature = Array.isArray(activePermits)
            ? activePermits.map(p => `${p.wo}|${p.status}`).join("::")
            : "empty";

        if (signature === this.lastPermitSignature) {
            return; // ⛔ no DOM work, no scroll reset
        }

        this.lastPermitSignature = signature;

        // ---- EMPTY STATE ----
        if (!Array.isArray(activePermits) || activePermits.length === 0) {

            body.innerHTML = `
                <tr class="empty-row">
                    <td colspan="5">No active permits</td>
                </tr>
            `;

            if (footer) footer.textContent = "No permits found";

            this.prevPermitLength = 0;
            return;
        }

        // ---- NORMAL DATA PATH ----
        const sorted = [...activePermits].sort((a, b) =>
            (parseInt(a.wo, 10) || 0) - (parseInt(b.wo, 10) || 0)
        );

        const lengthChanged = sorted.length !== this.prevPermitLength;

        body.innerHTML = "";
        const frag = document.createDocumentFragment();

        sorted.forEach(p => {
            const tr = document.createElement("tr");
            tr.classList.add("permit-row");

            if (lengthChanged) tr.classList.add("updated");

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

        if (footer) {
            footer.textContent =
                `${sorted.length} active permit${sorted.length === 1 ? "" : "s"}`;
        }

        this.prevPermitLength = sorted.length;

        // ---- SCROLL RESTART (ON REAL DATA CHANGE ONLY) ----
        requestAnimationFrame(() => {
            if (
                this.dom.permitScroll &&
                this.dom.cardActivePermits &&
                this.dom.cardActivePermits.style.display !== "none" &&
                this.dom.permitScroll.scrollHeight > this.dom.permitScroll.clientHeight
            ) {
                this.autoScroll.start(
                    this.dom.permitScroll,
                    this.rotation.duration
                );
            }
        });
    },
    /* #endregion */

    /* #region ANNOUNCEMENTS */
    updateAnnouncements: function (arr) {
        const list = document.getElementById("announcementList");
        if (!list) return;

        list.innerHTML = !arr || arr.length === 0
            ? `<li>No announcements posted.</li>`
            : arr.map(a =>
                `<li><strong>${a.title}</strong>: ${a.message}</li>`
              ).join("");
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

    /* #region HIGHLIGHTS CARD */
    updateHighlights: function (payload) {
        if (!payload) return;

        const t = payload.timeDateArray || {};
        const w = payload.weather || {};
        const h = payload.holidayState || {};

        const set = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.textContent = val ?? "--";
        };

        try {
            set("hlDate", t.currentDate);
            set("hlDayOfYear", t.currentDayNumber);
            set("hlDaysRemain", h.nextHoliday?.daysAway);

            set("hlSunrise", w.sunrise);
            set("hlSunset", w.sunset);

            if (w.sunrise && w.sunset) {
                set("hlDayHours", `${w.sunrise} – ${w.sunset}`);
            }

            set("hlNextHoliday", h.nextHoliday?.name);

            set("hlForecast",
                w.condition
                    ? `${Math.round(w.temp ?? 0)}°F – ${w.condition}`
                    : "--"
            );
        } catch (err) {
            console.error("updateHighlights failed:", err);
        }
    },
    /* #endregion */

    /* #region SSE ROUTING */
    onSSE: function (payload) {

        this.updateHeader(payload);

        if (Array.isArray(payload.activePermits)) {

            // Update the table
            this.updatePermitTable(payload.activePermits);

            const currentLength = payload.activePermits.length;

            // FIRST data arrival – start scroll if visible
            if (!this._scrollStarted && currentLength > 0) {
                this._scrollStarted = true;

                requestAnimationFrame(() => {

                    const el = this.dom.permitScroll;

                    const needsScroll =
                        el &&
                        el.scrollHeight > el.clientHeight &&
                        this.dom.permitTableBody &&
                        this.dom.permitTableBody.children.length > 3;

                    if (needsScroll) {
                        this.autoScroll.start(el, this.rotation.duration);
                    }
                });

            }

        }

        if (Array.isArray(payload.announcements)) {
            this.updateAnnouncements(payload.announcements);
        }

        this.updateHighlights(payload);

        this.updateFooter(payload);
    }
    /* #endregion */
};
/* #endregion */

/* #region REGISTER PAGE */
window.SkyeApp.registerPage("officeBoard", window.SkyOfficeBoard);
/* #endregion */