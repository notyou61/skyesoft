/* #region Card Definitions */

window.SKYECARDS = [

  {
    id: "activePermits",
    duration: 30000,
    header: `<h2>📋 Active Permits</h2>`,
    body: `
      <div class="permitCard">
        <div class="scrollContainer">
          <table class="permitTable">
            <thead>
              <tr>
                <th>WO #</th><th>Customer</th><th>Jobsite</th>
                <th>Jurisdiction</th><th>Fee</th><th>Status</th>
              </tr>
            </thead>
            <tbody id="permitTableBody"><tr><td colspan="6">Loading...</td></tr></tbody>
          </table>
        </div>
      </div>
    `,
    footer: `<small>Active Permits</small>`
  },

  {
    id: "todaysHighlights",
    duration: 30000,
    header: `<h2>🌅 Today’s Highlights</h2>`,
    body: `
      <div class="entry">📅 <span id="todaysDate"></span> · Day <span id="dayOfYear"></span> of 365 (<span id="daysRemaining"></span> remaining)</div>
      <div class="entry">🌄 Sunrise: <span id="sunriseTime">--</span> · 🌇 Sunset: <span id="sunsetTime">--</span></div>
      <div class="entry">🕒 Daylight: <span id="daylightTime">--</span> · 🌌 Night: <span id="nightTime">--</span></div>
      <div class="entry">Next Holiday: <span id="nextHoliday">Loading...</span></div>

      <hr style="margin:12px 0;border:none;border-top:1px solid #ccc">

      <div class="entry" id="tipOfTheDay">💡 Tip of the Day: Loading...</div>

      <hr>

      <div class="forecastRow">
        <div class="forecastItem"><span id="forecastDay1">Loading...</span></div>
        <div class="forecastItem"><span id="forecastDay2">Loading...</span></div>
        <div class="forecastItem"><span id="forecastDay3">Loading...</span></div>
      </div>
    `,
    footer: `<small>Live Data</small>`
  },

  {
    id: "kpiDashboard",
    duration: 30000,
    header: `<h2>📈 KPI Dashboard</h2>`,
    body: `<div class="entry">Coming Soon...</div>`,
    footer: `<small>Updated Daily</small>`
  },

  {
    id: "announcements",
    duration: 30000,
    header: `<h2>📢 Announcements</h2>`,
    body: `<div class="entry">No announcements posted.</div>`,
    footer: `<small>Company-Wide</small>`
  }

];

/* #endregion */