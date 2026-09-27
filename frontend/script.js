/**
 * DriveSure Frontend JS
 * Works with the PHP API in /api
 */

const DEFAULT_API_BASE = "http://localhost/Drive-Sure/api";

function normalizeApiBase(value) {
  return typeof value === "string" ? value.replace(/\/+$/, "") : "";
}

function getApiBaseCandidates() {
  const candidates = [
    window.DRIVESURE_API_BASE,
    document.querySelector('meta[name="drivesure-api-base"]')?.content,
    localStorage.getItem("drivesureApiBase"),
    DEFAULT_API_BASE
  ];

  if (window.location.origin) {
    candidates.push(window.location.origin);
    candidates.push(`${window.location.origin}/api`);
    // If frontend is in /Drive-Sure/frontend, API is one level up
    if (window.location.pathname.includes("/frontend")) {
      candidates.push(window.location.origin + "/Drive-Sure/api");
      candidates.push(window.location.origin + window.location.pathname.replace(/\/frontend.*/, "/api"));
    }
  }

  return [...new Set(candidates.map(normalizeApiBase).filter(Boolean))];
}

let activeApiBase = normalizeApiBase(
  window.DRIVESURE_API_BASE ||
  document.querySelector('meta[name="drivesure-api-base"]')?.content ||
  localStorage.getItem("drivesureApiBase") ||
  DEFAULT_API_BASE
);

// ---------- Session helpers ----------
function getCustomerId(user = getSession()) {
  return user?.customer_id ?? user?.id ?? user?.user_id ?? null;
}

function getAdminId(admin = getAdminSession()) {
  return admin?.admin_id ?? null;
}

function getArrayFromResponse(data, preferredKeys = []) {
  for (const key of preferredKeys) {
    if (Array.isArray(data?.[key])) return data[key];
  }
  if (Array.isArray(data)) return data;
  for (const value of Object.values(data || {})) {
    if (Array.isArray(value)) return value;
  }
  return [];
}

function saveSession(user) {
  localStorage.setItem("user", JSON.stringify(user));
  localStorage.setItem("loggedIn", "true");
  localStorage.removeItem("admin");
}

function getSession() {
  const saved = localStorage.getItem("user");
  return saved ? JSON.parse(saved) : null;
}

function clearSession() {
  localStorage.removeItem("user");
  localStorage.removeItem("loggedIn");
}

function saveAdminSession(admin) {
  localStorage.setItem("admin", JSON.stringify(admin));
  localStorage.removeItem("user");
  localStorage.removeItem("loggedIn");
}

function getAdminSession() {
  const saved = localStorage.getItem("admin");
  return saved ? JSON.parse(saved) : null;
}

function clearAdminSession() {
  localStorage.removeItem("admin");
}

// Logout links
document.querySelectorAll("[data-logout-link]").forEach((link) => {
  link.addEventListener("click", (e) => {
    e.preventDefault();
    clearSession();
    clearAdminSession();
    window.location.href = "login.html";
  });
});

document.querySelectorAll("[data-admin-logout]").forEach((link) => {
  link.addEventListener("click", (e) => {
    e.preventDefault();
    clearAdminSession();
    window.location.href = "admin-login.html";
  });
});

function formatCurrency(value) {
  return `Rs. ${Number(value || 0).toLocaleString("en-IN")}`;
}

function formatDateLabel(value) {
  if (!value) return "None";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "None";
  return date.toLocaleDateString("en-IN", {
    day: "2-digit",
    month: "short",
    year: "numeric"
  });
}

// ---------- API request helper ----------
async function sendRequest(path, options = {}) {
  const candidates = [activeApiBase, ...getApiBaseCandidates()].filter(Boolean);
  let lastError = null;

  for (const apiBase of [...new Set(candidates)]) {
    let response;
    try {
      const isFormData = options?.body instanceof FormData;
      const headers = isFormData ? {} : { "Content-Type": "application/json" };
      if (options?.headers) Object.assign(headers, options.headers);

      response = await fetch(`${apiBase}${path}`, { ...options, headers });
    } catch (_err) {
      lastError = new Error(`Cannot reach the DriveSure backend at ${apiBase}.`);
      continue;
    }

    const contentType = response.headers.get("content-type") || "";
    if (!contentType.includes("application/json")) {
      const text = await response.text();
      const looksLikeHtml = text.trim().startsWith("<!DOCTYPE") || text.trim().startsWith("<html");
      if (looksLikeHtml) {
        lastError = new Error(`The URL ${apiBase} returned HTML instead of JSON. Check that Apache is running and the api folder is correct.`);
        continue;
      }
      lastError = new Error(`Backend at ${apiBase} returned a non-JSON response.`);
      continue;
    }

    const data = await response.json();
    if (!response.ok) {
      const msg = data.message || data.error || "Request failed.";
      throw new Error(data.details ? `${msg} ${data.details}` : msg);
    }

    activeApiBase = apiBase;
    localStorage.setItem("drivesureApiBase", apiBase);
    return data;
  }

  throw new Error(
    `${lastError?.message || "Unable to reach the backend API."} Tried: ${[...new Set(candidates)].join(", ")}`
  );
}

