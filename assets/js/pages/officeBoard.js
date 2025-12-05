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

    // --- DAYS PRESENT ---
    if (days > 0) {
        parts.push(`${days}d`);

        // STF-X RULE: never show 00h when days > 0
        if (hours > 0) {
            parts.push(`${String(hours).padStart(2, "0")}h`);
        }

        parts.push(`${String(minutes).padStart(2, "0")}m`);
        parts.push(`${String(seconds).padStart(2, "0")}s`);
        return parts.join(" ");
    }

    // --- HOURS PRESENT (no days) ---
    if (hours > 0) {
        parts.push(`${hours}h`);
        parts.push(`${String(minutes).padStart(2, "0")}m`);
        parts.push(`${String(seconds).padStart(2, "0")}s`);
        return parts.join(" ");
    }

    // --- MINUTES PRESENT ---
    if (minutes > 0) {
        parts.push(`${minutes}m`);
        parts.push(`${String(seconds).padStart(2, "0")}s`);
        return parts.join(" ");
    }

    // --- SECONDS ONLY ---
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

    /* #region AUTOSCROLL ENGINE (ASC-V: Dynamic 95% Completion Algorithm) */
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

            // No scroll required
            if (distance <= 0) {
                console.log("ASC-V: No scroll needed (content fits).");
                return;
            }

            /* ------------------------------------------------------
            ASC-V (Dynamic Velocity)
            • Scroll must finish by 95% of card interval
            • Linger takes the remaining 5%
            • Speed adjusts based on distance + duration only
            ------------------------------------------------------ */

            const P = 0.95;                        // finish by 95% of interval
            const scrollTimeMs = totalDurationMs * P;

            const frames = Math.max(
                1,
                Math.round(scrollTimeMs / (1000 / this.FPS))
            );

            const speed = distance / frames;       // px per frame

            console.log(
                `ASC-V: distance=${distance}px, frames=${frames}, `
                + `speed=${speed.toFixed(3)} px/frame`
            );

            // Reset to top
            el.scrollTop = 0;

            this.currentEl = el;
            this.isRunning = true;

            const step = () => {
                if (!this.isRunning) return;

                const currentMax = el.scrollHeight - el.clientHeight;

                // Clean stop at bottom (linger begins automatically)
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

    /* #region PATCH: Card Rotation Reset */
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

                // Always start fresh when switching to permit card
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

        // --- DOM REFERENCES ---
        this.dom.weatherDisplay     = document.getElementById("headerWeather");
        this.dom.timeDisplay        = document.getElementById("headerTime");
        this.dom.intervalDisplay    = document.getElementById("headerInterval");
        this.dom.versionFooter      = document.getElementById("versionFooter");

        this.dom.cardActivePermits  = document.getElementById("cardActivePermits");
        this.dom.cardHighlights     = document.getElementById("cardHighlights");
        this.dom.cardKPI            = document.getElementById("cardKPI");
        this.dom.cardAnnouncements  = document.getElementById("cardAnnouncements");

        this.dom.permitTableBody    = document.getElementById("permitTableBody");
        this.dom.permitScroll       = document.getElementById("permitScrollWrap");

        console.log("permitScroll:", this.dom.permitScroll);

        // --- FIRST CARD & ROTATION ---
        this.showCard(0);
        this.startRotation();

        console.log("Rotation initialized");

        // --- Track data readiness & changes ---
        this._scrollStarted = false;
        this._hasPermitData = false;
        this._dataChanged = false;
        this.prevPermitLength = 0;

        // --- If SSE already delivered once (page refresh) ---
        if (window.SkyeApp.lastSSE) {
            this.onSSE(window.SkyeApp.lastSSE);
        }
    },
    /* #endregion */

    /* #region CARD ROTATION */
    startRotation: function () {
        if (this.rotation.timer) {
            clearInterval(this.rotation.timer);
        }

        this.rotation.timer = setInterval(() => {
            this.rotation.index =
                (this.rotation.index + 1) % this.rotation.cards.length;

            console.log("🔄 Rotating to card:", this.rotation.cards[this.rotation.index]);

            this.showCard(this.rotation.index);
        }, this.rotation.duration);
    },

    // Stop auto-scroll when changing cards
    showCard: function (index) {

        // Hard stop autoscroll BEFORE anything else
        this.autoScroll.stop();

        const key = this.rotation.cards[index];

        // Hide all cards
        this.dom.cardActivePermits.style.display = "none";
        this.dom.cardHighlights.style.display    = "none";
        this.dom.cardKPI.style.display           = "none";
        this.dom.cardAnnouncements.style.display = "none";

        // Show only the selected card
        switch (key) {
            case "activePermits":
                this.dom.cardActivePermits.style.display = "block";

                // Reset change flag on new cycle (forces fresh start)
                this._dataChanged = true;

                // Conditional start: algo handles no-data gracefully
                requestAnimationFrame(() => {
                    this.autoScroll.start(this.dom.permitScroll, this.rotation.duration);
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

            this._hasPermitData = false;
            if (this.prevPermitLength !== 0) {
                this._dataChanged = true;  // Empty is a change
            }
            this.prevPermitLength = 0;
            return;
        }

        const currentLength = activePermits.length;
        const lengthChanged = currentLength !== this.prevPermitLength;

        // Mark as having data
        this._hasPermitData = true;

        if (lengthChanged) {
            this._dataChanged = true;
            console.log(`Permit data changed: ${this.prevPermitLength} → ${currentLength} rows`);
        }

        this.prevPermitLength = currentLength;

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

        // During active scroll: log but no action (reflow handled)
        if (this.dom.permitScroll && this.autoScroll.isRunning) {
            console.log("Data updated mid-scroll; continuing (reflow handled per-frame)");
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

                // Update table rows & footer (may cause reflow mid-scroll)
                this.updatePermitTable(payload.activePermits);

                // --- Initial kickoff ONLY if no prior data & visible ---
                if (!this._scrollStarted && this._hasPermitData && this.dom.cardActivePermits.style.display !== "none") {
                    this._scrollStarted = true;
                    console.log("Initial scroll kickoff after first data");
                    requestAnimationFrame(() => {
                        if (this.dom.permitScroll) {
                            this.autoScroll.start(this.dom.permitScroll, this.rotation.duration);
                        }
                    });
                } else if (this._hasPermitData && !this.autoScroll.isRunning && this.dom.cardActivePermits.style.display !== "none" && this._dataChanged) {
                    // Restart ONLY during linger IF data actually changed (prevents frequent resets)
                    console.log("Restart scroll during linger (data changed)");
                    this._dataChanged = false;  // Reset flag after handling
                    requestAnimationFrame(() => {
                        if (this.dom.permitScroll) {
                            this.autoScroll.start(this.dom.permitScroll, this.rotation.duration);
                        }
                    });
                }
                // Active scroll: update data silently (algo handles reflow via per-frame max recalc)
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