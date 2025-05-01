async function updateWeather() {
  try {
    const url = `https://api.openweathermap.org/data/2.5/weather?q=${weatherLocation}&appid=${weatherApiKey}&units=imperial`;
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
  } catch (err) {
    document.getElementById("weatherDisplay").textContent = "⚠️ Weather unavailable";
  }
}
updateWeather();