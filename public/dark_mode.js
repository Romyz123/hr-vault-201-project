/**
 * dark_mode.js
 * Handles theme switching and persistence for TESP HR 201 System
 */

(function () {
  "use strict";

  const getStoredTheme = () => localStorage.getItem("theme");
  const setStoredTheme = (theme) => localStorage.setItem("theme", theme);

  const getPreferredTheme = () => {
    const storedTheme = getStoredTheme();
    if (storedTheme) return storedTheme;
    return window.matchMedia("(prefers-color-scheme: dark)").matches
      ? "dark"
      : "light";
  };

  const setTheme = (theme) => {
    document.documentElement.setAttribute("data-bs-theme", theme);
  };

  const updateUI = (theme) => {
    const toggleBtns = document.querySelectorAll("#darkModeToggle");
    toggleBtns.forEach((btn) => {
      const icon = btn.querySelector("i");
      if (icon) {
        if (theme === "dark") {
          icon.classList.replace("bi-moon-stars-fill", "bi-sun-fill");
        } else {
          icon.classList.replace("bi-sun-fill", "bi-moon-stars-fill");
        }
      }
    });
  };

  // 1. Initial theme application (Immediate to prevent flash)
  const initialTheme = getPreferredTheme();
  setTheme(initialTheme);

  // 2. Listen for system preference changes
  window
    .matchMedia("(prefers-color-scheme: dark)")
    .addEventListener("change", () => {
      if (!getStoredTheme()) {
        const theme = getPreferredTheme();
        setTheme(theme);
        updateUI(theme);
      }
    });

  // 3. Attach click handler using event delegation
  window.addEventListener("DOMContentLoaded", () => {
    updateUI(initialTheme);
    document.addEventListener("click", (e) => {
      const btn = e.target.closest("#darkModeToggle");
      if (!btn) return;
      const newTheme =
        document.documentElement.getAttribute("data-bs-theme") === "dark"
          ? "light"
          : "dark";
      setStoredTheme(newTheme);
      setTheme(newTheme);
      updateUI(newTheme);
    });
  });
})();
