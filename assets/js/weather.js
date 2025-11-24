/* #region Weather Engine */

window.renderWeather = function(d) {
    if (!d.weatherData) return;

    const w = d.weatherData;

    const icons = {
        "01":"☀️","02":"🌤️","03":"⛅","04":"☁️",
        "09":"🌦️","10":"🌧️","11":"⛈️","13":"❄️","50":"🌫️"
    };

    const em = icons[w.icon?.substring(0,2)] || "🌤️";

    safeSet("weatherDisplay", `${em} ${w.temp}°F — ${w.description}`);
    safeSet("sunriseTime",     w.sunrise);
    safeSet("sunsetTime",      w.sunset);
    safeSet("daylightTime",    w.daytimeHours);
    safeSet("nightTime",       w.nighttimeHours);

    if (Array.isArray(w.forecast)) {
        w.forecast.slice(0,3).forEach((day, i) => {
            const el = document.getElementById("forecastDay"+(i+1));
            if (!el) return;

            const emo = icons[day.icon?.substring(0,2)] || "🌤️";
            const desc = day.description[0].toUpperCase() + day.description.slice(1);

            el.innerHTML = `
              <strong>📅 ${day.date}</strong><br>
              ${emo} ${desc}<br>
              High ${day.high}°F / Low ${day.low}°F |
              💧 ${day.precip}% | 💨 ${day.wind} mph
            `;
        });
    }
};

/* #endregion */