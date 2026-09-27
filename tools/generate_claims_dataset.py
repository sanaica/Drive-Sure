#!/usr/bin/env python3
"""
DriveSure – Synthetic Indian vehicle claims dataset generator

Generates realistic-looking past claims for RAG / training demos.
Does NOT include EXIF (your app already scores EXIF separately).

Usage:
  python generate_claims_dataset.py
  python generate_claims_dataset.py --rows 500 --out claims_india.csv

Output columns:
  claim_id, vehicle_type, make, model, year, plate_no,
  incident_date, day_of_week, incident_type, location, city, state,
  damage_done, garage_invoice_amount, policy_premium, policy_plan,
  claim_status, decision_reason, description
"""

from __future__ import annotations

import argparse
import csv
import random
from datetime import datetime, timedelta
from pathlib import Path

# ---------------------------------------------------------------------------
# Seed data (India-focused)
# ---------------------------------------------------------------------------

VEHICLE_TYPES = ["Car", "Bike", "Scooter", "Truck"]

MAKES_MODELS = {
    "Car": [
        ("Maruti", "Swift"), ("Maruti", "Baleno"), ("Maruti", "WagonR"),
        ("Hyundai", "i20"), ("Hyundai", "Creta"), ("Hyundai", "Venue"),
        ("Honda", "City"), ("Honda", "Amaze"), ("Tata", "Nexon"),
        ("Tata", "Punch"), ("Mahindra", "XUV700"), ("Mahindra", "Scorpio"),
        ("Toyota", "Innova"), ("Kia", "Seltos"), ("BMW", "3 Series"),
        ("Mercedes", "C-Class"),
    ],
    "Bike": [
        ("Hero", "Splendor"), ("Honda", "Activa"), ("Bajaj", "Pulsar"),
        ("TVS", "Apache"), ("Royal Enfield", "Classic 350"), ("Yamaha", "R15"),
    ],
    "Scooter": [
        ("Honda", "Activa 6G"), ("TVS", "Jupiter"), ("Suzuki", "Access"),
        ("Yamaha", "Fascino"), ("Ather", "450X"),
    ],
    "Truck": [
        ("Tata", "Ace"), ("Ashok Leyland", "Dost"), ("Mahindra", "Bolero Pickup"),
        ("Eicher", "Pro 2049"),
    ],
}

INCIDENT_TYPES = ["Collision", "Theft", "Weather", "Vandalism", "Other"]

CITIES = [
    ("Mumbai", "Maharashtra"), ("Pune", "Maharashtra"), ("Nagpur", "Maharashtra"),
    ("Thane", "Maharashtra"), ("Nashik", "Maharashtra"),
    ("Delhi", "Delhi"), ("Noida", "Uttar Pradesh"), ("Gurgaon", "Haryana"),
    ("Bengaluru", "Karnataka"), ("Mysuru", "Karnataka"),
    ("Chennai", "Tamil Nadu"), ("Coimbatore", "Tamil Nadu"),
    ("Hyderabad", "Telangana"), ("Ahmedabad", "Gujarat"), ("Surat", "Gujarat"),
    ("Kolkata", "West Bengal"), ("Jaipur", "Rajasthan"), ("Lucknow", "Uttar Pradesh"),
    ("Indore", "Madhya Pradesh"), ("Bhopal", "Madhya Pradesh"),
    ("Kochi", "Kerala"), ("Chandigarh", "Chandigarh"),
]

AREAS = [
    "Andheri East", "Bandra West", "Mahim", "Powai", "Whitefield", "Koramangala",
    "Indiranagar", "Hinjewadi", "Baner", "Connaught Place", "Saket", "Gachibowli",
    "Banjara Hills", "Salt Lake", "Park Street", "T Nagar", "Adyar", "Sector 18",
    "MG Road", "Civil Lines", "Ring Road", "Highway NH48", "Outer Ring Road",
]

