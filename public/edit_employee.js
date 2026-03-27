// ======================================================
// Global Config Loader
// ======================================================
const config = window.EditEmpConfig || {};

// Logic for Sections and Auto-Capitalize
const sectionMap = config.sectionMap || {};
const rawDeptMap = config.rawDeptMap || {};
const currentSection = config.currentSection || "";
const deptInput = document.getElementById("dept");
const sectionSelect = document.getElementById("sectionPicker");

// [NEW] Multi-Department Logic
function addDept(val) {
  if (!val) return;
  let current = deptInput.value;
  if (current) {
    if (!current.includes(val)) deptInput.value = current + ", " + val;
  } else {
    deptInput.value = val;
  }
  document.getElementById("deptPicker").value = "";
  updateSections();
}

function updateSections() {
  const depts = deptInput.value
    .split(",")
    .map((s) => s.trim())
    .filter((s) => s !== "");
  sectionSelect.innerHTML = '<option value="">+ Add Section...</option>';

  depts.forEach((dept) => {
    let options = [];
    if (sectionMap[dept]) {
      options = sectionMap[dept];
    } else if (rawDeptMap[dept]) {
      options = rawDeptMap[dept].map((s) => ({
        val: s,
        text: s,
      }));
    }

    if (options.length > 0) {
      const group = document.createElement("optgroup");
      group.label = dept;
      options.forEach((data) => {
        const optEl = document.createElement("option");
        optEl.value = data.val;
        optEl.textContent = data.text;
        group.appendChild(optEl);
      });
      sectionSelect.appendChild(group);
    }
  });
}

function capitalize(input) {
  let words = input.value.split(" ");
  for (let i = 0; i < words.length; i++) {
    if (words[i].length > 0)
      words[i] =
        words[i].charAt(0).toUpperCase() + words[i].slice(1).toLowerCase();
  }
  input.value = words.join(" ");
}

// [NEW] Multi-Section Logic
function addSection(val) {
  const picker = document.getElementById("sectionPicker");

  if (val) appendSectionValue(val);
  picker.value = "";
}

function appendSectionValue(text) {
  const input = document.getElementById("section");
  let current = input.value;
  if (current) {
    if (!current.includes(text)) input.value = current + ", " + text;
  } else {
    input.value = text;
  }
}

function confirmDelete() {
  // [NEW] Check for existing documents
  const docCount = config.docCount || 0;
  let warningText = "This action cannot be undone.";

  if (docCount > 0) {
    warningText = `⚠️ WARNING: This employee has ${docCount} document(s). Deleting the employee will ORPHAN these files (they will remain on the server but be unlinked). Please delete the documents first!`;
  }

  Swal.fire({
    title: "Are you sure?",
    text: warningText,
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#dc3545",
    confirmButtonText: "Yes, delete it!",
  }).then((result) => {
    if (result.isConfirmed) {
      const form = document.getElementById("deleteForm");
      if (form) form.submit();
    }
  });
}

function confirmDeleteEval(e, form) {
  e.preventDefault();
  Swal.fire({
    title: "Delete Evaluation?",
    text: "This action cannot be undone.",
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#dc3545",
    confirmButtonText: "Yes, delete it!",
  }).then((result) => {
    if (result.isConfirmed) {
      form.submit();
    }
  });
}

function openEditEvalModal(id, date, score, evaluator, remarks) {
  document.getElementById("edit_eval_id").value = id;
  document.getElementById("edit_eval_date").value = date;
  document.getElementById("edit_score").value = score;
  document.getElementById("edit_evaluator").value = evaluator;
  document.getElementById("edit_remarks").value = remarks;
  new bootstrap.Modal(document.getElementById("editEvalModal")).show();
}

// [NEW] Tab Persistence Logic
document.addEventListener("DOMContentLoaded", () => {
  const urlParams = new URLSearchParams(window.location.search);
  const activeTab = urlParams.get("tab");
  if (activeTab) {
    const tabTrigger = document.querySelector(
      `#profileTabs button[data-bs-target="#${activeTab}"]`,
    );
    if (tabTrigger) {
      const tab = new bootstrap.Tab(tabTrigger);
      tab.show();
    }
  }
});

