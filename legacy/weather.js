// 📁 File: assets/js/weather.js

// #region 🌐 Config & Refresh Settings

// 🔁 Refresh interval in milliseconds (15 minutes)
const WEATHER_REFRESH_INTERVAL = 15 * 60 * 1000;

// #endregion

// #region 🌦️ Weather Fetch & Display

// ⛅ Main function to fetch weather data and update display
async function updateWeather() {
  try {
    // 🌐 Build API URL using global location and key
    const url = `https://api.openweathermap.org/data/2.5/weather?q=${window.weatherLocation}&appid=${window.weatherApiKey}&units=imperial`;
    const res = await fetch(url);
    const data = await res.json();

    // 🌡️ Get temperature and description
    const temp = Math.round(data.main.temp);
    const desc = data.weather[0].main.toLowerCase();

    // 🌈 Match description to icon
    let icon = "❓"; // Default (unknown)
    if (desc.includes("clear")) icon = "☀️";
    else if (desc.includes("cloud")) icon = "☁️";
    else if (desc.includes("rain")) icon = "🌧️";
    else if (desc.includes("storm")) icon = "⛈️";
    else if (desc.includes("snow")) icon = "❄️";
    else if (desc.includes("fog") || desc.includes("mist")) icon = "🌫️";

    // 🖥️ Update DOM display
    const text = `${icon} ${data.weather[0].description} | ${temp}°F`;
    document.getElementById("weatherDisplay").textContent = text;

    // 🌅 Convert and store sunrise/sunset timestamps
    window.sunriseTime = convertTimestamp(data.sys.sunrise);
    window.sunsetTime = convertTimestamp(data.sys.sunset);

  } catch (err) {
    // ⚠️ Show fallback message on failure
    document.getElementById("weatherDisplay").textContent = "⚠️ Weather unavailable";
  }
}

// #endregion

// #region 🕒 Time Conversion Utility

// Converts UNIX timestamp to 12-hour AM/PM format
function convertTimestamp(timestamp) {
  const date = new Date(timestamp * 1000);
  let hours = date.getHours();
  let minutes = date.getMinutes();

  hours = hours % 12 || 12; // Convert 0 to 12
  minutes = minutes < 10 ? '0' + minutes : minutes;

  const ampm = date.getHours() >= 12 ? 'PM' : 'AM';
  return `${hours}:${minutes} ${ampm}`;
}

// #endregion

// #region 🚀 Initialize Weather Module

// 🔄 Load weather data on page load
updateWeather();

// 🔁 Set interval to refresh periodically
setInterval(updateWeather, WEATHER_REFRESH_INTERVAL);

// #endregion
