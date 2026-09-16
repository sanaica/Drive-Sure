const DEFAULT_API_BASE = "https://drive-sure-5gwr.onrender.com";

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
  }

  return [...new Set(candidates.map(normalizeApiBase).filter(Boolean))];
}

let activeApiBase = normalizeApiBase(
  window.DRIVESURE_API_BASE ||
  document.querySelector('meta[name="drivesure-api-base"]')?.content ||
  localStorage.getItem("drivesureApiBase") ||
  DEFAULT_API_BASE
);

function getCustomerId(user = getSession()) {
  return user?.customer_id ?? user?.id ?? user?.user_id ?? null;
}

function getArrayFromResponse(data, preferredKeys = []) {
  for (const key of preferredKeys) {
    if (Array.isArray(data?.[key])) {
      return data[key];
    }
  }

  if (Array.isArray(data)) {
    return data;
  }

  for (const value of Object.values(data || {})) {
    if (Array.isArray(value)) {
      return value;
    }
  }

  return [];
}
function saveSession(user) {
  localStorage.setItem("user", JSON.stringify(user));
  localStorage.setItem("loggedIn", "true");
}

function getSession() {
  const savedUser = localStorage.getItem("user");
  return savedUser ? JSON.parse(savedUser) : null;
}

function clearSession() {
  localStorage.removeItem("user");
  localStorage.removeItem("loggedIn");
}

document.querySelectorAll("[data-logout-link]").forEach((link) => {
  link.addEventListener("click", (event) => {
    event.preventDefault();
    clearSession();
    window.location.href = "login.html";
  });
});

function formatCurrency(value) {
  return `Rs. ${Number(value || 0).toLocaleString("en-IN")}`;
}

function formatDateLabel(value) {
  if (!value) {
    return "None";
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return "None";
  }

  return date.toLocaleDateString("en-IN", {
    day: "2-digit",
    month: "short",
    year: "numeric"
  });
}

function updateDashboardSummary({ cars = [], policies = [], payments = [] }) {
  const summaryPolicyCount = document.getElementById("summaryPolicyCount");
  const summaryPolicyText = document.getElementById("summaryPolicyText");
  const summaryTotalPaid = document.getElementById("summaryTotalPaid");
  const summaryPaymentText = document.getElementById("summaryPaymentText");
  const summaryVehicleCount = document.getElementById("summaryVehicleCount");
  const summaryVehicleText = document.getElementById("summaryVehicleText");
  const summaryLastPaymentDate = document.getElementById("summaryLastPaymentDate");
  const summaryLastPaymentText = document.getElementById("summaryLastPaymentText");

  if (
    !summaryPolicyCount ||
    !summaryPolicyText ||
    !summaryTotalPaid ||
    !summaryPaymentText ||
    !summaryVehicleCount ||
    !summaryVehicleText ||
    !summaryLastPaymentDate ||
    !summaryLastPaymentText
  ) {
    return;
  }

  const totalPaid = payments.reduce((sum, payment) => sum + Number(payment.amount || 0), 0);
  const latestPayment = payments[0];

  summaryPolicyCount.textContent = String(policies.length);
  summaryPolicyText.textContent =
    policies.length > 0
      ? `${policies.length} active coverage record${policies.length === 1 ? "" : "s"} saved for this account.`
      : "Policies you save will appear here once coverage is selected.";

  summaryTotalPaid.textContent = formatCurrency(totalPaid);
  summaryPaymentText.textContent =
    payments.length > 0
      ? `${payments.length} billing entr${payments.length === 1 ? "y has" : "ies have"} been recorded so far.`
      : "Payments recorded in the billing center will total up here.";

  summaryVehicleCount.textContent = String(cars.length);
  summaryVehicleText.textContent =
    cars.length > 0
      ? `${cars.length} vehicle profile${cars.length === 1 ? "" : "s"} ready for quotes and policies.`
      : "Add a vehicle to start linking policies and payments.";

  summaryLastPaymentDate.textContent = latestPayment ? formatDateLabel(latestPayment.payment_date) : "None";
  summaryLastPaymentText.textContent = latestPayment
    ? `${latestPayment.plan_name ? `${latestPayment.plan_name} payment` : `Payment #${latestPayment.payment_id}`} was the most recent payment activity.`
    : "Your latest billing activity will be shown here.";
}