function confirmLink(e, msg) {
  e.preventDefault();
  const url = e.currentTarget.href;
  Swal.fire({
    title: "Are you sure?",
    text: msg,
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#d33",
    confirmButtonText: "Yes, delete it!",
  }).then((result) => {
    if (result.isConfirmed) window.location.href = url;
  });
}

// TOGGLE EXIT FIELDS LOGIC
function toggleExitFields() {
  const statusSelect = document.getElementById("statusSelect");
  if (!statusSelect) return; // Guard clause

  const status = statusSelect.value;
  const fields = document.querySelectorAll(".exit-field");

  // If status is ANYTHING other than 'Active', show the exit fields
  if (status !== "Active") {
    fields.forEach((field) => (field.style.display = "block"));
  } else {
    fields.forEach((field) => (field.style.display = "none"));
  }
}

// [FIX] Centralized document list to remove redundancy.
const standardDocs = [
  {
    val: "probationary",
    text: "📄 Probationary Employment Contract",
  },
  {
    val: "confidentiality",
    text: "🔒 Confidentiality Agreement (NDA)",
  },
  {
    val: "project",
    text: "📄 Project Employment Contract",
  },
  {
    val: "data_consent",
    text: "🛡️ Data Privacy Consent Form",
  },
  {
    val: "notice_to_explain",
    text: "⚠️ Notice to Explain (NTE)",
  },
  {
    val: "notice_of_decision",
    text: "⚖️ Notice of Decision (NOD)",
  },
  {
    val: "employee_pledge",
    text: "⛑️ Employee Safety Pledge (LSR)",
  },
  {
    val: "whistleblowing",
    text: "📢 Whistle Blowing Consent Form",
  },
];

const docLibrary = {
  lms_tech: standardDocs,
  office: standardDocs,
  general: standardDocs,
};

// [SMART FILTER LOGIC]
// This function runs whenever the Category dropdown changes.
function filterDocuments() {
  const category = document.getElementById("jobCategory").value;
  const docSelect = document.getElementById("docType");
  const btn = document.getElementById("generateBtn");

  // 1. Reset the second dropdown (Clear old options)
  docSelect.innerHTML =
    '<option value="" selected disabled>-- Select Document --</option>';

  if (category && docLibrary[category]) {
    // 3. Enable the dropdown
    docSelect.disabled = false;

    // 4. Loop through the allowed documents and create <option> tags
    docLibrary[category].forEach((doc) => {
      const option = document.createElement("option");
      option.value = doc.val;
      option.text = doc.text;
      docSelect.appendChild(option);
    });
  } else {
    // Disable if no category
    docSelect.disabled = true;
  }

  // 5. Reset the date fields visibility since the document selection changed
  toggleDateFields();
}

function setInputsDisabled(id, disabled) {
  const div = document.getElementById(id);
  if (!div) return;
  const inputs = div.querySelectorAll("input, select, textarea");
  inputs.forEach((el) => (el.disabled = disabled));
}

