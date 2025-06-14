// #region Weather Update
async function updateWeather() {
  try {
    const url = `https://api.openweathermap.org/data/2.5/weather?q=${window.weatherLocation}&appid=${window.weatherApiKey}&units=imperial`;
    const res = await fetch(url);
    const data = await res.json();
    const temp = Math.round(data.main.temp);
    const desc = data.weather[0].main.toLowerCase();

    let icon = "❓";
    if (desc.includes("clear")) icon = "☀️";
    else if (desc.includes("cloud")) icon = "☁️";
    else if (desc.includes("rain")) icon = "🌧️";
    else if (desc.includes("storm")) icon = "⛈️";
    else if (desc.includes("snow")) icon = "❄️";
    else if (desc.includes("fog") || desc.includes("mist")) icon = "🌫️";

    const text = `${icon} ${data.weather[0].description} | ${temp}°F`;
    document.getElementById("weatherDisplay").textContent = text;

    // Get sunrise and sunset
    window.sunriseTime = convertTimestamp(data.sys.sunrise);
    window.sunsetTime = convertTimestamp(data.sys.sunset);

  } catch (err) {
    document.getElementById("weatherDisplay").textContent = "⚠️ Weather unavailable";
  }
}

function convertTimestamp(timestamp) {
  const date = new Date(timestamp * 1000);
  let hours = date.getHours();
  let minutes = date.getMinutes();

  hours = hours % 12;
  hours = hours ? hours : 12;
  minutes = minutes < 10 ? '0' + minutes : minutes;

  const ampm = date.getHours() >= 12 ? 'PM' : 'AM';

  return hours + ':' + minutes + ' ' + ampm;
}

// Initial load
updateWeather();

// Refresh every 15 minutes (900,000 ms)
setInterval(updateWeather, 15 * 60 * 1000);
// #endregion
