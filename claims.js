async function loadClaimPolicies() {
  const policySelect = document.getElementById("claimPolicySelect");
  const policyHelp = document.getElementById("claimPolicyHelp");

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
    policyHelp.textContent = "Select the active policy under which you are claiming.";

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

document.getElementById("claimForm")?.addEventListener("submit", async (e) => {
  e.preventDefault();

  const user = getSession();
  const customerId = getCustomerId(user);

  if (!customerId) {
    alert("Please log in before filing a claim.");
    window.location.href = "login.html";
    return;
  }

  const damagePicsInput = document.getElementById("damagePics");
  const garageInvoicesInput = document.getElementById("garageInvoices");

  const formData = new FormData();
  formData.append("customer_id", customerId);
  formData.append("policy_id", document.getElementById("claimPolicySelect").value);
  formData.append("incident_date", document.getElementById("incidentDate").value);
  formData.append("incident_type", document.getElementById("incidentType").value);
  formData.append("incident_location", document.getElementById("incidentLocation").value);
  formData.append("incident_casualty", document.getElementById("incidentCasualty").value);
  formData.append("claim_description", document.getElementById("claimDescription").value);
  
  if (damagePicsInput && damagePicsInput.files.length > 0) {
    for (let i = 0; i < damagePicsInput.files.length; i++) {
      formData.append("damage_pics[]", damagePicsInput.files[i]);
    }
  }

  if (garageInvoicesInput && garageInvoicesInput.files.length > 0) {
    for (let i = 0; i < garageInvoicesInput.files.length; i++) {
      formData.append("garage_invoices[]", garageInvoicesInput.files[i]);
    }
  }

  try {
    const data = await sendRequest("/claims", {
      method: "POST",
      body: formData
    });

    alert(data.message);
    window.location.href = "dashboard.html";
  } catch (error) {
    alert(error.message);
  }
});

loadClaimPolicies();
