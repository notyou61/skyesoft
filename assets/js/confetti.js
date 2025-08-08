// 📁 File: assets/js/confetti.js
// Confetti Animation with Emojis

function showConfetti(count = 24, emojis = ["🎉", "✨", "🎊", "⭐", "💡", "🦄"]) {
  if (document.querySelectorAll('.skyebot-confetti-emoji').length > 60) return;
  const board = document.body;
  const width = window.innerWidth;
  const height = window.innerHeight;

  for (let i = 0; i < count; i++) {
    const emoji = emojis[Math.floor(Math.random() * emojis.length)];
    const span = document.createElement("span");
    span.textContent = emoji;
    span.className = "skyebot-confetti-emoji";
    span.style.left = (Math.random() * width) + "px";
    span.style.top = "-30px";
    span.style.setProperty('--confetti-distance', `${height * (0.7 + Math.random() * 0.3)}px`);
    span.style.fontSize = (1.3 + Math.random()) + "em";
    span.style.animationDelay = (Math.random() * 0.2) + "s";
    board.appendChild(span);
    setTimeout(() => { span.remove(); }, 2500);
  }
}
window.showConfetti = showConfetti;
