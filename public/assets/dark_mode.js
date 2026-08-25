// --- START: UI REPAIR ---
(function () {
  // 1. Apply theme IMMEDIATELY to documentElement to prevent white flash
  const theme =
    localStorage.getItem("theme") ||
    (window.matchMedia("(prefers-color-scheme: dark)").matches
      ? "dark"
      : "light");

  document.documentElement.setAttribute("data-bs-theme", theme);

  // 2. Setup Toggle Button logic
  document.addEventListener("DOMContentLoaded", () => {
    const btn = document.getElementById("darkModeToggle");
    if (!btn) return;

    const updateUI = (currentTheme) => {
      const icon = btn.querySelector("i");
      if (!icon) return;
      if (currentTheme === "dark") {
        icon.className = "bi bi-sun-fill";
        btn.title = "Switch to Light Mode";
      } else {
        icon.className = "bi bi-moon-stars-fill";
        btn.title = "Switch to Dark Mode";
      }
    };

    // Sync UI on load
    updateUI(document.documentElement.getAttribute("data-bs-theme"));

    btn.addEventListener("click", () => {
      const current = document.documentElement.getAttribute("data-bs-theme");
      const next = current === "dark" ? "light" : "dark";

      document.documentElement.setAttribute("data-bs-theme", next);
      localStorage.setItem("theme", next);
      updateUI(next);
    });
  });
})();
// --- END: UI REPAIR ---