// ---------- Register ----------
document.getElementById("registerForm")?.addEventListener("submit", async (e) => {
  e.preventDefault();
  const payload = {
    name: document.getElementById("name").value.trim(),
    email: document.getElementById("email").value.trim(),
    password: document.getElementById("password").value
  };
  try {
    const data = await sendRequest("/auth/register", {
      method: "POST",
      body: JSON.stringify(payload)
    });
    saveSession(data.user);
    alert(data.message);
    window.location.href = "dashboard.html";
  } catch (err) {
    alert(err.message);
  }
});

// ---------- Login ----------
document.getElementById("loginForm")?.addEventListener("submit", async (e) => {
  e.preventDefault();
  const payload = {
    email: document.getElementById("loginEmail").value.trim(),
    password: document.getElementById("loginPassword").value
  };
  try {
    const data = await sendRequest("/auth/login", {
      method: "POST",
      body: JSON.stringify(payload)
    });
    saveSession(data.user);
    alert(data.message);
    window.location.href = "dashboard.html";
  } catch (err) {
    alert(err.message);
  }
});

// ---------- Admin login ----------
document.getElementById("adminLoginForm")?.addEventListener("submit", async (e) => {
  e.preventDefault();
  const payload = {
    email: document.getElementById("adminEmail").value.trim(),
    password: document.getElementById("adminPassword").value
  };
  try {
    const data = await sendRequest("/admin/login", {
      method: "POST",
      body: JSON.stringify(payload)
    });
    saveAdminSession(data.admin);
    alert(data.message);
    window.location.href = "admin-dashboard.html";
  } catch (err) {
    alert(err.message);
  }
});

// ---------- Add vehicle ----------
document.getElementById("carForm")?.addEventListener("submit", async (e) => {
  e.preventDefault();
  const user = getSession();
  const customerId = getCustomerId(user);
  if (!customerId) {
    alert("Please log in before adding a vehicle.");
    window.location.href = "login.html";
    return;
  }
  const payload = {
    customer_id: customerId,
    vehicle_type: document.getElementById("vehicleType").value,
    make: document.getElementById("make").value.trim(),
    model: document.getElementById("model").value.trim(),
    year: document.getElementById("year").value,
    plate_no: document.getElementById("plateNo").value.trim()
  };
  try {
    const data = await sendRequest("/vehicles", {
      method: "POST",
      body: JSON.stringify(payload)
    });
    alert(data.message);
    window.location.href = "dashboard.html";
  } catch (err) {
    alert(err.message);
  }
});

// ---------- Policy select ----------
document.querySelectorAll(".policy-select-btn").forEach((button) => {
  button.addEventListener("click", async () => {
    const user = getSession();
    const vehicleSelect = document.getElementById("policyVehicleSelect");
    const customerId = getCustomerId(user);

    if (!customerId) {
      alert("Please log in before choosing a policy.");
      window.location.href = "login.html";
      return;
    }
    if (!vehicleSelect?.value) {
      alert("Please select a vehicle before choosing a policy.");
      return;
    }

    const payload = {
      customer_id: customerId,
      car_id: vehicleSelect.value,
      plan_name: button.dataset.planName,
      coverage_type: button.dataset.coverageType,
      premium_amount: button.dataset.premiumAmount,
      billing_cycle: button.dataset.billingCycle,
      status: "Active"
    };

    try {
      const data = await sendRequest("/policies", {
        method: "POST",
        body: JSON.stringify(payload)
      });
      alert(data.message);
      window.location.href = "dashboard.html";
    } catch (err) {
      alert(err.message);
    }
  });
});

