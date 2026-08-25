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
    // Add a smooth transition class to the body and all interactive elements
    const transitionElements = [
      document.body,
      ...document.querySelectorAll(
        ".card, .navbar, .alert, .modal, .dropdown-menu, .btn, input, textarea, select",
      ),
    ];

    transitionElements.forEach((el) => {
      el.style.transition =
        "background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease";
    });
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

      // Add a subtle animation to the button
      btn.style.transform = "rotate(180deg)";
      setTimeout(() => {
        btn.style.transform = "rotate(0deg)";
      }, 300);
    });

    // Add transition to button
    btn.style.transition = "transform 0.4s ease";
  });
})();
