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
  version: "v2025.05.02.2014",
  weather: {
    temp: null,
    icon: "❓",
    description: "Loading..."
  },
  kpis: {
    contacts: 36,
    orders: 22,
    approvals: 3
  }
};

function calculateIntervalRemaining(now) {
  const day = now.getDay();
  const isWeekend = (day === 0 || day === 6);
  const isHoliday = false;

  glbVar.isWeekend = isWeekend;
  glbVar.isHoliday = isHoliday;

  const startParts = glbVar.workdayIntervals.start.split(":");
  const endParts = glbVar.workdayIntervals.end.split(":");

  const start = new Date(now);
  start.setHours(startParts[0], startParts[1], 0, 0);

  const end = new Date(now);
  end.setHours(endParts[0], endParts[1], 0, 0);

  let prefix = "";
  let seconds = 0;

  if (isHoliday) {
    return "Enjoy the holiday!";
  } else if (isWeekend) {
    return "Enjoy your weekend!";
  } else if (now < start) {
    seconds = Math.ceil((start - now) / 1000);
    prefix = "Workday begins in";
  } else if (now >= start && now <= end) {
    seconds = Math.ceil((end - now) / 1000);
    prefix = "Workday ends in";
  } else {
    const tomorrowStart = new Date(start);
    tomorrowStart.setDate(now.getDate() + 1);
    seconds = Math.ceil((tomorrowStart - now) / 1000);
    prefix = "Next workday starts in";
  }

  return formatInterval(prefix, seconds);
}

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

function updateDOMFromGlbVar() {
  const now = glbVar.timeDate.now;

  // Time formatting
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

// Main update loop
setInterval(() => {
  const now = new Date();
  glbVar.timeDate.now = now;
  glbVar.intervalRemaining = calculateIntervalRemaining(now);
  updateDOMFromGlbVar();
}, 1000);

// forced update
