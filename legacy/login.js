// 📁 File: assets/js/login.js

// #region  Cookie Utility 🍪
// Get a cookie value by name
function getCookie(name) {
  const value = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
  return value ? value.pop() : '';
}
// #endregion

// #region 🔐 DOMContentLoaded: Login UI/Session Handler
document.addEventListener('DOMContentLoaded', () => {
  // #region 🏷️ Element References
  const loginWrapper   = document.querySelector('.login-wrapper');
  const loginForm      = document.querySelector('.login-form');
  const usernameInput  = loginForm?.querySelector('[name="username"]');
  const loginError     = document.getElementById('loginError');
  const dashboard      = document.getElementById('dashboardSection');
  const newsUpdates    = document.querySelector('.news-updates');
  const projectSummary = document.querySelector("#projectTable")?.closest(".board-panel");
  const header         = document.getElementById("bodyHeaderCopy");
  // #endregion

  // #region 🚦 UI Show/Hide Functions
  function showDashboard() {
    if (loginWrapper)   loginWrapper.style.display = "none";
    if (newsUpdates)    newsUpdates.style.display = "block";
    if (projectSummary) projectSummary.style.display = "block";
    if (dashboard)      dashboard.style.display = "block";
    if (header)         header.textContent = "📋 Project Dashboard";
  }
  function showLogin() {
    if (loginWrapper)   loginWrapper.style.display = "flex";
    if (newsUpdates)    newsUpdates.style.display = "none";
    if (projectSummary) projectSummary.style.display = "none";
    if (dashboard)      dashboard.style.display = "none";
    if (header)         header.textContent = "🔒 User Log In";
    if (loginError)     loginError.textContent = '';
  }
  // #endregion

  // #region 🧠 Initial UI State (on load)
  if (getCookie('skyelogin_user')) {
    showDashboard();
  } else {
    showLogin();
  }
  // #endregion

  // #region 📝 Login Form Handler
  loginForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const username = usernameInput.value.trim();
    try {
      // Fetch user data and validate username
      const data = await fetch('/home/notyou64/public_html/data/skyesoft-data.json').then(r => r.json());
      const contacts = Array.isArray(data.contacts) ? data.contacts : [];
      const match = contacts.find(
        c => c.contactEmail && c.contactEmail.toLowerCase() === username.toLowerCase()
      );
      if (match) {
        document.cookie = `skyelogin_user=${username}; path=/; max-age=604800; SameSite=Lax`;
        localStorage.setItem('userId', match.contactID);
        showDashboard();
        await fetch('/skyesoft/api/addAction.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            actionTypeID: 1,
            actionContactID: match.contactID,
            actionNote: "User logged in",
            actionTimestamp: new Date().toISOString(),
            actionLatitude: 33.45,
            actionLongitude: -112.07
          })
        });
      } else {
        if (loginError) loginError.textContent = '❌ Invalid username or password.';
        localStorage.removeItem('userId');
      }
    } catch (err) {
      if (loginError) loginError.textContent = '❌ Network or server error.';
      localStorage.removeItem('userId');
    }
  });
  // #endregion
});
// #endregion