/* #region SSE Engine */

window.serverData = null;
window.serverTimeOffset = 0;

window.updateVersionFooter = function(d) {
    try {
        if (!d.versions) return safeSet("versionFooter", "Version info unavailable");

        const v = d.versions;
        const vb = v.modules.officeBoard.version;
        const ap = v.modules.activePermits.version;
        const cx = v.codex.version;

        safeSet("versionFooter", `OfficeBoard ${vb} • ActivePermits ${ap} • Codex ${cx}`);
    } catch (e) {
        safeSet("versionFooter", "Version info unavailable");
    }
};

window.loadLiveData = function() {
    fetch("/skyesoft/api/getDynamicData.php")
        .then(r => r.json())
        .then(d => {
            serverData = d;

            updateVersionFooter(d);
            renderWeather(d);

            if (d?.timeDateArray?.currentUnixTime) {
                serverTimeOffset = (d.timeDateArray.currentUnixTime * 1000) - Date.now();
            }
        })
        .catch(() => {});
};

/* #endregion */
