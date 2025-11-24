//#region App Engine
// ====================================================================
// Skyesoft OfficeBoard — Rotation, Live Data, Scroll Engine
// ====================================================================

var cardIndex = 0;
var serverData = null;
var serverTimeOffset = 0;

// =======================================================
// Load the current card's HTML
// =======================================================
function loadCard(id) {
    var c = Cards[id];

    UI.safeSet("bodyHeader", c.header);
    UI.safeSet("bodyMain", '<div class="cardBody">' + c.body + '</div>');
    UI.safeSet("bodyFooter", c.footer);
}

// =======================================================
// Auto Scroll — Dynamic speed
// =======================================================
function autoScrollActivePermits() {

    var m = UI.getScrollMetrics();
    var dist = m.dist;

    if (dist <= 0) return;

    var total = Cards.durations.permits;
    var scrollTime = total - 2000; // scroll finishes 2 sec early

    var step = dist / (scrollTime / 30);
    var pos = 0;

    var container = document.querySelector(".scrollContainer");
    if (!container) return;

    container.scrollTop = 0;

    var timer = setInterval(function () {
        pos += step;
        container.scrollTop = pos;

        if (pos >= dist) {
            clearInterval(timer);
        }
    }, 30);
}

// =======================================================
// Rotation Engine
// =======================================================
function rotate() {
    var cardId = Cards.order[cardIndex];

    loadCard(cardId);

    if (cardId === "permits") {
        setTimeout(loadPermits, 50);
        setTimeout(autoScrollActivePermits, 500);
    }

    if (cardId === "highlights") {
        setTimeout(updateHighlights, 50);
    }

    cardIndex = (cardIndex + 1) % Cards.order.length;
    setTimeout(rotate, Cards.durations[cardId]);
}

// =======================================================
// Permits
// =======================================================
function loadPermits() {
    fetch("/skyesoft/assets/data/activePermits.json")
        .then(function (r) { return r.json(); })
        .then(function (json) {
            UI.renderPermits(json);
        });
}

// =======================================================
// Highlights
// =======================================================
function updateHighlights() {
    var info = getDateInfo();
    UI.safeSet("todaysDate", info.formattedDate);
    UI.safeSet("dayOfYear", info.dayOfYear);
    UI.safeSet("daysRemaining", info.daysRemaining);
}

function getDateInfo() {
    var n = new Date();
    var f = n.toLocaleDateString('en-US',
        { weekday: 'long', month: 'long', day:'numeric' }
    );
    var y = n.getFullYear();
    var leap = (y % 4 === 0 && (y % 100 !== 0 || y % 400 === 0)) ? 366 : 365;
    var doy = Math.floor((n - new Date(y,0,0)) / 86400000);

    return { formattedDate: f, dayOfYear: doy, daysRemaining: leap - doy };
}

// =======================================================
// Live Data / Clock / Interval
// =======================================================
function loadLiveData() {
    fetch("/skyesoft/api/getDynamicData.php")
        .then(function (r) { return r.json(); })
        .then(function (d) {
            serverData = d;

            if (d.timeDateArray && d.timeDateArray.currentUnixTime) {
                serverTimeOffset = (d.timeDateArray.currentUnixTime * 1000) - Date.now();
            }

            updateClock();
        });
}

function updateClock() {
    if (!serverData) return;

    var now = new Date(Date.now() + serverTimeOffset);
    var h = now.getHours();
    var m = ("" + now.getMinutes()).replace(/^(\d)$/, "0$1");
    var s = ("" + now.getSeconds()).replace(/^(\d)$/, "0$1");
    var am = (h >= 12 ? "PM" : "AM");

    UI.safeSet("currentTime", ((h % 12) || 12) + ":" + m + ":" + s + " " + am);
}

// =======================================================
// Start
// =======================================================
window.addEventListener("DOMContentLoaded", function () {
    loadLiveData();
    rotate();

    setInterval(loadLiveData, 15000);
    setInterval(updateClock, 1000);
});

//#endregion