// ---------- Load vehicles for policy page ----------
async function loadPolicyVehicles() {
  const vehicleSelect = document.getElementById("policyVehicleSelect");
  const vehicleHelp = document.getElementById("policyVehicleHelp");
  if (!vehicleSelect || !vehicleHelp) return;

  const customerId = getCustomerId();
  if (!customerId) {
    clearSession();
    window.location.href = "login.html";
    return;
  }

  try {
    const data = await sendRequest(`/vehicles/${customerId}`, { method: "GET" });
    const cars = getArrayFromResponse(data, ["cars", "vehicles"]);
    vehicleSelect.innerHTML = "";

    if (cars.length === 0) {
      vehicleSelect.innerHTML = '<option value="">No vehicles found</option>';
      vehicleSelect.disabled = true;
      vehicleHelp.textContent = "No registered vehicles. Please add a vehicle first.";
      return;
    }

    vehicleSelect.disabled = false;
    vehicleHelp.textContent = "Select the vehicle this policy should cover.";

    const defaultOption = document.createElement("option");
    defaultOption.value = "";
    defaultOption.textContent = "Select a vehicle";
    vehicleSelect.appendChild(defaultOption);

    cars.forEach((car) => {
      const option = document.createElement("option");
      option.value = car.car_id;
      option.textContent = `${car.vehicle_type || "Vehicle"} – ${car.make} ${car.model} (${car.plate_no})`;
      vehicleSelect.appendChild(option);
    });
  } catch (err) {
    vehicleSelect.innerHTML = '<option value="">Unable to load vehicles</option>';
    vehicleSelect.disabled = true;
    vehicleHelp.textContent = err.message;
  }
}

// ---------- Load policies for payment page ----------
async function loadPaymentPolicies() {
  const policySelect = document.getElementById("paymentPolicySelect");
  const policyHelp = document.getElementById("paymentPolicyHelp");
  if (!policySelect || !policyHelp) return;

  const customerId = getCustomerId();
  if (!customerId) {
    clearSession();
    window.location.href = "login.html";
    return;
  }

  try {
    const data = await sendRequest(`/policies/${customerId}`, { method: "GET" });
    const policies = getArrayFromResponse(data, ["policies"]);
    policySelect.innerHTML = "";

    if (policies.length === 0) {
      policySelect.innerHTML = '<option value="">No policies found</option>';
      policySelect.disabled = true;
      policyHelp.textContent = "No saved policies. Choose a policy first.";
      return;
    }

    policySelect.disabled = false;
    policyHelp.textContent = "Select the policy you are paying for.";

    const defaultOption = document.createElement("option");
    defaultOption.value = "";
    defaultOption.textContent = "Select a policy";
    policySelect.appendChild(defaultOption);

    policies.forEach((p) => {
      const option = document.createElement("option");
      option.value = p.policy_id;
      option.textContent = `${p.plan_name}${p.make && p.model ? ` – ${p.make} ${p.model}` : ""}`;
      policySelect.appendChild(option);
    });
  } catch (err) {
    policySelect.innerHTML = '<option value="">Unable to load policies</option>';
    policySelect.disabled = true;
    policyHelp.textContent = err.message;
  }
}

// ---------- Payment buttons ----------
document.querySelectorAll(".payment-action-btn").forEach((button) => {
  button.addEventListener("click", async () => {
    const policySelect = document.getElementById("paymentPolicySelect");
    if (!getCustomerId()) {
      alert("Please log in before managing payments.");
      window.location.href = "login.html";
      return;
    }
    if (!policySelect?.value) {
      alert("Please select a policy before recording a payment.");
      return;
    }

    const payload = {
      policy_id: policySelect.value,
      amount: button.dataset.amount,
      payment_method: button.dataset.paymentMethod,
      status: button.dataset.paymentStatus,
      payment_date: new Date().toISOString().slice(0, 19).replace("T", " ")
    };

    try {
      const data = await sendRequest("/payments", {
        method: "POST",
        body: JSON.stringify(payload)
      });
      alert(data.message);
      window.location.href = "dashboard.html";
    } catch (err) {
      alert(err.message);
    }
  });
});

