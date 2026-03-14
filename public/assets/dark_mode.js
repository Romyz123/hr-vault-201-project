(function () {
  // 1. Apply theme immediately to avoid flash of unstyled content
  const getStoredTheme = () => localStorage.getItem("theme");
  const getPreferredTheme = () => {
    const stored = getStoredTheme();
    if (stored) return stored;
    return window.matchMedia("(prefers-color-scheme: dark)").matches
      ? "dark"
      : "light";
  };

  const setTheme = (theme) => {
    document.documentElement.setAttribute("data-bs-theme", theme);
  };

  setTheme(getPreferredTheme());

  // 2. Setup Toggle Button (after DOM load)
  document.addEventListener("DOMContentLoaded", () => {
    const btn = document.getElementById("darkModeToggle");
    if (!btn) return;

    const icon = btn.querySelector("i");
    if (!icon) return;

    const updateIcon = (theme) => {
      if (theme === "dark") {
        icon.classList.remove("bi-moon-stars-fill");
        icon.classList.add("bi-sun-fill");
        btn.title = "Switch to Light Mode";
      } else {
        icon.classList.remove("bi-sun-fill");
        icon.classList.add("bi-moon-stars-fill");
        btn.title = "Switch to Dark Mode";
      }
    };
    updateIcon(document.documentElement.getAttribute("data-bs-theme"));

    btn.addEventListener("click", () => {
      const current = document.documentElement.getAttribute("data-bs-theme");
      const next = current === "dark" ? "light" : "dark";
      setTheme(next);
      localStorage.setItem("theme", next);
      updateIcon(next);
    });
  });
})();
