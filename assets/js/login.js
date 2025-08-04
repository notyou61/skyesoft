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
      const data = await fetch('/skyesoft/assets/data/skyesoft-data.json').then(r => r.json());
      const match = data.contacts.find(
        c => c.email.toLowerCase() === username.toLowerCase()
      );
      if (match) {
        // Set Cookie
        document.cookie = `skyelogin_user=${username}; path=/; max-age=604800; SameSite=Lax`;
        // Set user ID in localStorage
        localStorage.setItem('userId', match.id);
        // Show dashboard and update UI
        showDashboard();
        // Fetch Add Action
       fetch('/api/addAction.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            actionTypeID: 1,
            actionContactID: match.id,            // Set this to the user/contact id
            actionNote: "User logged in",
            actionTimestamp: Date.now(),
            actionLatitude: 33.45,                // Use actual or fallback coords
            actionLongitude: -112.07
          })
        });

      } else {
        if (loginError) loginError.textContent = '❌ Invalid username or password.';
        localStorage.removeItem('userId');
      }
    } catch (err) {
      if (loginError) loginError.textContent = "❌ Could not load skyesoft-data.json";
      console.error("JSON load error:", err);
    }
  });
  // #endregion
});
// #endregion