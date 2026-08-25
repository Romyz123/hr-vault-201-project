/**
 * TESP HR 201 - Centralized JavaScript Core
 * Handles global SweetAlerts, password toggles, and UI utilities.
 */

document.addEventListener("DOMContentLoaded", function () {
  // 1. Global SweetAlert for URL Parameters (?msg=... & ?error=...)
  const urlParams = new URLSearchParams(window.location.search);

  if (urlParams.has("msg")) {
    const msgText = urlParams.get("msg");
    const isWarning =
      msgText.toLowerCase().includes("failed") ||
      msgText.toLowerCase().includes("warning");

    Swal.fire({
      icon: isWarning ? "warning" : "success",
      title: isWarning ? "Notice" : "Success",
      text: msgText,
      timer: isWarning ? undefined : 2500,
      showConfirmButton: isWarning,
      didOpen: (modal) => {
        modal.classList.add("swal-animated");
      },
      allowOutsideClick: false,
      allowEscapeKey: !isWarning,
    });

    localStorage.removeItem("hr_add_emp_draft"); // Clean up forms on success
    cleanUrlParams(["msg"]);
  }

  if (urlParams.has("error")) {
    Swal.fire({
      icon: "error",
      title: "Error",
      text: urlParams.get("error"),
      didOpen: (modal) => {
        modal.classList.add("swal-animated");
      },
      allowOutsideClick: false,
    });
    cleanUrlParams(["error"]);
  }
});

function cleanUrlParams(paramsToRemove) {
  if (window.history.replaceState) {
    const url = new URL(window.location.href);
    paramsToRemove.forEach((param) => url.searchParams.delete(param));
    window.history.replaceState(null, null, url.toString());
  }
}

function togglePass(id) {
  const input = document.getElementById(id);
  if (!input) return;
  const icon = input.nextElementSibling.querySelector("i");
  if (input.type === "password") {
    input.type = "text";
    if (icon) icon.classList.replace("bi-eye", "bi-eye-slash");
  } else {
    input.type = "password";
    if (icon) icon.classList.replace("bi-eye-slash", "bi-eye");
  }
}

function confirmForm(e, msg, confirmText = "Yes, proceed!") {
  e.preventDefault();
  const form = e.target;
  Swal.fire({
    title: "Are you sure?",
    html: msg,
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#d33",
    cancelButtonColor: "#6c757d",
    confirmButtonText: confirmText,
  }).then((result) => {
    if (
      result.isConfirmed &&
      form.tagName &&
      form.tagName.toLowerCase() === "form"
    ) {
      form.submit();
    }
  });
}