// DOCUMENT MODAL LOGIC
function toggleDateFields() {
  const type = document.getElementById("docType").value;
  const dateDiv = document.getElementById("dateFields");
  const dutiesDiv = document.getElementById("customDutiesField");
  const nteDiv = document.getElementById("nteFields");
  const nodDiv = document.getElementById("nodFields");
  const projectDiv = document.getElementById("projectFields");
  const help = document.getElementById("docHelp");
  const btn = document.getElementById("generateBtn");

  if (type && type !== "") {
    btn.disabled = false;
  } else {
    btn.disabled = true;
  }

  // Reset all
  dateDiv.style.display = "none";
  if (nteDiv) nteDiv.style.display = "none";
  if (nodDiv) nodDiv.style.display = "none";
  if (dutiesDiv) dutiesDiv.style.display = "none";
  if (projectDiv) projectDiv.style.display = "none";

  // Disable hidden inputs to avoid conflicts
  setInputsDisabled("nteFields", true);
  setInputsDisabled("nodFields", true);
  help.innerText = "";

  if (type === "notice_to_explain") {
    if (nteDiv) nteDiv.style.display = "block";
    setInputsDisabled("nteFields", false);
    help.innerText =
      "Generates a formal disciplinary notice requiring written explanation.";
  }
  // Show Dates ONLY for Contracts
  else if (
    type.includes("probationary") ||
    type.includes("contract") ||
    type.includes("project") ||
    type === "consultant" ||
    type === "regular"
  ) {
    dateDiv.style.display = "block";
    if (
      dutiesDiv &&
      (type.includes("probationary") ||
        type === "regular" ||
        type === "consultant")
    )
      dutiesDiv.style.display = "block"; // Show custom duties
    if (type === "probationary") {
      document.getElementById("durationSelect").value = "6";
      document.getElementById("durationInput").value = "6";
      document.getElementById("durationInput").readOnly = true;
      help.innerText = "Standard 6-month probationary contract.";
    }
    calcEndDate();
  } else if (type === "notice_of_decision") {
    if (nodDiv) nodDiv.style.display = "block";
    setInputsDisabled("nodFields", false);
    help.innerText = "Generates a formal Notice of Decision / Sanction.";
  } else {
    // Reset to default state if hidden
    document.getElementById("durationInput").readOnly = true;
  }

  // Show Project Name field only for project contract
  if (type === "project") {
    projectDiv.style.display = "block";
  } else {
    projectDiv.style.display = "none";
  }
}

function validateDocForm() {
  const type = document.getElementById("docType").value;
  const projInput = document.getElementById("projectNameInput");

  if (type === "project" && projInput.value.trim() === "") {
    alert("Please enter a Project Name.");
    projInput.focus();
    return false; // Prevent submission
  }
  return true;
}

function updateDuration() {
  const select = document.getElementById("durationSelect");
  const input = document.getElementById("durationInput");
  if (select.value !== "custom") {
    input.value = select.value;
    input.readOnly = true;
    calcEndDate();
  } else {
    input.readOnly = false;
  }
}

function validateDuration(input) {
  // Allow any 2 digit number
  if (input.value > 99) input.value = 99;
  if (input.value !== "" && input.value < 1) input.value = 1;
}

function calcEndDate() {
  const startVal = document.getElementById("startDate").value;
  const duration = document.getElementById("durationInput").value;
  const endInput = document.getElementById("endDate");

  if (!startVal || !duration) return;

  const date = new Date(startVal);
  // Add months
  date.setMonth(date.getMonth() + parseInt(duration));
  // Format YYYY-MM-DD
  const yyyy = date.getFullYear();
  const mm = String(date.getMonth() + 1).padStart(2, "0");
  const dd = String(date.getDate()).padStart(2, "0");
  endInput.value = `${yyyy}-${mm}-${dd}`;
}