// ---------- Dashboard summary ----------
function updateDashboardSummary({ cars = [], policies = [], payments = [], claims = [] }) {
  const el = (id) => document.getElementById(id);

  if (el("summaryPolicyCount")) {
    el("summaryPolicyCount").textContent = String(policies.length);
    el("summaryPolicyText").textContent =
      policies.length > 0
        ? `${policies.length} active coverage record${policies.length === 1 ? "" : "s"}.`
        : "Policies you save will appear here once coverage is selected.";
  }

  if (el("summaryTotalPaid")) {
    const totalPaid = payments.reduce((s, p) => s + Number(p.amount || 0), 0);
    el("summaryTotalPaid").textContent = formatCurrency(totalPaid);
    el("summaryPaymentText").textContent =
      payments.length > 0
        ? `${payments.length} billing entr${payments.length === 1 ? "y has" : "ies have"} been recorded.`
        : "Payments recorded in the billing center will total up here.";
  }

  if (el("summaryVehicleCount")) {
    el("summaryVehicleCount").textContent = String(cars.length);
    el("summaryVehicleText").textContent =
      cars.length > 0
        ? `${cars.length} vehicle profile${cars.length === 1 ? "" : "s"} ready.`
        : "Add a vehicle to start linking policies and payments.";
  }

  if (el("summaryLastPaymentDate")) {
    const latest = payments[0];
    el("summaryLastPaymentDate").textContent = latest ? formatDateLabel(latest.payment_date) : "None";
    el("summaryLastPaymentText").textContent = latest
      ? `${latest.plan_name ? latest.plan_name + " payment" : "Payment #" + latest.payment_id} was the most recent.`
      : "Your latest billing activity will be shown here.";
  }

  if (el("summaryClaimCount")) {
    el("summaryClaimCount").textContent = String(claims.length);
    el("summaryClaimText").textContent =
      claims.length > 0
        ? `${claims.length} claim${claims.length === 1 ? "" : "s"} filed.`
        : "Filed claims will appear here.";
  }
}

// ---------- Dashboard loaders ----------
async function loadDashboardVehicles() {
  const grid = document.getElementById("vehicleGrid");
  const empty = document.getElementById("vehicleEmptyState");
  const count = document.getElementById("vehicleCount");
  if (!grid || !empty || !count) return;

  const customerId = getCustomerId();
  if (!customerId) {
    clearSession();
    window.location.href = "login.html";
    return;
  }

  try {
    const data = await sendRequest(`/vehicles/${customerId}`, { method: "GET" });
    const cars = getArrayFromResponse(data, ["cars", "vehicles"]);
    count.textContent = `${cars.length} vehicle${cars.length === 1 ? "" : "s"}`;
    grid.innerHTML = "";

    if (cars.length === 0) {
      empty.classList.remove("hidden");
      return;
    }
    empty.classList.add("hidden");

    cars.forEach((car) => {
      const card = document.createElement("article");
      card.className = "vehicle-item";
      card.innerHTML = `
        <h3>${car.vehicle_type || "Vehicle"} – ${car.make} ${car.model}</h3>
        <div class="vehicle-meta">
          <span><strong>Year:</strong> ${car.year || "—"}</span>
          <span><strong>Plate:</strong> ${car.plate_no}</span>
        </div>
      `;
      grid.appendChild(card);
    });
  } catch (err) {
    empty.classList.remove("hidden");
    grid.innerHTML = "";
    count.textContent = "0 vehicles";
    empty.innerHTML = `<strong>Unable to load vehicles.</strong><p class="helper-text">${err.message}</p>`;
  }
}

async function loadDashboardPolicies() {
  const grid = document.getElementById("policyGrid");
  const empty = document.getElementById("policyEmptyState");
  const count = document.getElementById("policyCount");
  if (!grid || !empty || !count) return;

  const customerId = getCustomerId();
  if (!customerId) return;

  try {
    const data = await sendRequest(`/policies/${customerId}`, { method: "GET" });
    const policies = getArrayFromResponse(data, ["policies"]);
    count.textContent = `${policies.length} polic${policies.length === 1 ? "y" : "ies"}`;
    grid.innerHTML = "";

    if (policies.length === 0) {
      empty.classList.remove("hidden");
      return;
    }
    empty.classList.add("hidden");

    policies.forEach((p) => {
      const card = document.createElement("article");
      card.className = "policy-item";
      card.innerHTML = `
        <h3>${p.plan_name}</h3>
        <p class="helper-text">${p.coverage_type} coverage for ${p.make && p.model ? p.make + " " + p.model : "vehicle #" + p.car_id}</p>
        <div class="policy-meta">
          <span><strong>Premium:</strong> Rs. ${Number(p.premium_amount).toLocaleString("en-IN")} / ${(p.billing_cycle || "Yearly").toLowerCase()}</span>
          <span><strong>Status:</strong> ${p.status || "Active"}</span>
        </div>
      `;
      grid.appendChild(card);
    });
  } catch (err) {
    empty.classList.remove("hidden");
    grid.innerHTML = "";
    count.textContent = "0 policies";
    empty.innerHTML = `<strong>Unable to load policies.</strong><p class="helper-text">${err.message}</p>`;
  }
}

