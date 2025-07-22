console.log("login.js loaded! (minimal cookie test)");

// Minimal DOMContentLoaded for the simplest test
document.addEventListener('DOMContentLoaded', () => {
  const loginForm = document.querySelector('.login-form');
  const usernameInput = loginForm?.querySelector('[name="username"]');

  if (!loginForm || !usernameInput) {
    console.error('Minimal login: Form or input not found.');
    return;
  }

  loginForm.addEventListener('submit', (e) => {
    e.preventDefault();
    const username = usernameInput.value.trim();
    document.cookie = `skyelogin_user=${username}; path=/; max-age=604800; SameSite=Lax`;
    alert(`Cookie set: skyelogin_user=${username}`);
    // Show result in console
    console.log("After submit, document.cookie:", document.cookie);
  });
});
