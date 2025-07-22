// 📁 File: assets/js/login.js
// Minimal login script for Skyesoft
document.addEventListener('DOMContentLoaded', () => {
  const loginForm = document.querySelector('.login-form');
  const usernameInput = loginForm?.querySelector('[name="username"]');

  if (!loginForm || !usernameInput) {
    console.error('Minimal login: Form or input not found.');
    return;
  }

  loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const username = usernameInput.value.trim();

    // 1. Fetch the contacts JSON
    try {
      // Fetch the JSON data from the specified path
      const data = await fetch('assets/data/skyesoft-data.json').then(r => r.json());
      // 2. Find the contact by email (case-insensitive)
      const match = data.contacts.find(
        c => c.email.toLowerCase() === username.toLowerCase()
      );

      if (match) {
        // 3. Set cookie and userId in localStorage
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