async function loadDashboardPayments() {
  const grid = document.getElementById("paymentGrid");
  const empty = document.getElementById("paymentEmptyState");
  const count = document.getElementById("paymentCount");
  if (!grid || !empty || !count) return;

  const customerId = getCustomerId();
  if (!customerId) return;

  try {
    const data = await sendRequest(`/payments/${customerId}`, { method: "GET" });
    const payments = getArrayFromResponse(data, ["payments"]);
    count.textContent = `${payments.length} payment${payments.length === 1 ? "" : "s"}`;
    grid.innerHTML = "";

    if (payments.length === 0) {
      empty.classList.remove("hidden");
      return;
    }
    empty.classList.add("hidden");

    payments.forEach((pay) => {
      const card = document.createElement("article");
      card.className = "payment-item";
      card.innerHTML = `
        <h3>${pay.plan_name ? pay.plan_name + " payment" : "Payment #" + pay.payment_id}</h3>
        <div class="payment-meta">
          <span><strong>Amount:</strong> Rs. ${Number(pay.amount).toLocaleString("en-IN")}</span>
          <span><strong>Method:</strong> ${pay.payment_method || "—"}</span>
          <span><strong>Status:</strong> ${pay.status || "Paid"}</span>
          <span><strong>Date:</strong> ${String(pay.payment_date).slice(0, 10)}</span>
        </div>
      `;
      grid.appendChild(card);
    });
  } catch (err) {
    empty.classList.remove("hidden");
    grid.innerHTML = "";
    count.textContent = "0 payments";
    empty.innerHTML = `<strong>Unable to load payments.</strong><p class="helper-text">${err.message}</p>`;
  }
}

async function loadDashboardClaims() {
  const grid = document.getElementById("claimGrid");
  const empty = document.getElementById("claimEmptyState");
  const count = document.getElementById("claimCount");
  if (!grid || !empty || !count) return;

  const customerId = getCustomerId();
  if (!customerId) return;

  try {
    const data = await sendRequest(`/claims/${customerId}`, { method: "GET" });
    const claims = getArrayFromResponse(data, ["claims"]);
    count.textContent = `${claims.length} claim${claims.length === 1 ? "" : "s"}`;
    grid.innerHTML = "";

    if (claims.length === 0) {
      empty.classList.remove("hidden");
      return;
    }
    empty.classList.add("hidden");

    claims.forEach((c) => {
      const statusClass =
        c.status === "Approved" ? "status-approved" :
        c.status === "Rejected" ? "status-rejected" : "status-pending";

      const card = document.createElement("article");
      card.className = "claim-item";
      card.innerHTML = `
        <h3>${c.plan_name || "Claim #" + c.claim_id}</h3>
        <p class="helper-text">${c.incident_type || "Incident"} on ${c.incident_date || "—"}</p>
        <div class="claim-meta">
          <span><strong>Vehicle:</strong> ${c.make || ""} ${c.model || ""} ${c.plate_no ? "(" + c.plate_no + ")" : ""}</span>
          <span><strong>Status:</strong> <span class="status-badge ${statusClass}">${c.status || "Pending Review"}</span></span>
          <span><strong>Filed:</strong> ${c.date_filed || "—"}</span>
          ${c.admin_note ? `<span><strong>Admin note:</strong> ${c.admin_note}</span>` : ""}
        </div>
      `;
      grid.appendChild(card);
    });
  } catch (err) {
    empty.classList.remove("hidden");
    grid.innerHTML = "";
    count.textContent = "0 claims";
    empty.innerHTML = `<strong>Unable to load claims.</strong><p class="helper-text">${err.message}</p>`;
  }
}

