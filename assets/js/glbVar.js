// 📁 File: glbVar.js

// #region 🌐 Global Variable Object for Skyesoft
const glbVar = {
  // #region 🕒 Time & Workday Data
  timeDate: {
    currentLocalTime: "", // Store preformatted time string from SSE
    currentDate: "", // Optionally store date from SSE
    now: new Date() // Fallback, kept for compatibility
  },
  isHoliday: false,
  isWeekend: false,
  workdayIntervals: {
    start: "07:30", // 🔔 Start of workday
    end: "15:30" // 🖚 End of workday
  },
  intervalRemaining: "", // ⏳ Text shown for remaining work interval
  // #endregion

  // #region 🏽 Site Info
  version: "", // ✅ Populated dynamically from JSON
  // #endregion

  // #region 🌦️ Weather Data
  weather: {
    temp: null,
    icon: "❓",
    description: "Loading..."
  },
  // #endregion

  // #region 📊 KPIs
  kpis: {
    contacts: 36,
    orders: 22,
    approvals: 3
  },
  // #endregion

  // #region 💡 Tips and Quotes
  tips: [
    "Measure twice, cut once.",
    "Stay positive, work hard, make it happen.",
    "Quality is never an accident.",
    "Efficiency is doing better what is already being done.",
    "Every day is a fresh start.",
    "Take small steps every day toward big goals.",
    "Be Proactive – Take responsibility for your actions.",
    "Begin with the End in Mind – Define clear goals.",
    "Put First Things First – Prioritize what matters most.",
    "Think Win-Win – Seek mutually beneficial solutions.",
    "Seek First to Understand, Then to Be Understood – Practice empathetic listening.",
    "Synergize – Value teamwork and collaboration.",
    "Sharpen the Saw – Invest in continuous personal growth."
  ]
  // #endregion
};
// #endregion

// #region ♻️ Dynamic Version Assignment
fetch("https://notyou61.github.io/skyesoft/assets/data/version.json")
  .then(res => res.json())
  .then(data => {
    if (data.version) {
      glbVar.version = data.version;
      const versionElement = document.querySelector('.version');
      if (versionElement) versionElement.textContent = data.version;
    }
  })
  .catch(err => console.error("Failed to load version:", err));
// #endregion

// #region ⏱️ Format Duration Helper
function formatInterval(prefix, seconds) {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;
  const parts = [];

  if (h > 0) parts.push(h + "h");
  if (m > 0 || h > 0) parts.push(m.toString().padStart(2, '0') + "m");
  parts.push(s.toString().padStart(2, '0') + "s");

  return `${prefix} ${parts.join(" ")}`;
}
// #endregion

// #region 📱 SSE Connection for Real-Time Data
function setupSSE() {
  const source = new EventSource("/.netlify/functions/getDynamicData");

  source.onmessage = function (event) {
    try {
      const data = JSON.parse(event.data);
      //
      console.log("⏳ SSE Data Received:", data); // ✅ Check this!
      //
      if (data.timeDateArray) {
        // Update glbVar with SSE data
        glbVar.timeDate.currentLocalTime = data.timeDateArray.currentLocalTime;
        //
        console.log("🕒 Stored in glbVar:", glbVar.timeDate.currentLocalTime);
        //
        glbVar.timeDate.currentDate = data.timeDateArray.currentDate;
        //
        glbVar.timeDate.now = new Date(data.timeDateArray.currentUnixTime * 1000); // Fallback
        //
        updateDOMFromGlbVar(); // Update DOM immediately
      }
    } catch (err) {
      console.error("Error parsing SSE data:", err);
    }
  };

  source.onerror = function () {
    console.error("SSE connection error. Attempting to reconnect...");
    // EventSource automatically attempts reconnection
  };
}
// #endregion

// #region 🔁 DOM Update from glbVar
function updateDOMFromGlbVar() {
  // ⏰ Time (use preformatted currentLocalTime from SSE)
  const timeEl = document.getElementById("currentTime");
  if (timeEl && glbVar.timeDate.currentLocalTime) {
    timeEl.textContent = glbVar.timeDate.currentLocalTime;
  } else if (timeEl) {
    // Fallback: reconstruct time if SSE data is unavailable
    const now = glbVar.timeDate.now;
    const hours = now.getHours();
    const minutes = now.getMinutes().toString().padStart(2, '0');
    const seconds = now.getSeconds().toString().padStart(2, '0');
    const ampm = hours >= 12 ? 'PM' : 'AM';
    const standardHours = (hours % 12 || 12).toString().padStart(2, '0');
    timeEl.textContent = `${standardHours}:${minutes}:${seconds} ${ampm}`;
  }

  // ⏳ Interval Remaining
  const intervalEl = document.getElementById("intervalRemainingData");
  if (intervalEl) intervalEl.textContent = glbVar.intervalRemaining;

  // 🏽 Version
  const versionEl = document.querySelector(".version");
  if (versionEl) versionEl.textContent = glbVar.version;
}
// #endregion

// #region 🚀 Initialize SSE
setupSSE();
// #endregion