document.addEventListener("DOMContentLoaded", () => {
  toggleExitFields();
  updateSections(); // Initialize sections on load

  // Handle URL Messages (Success/Error)
  const urlParams = new URLSearchParams(window.location.search);

  if (urlParams.has("msg")) {
    Swal.fire({
      icon: "success",
      title: "Success",
      text: urlParams.get("msg"),
      timer: 2500,
      showConfirmButton: true,
    });
    localStorage.removeItem("hr_add_emp_draft");
    if (window.history.replaceState) {
      window.history.replaceState(
        null,
        null,
        window.location.pathname + "?id=" + config.empId,
      );
    }
  }

  if (urlParams.has("error")) {
    Swal.fire({
      icon: "error",
      title: "Error",
      text: urlParams.get("error"),
    });
    if (window.history.replaceState) {
      window.history.replaceState(
        null,
        null,
        window.location.pathname + "?id=" + config.empId,
      );
    }
  }

  // AUTO-DETECT EMPLOYEE ROLE ON LOAD
  const section = config.sectionLower || "";
  const job = config.jobLower || "";
  const categorySelect = document.getElementById("jobCategory");

  let autoCategory = "general"; // Default fallback

  if (
    section.includes("light maintenance") ||
    section.includes("lms") ||
    section.includes("heavy maintenance") ||
    section.includes("hms") ||
    section.includes("root cause") ||
    section.includes("ras") ||
    section.includes("technical research") ||
    section.includes("trs") ||
    section.includes("civil tracks") ||
    section.includes("cts") ||
    section.includes("power supply") ||
    section.includes("pss") ||
    section.includes("overhead catenary") ||
    section.includes("ocs") ||
    section.includes("signaling") ||
    section.includes("sigcom") ||
    section.includes("building facilities") ||
    section.includes("bfs") ||
    section.includes("warehouse") ||
    section.includes("whs") ||
    job.includes("technician")
  ) {
    autoCategory = "lms_tech";
  } else if (
    section.includes("sqp") ||
    section.includes("admin") ||
    section.includes("finance") ||
    section.includes("dos") ||
    section.includes("department operations")
  ) {
    autoCategory = "office";
  }

  if (categorySelect) {
    categorySelect.value = autoCategory;
    filterDocuments();
  }
  calcEndDate();
});

// [NEW] Auto-Resize Textareas
document.addEventListener("input", function (e) {
  if (e.target.tagName.toLowerCase() === "textarea") {
    autoResize(e.target);
  }
});

document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll("textarea").forEach(autoResize);
  document.querySelectorAll(".modal").forEach((modal) => {
    modal.addEventListener("shown.bs.modal", () => {
      modal.querySelectorAll("textarea").forEach(autoResize);
    });
  });
});

function autoResize(el) {
  el.style.height = "auto";
  el.style.height = el.scrollHeight + "px";
}

function toggleEditOther() {
  const val = document.getElementById("edit_category").value;
  const div = document.getElementById("edit_other_cat_div");
  const input = document.getElementById("edit_other_category");
  if (val === "Others") {
    div.style.display = "block";
    input.required = true;
  } else {
    div.style.display = "none";
    input.required = false;
  }
}

function openEditDocModal(id, name, category, expiryDate) {
  document.getElementById("edit_doc_id").value = id;
  document.getElementById("edit_file_name").value = name;
  document.getElementById("edit_expiry_date").value = expiryDate || "";

  const select = document.getElementById("edit_category");
  const otherInput = document.getElementById("edit_other_category");

  document.getElementById("edit_employeeSearch").value = "";
  document.getElementById("edit_move_to_emp_id").value = "";
  const form = document.querySelector("#editDocModal form");
  if (form) form.classList.remove("was-validated");

  let isStandard = false;
  for (let i = 0; i < select.options.length; i++) {
    if (select.options[i].value === category && category !== "Others") {
      isStandard = true;
      break;
    }
  }

  if (isStandard) {
    select.value = category;
    otherInput.value = "";
  } else {
    select.value = "Others";
    otherInput.value = category === "Others" ? "" : category;
  }
  toggleEditOther();

  new bootstrap.Modal(document.getElementById("editDocModal")).show();
}