async function loadDashboardData() {
  const customerId = getCustomerId();
  if (!customerId) return;

  const [carsR, policiesR, paymentsR, claimsR] = await Promise.allSettled([
    sendRequest(`/vehicles/${customerId}`, { method: "GET" }),
    sendRequest(`/policies/${customerId}`, { method: "GET" }),
    sendRequest(`/payments/${customerId}`, { method: "GET" }),
    sendRequest(`/claims/${customerId}`, { method: "GET" })
  ]);

  updateDashboardSummary({
    cars: carsR.status === "fulfilled" ? getArrayFromResponse(carsR.value, ["cars", "vehicles"]) : [],
    policies: policiesR.status === "fulfilled" ? getArrayFromResponse(policiesR.value, ["policies"]) : [],
    payments: paymentsR.status === "fulfilled" ? getArrayFromResponse(paymentsR.value, ["payments"]) : [],
    claims: claimsR.status === "fulfilled" ? getArrayFromResponse(claimsR.value, ["claims"]) : []
  });
}

// ---------- Admin dashboard ----------
let adminClaimsCache = [];

function getEvidenceUrl(filePath) {
  if (!filePath) return "#";
  // Stored as "uploads/filename" relative to Drive-Sure root
  const clean = String(filePath).replace(/^\/+/, "");
  return `../${clean}`;
}

function isImageFile(filePath) {
  return /\.(jpe?g|png|gif|webp|bmp)$/i.test(filePath || "");
}

function scoreClass(score) {
  if (score == null || score === "") return "score-unknown";
  const n = Number(score);
  if (n >= 70) return "score-high";
  if (n >= 45) return "score-mid";
  return "score-low";
}

