// --- START: Dark Mode Fix ---
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", () => {
    const getStoredTheme = () => localStorage.getItem("theme") || "light";
    const setStoredTheme = (theme) => localStorage.setItem("theme", theme);
    const setTheme = (theme) =>
      document.documentElement.setAttribute("data-bs-theme", theme);

    const btn = document.getElementById("darkModeToggle");

    const updateUI = (currentTheme) => {
      document.querySelectorAll("#darkModeToggle").forEach((toggle) => {
        const icon = toggle.querySelector("i");
        if (icon) {
          if (currentTheme === "dark") {
            icon.classList.replace("bi-moon-stars-fill", "bi-sun-fill");
            toggle.title = "Switch to Light Mode";
          } else {
            icon.classList.replace("bi-sun-fill", "bi-moon-stars-fill");
            toggle.title = "Switch to Dark Mode";
          }
        }
      });
    };

    updateUI(getStoredTheme());

    document.addEventListener("click", (e) => {
      const toggle = e.target.closest("#darkModeToggle");
      if (!toggle) return;

      const next =
        document.documentElement.getAttribute("data-bs-theme") === "dark"
          ? "light"
          : "dark";
      setTheme(next);
      setStoredTheme(next);
      updateUI(next);
    });
  });
})();
// --- END: Dark Mode Fix ---
