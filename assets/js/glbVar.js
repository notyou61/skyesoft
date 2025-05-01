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

function updateGlbVar(now) {
  const day = now.getDay();
  glbVar.isWeekend = (day === 0 || day === 6);
  glbVar.isHoliday = false;

  const startParts = glbVar.workdayIntervals.start.split(":");
  const endParts = glbVar.workdayIntervals.end.split(":");
  const start = new Date(now); start.setHours(startParts[0], startParts[1], 0, 0);
  const end = new Date(now); end.setHours(endParts[0], endParts[1], 0, 0);

  let msg = "";
  if (glbVar.isHoliday) {
    msg = "Enjoy the holiday!";
  } else if (glbVar.isWeekend) {
    msg = "Enjoy your weekend!";
  } else if (now < start) {
    let remaining = Math.ceil((start - now) / 1000);
    msg = formatInterval("Workday begins in", remaining);
  } else if (now >= start && now <= end) {
    let remaining = Math.ceil((end - now) / 1000);
    msg = formatInterval("Workday ends in", remaining);
  } else {
    const tomorrowStart = new Date(start);
    tomorrowStart.setDate(now.getDate() + 1);
    let remaining = Math.ceil((tomorrowStart - now) / 1000);
    msg = formatInterval("Next workday starts in", remaining);
  }

  glbVar.intervalRemaining = msg;
}

function formatInterval(prefix, seconds) {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;

  let parts = [];
  if (h > 0) parts.push(h + "h");
  if (m > 0 || h > 0) parts.push(m + "m");
  parts.push(s + "s");

  return `${prefix} ${parts.join(" ")}`;
}

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
}

// Main update loop — single timestamp
setInterval(() => {
  const now = new Date();
  glbVar.timeDate.now = now;
  updateGlbVar(now);
  updateDOMFromGlbVar();
}, 1000);