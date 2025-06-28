// 🔐 Session & Login Handling — login.js

// 🔎 Cookie Helper
function getCookie(name) {
  const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
  return match ? match[2] : null;
}
// 🚪 Logout Function
function logoutUser() {
  // Clear user session
  localStorage.clear();
  document.cookie = "skyelogin_user=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
  // Reset DOM state
  const loginWrapper = document.querySelector(".login-wrapper");
  const loginForm = document.querySelector(".login-form");
  const dashboard = document.getElementById("dashboardSection");
  const pageHeader = document.getElementById("bodyHeaderCopy");
  const newsUpdates = document.querySelector(".news-updates");
  const projectSummary = document.querySelector("#projectTable")?.closest(".board-panel");
  // Show login UI
  if (loginWrapper) loginWrapper.style.display = "flex";
  if (loginForm) {
    // Reset login form
    loginForm.reset();
    // Hide login error
    loginForm.style.display = ""; // ✅ This removes "display: none" safely
  }
  // Hide dashboard UI
  if (dashboard) dashboard.style.display = "none";
  if (newsUpdates) newsUpdates.style.display = "none";
  if (projectSummary) projectSummary.style.display = "none";
  // Update header
  if (pageHeader) pageHeader.textContent = "🔒 User Log In";
  // ⏳ Auto-close Skyebot modal after logout
  setTimeout(() => {
    const modal = document.getElementById("skyebotModal");
    if (modal) modal.style.display = "none";
    document.body.classList.remove("modal-open");
  }, 2000);
  // 🍪 Pre-fill Username from Cookie
  const usernameInput = document.querySelector('[name="username"]');
  const savedUser = getCookie('skyelogin_user');
  if (savedUser && usernameInput) usernameInput.value = savedUser;
  // Console Log
  console.log("👋 User logged out successfully.");
}
// 🖼️ Modal Toggle Logic
function toggleModal() {
  const modal = document.getElementById('skyebotModal');
  modal.style.display = (modal.style.display === "none" || !modal.style.display) ? "flex" : "none";
}
// 🏠 Redirect to Login
document.addEventListener('DOMContentLoaded', () => {
  const loginForm = document.querySelector('.login-form');
  const loginError = document.getElementById('loginError');
  const usernameInput = loginForm?.querySelector('[name="username"]');
  const passwordInput = loginForm?.querySelector('[name="password"]');
  const pageHeader = document.getElementById('bodyHeaderCopy');
  const dashboard = document.getElementById('dashboardSection');
  // 🧠 Auto-Login Check
  if (localStorage.getItem('userLoggedIn') === 'true') {
    if (loginForm) loginForm.style.display = 'none';
    if (pageHeader) pageHeader.textContent = '📊 Skyesoft Dashboard';
    if (dashboard) dashboard.style.display = 'block';
    return;
  }
  // 🍪 Pre-fill Username from Cookie
  const savedUser = getCookie('skyelogin_user');
  if (savedUser && usernameInput) usernameInput.value = savedUser;
  // 🧼 Clear login error
  usernameInput?.addEventListener('input', () => loginError.textContent = '');
  passwordInput?.addEventListener('input', () => loginError.textContent = '');
  // 🔑 Form Submit Logic
  loginForm?.addEventListener('submit', (e) => {
    e.preventDefault();
    const username = usernameInput.value.trim();
    const password = passwordInput.value.trim();

    if (username === 'admin' && password === 'skyelogin') {
      localStorage.setItem('userLoggedIn', 'true');
      document.cookie = `skyelogin_user=${username}; path=/; max-age=604800`; // 7 days

      loginForm.style.display = 'none';
      loginError.textContent = '';
      loginError.style.display = 'none';

      pageHeader.textContent = '📊 Skyesoft Dashboard';
      if (dashboard) dashboard.style.display = 'block';
    } else {
      loginError.textContent = '❌ Invalid username or password.';
      loginError.style.display = 'block';
      loginForm.reset();
    }
  });
});