DAMAGE_TEMPLATES = {
    "Collision": [
        "Front bumper cracked, headlamp broken, bonnet dented",
        "Rear bumper smashed, tail light damaged, trunk lid bent",
        "Driver side door dent, side mirror broken, paint scratches",
        "Front left fender damaged, alloy scratched after hitting divider",
        "Multiple panel dents after multi-vehicle pile-up",
        "Windscreen shattered, dashboard airbag deployed",
    ],
    "Theft": [
        "Vehicle stolen from residential parking; recovered with ignition damage",
        "Two-wheeler stolen; recovered with missing battery and scratches",
        "Break-in attempt; door lock forced, interior vandalized",
        "Catalytic converter / parts theft; underbody damage",
    ],
    "Weather": [
        "Hail damage on roof and bonnet",
        "Flood water entered cabin; electrical short suspected",
        "Tree branch fell on roof during storm",
        "Cyclone-related panel dents and broken glass",
    ],
    "Vandalism": [
        "Paint keying across driver door and rear panel",
        "Windows smashed; stereo removed",
        "Tyres slashed; side mirror broken",
    ],
    "Other": [
        "Parking scrape against pillar; minor bumper damage",
        "Animal hit on highway; front grille damaged",
        "Garage mishandling; new scratches on rear quarter panel",
    ],
}

PLANS = [
    ("Essential Third-Party", 4500, 9000),
    ("Comprehensive Plus", 12000, 28000),
    ("Premium Fleet Protect", 25000, 45000),
]

# Decision rules (simple heuristics → realistic accept/deny + reason)
APPROVE_REASONS = [
    "Damage consistent with reported incident and policy covers this peril",
    "Police FIR attached; theft claim within policy terms",
    "Surveyor confirmed repair estimate within IDV limits",
    "Own-damage cover active; deductible applied; claim approved",
    "Weather-related loss covered under comprehensive plan",
    "Invoice from authorized garage; amount verified",
]

DENY_REASONS = [
    "Incident date outside policy period",
    "Third-party only policy; own-damage not covered",
    "Delayed intimation beyond allowed claim window",
    "Damage pattern inconsistent with stated cause",
    "Suspected pre-existing damage / wear and tear exclusion",
    "Missing FIR for theft claim",
    "Garage invoice amount exceeds surveyor estimate significantly",
    "Policy lapsed at time of incident",
    "Drunk driving / policy violation indicated in report",
]


def random_plate(state_code: str | None = None) -> str:
    codes = ["MH", "DL", "KA", "TN", "TS", "GJ", "WB", "RJ", "UP", "MP", "KL", "HR"]
    st = state_code or random.choice(codes)
    rto = random.randint(1, 99)
    series = "".join(random.choices("ABCDEFGHJKLMNPRSTUVWXYZ", k=2))
    num = random.randint(1000, 9999)
    return f"{st} {rto:02d} {series} {num}"


def random_incident_date(start_year: int = 2022, end_year: int = 2026) -> datetime:
    start = datetime(start_year, 1, 1)
    end = datetime(end_year, 12, 28)
    delta = (end - start).days
    return start + timedelta(days=random.randint(0, delta))


def decide_claim(
    incident_type: str,
    plan_name: str,
    invoice: int,
    premium: int,
    days_to_file: int,
) -> tuple[str, str]:
    """Return (Accepted|Denied, reason)."""
    score = 0.55  # base chance to approve

    if plan_name == "Essential Third-Party" and incident_type != "Collision":
        # TP-only rarely pays own damage / theft of own vehicle
        if incident_type in ("Theft", "Weather", "Vandalism", "Other"):
            return "Denied", "Third-party only policy; own-damage not covered"

    if plan_name == "Comprehensive Plus":
        score += 0.15
    if plan_name == "Premium Fleet Protect":
        score += 0.2

    if days_to_file > 30:
        score -= 0.35
    elif days_to_file > 14:
        score -= 0.15

    # Very high invoice vs premium → more scrutiny
    if invoice > premium * 3:
        score -= 0.2
    if invoice > premium * 5:
        score -= 0.25

    if incident_type == "Theft" and random.random() < 0.18:
        return "Denied", "Missing FIR for theft claim"

    if random.random() < score:
        return "Accepted", random.choice(APPROVE_REASONS)

    # Pick a denial reason that fits the situation
    if plan_name == "Essential Third-Party":
        return "Denied", "Third-party only policy; own-damage not covered"
    if days_to_file > 30:
        return "Denied", "Delayed intimation beyond allowed claim window"
    if invoice > premium * 5:
        return "Denied", "Garage invoice amount exceeds surveyor estimate significantly"
    return "Denied", random.choice([
        "Damage pattern inconsistent with stated cause",
        "Suspected pre-existing damage / wear and tear exclusion",
        "Policy lapsed at time of incident",
        "Incident date outside policy period",
    ])