async function sendRequest(path, options) {
  const candidates = [activeApiBase, ...getApiBaseCandidates()].filter(Boolean);
  let lastError = null;

  for (const apiBase of [...new Set(candidates)]) {
    let response;

    try {
      response = await fetch(`${apiBase}${path}`, {
        headers: {
          "Content-Type": "application/json"
        },
        ...options
      });
    } catch (_error) {
      lastError = new Error(`Cannot reach the DriveSure backend at ${apiBase}.`);
      continue;
    }

    const contentType = response.headers.get("content-type") || "";

    if (!contentType.includes("application/json")) {
      const text = await response.text();
      const looksLikeHtml = text.trim().startsWith("<!DOCTYPE") || text.trim().startsWith("<html");

      if (looksLikeHtml) {
        lastError = new Error(`The URL ${apiBase} returned an HTML page instead of JSON.`);
        continue;
      }

      lastError = new Error(`The backend at ${apiBase} returned a non-JSON response.`);
      continue;
    }

    const data = await response.json();

    if (!response.ok) {
      const baseMessage = data.message || data.error || "Request failed.";
      throw new Error(data.details ? `${baseMessage} ${data.details}` : baseMessage);
    }

    activeApiBase = apiBase;
    localStorage.setItem("drivesureApiBase", apiBase);
    return data;
  }

  throw new Error(
    `${lastError?.message || "Unable to reach the backend API."} Tried: ${[...new Set(candidates)].join(", ")}`
  );
}

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
  } catch (error) {
    alert(error.message);
  }
});

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
  } catch (error) {
    alert(error.message);
  }
});

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
  } catch (error) {
    alert(error.message);
  }
});

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
    } catch (error) {
      alert(error.message);
    }
  });
});

async function loadPolicyVehicles() {
  const vehicleSelect = document.getElementById("policyVehicleSelect");
  const vehicleHelp = document.getElementById("policyVehicleHelp");

  if (!vehicleSelect || !vehicleHelp) {
    return;
  }

  const user = getSession();

  const customerId = getCustomerId(user);

  if (!customerId) {
    clearSession();
    window.location.href = "login.html";
    return;
  }

  try {
    const data = await sendRequest(`/vehicles/${customerId}`, {
      method: "GET"
    });

    const cars = getArrayFromResponse(data, ["cars", "vehicles"]);
    vehicleSelect.innerHTML = "";

    if (cars.length === 0) {
      vehicleSelect.innerHTML = '<option value="">No vehicles found</option>';
      vehicleSelect.disabled = true;
      vehicleHelp.textContent = "No registered vehicles found. Please add a vehicle first.";
      return;
    }

    vehicleSelect.disabled = false;
    vehicleHelp.textContent = "Select the exact vehicle that should be covered under the policy.";

    const defaultOption = document.createElement("option");
    defaultOption.value = "";
    defaultOption.textContent = "Select a vehicle";
    vehicleSelect.appendChild(defaultOption);

    cars.forEach((car) => {
      const option = document.createElement("option");
      option.value = car.car_id;
      option.textContent = `${car.vehicle_type || "Vehicle"} - ${car.make} ${car.model} (${car.plate_no})`;
      vehicleSelect.appendChild(option);
    });
  } catch (error) {
    vehicleSelect.innerHTML = '<option value="">Unable to load vehicles</option>';
    vehicleSelect.disabled = true;
    vehicleHelp.textContent = error.message;
  }
}

async function loadPaymentPolicies() {
  const policySelect = document.getElementById("paymentPolicySelect");
  const policyHelp = document.getElementById("paymentPolicyHelp");

  if (!policySelect || !policyHelp) {
    return;
  }

  const user = getSession();

  const customerId = getCustomerId(user);

  if (!customerId) {
    clearSession();
    window.location.href = "login.html";
    return;
  }

  try {
    const data = await sendRequest(`/policies/${customerId}`, {
      method: "GET"
    });

    const policies = getArrayFromResponse(data, ["policies"]);
    policySelect.innerHTML = "";

    if (policies.length === 0) {
      policySelect.innerHTML = '<option value="">No policies found</option>';
      policySelect.disabled = true;
      policyHelp.textContent = "No saved policies found. Please choose a policy first.";
      return;
    }

    policySelect.disabled = false;
    policyHelp.textContent = "Select the exact policy you are paying for or updating.";

    const defaultOption = document.createElement("option");
    defaultOption.value = "";
    defaultOption.textContent = "Select a policy";
    policySelect.appendChild(defaultOption);

    policies.forEach((policy) => {
      const option = document.createElement("option");
      option.value = policy.policy_id;
      option.textContent = `${policy.plan_name}${policy.make && policy.model ? ` - ${policy.make} ${policy.model}` : ""}`;
      policySelect.appendChild(option);
    });
  } catch (error) {
    policySelect.innerHTML = '<option value="">Unable to load policies</option>';
    policySelect.disabled = true;
    policyHelp.textContent = error.message;
  }
}