// [NEW] Modal Form Validation Styling
document.addEventListener("DOMContentLoaded", () => {
  const editDocForm = document.querySelector("#editDocModal form");
  if (editDocForm) {
    editDocForm.addEventListener("submit", function (event) {
      if (!this.checkValidity()) {
        event.preventDefault();
        event.stopPropagation();
        this.classList.add("was-validated");
        return;
      }

      const moveSearch = document.getElementById("edit_employeeSearch");
      const moveIdInput = document.getElementById("edit_move_to_emp_id");

      if (moveSearch.value.trim() !== "" && moveIdInput.value === "") {
        event.preventDefault();
        event.stopPropagation();
        Swal.fire({
          icon: "warning",
          title: "Invalid Selection",
          text: 'You typed a name in the "Move to" box but didn\'t select an employee from the list (or you edited the name after selecting). Please click a name from the suggestions and do not edit it.',
        });
        return;
      }

      const moveIdVal = moveIdInput.value;
      const moveName = moveSearch.value;

      if (moveIdVal && moveIdVal.trim() !== "") {
        event.preventDefault();
        Swal.fire({
          title: "Transfer Document?",
          html: `You are moving this file to:<br><strong class="text-primary">${moveName}</strong><br><br>This will change the document owner. Continue?`,
          icon: "warning",
          showCancelButton: true,
          confirmButtonColor: "#ffc107",
          cancelButtonColor: "#6c757d",
          confirmButtonText: "Yes, Transfer",
        }).then((result) => {
          if (result.isConfirmed) this.submit();
        });
      }

      this.classList.add("was-validated");
    });
  }
});

function showDownloadLoader(form) {
  Swal.fire({
    title: "Compiling Data...",
    html: `
            <p class="text-muted small mb-3">Scanning files and building the ZIP archive. Please wait...</p>
            <div class="progress mb-3" style="height: 25px;">
                <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width: 100%"></div>
            </div>
            <span class="text-danger fw-bold small">This may take a few minutes. Do not close this window!</span>
        `,
    allowOutsideClick: false,
    allowEscapeKey: false,
    showConfirmButton: false,
  });

  const csrf = form.querySelector('[name="csrf_token"]').value;
  const checkCookie = setInterval(() => {
    if (document.cookie.includes("downloadToken=" + csrf)) {
      clearInterval(checkCookie);
      Swal.close();
      document.cookie =
        "downloadToken=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";

      const modalEl = document.getElementById("downloadAllModal");
      const modal = bootstrap.Modal.getInstance(modalEl);
      if (modal) modal.hide();
    }
  }, 1000);
}

// [NEW] Employee Search Logic for "Move Document"
document.addEventListener("DOMContentLoaded", () => {
  const searchInput = document.getElementById("edit_employeeSearch");
  const suggestionBox = document.getElementById("edit_suggestionBox");
  const hiddenIdInput = document.getElementById("edit_move_to_emp_id");

  if (searchInput && suggestionBox && hiddenIdInput) {
    let debounceTimer = null;

    searchInput.addEventListener("input", function () {
      const q = this.value.trim();
      hiddenIdInput.value = ""; // Clear ID if user types something new

      if (q.length < 2) {
        suggestionBox.innerHTML = "";
        suggestionBox.style.display = "none";
        return;
      }

      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(() => {
        fetch(`api/search_suggestions.php?q=${encodeURIComponent(q)}`)
          .then((r) => r.json())
          .then((data) => {
            suggestionBox.innerHTML = "";
            if (Array.isArray(data) && data.length > 0) {
              suggestionBox.style.display = "block";
              data.slice(0, 5).forEach((emp) => {
                const item = document.createElement("a");
                item.className = "list-group-item list-group-item-action";
                item.style.cursor = "pointer";
                const strong = document.createElement("strong");
                strong.textContent = `${emp.first_name} ${emp.last_name}`;
                const small = document.createElement("small");
                small.className = "text-muted";
                small.textContent = ` ${emp.emp_id}`;
                item.appendChild(strong);
                item.appendChild(small);
                item.onmousedown = () => {
                  searchInput.value = `${emp.first_name} ${emp.last_name} - ${emp.emp_id}`;
                  hiddenIdInput.value = emp.emp_id;
                  suggestionBox.style.display = "none";
                };
                suggestionBox.appendChild(item);
              });
            } else {
              suggestionBox.style.display = "none";
            }
          })
          .catch((e) => console.error("Search error:", e));
      }, 250);
    });

    document.addEventListener("click", function (e) {
      if (
        !searchInput.contains(e.target) &&
        !suggestionBox.contains(e.target)
      ) {
        suggestionBox.style.display = "none";
      }
    });
  }

  // [NEW] Edit Confirmation Alert
  const editForm = document.getElementById("editEmployeeForm");
  let clickedButtonValue = null;

  if (editForm) {
    editForm.querySelectorAll('button[type="submit"]').forEach((btn) => {
      btn.addEventListener("click", function () {
        clickedButtonValue = this.value;
      });
    });

    editForm.addEventListener("submit", function (e) {
      e.preventDefault();
      Swal.fire({
        title: "Save Changes?",
        text: "Are you sure you want to update this profile?",
        icon: "question",
        showCancelButton: true,
        confirmButtonColor: "#ffc107",
        cancelButtonColor: "#6c757d",
        confirmButtonText: "Yes, Save",
      }).then((result) => {
        if (result.isConfirmed) {
          if (clickedButtonValue) {
            const input = document.createElement("input");
            input.type = "hidden";
            input.name = "save_action";
            input.value = clickedButtonValue;
            this.appendChild(input);
          }
          this.submit();
        }
      });
    });
  }
});