function buildEvidenceHtml(evidence) {
  if (!evidence || evidence.length === 0) {
    return `<div class="evidence-block"><p class="evidence-empty">No files uploaded for this claim.</p></div>`;
  }

  const items = evidence.map((ev) => {
    const url = getEvidenceUrl(ev.file_path);
    const label = ev.file_category || "File";
    const score = ev.confidence_score;
    const scoreLabel = score != null && score !== "" ? `${score}/100` : "N/A";
    const sc = scoreClass(score);

    const metaBits = [];
    if (ev.has_exif == 1 || ev.has_exif === true) metaBits.push("EXIF");
    if (ev.camera_make || ev.camera_model) {
      metaBits.push(`${ev.camera_make || ""} ${ev.camera_model || ""}`.trim());
    }
    if (ev.exif_timestamp) metaBits.push(String(ev.exif_timestamp).slice(0, 16));
    if (ev.is_blurry == 1 || ev.is_blurry === true) metaBits.push("Blurry");
    if (ev.exif_latitude && ev.exif_longitude) {
      metaBits.push(`GPS ${ev.exif_latitude}, ${ev.exif_longitude}`);
    }

    const notes = ev.analysis_notes ? String(ev.analysis_notes) : "";
    const notesShort = notes.length > 120 ? notes.slice(0, 120) + "…" : notes;
    const notesAttr = notes.replace(/&/g, "&amp;").replace(/"/g, "&quot;");

    const preview = isImageFile(ev.file_path)
      ? `<a href="${url}" target="_blank" rel="noopener" title="${label}">
           <img class="evidence-thumb" src="${url}" alt="${label}" onerror="this.style.display='none'">
         </a>`
      : `<a class="evidence-link" href="${url}" target="_blank" rel="noopener">${label}<br>Open file</a>`;

    return `
      <div class="evidence-card">
        ${preview}
        <div class="evidence-meta">
          <span class="score-badge ${sc}">Confidence ${scoreLabel}</span>
          <span class="evidence-cat">${label}</span>
          ${metaBits.length ? `<span class="evidence-detail">${metaBits.join(" · ")}</span>` : ""}
          ${notes ? `<span class="evidence-notes" title="${notesAttr}">${notesShort}</span>` : ""}
        </div>
      </div>
    `;
  }).join("");

  return `
    <div class="evidence-block">
      <strong>Uploaded evidence + EXIF check</strong>
      <div class="evidence-gallery">${items}</div>
    </div>
  `;
}

function getAdminFilters() {
  return {
    name: (document.getElementById("filterName")?.value || "").trim().toLowerCase(),
    plan: document.getElementById("filterPlan")?.value || "",
    status: document.getElementById("filterStatus")?.value || "",
    dateFrom: document.getElementById("filterDateFrom")?.value || "",
    dateTo: document.getElementById("filterDateTo")?.value || ""
  };
}

function filterAdminClaims(claims) {
  const f = getAdminFilters();
  return claims.filter((c) => {
    if (f.name) {
      const blob = `${c.customer_name || ""} ${c.customer_email || ""}`.toLowerCase();
      if (!blob.includes(f.name)) return false;
    }
    if (f.plan && (c.plan_name || "") !== f.plan) return false;
    if (f.status && (c.status || "") !== f.status) return false;
    if (f.dateFrom && c.date_filed && c.date_filed < f.dateFrom) return false;
    if (f.dateTo && c.date_filed && c.date_filed > f.dateTo) return false;
    return true;
  });
}

function populatePlanFilter(claims) {
  const select = document.getElementById("filterPlan");
  if (!select) return;
  const current = select.value;
  const plans = [...new Set(claims.map((c) => c.plan_name).filter(Boolean))].sort();
  select.innerHTML = '<option value="">All plans</option>';
  plans.forEach((p) => {
    const opt = document.createElement("option");
    opt.value = p;
    opt.textContent = p;
    select.appendChild(opt);
  });
  if (current && plans.includes(current)) select.value = current;
}

function renderAdminClaims(claims) {
  const grid = document.getElementById("adminClaimGrid");
  const empty = document.getElementById("adminClaimEmpty");
  const count = document.getElementById("adminClaimCount");
  if (!grid) return;

  const filtered = filterAdminClaims(claims);
  if (count) count.textContent = `${filtered.length} claim${filtered.length === 1 ? "" : "s"}`;
  grid.innerHTML = "";

  if (filtered.length === 0) {
    if (empty) empty.classList.remove("hidden");
    return;
  }
  if (empty) empty.classList.add("hidden");

  filtered.forEach((c) => {
    const statusClass =
      c.status === "Approved" ? "status-approved" :
      c.status === "Rejected" ? "status-rejected" : "status-pending";

    const card = document.createElement("article");
    card.className = "claim-item";
    card.innerHTML = `
      <h3>Claim #${c.claim_id} – ${c.plan_name || "Policy"}</h3>
      <p class="helper-text">${c.customer_name || "Customer"} (${c.customer_email || ""})</p>
      <div class="claim-meta">
        <span><strong>Vehicle:</strong> ${c.make || ""} ${c.model || ""} ${c.plate_no ? "(" + c.plate_no + ")" : ""}</span>
        <span><strong>Incident:</strong> ${c.incident_type || "—"} on ${c.incident_date || "—"}</span>
        <span><strong>Location:</strong> ${c.incident_location || "—"}</span>
        <span><strong>Description:</strong> ${c.description || "—"}</span>
        <span><strong>Status:</strong> <span class="status-badge ${statusClass}">${c.status || "Pending Review"}</span></span>
        <span><strong>Filed:</strong> ${c.date_filed || "—"}</span>
        ${c.admin_note ? `<span><strong>Note:</strong> ${c.admin_note}</span>` : ""}
      </div>
      ${buildEvidenceHtml(c.evidence)}
      <div id="ai-score-${c.claim_id}" class="ai-score-box hidden"></div>
      <div class="claim-actions">
        <button class="btn-soft" type="button" data-score="${c.claim_id}">Get AI score</button>
        ${c.status !== "Approved" ? `<button class="btn-brand" type="button" data-approve="${c.claim_id}">Approve</button>` : ""}
        ${c.status !== "Rejected" ? `<button class="btn-danger" type="button" data-reject="${c.claim_id}">Reject</button>` : ""}
      </div>
    `;
    grid.appendChild(card);
  });

  grid.querySelectorAll("[data-approve]").forEach((btn) => {
    btn.addEventListener("click", () => updateClaimStatus(btn.dataset.approve, "Approved"));
  });
  grid.querySelectorAll("[data-reject]").forEach((btn) => {
    btn.addEventListener("click", () => updateClaimStatus(btn.dataset.reject, "Rejected"));
  });
  grid.querySelectorAll("[data-score]").forEach((btn) => {
    btn.addEventListener("click", () => requestClaimScore(btn.dataset.score, btn));
  });
}

function bindAdminFilters() {
  ["filterName", "filterPlan", "filterStatus", "filterDateFrom", "filterDateTo"].forEach((id) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener("input", () => renderAdminClaims(adminClaimsCache));
    el.addEventListener("change", () => renderAdminClaims(adminClaimsCache));
  });

  document.getElementById("filterClearBtn")?.addEventListener("click", () => {
    const name = document.getElementById("filterName");
    const plan = document.getElementById("filterPlan");
    const status = document.getElementById("filterStatus");
    const from = document.getElementById("filterDateFrom");
    const to = document.getElementById("filterDateTo");
    if (name) name.value = "";
    if (plan) plan.value = "";
    if (status) status.value = "";
    if (from) from.value = "";
    if (to) to.value = "";
    renderAdminClaims(adminClaimsCache);
  });
}