document.querySelectorAll(".payment-action-btn").forEach((button) => {
  button.addEventListener("click", async () => {
    const user = getSession();
    const policySelect = document.getElementById("paymentPolicySelect");

    if (!getCustomerId(user)) {
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
    } catch (error) {
      alert(error.message);
    }
  });
});

async function loadDashboardVehicles() {
  const vehicleGrid = document.getElementById("vehicleGrid");
  const vehicleEmptyState = document.getElementById("vehicleEmptyState");
  const vehicleCount = document.getElementById("vehicleCount");

  if (!vehicleGrid || !vehicleEmptyState || !vehicleCount) {
    return;
  }

  const user = getSession();

  const customerId = getCustomerId(user);

  if (!customerId) {
    clearSession();
    window.location.href = "login.html";
    return;
  }

  try {
    const data = await sendRequest(`/vehicles/${customerId}`, {
      method: "GET"
    });

    const cars = getArrayFromResponse(data, ["cars", "vehicles"]);
    vehicleCount.textContent = `${cars.length} vehicle${cars.length === 1 ? "" : "s"}`;
    vehicleGrid.innerHTML = "";

    if (cars.length === 0) {
      vehicleEmptyState.classList.remove("hidden");
      return;
    }

    vehicleEmptyState.classList.add("hidden");

    cars.forEach((car) => {
      const card = document.createElement("article");
      card.className = "vehicle-item";
      card.innerHTML = `
        <h3>${car.vehicle_type || "Vehicle"} - ${car.make} ${car.model}</h3>
        <p class="helper-text">Registered under customer ID ${car.customer_id}</p>
        <div class="vehicle-meta">
          <span><strong>Type:</strong> ${car.vehicle_type || "Vehicle"}</span>
          <span><strong>Year:</strong> ${car.year}</span>
          <span><strong>Plate number:</strong> ${car.plate_no}</span>
        </div>
      `;
      vehicleGrid.appendChild(card);
    });
  } catch (error) {
    vehicleEmptyState.classList.remove("hidden");
    vehicleGrid.innerHTML = "";
    vehicleCount.textContent = "0 vehicles";
    vehicleEmptyState.innerHTML = `
      <strong>Unable to load vehicles right now.</strong>
      <p class="helper-text">${error.message}</p>
      <div class="stack-actions">
        <a href="addCar.html" class="btn-soft">Add vehicle manually</a>
      </div>
    `;
  }
}