// --- AVATAR PREVIEW & CAMERA LOGIC ---
function previewAvatar(input) {
  document.getElementById("removeAvatarFlag").value = "0";
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = function (e) {
      const preview = document.querySelector(".avatar-preview");
      if (preview) preview.src = e.target.result;
    };
    reader.readAsDataURL(input.files[0]);
  }
}

function clearAvatar() {
  document.getElementById("avatarInput").value = "";
  document.getElementById("removeAvatarFlag").value = "1";
  const preview = document.querySelector(".avatar-preview");
  if (preview) {
    preview.src = "uploads/avatars/default.png";
  }
}

let videoStream = null;
let capturedBlob = null;

async function startCamera() {
  const video = document.getElementById("cameraVideo");
  stopCamera();
  retakePhoto();

  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    Swal.fire({
      icon: "error",
      title: "HTTPS Required",
      html: "Modern browsers strictly block camera access on unsecure (HTTP) networks.<br><br>Please access this system via <b>HTTPS</b> or <b>localhost</b> to use the camera.",
      confirmButtonColor: "#dc3545",
    });
    const modalEl = document.getElementById("cameraModal");
    if (modalEl) {
      const modal = bootstrap.Modal.getInstance(modalEl);
      if (modal) modal.hide();
    }
    return;
  }

  try {
    videoStream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: { ideal: "user" } },
    });
    video.srcObject = videoStream;
    video.play().catch((e) => console.error("Play error:", e));
  } catch (err) {
    console.error("Camera error:", err);
    Swal.fire(
      "Error",
      "Unable to access camera. Please check permissions or ensure you are using HTTPS.",
      "error",
    );
    const modalEl = document.getElementById("cameraModal");
    if (modalEl) {
      const modal = bootstrap.Modal.getInstance(modalEl);
      if (modal) modal.hide();
    }
  }
}

function stopCamera() {
  if (videoStream) {
    videoStream.getTracks().forEach((track) => track.stop());
    videoStream = null;
  }
}

