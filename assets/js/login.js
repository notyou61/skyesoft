// 📁 File: assets/js/login.js
// Skyesoft MTCO: login.js - Cookie/session dashboard control
// This script manages the login state of the user, showing the dashboard or login form
function getCookie(name) {
  const value = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
  return value ? value.pop() : '';
}
// Function to set a cookie
document.addEventListener('DOMContentLoaded', () => {
  const loginWrapper = document.querySelector('.login-wrapper');
  const loginForm = document.querySelector('.login-form');
  const usernameInput = loginForm?.querySelector('[name="username"]');
  const loginError = document.getElementById('loginError');
  const dashboard = document.getElementById('dashboardSection');
  const newsUpdates = document.querySelector('.news-updates');
  const projectSummary = document.querySelector("#projectTable")?.closest(".board-panel");
  const header = document.getElementById("bodyHeaderCopy");

  // --- Toggle UI based on cookie presence ---
  function showDashboard() {
    if (loginWrapper) loginWrapper.style.display = "none";
    if (newsUpdates) newsUpdates.style.display = "block";
    if (projectSummary) projectSummary.style.display = "block";
    if (dashboard) dashboard.style.display = "block";
    if (header) header.textContent = "📋 Project Dashboard";
  }
  function showLogin() {
    if (loginWrapper) loginWrapper.style.display = "flex";
    if (newsUpdates) newsUpdates.style.display = "none";
    if (projectSummary) projectSummary.style.display = "none";
    if (dashboard) dashboard.style.display = "none";
    if (header) header.textContent = "🔒 User Log In";
    if (loginError) loginError.textContent = '';
  }

  if (getCookie('skyelogin_user')) {
    showDashboard();
  } else {
    showLogin();
  }

  // --- Login form submit: set cookie and userId ---
  loginForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const username = usernameInput.value.trim();

    try {
      const data = await fetch('/skyesoft/assets/data/skyesoft-data.json').then(r => r.json());
      const match = data.contacts.find(
        c => c.email.toLowerCase() === username.toLowerCase()
      );

      if (match) {
        document.cookie = `skyelogin_user=${username}; path=/; max-age=604800; SameSite=Lax`;
        localStorage.setItem('userId', match.id);
        // On login, show dashboard immediately
        showDashboard();
      } else {
        if (loginError) loginError.textContent = '❌ Invalid username or password.';
        localStorage.removeItem('userId');
      }
    } catch (err) {
      if (loginError) loginError.textContent = "❌ Could not load skyesoft-data.json";
      console.error("JSON load error:", err);
    }
  });
});