async function loadAdminClaims() {
  const grid = document.getElementById("adminClaimGrid");
  const empty = document.getElementById("adminClaimEmpty");
  if (!grid) return;

  if (!getAdminId()) {
    window.location.href = "admin-login.html";
    return;
  }

  try {
    const data = await sendRequest("/admin/claims", { method: "GET" });
    adminClaimsCache = getArrayFromResponse(data, ["claims"]);
    populatePlanFilter(adminClaimsCache);
    bindAdminFilters();
    renderAdminClaims(adminClaimsCache);
  } catch (err) {
    if (empty) {
      empty.classList.remove("hidden");
      empty.innerHTML = `<strong>Unable to load claims.</strong><p class="helper-text">${err.message}</p>`;
    }
  }
}

async function updateClaimStatus(claimId, status) {
  const note = prompt(`Optional note for this ${status.toLowerCase()} decision:`, "") || "";
  try {
    const data = await sendRequest("/admin/claims/update", {
      method: "POST",
      body: JSON.stringify({ claim_id: claimId, status, admin_note: note })
    });
    alert(data.message);
    loadAdminClaims();
  } catch (err) {
    alert(err.message);
  }
}

/**
 * On-demand score for ONE claim from YOUR live DB.
 * Default = free local rules (no API credits).
 * Optional confirm dialog can request Groq if server has GROQ_API_KEY.
 */
async function requestClaimScore(claimId, buttonEl) {
  const box = document.getElementById(`ai-score-${claimId}`);
  if (!box) return;

  if (!confirm("Get confidence score for this claim from your database?\n\nOK = continue\nCancel = abort")) {
    return;
  }

  // Cancel on this dialog = free local score; OK = try Groq (needs API key on server)
  const callGroq = confirm(
    "Use Groq AI for this score?\n\n" +
    "OK = yes (uses API credits)\n" +
    "Cancel = free local score only (recommended)"
  );

  const oldLabel = buttonEl?.textContent;
  if (buttonEl) {
    buttonEl.disabled = true;
    buttonEl.textContent = "Scoring…";
  }

  try {
    const data = await sendRequest("/admin/claims/score", {
      method: "POST",
      body: JSON.stringify({ claim_id: claimId, use_groq: callGroq })
    });

    const sc = scoreClass(data.confidence_score);
    const reasons = Array.isArray(data.reasons)
      ? data.reasons.map((r) => `<li>${r}</li>`).join("")
      : "";

    box.classList.remove("hidden");
    box.innerHTML = `
      <strong>Claim confidence</strong>
      <span class="score-badge ${sc}">${data.confidence_score ?? "N/A"}/100 · ${data.suggested_status || "Review"}</span>
      <p class="helper-text">Source: ${data.source || "local"}${data.model_used ? ` · ${data.model_used}` : ""}${data.avg_exif_score != null ? ` · Avg EXIF ${data.avg_exif_score}/100` : ""}${data.rag_count != null ? ` · RAG ${data.rag_count}` : ""}${data.images_sent != null ? ` · Images ${data.images_sent}` : ""}${data.vision_used ? " · vision" : ""}</p>
      ${data.vision_summary ? `<p class="helper-text"><strong>Vision:</strong> ${data.vision_summary}</p>` : ""}
      ${reasons ? `<ul class="list-clean score-reasons">${reasons}</ul>` : ""}
      ${data.note ? `<p class="helper-text">${data.note}</p>` : ""}
      ${data.groq_error ? `<p class="helper-text"><strong>Groq error:</strong> ${data.groq_error}</p>` : ""}
      ${data.groq_raw ? `<pre class="helper-text" style="white-space:pre-wrap;font-size:0.75rem;max-height:120px;overflow:auto;">${String(data.groq_raw).replace(/</g,'&lt;')}</pre>` : ""}
    `;
  } catch (err) {
    box.classList.remove("hidden");
    box.innerHTML = `<strong>Score failed</strong><p class="helper-text">${err.message}</p>`;
  } finally {
    if (buttonEl) {
      buttonEl.disabled = false;
      buttonEl.textContent = oldLabel || "Get AI score";
    }
  }
}

// ---------- Boot ----------
loadDashboardData();
loadDashboardVehicles();
loadPolicyVehicles();
loadPaymentPolicies();
loadDashboardPolicies();
loadDashboardPayments();
loadDashboardClaims();
loadAdminClaims();