function capturePhotoPreview() {
  const video = document.getElementById("cameraVideo");
  const canvas = document.getElementById("cameraCanvas");
  if (!videoStream) return;

  const modalBody = video.closest(".modal-body");
  if (modalBody) {
    const flash = document.createElement("div");
    flash.style.position = "absolute";
    flash.style.inset = "0";
    flash.style.backgroundColor = "#ffffff";
    flash.style.zIndex = "9999";
    flash.style.transition = "opacity 0.25s ease-out";
    modalBody.appendChild(flash);
    setTimeout(() => (flash.style.opacity = "0"), 10);
    setTimeout(() => flash.remove(), 300);
  }

  const MAX_DIM = 800;
  let outWidth = video.videoWidth || video.clientWidth || 640;
  let outHeight = video.videoHeight || video.clientHeight || 480;

  if (outWidth > MAX_DIM || outHeight > MAX_DIM) {
    if (outWidth > outHeight) {
      outHeight = Math.floor(outHeight * (MAX_DIM / outWidth));
      outWidth = MAX_DIM;
    } else {
      outWidth = Math.floor(outWidth * (MAX_DIM / outHeight));
      outHeight = MAX_DIM;
    }
  }

  canvas.width = outWidth;
  canvas.height = outHeight;
  const ctx = canvas.getContext("2d");
  ctx.drawImage(video, 0, 0, outWidth, outHeight);

  const dataUrl = canvas.toDataURL("image/jpeg", 0.85);
  const previewImg = document.getElementById("cameraPreviewImage");
  if (previewImg) {
    previewImg.src = dataUrl;
    previewImg.style.display = "block";
  }
  if (video) video.style.display = "none";

  const overlay = document.getElementById("cameraOverlay");
  if (overlay) overlay.style.display = "none";

  document.getElementById("cameraControls").style.display = "none";
  document.getElementById("previewControls").style.display = "block";

  const confirmBtn = document.querySelector(
    "#previewControls button.btn-primary",
  );
  if (confirmBtn) confirmBtn.disabled = true;

  canvas.toBlob(
    (blob) => {
      if (!blob) {
        Swal.fire(
          "Error",
          "Failed to capture image. Please try again.",
          "error",
        );
        retakePhoto();
        return;
      }
      capturedBlob = blob;
      if (confirmBtn) confirmBtn.disabled = false;
    },
    "image/jpeg",
    0.85,
  );
}

function retakePhoto() {
  capturedBlob = null;
  const previewImg = document.getElementById("cameraPreviewImage");
  const video = document.getElementById("cameraVideo");
  const overlay = document.getElementById("cameraOverlay");
  const cameraControls = document.getElementById("cameraControls");
  const previewControls = document.getElementById("previewControls");

  if (previewImg) previewImg.style.display = "none";
  if (video) {
    video.style.display = "block";
    if (video.paused && typeof videoStream !== "undefined" && videoStream) {
      video.play().catch((e) => console.error("Play error:", e));
    }
  }
  if (overlay) overlay.style.display = "flex";
  if (cameraControls) cameraControls.style.display = "block";
  if (previewControls) previewControls.style.display = "none";
}

function confirmPhoto() {
  if (!capturedBlob) return;
  const file = new File(
    [capturedBlob],
    "profile_capture_" + Date.now() + ".jpg",
    {
      type: "image/jpeg",
    },
  );
  const dataTransfer = new DataTransfer();
  dataTransfer.items.add(file);
  const input = document.getElementById("avatarInput");
  input.files = dataTransfer.files;
  previewAvatar(input);

  const modal = bootstrap.Modal.getInstance(
    document.getElementById("cameraModal"),
  );
  if (modal) modal.hide();
  stopCamera();

  Swal.fire({
    toast: true,
    position: "top-end",
    showConfirmButton: false,
    timer: 4000,
    icon: "success",
    title: 'Photo attached! Click "Save Changes" to upload.',
  });
}

// [SECURITY] Auto-Logout Timer
function updateTimer() {
  const config = window.EditEmpConfig || {};
  if (!config.timeoutDuration) return;

  config.timeoutDuration -= 1000;
  if (config.timeoutDuration <= 0) window.location.href = "logout.php";

  const m = Math.floor(config.timeoutDuration / 60000);
  const s = Math.floor((config.timeoutDuration % 60000) / 1000);
  const timerDisplay = document.getElementById("sessionTimer");
  if (timerDisplay)
    timerDisplay.innerText = `${m}:${s.toString().padStart(2, "0")}`;
}

if (window.EditEmpConfig && window.EditEmpConfig.timeoutDuration) {
  document.addEventListener(
    "mousemove",
    () =>
      (window.EditEmpConfig.timeoutDuration =
        window.EditEmpConfig.timeoutDuration),
  );
  document.addEventListener(
    "keypress",
    () =>
      (window.EditEmpConfig.timeoutDuration =
        window.EditEmpConfig.timeoutDuration),
  );
  setInterval(updateTimer, 1000);
  updateTimer();
}
