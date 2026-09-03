(function () {
  const getStoredTheme = () => localStorage.getItem("theme");
  const getPreferredTheme = () => {
    const stored = getStoredTheme();
    if (stored === "dark" || stored === "light") return stored;
    return window.matchMedia("(prefers-color-scheme: dark)").matches
      ? "dark"
      : "light";
  };

  const setTheme = (theme) => {
    const finalTheme = theme === "dark" ? "dark" : "light";
    document.documentElement.setAttribute("data-bs-theme", finalTheme);
    document.documentElement.style.colorScheme = finalTheme;

    const transitionElements = [
      document.body,
      ...document.querySelectorAll(
        ".card, .navbar, .alert, .modal, .dropdown-menu, .btn, input, textarea, select",
      ),
    ];

    transitionElements.forEach((el) => {
      if (el) {
        el.style.transition =
          "background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease";
      }
    });

    const navbars = document.querySelectorAll(".navbar");
    navbars.forEach((nav) => {
      if (finalTheme === "dark") {
        nav.classList.add("navbar-dark");
        nav.classList.remove("navbar-light");
        nav.style.backgroundColor = "#212529";
      } else {
        nav.classList.add("navbar-light");
        nav.classList.remove("navbar-dark");
        nav.style.backgroundColor = "#f8f9fa";
      }
    });
  };

  setTheme(getPreferredTheme());

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

    const syncTheme = () => {
      const current =
        document.documentElement.getAttribute("data-bs-theme") || "light";
      setTheme(current);
      updateIcon(current);
    };

    syncTheme();

    btn.addEventListener("click", () => {
      const current = document.documentElement.getAttribute("data-bs-theme");
      const next = current === "dark" ? "light" : "dark";
      setTheme(next);
      localStorage.setItem("theme", next);
      updateIcon(next);
      btn.style.transform = "rotate(180deg)";
      setTimeout(() => {
        btn.style.transform = "rotate(0deg)";
      }, 300);
    });

    btn.style.transition = "transform 0.4s ease";
  });
})();
