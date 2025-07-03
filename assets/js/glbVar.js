// glbVar
const glbVar = {
  timeDate: {
    now: new Date()
  },
  isHoliday: false,
  isWeekend: false,
  workdayIntervals: {
    start: "07:30",
    end: "15:30"
  },
  intervalRemaining: "",
  version: "", // ✅ Will be populated dynamically
  weather: {
    temp: null,
    icon: "❓",
    description: "Loading..."
  },
  kpis: {
    contacts: 36,
    orders: 22,
    approvals: 3
  },
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
};
// ✅ Dynamically fetch and assign version to glbVar and .version display
fetch("https://notyou61.github.io/skyesoft/assets/data/version.json")
.then(res => res.json())
.then(data => {
  if (data.version) {
    glbVar.version = data.version;
    const versionElement = document.querySelector('.version');
    if (versionElement) versionElement.textContent = data.version;
  }
})
.catch(err => {
  console.error("Failed to load version:", err);
});
// Format the interval into a human-readable string
function formatInterval(prefix, seconds) {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;

  let parts = [];
  if (h > 0) parts.push(h + "h");
  if (m > 0 || h > 0) parts.push(m.toString().padStart(2, '0') + "m");
  parts.push(s.toString().padStart(2, '0') + "s");

  return `${prefix} ${parts.join(" ")}`;
}
// Update the DOM elements with glbVar data
function updateDOMFromGlbVar() {
  const now = glbVar.timeDate.now;

  const hours = now.getHours();
  const minutes = now.getMinutes().toString().padStart(2, '0');
  const seconds = now.getSeconds().toString().padStart(2, '0');
  const ampm = hours >= 12 ? 'PM' : 'AM';
  const standardHours = (hours % 12 || 12).toString().padStart(2, '0');

  const timeString = `${standardHours}:${minutes}:${seconds} ${ampm}`;
  const timeEl = document.getElementById("currentTime");
  if (timeEl) timeEl.textContent = timeString;

  const intervalEl = document.getElementById("intervalRemainingData");
  if (intervalEl) intervalEl.textContent = glbVar.intervalRemaining;

  const versionEl = document.querySelector(".version");
  if (versionEl) versionEl.textContent = glbVar.version;
}
// Main update loop (time display only, interval handled by workdayTicker.js)
setInterval(() => {
  const now = new Date();
  glbVar.timeDate.now = now;
  updateDOMFromGlbVar();
}, 1000);

