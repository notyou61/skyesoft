// 🔐 Session & Login Handling — login.js

// 🖼️ Modal Toggle Logic
function toggleModal() {
  const modal = document.getElementById('skyebotModal');
  modal.style.display = (modal.style.display === "none" || !modal.style.display) ? "flex" : "none";
}
// 🔎 Cookie Helper
function getCookie(name) {
  const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
  return match ? match[2] : null;
}

// 🚪 Logout Function
function logoutUser1() {
  console.log('DEBUG: logoutUser called', new Error().stack);
  // Pre-fill username before wiping
  const savedUser = getCookie('skyelogin_user');
  const usernameInput = document.querySelector('[name="username"]');
  if (savedUser && usernameInput) usernameInput.value = savedUser;
  // Clear user session
  localStorage.clear();
  document.cookie = "skyelogin_user=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
  // Reset UI as in your code...
  // Optionally: window.location.href = "/skyesoft/index.html";
}

// 🧠 Robust session checker for all auto-logout logic
function isTrulyLoggedOut() {
  return (
    !localStorage.getItem('userLoggedIn') &&
    !getCookie('skyelogin_user')
  );
}

// 🏠 DOMContentLoaded: Restore session or show login
document.addEventListener('DOMContentLoaded', () => {
  const loginForm = document.querySelector('.login-form');
  const loginError = document.getElementById('loginError');
  const usernameInput = loginForm?.querySelector('[name="username"]');
  const passwordInput = loginForm?.querySelector('[name="password"]');
  const pageHeader = document.getElementById('bodyHeaderCopy');
  const dashboard = document.getElementById('dashboardSection');

  // 🧠 Auto-Login Check: Accept session if EITHER is present
  const isLoggedIn =
    localStorage.getItem('userLoggedIn') === 'true' ||
    getCookie('skyelogin_user');
  if (isLoggedIn) {
    if (loginForm) loginForm.style.display = 'none';
    if (pageHeader) pageHeader.textContent = '📊 Skyesoft Dashboard';
    if (dashboard) dashboard.style.display = 'block';
    return;
  }

  // 🍪 Pre-fill Username from Cookie (optional: also support from localStorage)
  const savedUser = getCookie('skyelogin_user');
  if (savedUser && usernameInput) usernameInput.value = savedUser;

  // 🧼 Clear login error on input
  usernameInput?.addEventListener('input', () => loginError.textContent = '');
  passwordInput?.addEventListener('input', () => loginError.textContent = '');

  // 🔑 Form Submit Logic
  loginForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const username = usernameInput.value.trim();
    const password = passwordInput.value.trim();

    try {
      const userData = await fetch('/skyesoft-data.json').then(r => r.json());
      const match = userData.contacts.find(
        c => c.email === username && c.password === password
      );

      if (match) {
        localStorage.setItem('userLoggedIn', 'true');
        localStorage.setItem('userId', match.id);

        console.log("Setting cookie with value:", username);
        document.cookie = `skyelogin_user=${username}; path=/; max-age=604800; SameSite=Lax`;
        console.log("Cookie after set:", document.cookie);

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
