// --- START: Dark Mode Fix ---
(function () {
  "use strict";

  // 1. Apply theme immediately to prevent "white flash" on page load
  const theme =
    localStorage.getItem("theme") ||
    (window.matchMedia("(prefers-color-scheme: dark)").matches
      ? "dark"
      : "light");
  document.documentElement.setAttribute("data-bs-theme", theme);

  // 2. Attach toggle logic after DOM is ready
  document.addEventListener("DOMContentLoaded", () => {
    const btn = document.getElementById("darkModeToggle");
    if (!btn) return;

    const updateUI = (currentTheme) => {
      const icon = btn.querySelector("i");
      if (icon) {
        if (currentTheme === "dark") {
          icon.classList.replace("bi-moon-stars-fill", "bi-sun-fill");
          btn.title = "Switch to Light Mode";
        } else {
          icon.classList.replace("bi-sun-fill", "bi-moon-stars-fill");
          btn.title = "Switch to Dark Mode";
        }
      }
    };

    // Sync UI icon on load
    updateUI(theme);

    btn.addEventListener("click", () => {
      const next =
        document.documentElement.getAttribute("data-bs-theme") === "dark"
          ? "light"
          : "dark";
      document.documentElement.setAttribute("data-bs-theme", next);
      localStorage.setItem("theme", next);
      updateUI(next);
    });
  });
})();
// --- END: Dark Mode Fix ---
