// 🔐 Session & Login Handling — login.js

// 🔎 Cookie Helper
function getCookie(name) {
  const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
  return match ? match[2] : null;
}
// 🚪 Logout Function
function logoutUser() {
  // 🍪 Pre-fill Username from Cookie BEFORE wiping it
  const savedUser = getCookie('skyelogin_user');
  const usernameInput = document.querySelector('[name="username"]');
  if (savedUser && usernameInput) usernameInput.value = savedUser;
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
  //  🖼️ Reset UI 
  if (loginWrapper) loginWrapper.style.display = "flex";
  // Reset login form
  if (loginForm) {
    loginForm.style.display = "";
    const passwordInput = loginForm.querySelector('[name="password"]');
    if (passwordInput) passwordInput.value = "";
  }
  // Hide dashboard UI
  if (dashboard) dashboard.style.display = "none";
  if (newsUpdates) newsUpdates.style.display = "none";
  if (projectSummary) projectSummary.style.display = "none";
  // Update header
  if (pageHeader) pageHeader.textContent = "🔒 User Log In";
  // ⏳ Auto-close Skyebot modal
  setTimeout(() => {
    const modal = document.getElementById("skyebotModal");
    if (modal) modal.style.display = "none";
    document.body.classList.remove("modal-open");

    // 🧹 Clear chat and reset message
    const chatLog = document.getElementById("chatLog");
    const promptInput = document.getElementById("promptInput");
    if (chatLog) chatLog.innerHTML = "";
    if (promptInput) promptInput.value = "";
    if (chatLog) {
      const welcome = document.createElement("div");
      welcome.className = "chat-entry bot-message";
      const time = new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
      welcome.innerHTML = `<span>🤖 Skyebot [${time}]: Hello! How can I assist you today?</span>`;
      chatLog.appendChild(welcome);
    }
  }, 2000);
  // 🖼️ Console Log
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
    // 🔑 Form Submit Logic — Loads users from skyesoft-data.json!
    loginForm?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const username = usernameInput.value.trim();
      const password = passwordInput.value.trim();

      try {
        // Fetch user list (update path if needed)
        const userData = await fetch('/skyesoft-data.json').then(r => r.json());
        // Find a match by email and password
        const match = userData.contacts.find(
          c => c.email === username && c.password === password
        );
        // 🖼️ If match found, set session and update UI
        if (match) {
          localStorage.setItem('userLoggedIn', 'true');
          localStorage.setItem('userId', match.id); // <— save userId for chat history etc.

          // 🔎 Debug: Log before setting cookie
          console.log("Setting cookie with value:", username);

          // 🖼️ Assign Cookie For local dev or non-SSL hosting
          document.cookie = `skyelogin_user=${username}; path=/; max-age=604800; SameSite=Lax`;

          // 🔎 Debug: Read cookie immediately after set
          console.log("Cookie after set:", document.cookie);

          // 🖼️ Update UI
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
      } catch (err) {
        loginError.textContent = '❌ Login failed: user data unavailable.';
        loginError.style.display = 'block';
        console.error('Login error:', err);
      }
    });
  });