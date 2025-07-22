// 📁 File: assets/js/login.js
// Minimal login script for Skyesoft
document.addEventListener('DOMContentLoaded', () => {
  const loginForm = document.querySelector('.login-form');
  const usernameInput = loginForm?.querySelector('[name="username"]');

  if (!loginForm || !usernameInput) {
    console.error('Minimal login: Form or input not found.');
    return;
  }
  // Set the username input to the current user if available
  loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const username = usernameInput.value.trim();

    try {
      const data = await fetch('/skyesoft/assets/data/skyesoft-data.json').then(r => r.json());
      console.log("All contacts:", data.contacts);
      console.log("Entered username:", username);

      const match = data.contacts.find(
        c => c.email.toLowerCase() === username.toLowerCase()
      );

      if (match) {
        document.cookie = `skyelogin_user=${username}; path=/; max-age=604800; SameSite=Lax`;
        localStorage.setItem('userId', match.id);
        alert(`Cookie set! userId = ${match.id}`);
        console.log("Contact found:", match);
      } else {
        alert("No matching contact (email) found in JSON.");
        localStorage.removeItem('userId');
      }
    } catch (err) {
      alert("Could not load skyesoft-data.json");
      console.error("JSON load error:", err);
    }
  });
});
