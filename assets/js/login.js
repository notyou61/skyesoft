// 🔐 Session & Login Handling — login.js

// 🔎 Cookie Helper
function getCookie(name) {
  const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
  return match ? match[2] : null;
}

// 🚪 Logout Function
function logoutUser() {
  localStorage.clear();
  document.cookie = "skyelogin_user=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
  location.reload();
}

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
    if (dashboard) {
  fetch("components/dashboard.html")
    .then(res => res.text())
    .then(html => {
      dashboard.innerHTML = html;
      dashboard.style.display = 'block';
    })
    .catch(err => {
      dashboard.innerHTML = "<p>⚠️ Failed to load dashboard content.</p>";
      dashboard.style.display = 'block';
      console.error("Dashboard load error:", err);
    });
}

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
      if (dashboard) {
  fetch("components/dashboard.html")
    .then(res => res.text())
    .then(html => {
      dashboard.innerHTML = html;
      dashboard.style.display = 'block';
    })
    .catch(err => {
      dashboard.innerHTML = "<p>⚠️ Failed to load dashboard content.</p>";
      dashboard.style.display = 'block';
      console.error("Dashboard load error:", err);
    });
}

    } else {
      loginError.textContent = '❌ Invalid username or password.';
      loginError.style.display = 'block';
      loginForm.reset();
    }
  });
});