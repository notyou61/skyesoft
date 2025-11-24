/* ============================================================
   #region WEATHER RENDERING — FULL LEGACY RESTORE
============================================================ */
const weatherIcons = {
  "01": "☀️",
  "02": "🌤️",
  "03": "⛅",
  "04": "☁️",
  "09": "🌦️",
  "10": "🌧️",
  "11": "⛈️",
  "13": "❄️",
  "50": "🌫️"
};

function renderWeatherBlock(w) {
  const i = weatherIcons[w.icon?.substring(0,2)] || "🌤️";
  safeSet("weatherDisplay", `${i} ${w.temp}°F — ${w.description}`);

  safeSet("sunriseTime", w.sunrise);
  safeSet("sunsetTime", w.sunset);
  safeSet("daylightTime", w.daytimeHours);
  safeSet("nightTime", w.nighttimeHours);

  if (Array.isArray(w.forecast)) {
    w.forecast.slice(0,3).forEach((d, idx) => {
      const el = document.getElementById("forecastDay" + (idx + 1));
      if (!el) return;

      const e = weatherIcons[d.icon?.substring(0,2)] || "🌤️";
      const t = d.description[0].toUpperCase() + d.description.slice(1);

      el.innerHTML = `
        <strong>📅 ${d.date}</strong><br>
        ${e} ${t}<br>
        High ${d.high}°F / Low ${d.low}°F |
        💧 ${d.precip}% | 💨 ${d.wind} mph
      `;
    });
  }
}
/* #endregion */