async function loadDashboardPolicies() {
  const policyGrid = document.getElementById("policyGrid");
  const policyEmptyState = document.getElementById("policyEmptyState");
  const policyCount = document.getElementById("policyCount");

  if (!policyGrid || !policyEmptyState || !policyCount) {
    return;
  }

  const user = getSession();

  const customerId = getCustomerId(user);

  if (!customerId) {
    return;
  }

  try {
    const data = await sendRequest(`/policies/${customerId}`, {
      method: "GET"
    });

    const policies = getArrayFromResponse(data, ["policies"]);
    policyCount.textContent = `${policies.length} polic${policies.length === 1 ? "y" : "ies"}`;
    policyGrid.innerHTML = "";

    if (policies.length === 0) {
      policyEmptyState.classList.remove("hidden");
      return;
    }

    policyEmptyState.classList.add("hidden");

    policies.forEach((policy) => {
      const card = document.createElement("article");
      card.className = "policy-item";
      card.innerHTML = `
        <h3>${policy.plan_name}</h3>
        <p class="helper-text">${policy.coverage_type} coverage for ${policy.make && policy.model ? `${policy.make} ${policy.model}` : `vehicle #${policy.car_id}`}</p>
        <div class="policy-meta">
          <span><strong>Linked vehicle:</strong> ${policy.make && policy.model ? `${policy.make} ${policy.model}${policy.plate_no ? ` (${policy.plate_no})` : ""}` : `Vehicle #${policy.car_id}`}</span>
          <span><strong>Premium:</strong> Rs. ${Number(policy.premium_amount).toLocaleString("en-IN")} / ${String(policy.billing_cycle || "Yearly").toLowerCase()}</span>
          <span><strong>Status:</strong> ${policy.status || "Active"}</span>
        </div>
      `;
      policyGrid.appendChild(card);
    });
  } catch (error) {
    policyEmptyState.classList.remove("hidden");
    policyGrid.innerHTML = "";
    policyCount.textContent = "0 policies";
    policyEmptyState.innerHTML = `
      <strong>Unable to load policies right now.</strong>
      <p class="helper-text">${error.message}</p>
      <div class="stack-actions">
        <a href="policy.html" class="btn-soft">Choose a policy</a>
      </div>
    `;
  }
}

async function loadDashboardPayments() {
  const paymentGrid = document.getElementById("paymentGrid");
  const paymentEmptyState = document.getElementById("paymentEmptyState");
  const paymentCount = document.getElementById("paymentCount");

  if (!paymentGrid || !paymentEmptyState || !paymentCount) {
    return;
  }

  const user = getSession();

  const customerId = getCustomerId(user);

  if (!customerId) {
    return;
  }

  try {
    const data = await sendRequest(`/payments/${customerId}`, {
      method: "GET"
    });

    const payments = getArrayFromResponse(data, ["payments"]);
    paymentCount.textContent = `${payments.length} payment${payments.length === 1 ? "" : "s"}`;
    paymentGrid.innerHTML = "";

    if (payments.length === 0) {
      paymentEmptyState.classList.remove("hidden");
      return;
    }

    paymentEmptyState.classList.add("hidden");

    payments.forEach((payment) => {
      const card = document.createElement("article");
      card.className = "payment-item";
      card.innerHTML = `
        <h3>${payment.plan_name ? `${payment.plan_name} payment` : `Payment #${payment.payment_id}`}</h3>
        <p class="helper-text">${payment.plan_name ? `${payment.plan_name} policy` : "Policy payment"} via ${payment.payment_method}</p>
        <div class="payment-meta">
          <span><strong>Linked policy:</strong> ${payment.plan_name ? `${payment.plan_name}${payment.coverage_type ? ` (${payment.coverage_type})` : ""}` : `Policy #${payment.policy_id}`}</span>
          <span><strong>Amount:</strong> Rs. ${Number(payment.amount).toLocaleString("en-IN")}</span>
          <span><strong>Status:</strong> ${payment.status || "Paid"}</span>
          <span><strong>Date:</strong> ${String(payment.payment_date).slice(0, 10)}</span>
        </div>
      `;
      paymentGrid.appendChild(card);
    });
  } catch (error) {
    paymentEmptyState.classList.remove("hidden");
    paymentGrid.innerHTML = "";
    paymentCount.textContent = "0 payments";
    paymentEmptyState.innerHTML = `
      <strong>Unable to load payments right now.</strong>
      <p class="helper-text">${error.message}</p>
      <div class="stack-actions">
        <a href="payment.html" class="btn-soft">Open billing center</a>
      </div>
    `;
  }
}

async function loadDashboardData() {
  const user = getSession();

  const customerId = getCustomerId(user);

  if (!customerId) {
    return;
  }

  const [carsResult, policiesResult, paymentsResult] = await Promise.allSettled([
    sendRequest(`/vehicles/${customerId}`, { method: "GET" }),
    sendRequest(`/policies/${customerId}`, { method: "GET" }),
    sendRequest(`/payments/${customerId}`, { method: "GET" })
  ]);

  updateDashboardSummary({
    cars: carsResult.status === "fulfilled" ? getArrayFromResponse(carsResult.value, ["cars", "vehicles"]) : [],
    policies: policiesResult.status === "fulfilled" ? getArrayFromResponse(policiesResult.value, ["policies"]) : [],
    payments: paymentsResult.status === "fulfilled" ? getArrayFromResponse(paymentsResult.value, ["payments"]) : []
  });
}

loadDashboardData();
loadDashboardVehicles();
loadPolicyVehicles();
loadPaymentPolicies();
loadDashboardPolicies();
loadDashboardPayments();
