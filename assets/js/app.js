// ===============================================================
//  LIVE DATA + ROTATION ENGINE (Legacy Behavior Restored)
// ===============================================================

// #region Global State
let serverData = null;
let serverTimeOffset = 0;
let cardTimer = null;
// #endregion



// ===============================================================
//  LIVE DATA LOADER (SSE-like)
// ===============================================================
function loadLiveData() {
    fetch("/skyesoft/api/getDynamicData.php")
        .then(r => r.json())
        .then(d => {
            serverData = d;

            updateVersionFooter(d);
            syncClockOffset(d);
            updateWeather(d);
            updateInterval(d);
        })
        .catch(() => {});
}


// #region Version Footer
function updateVersionFooter(d) {
    try {
        if (!d.versions) {
            UI.safeSet("versionFooter", "Version info unavailable");
            return;
        }

        const v = d.versions;
        const vb = v.modules.officeBoard.version;
        const vp = v.modules.activePermits.version;
        const codex = v.codex.version;

        UI.safeSet("versionFooter", `OfficeBoard ${vb} • Permits ${vp} • Codex ${codex}`);
    } catch {
        UI.safeSet("versionFooter", "Version info unavailable");
    }
}
// #endregion



// ===============================================================
//  CLOCK SYNC + REAL-TIME UPDATE
// ===============================================================
function syncClockOffset(d) {
    if (d?.timeDateArray?.currentUnixTime) {
        serverTimeOffset =
            (d.timeDateArray.currentUnixTime * 1000) - Date.now();
    }
}

function updateClock() {
    if (!serverData) return;

    const now = new Date(Date.now() + serverTimeOffset);
    const hh = now.getHours();
    const mm = now.getMinutes().toString().padStart(2,"0");
    const ss = now.getSeconds().toString().padStart(2,"0");
    const am = (hh >= 12 ? "PM" : "AM");

    UI.safeSet("currentTime", `${(hh % 12) || 12}:${mm}:${ss} ${am}`);
}

setInterval(updateClock, 1000);
// ===============================================================



// ===============================================================
//  WEATHER & FORECAST
// ===============================================================
function updateWeather(d) {
    if (!d.weatherData) return;

    const w = d.weatherData;
    const icons = {
        "01":"☀️","02":"🌤️","03":"⛅",
        "04":"☁️","09":"🌦️","10":"🌧️",
        "11":"⛈️","13":"❄️","50":"🌫️"
    };

    const emoji = icons[w.icon?.substring(0,2)] || "🌤️";
    UI.safeSet("weatherDisplay", `${emoji} ${w.temp}°F — ${w.description}`);
}
// ===============================================================



// ===============================================================
//  INTERVAL COUNTDOWN
// ===============================================================
function updateInterval(d) {
    if (!d.intervalsArray) return;

    let sec = d.intervalsArray.secondsRemainingToInterval;
    if (sec == null) return;

    let name = d.intervalsArray.intervalName;
    let day  = d.intervalsArray.dayType;

    let emoji="", label="";

    if (day !== "Workday") {
        emoji="🟦"; label="Office closed";
    } else if (name === "Worktime") {
        emoji="🟩"; label="Workday ends in";
    } else if (name === "Before Worktime") {
        emoji="🟨"; label="Workday begins in";
    } else {
        emoji="🔴"; label="Workday resumes tomorrow";
    }

    if (label.includes("in")) {
        const hh = Math.floor(sec/3600).toString().padStart(2,'0');
        const mm = Math.floor((sec%3600)/60).toString().padStart(2,'0');
        const ss = (sec%60).toString().padStart(2,'0');
        UI.safeSet("intervalRemainingData", `${emoji} ${label} ${hh}h ${mm}m ${ss}s`);
    } else {
        UI.safeSet("intervalRemainingData", `${emoji} ${label}`);
    }

    d.intervalsArray.secondsRemainingToInterval = Math.max(0, sec - 1);
}
// ===============================================================




// ===============================================================
//  CARD ROTATION ENGINE  (Legacy)
// ===============================================================
function rotateCards() {
    if (!Array.isArray(cards) || cards.length === 0) return;

    const c = cards[cardIndex];
    UI.populateCard(c);

    // Active Permits
    if (cardIndex === 0) {
        setTimeout(UI.loadPermitData, 50);
        setTimeout(() => UI.autoScrollActivePermits(c.duration), 600);
    }

    // Highlights
    if (cardIndex === 1) {
        setTimeout(UI.updateHighlightsCard, 80);
    }

    cardIndex = (cardIndex + 1) % cards.length;

    if (cardTimer) clearTimeout(cardTimer);
    cardTimer = setTimeout(rotateCards, c.duration);
}
// ===============================================================




// ===============================================================
//  INITIALIZATION
// ===============================================================
window.addEventListener("DOMContentLoaded", () => {
    loadLiveData();
    setInterval(loadLiveData, 15000);
    rotateCards();
});
