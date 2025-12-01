/* Skyesoft — officeBoard.js
   Office Bulletin Board Controller (Static Card Version)
   Fully matches officeBoard.html (<section> based layout)
*/

/* #region SMART INTERVAL FORMATTER (STF-X) */
function formatSmartInterval(totalSeconds) {
    let sec = Math.max(0, totalSeconds);

    const days    = Math.floor(sec / 86400);
    sec          %= 86400;

    const hours   = Math.floor(sec / 3600);
    sec          %= 3600;

    const minutes = Math.floor(sec / 60);
    const seconds = sec % 60;

    const parts = [];

    if (days > 0) {
        parts.push(`${String(days)}d`);
        parts.push(`${String(hours).padStart(2, "0")}h`);
        parts.push(`${String(minutes).padStart(2, "0")}m`);
        parts.push(`${String(seconds).padStart(2, "0")}s`);
        return parts.join(" ");
    }

    if (hours > 0) {
        parts.push(`${String(hours).padStart(2, "0")}h`);
        parts.push(`${String(minutes).padStart(2, "0")}m`);
        parts.push(`${String(seconds).padStart(2, "0")}s`);
        return parts.join(" ");
    }

    if (minutes > 0) {
        parts.push(`${String(minutes).padStart(2, "0")}m`);
        parts.push(`${String(seconds).padStart(2, "0")}s`);
        return parts.join(" ");
    }

    return `${String(seconds).padStart(2, "0")}s`;
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

    /* #region AUTOSCROLL ENGINE (ASC-X Standard) */
    autoScroll: {
        timer: null,
        isRunning: false,

        start(scrollEl, cardDurationMs) {
            if (!scrollEl) return;

            const maxScroll = scrollEl.scrollHeight - scrollEl.clientHeight;
            if (maxScroll <= 0) return; // no scrolling needed

            // End 2 seconds before rotation switch
            const usableDuration = Math.max(1000, cardDurationMs - 2000);

            this.stop();

            this.isRunning = true;
            const startTime = performance.now();

            const step = (now) => {
                if (!this.isRunning) return;

                const elapsed = now - startTime;
                const t = Math.min(1, elapsed / usableDuration); // 0 → 1

                // Ease-out cubic
                const eased = 1 - Math.pow(1 - t, 3);

                scrollEl.scrollTop = eased * maxScroll;

                if (t < 1) {
                    this.timer = requestAnimationFrame(step);
                }
            };

            this.timer = requestAnimationFrame(step);
        },

        stop() {
            this.isRunning = false;
            if (this.timer) cancelAnimationFrame(this.timer);
            this.timer = null;
        }
    },
    /* #endregion */

    /* #region INIT */
    init: function () {

        this.dom.weatherDisplay  = document.getElementById("headerWeather");
        this.dom.timeDisplay     = document.getElementById("headerTime");
        this.dom.intervalDisplay = document.getElementById("headerInterval");
        this.dom.versionFooter   = document.getElementById("versionFooter");

        this.dom.cardActivePermits = document.getElementById("cardActivePermits");
        this.dom.cardHighlights    = document.getElementById("cardHighlights");
        this.dom.cardKPI           = document.getElementById("cardKPI");
        this.dom.cardAnnouncements = document.getElementById("cardAnnouncements");

        this.dom.permitTableBody = document.getElementById("permitTableBody");
        this.dom.permitScroll = document.querySelector("#cardActivePermits .scrollContainer");

        this.showCard(0);
        this.startRotation();

        if (window.SkyeApp.lastSSE) {
            this.onSSE(window.SkyeApp.lastSSE);
        }
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
    
    // Stop auto-scroll when changing cards
    showCard: function (index) {

        // ✅ ASC-X compliant: stop scrolling immediately on card switch
        this.autoScroll.stop();

        const key = this.rotation.cards[index];

        // Hide all cards
        this.dom.cardActivePermits.style.display = "none";
        this.dom.cardHighlights.style.display    = "none";
        this.dom.cardKPI.style.display           = "none";
        this.dom.cardAnnouncements.style.display = "none";

        switch (key) {
            case "activePermits":
                this.dom.cardActivePermits.style.display = "block";

                // ✅ Start fresh scroll when Active Permits becomes visible
                if (this.dom.permitScroll) {
                    this.autoScroll.start(
                        this.dom.permitScroll,
                        this.rotation.duration
                    );
                }
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

    /* #region HEADER UPDATES (HSB-X Standard) */
    updateHeader: function (payload) {
        if (!payload) return;

        try {
            if (payload.timeDateArray && this.dom.timeDisplay) {
                this.dom.timeDisplay.textContent =
                    payload.timeDateArray.currentLocalTime ?? "--:--:--";
            }
        } catch (err) {
            console.error("updateHeader TIME failed:", err);
        }

        try {
            if (payload.currentInterval && this.dom.intervalDisplay) {
                const iv = payload.currentInterval;
                const formatted = formatSmartInterval(iv.secondsRemainingInterval);

                const nextEventLabels = {
                    beforeWork: "Workday begins in",
                    worktime:   "Workday ends in",
                    afterWork:  "Next workday begins in",
                    weekend:    "Workday resumes in",
                    holiday:    "Workday resumes after holiday in"
                };

                const label = nextEventLabels[iv.key] ?? "Interval ends in";
                this.dom.intervalDisplay.textContent = `${label} ${formatted}`;
            }
        } catch (err) {
            console.error("updateHeader INTERVAL failed:", err);
        }

        try {
            if (payload.weather && this.dom.weatherDisplay) {
                const w = payload.weather;
                const temp = Math.round(w.temp ?? 0);
                const cond = w.condition
                    ? w.condition.replace(/^\w/, c => c.toUpperCase())
                    : "--";

                this.dom.weatherDisplay.textContent = `${temp}°F – ${cond}`;
            }
        } catch (err) {
            console.error("updateHeader WEATHER failed:", err);
        }
    },
    /* #endregion */

    /* #region PERMIT TABLE */
    updatePermitTable: function (activePermits) {

        const body = this.dom.permitTableBody;
        const footer = document.getElementById("permitFooter");
        if (!body) return;

        body.innerHTML = "";

        if (!activePermits || activePermits.length === 0) {
            body.innerHTML = `
                <tr>
                    <td colspan="5" style="text-align:center;color:#777;">
                        No active permits
                    </td>
                </tr>
            `;
            if (footer) footer.textContent = "No permits found";
            return;
        }

        const sorted = [...activePermits].sort((a, b) =>
            (parseInt(a.wo) || 0) - (parseInt(b.wo) || 0)
        );

        const frag = document.createDocumentFragment();

        sorted.forEach(p => {
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

        if (footer) {
            footer.textContent = `${sorted.length} active permit${sorted.length === 1 ? "" : "s"}`;
        }
    },
    /* #endregion */

    /* #region ANNOUNCEMENTS */
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

        // --- Header (weather • time • interval)
        try {
            this.updateHeader(payload);
        } catch (err) {
            console.error("updateHeader failed:", err);
        }

        // --- Active Permits (flat array)
        try {
            if (Array.isArray(payload.activePermits)) {

                // Update table rows
                this.updatePermitTable(payload.activePermits);

                // Recalc + restart auto-scroll ONLY if this card is visible
                this.autoScroll.start(
                    this.dom.permitScroll,
                    this.rotation.duration
                );
            }
        } catch (err) {
            console.error("updatePermitTable failed:", err);
        }

        // --- Announcements
        try {
            if (Array.isArray(payload.announcements)) {
                this.updateAnnouncements(payload.announcements);
            }
        } catch (err) {
            console.error("updateAnnouncements failed:", err);
        }

        // --- Version footer
        try {
            this.updateFooter(payload);
        } catch (err) {
            console.error("updateFooter failed:", err);
        }
    }
    /* #endregion */

};
/* #endregion */

/* #region REGISTER PAGE */
window.SkyeApp.registerPage("officeBoard", window.SkyOfficeBoard);
/* #endregion */