//#region UI Helpers
// ====================================================================
// Skyesoft OfficeBoard — UI Utilities
// ====================================================================

var UI = {};

UI.safeSet = function (id, value) {
    var el = document.getElementById(id);
    if (el) { el.innerHTML = value; }
};

// =======================================================
// Build permit table rows
// =======================================================
UI.renderPermits = function (json) {
    var tbody = document.getElementById("permitTableBody");
    if (!tbody) return;

    if (!json || !json.activePermits) {
        tbody.innerHTML = '<tr><td colspan="6">No permit data</td></tr>';
        return;
    }

    var out = "";
    for (var i = 0; i < json.activePermits.length; i++) {
        var p = json.activePermits[i];
        out +=
          '<tr>' +
            '<td>' + p.wo + '</td>' +
            '<td>' + p.customer + '</td>' +
            '<td>' + p.jobsite + '</td>' +
            '<td>' + p.jurisdiction + '</td>' +
            '<td>$' + p.fee.toFixed(2) + '</td>' +
            '<td class="' + (p.status.indexOf("Review") >= 0 ? "status-review" : "status-ready") + '">' +
              p.status +
            '</td>' +
          '</tr>';
    }

    tbody.innerHTML = out;
};

// =======================================================
// Measure scroll distance (dynamic row count support)
// =======================================================
UI.getScrollMetrics = function () {
    var container = document.querySelector(".scrollContainer");
    if (!container) return { dist: 0, rows: 0 };

    var dist = container.scrollHeight - container.clientHeight;
    if (dist < 0) dist = 0;

    var rows = container.querySelectorAll("tbody tr").length;

    return { dist: dist, rows: rows };
};
//#endregion