def generate_row(i: int) -> dict:
    vtype = random.choices(VEHICLE_TYPES, weights=[55, 25, 12, 8])[0]
    make, model = random.choice(MAKES_MODELS[vtype])
    year = random.randint(2015, 2025)
    city, state = random.choice(CITIES)
    area = random.choice(AREAS)
    location = f"{area}, {city}"

    incident_type = random.choices(
        INCIDENT_TYPES, weights=[50, 15, 12, 10, 13]
    )[0]
    damage = random.choice(DAMAGE_TEMPLATES[incident_type])

    plan_name, prem_lo, prem_hi = random.choice(PLANS)
    premium = random.randint(prem_lo, prem_hi)

    # Garage invoice roughly related to vehicle class + severity
    base = {
        "Car": (8000, 120000),
        "Bike": (2000, 35000),
        "Scooter": (1500, 25000),
        "Truck": (15000, 200000),
    }[vtype]
    invoice = random.randint(base[0], base[1])

    incident_dt = random_incident_date()
    day_name = incident_dt.strftime("%a")  # Mon, Tue, ...
    date_str = incident_dt.strftime("%d_%m_%Y")

    days_to_file = random.randint(0, 45)
    status, reason = decide_claim(
        incident_type, plan_name, invoice, premium, days_to_file
    )

    description = (
        f"{incident_type} at {location}. {damage}. "
        f"Reported after {days_to_file} day(s)."
    )

    state_code = {
        "Maharashtra": "MH", "Delhi": "DL", "Karnataka": "KA",
        "Tamil Nadu": "TN", "Telangana": "TS", "Gujarat": "GJ",
        "West Bengal": "WB", "Rajasthan": "RJ", "Uttar Pradesh": "UP",
        "Madhya Pradesh": "MP", "Kerala": "KL", "Haryana": "HR",
        "Chandigarh": "CH",
    }.get(state, "MH")

    return {
        "claim_id": f"SYN-{10000 + i}",
        "vehicle_type": vtype,
        "make": make,
        "model": model,
        "year": year,
        "plate_no": random_plate(state_code),
        "incident_date": date_str,
        "day_of_week": day_name,
        "incident_type": incident_type,
        "location": location,
        "city": city,
        "state": state,
        "damage_done": damage,
        "garage_invoice_amount": invoice,
        "policy_premium": premium,
        "policy_plan": plan_name,
        "claim_status": status,
        "decision_reason": reason,
        "description": description,
        "days_to_intimate": days_to_file,
    }


def main():
    parser = argparse.ArgumentParser(description="Generate synthetic India vehicle claims dataset")
    parser.add_argument("--rows", type=int, default=300, help="Number of claims (default 300)")
    parser.add_argument(
        "--out",
        type=str,
        default="claims_india_synthetic.csv",
        help="Output CSV path",
    )
    parser.add_argument("--seed", type=int, default=42, help="Random seed for reproducibility")
    args = parser.parse_args()

    random.seed(args.seed)
    out_path = Path(args.out)
    out_path.parent.mkdir(parents=True, exist_ok=True)

    rows = [generate_row(i) for i in range(args.rows)]
    fieldnames = list(rows[0].keys())

    with out_path.open("w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)

    accepted = sum(1 for r in rows if r["claim_status"] == "Accepted")
    denied = args.rows - accepted
    print(f"Wrote {args.rows} rows → {out_path.resolve()}")
    print(f"  Accepted: {accepted}  |  Denied: {denied}")
    print("Columns:", ", ".join(fieldnames))


if __name__ == "__main__":
    main()
