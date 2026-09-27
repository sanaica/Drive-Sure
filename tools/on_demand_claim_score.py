#!/usr/bin/env python3
"""
DriveSure – On-demand claim confidence helper

Call this ONLY when an admin reviews a claim (saves API credits).

Modes:
  1) Local heuristic (default, FREE – no API key)
  2) Optional Groq text model if GROQ_API_KEY is set and --use-groq is passed

Usage (local):
  python on_demand_claim_score.py --vehicle-type Car --incident-date 22_09_2026 \\
    --day Mon --policy-premium 18600 --location "Mahim, Mumbai" \\
    --damage "Front bumper cracked" --invoice 45000 --plan "Comprehensive Plus" \\
    --description "Hit a tree"

Usage (Groq – only when you want AI opinion):
  set GROQ_API_KEY=your_key
  python on_demand_claim_score.py ... --use-groq

Prints JSON:
  {
    "confidence_score": 0-100,
    "suggested_status": "Accepted" | "Denied" | "Review",
    "reasons": [...],
    "source": "local" | "groq"
  }
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
from datetime import datetime


def parse_date(s: str):
    """Accept DD_MM_YYYY or YYYY-MM-DD."""
    s = s.strip()
    for fmt in ("%d_%m_%Y", "%Y-%m-%d", "%d-%m-%Y", "%d/%m/%Y"):
        try:
            return datetime.strptime(s, fmt)
        except ValueError:
            continue
    return None


def local_score(args) -> dict:
    """
    Cheap rule-based confidence (no API).
    Higher = more likely a legitimate payable claim under typical comprehensive terms.
    """
    score = 50
    reasons = []

    plan = (args.plan or "").lower()
    itype = (args.incident_type or "").lower()
    damage = (args.damage or "").lower()
    desc = (args.description or "").lower()
    invoice = int(args.invoice or 0)
    premium = int(args.policy_premium or 0)

    # Plan coverage
    if "third-party" in plan or "third party" in plan:
        if itype in ("theft", "weather", "vandalism") or "own" in damage:
            score -= 30
            reasons.append("Third-party plan usually excludes own-damage / theft of own vehicle")
        else:
            score -= 10
            reasons.append("Third-party plan – limited own-damage cover")
    elif "comprehensive" in plan or "fleet" in plan:
        score += 15
        reasons.append("Comprehensive / fleet plan supports own-damage claims")

    # Invoice vs premium sanity
    if premium > 0:
        ratio = invoice / premium
        if ratio > 6:
            score -= 20
            reasons.append(f"Invoice (Rs.{invoice}) is very high vs premium (Rs.{premium})")
        elif ratio > 3:
            score -= 10
            reasons.append("Invoice significantly higher than annual premium")
        else:
            score += 5
            reasons.append("Invoice amount within a plausible range vs premium")

    # Description / damage consistency (keyword overlap)
    keywords = set(re.findall(r"[a-z]{3,}", damage + " " + desc))
    if len(keywords) >= 4:
        score += 5
        reasons.append("Damage description has reasonable detail")
    else:
        score -= 5
        reasons.append("Damage description is thin – may need surveyor notes")

    # Incident type heuristics
    if itype == "theft":
        score -= 5
        reasons.append("Theft claims often need FIR; verify documents")
    if itype == "collision":
        score += 5
        reasons.append("Collision is a common covered peril under OD")

    # Day of week – mild noise only (no strong rule)
    if args.day:
        reasons.append(f"Incident day: {args.day}")

    # Location present
    if args.location and len(args.location) > 5:
        score += 5
        reasons.append("Location provided")
    else:
        score -= 10
        reasons.append("Location missing or too vague")

    score = max(0, min(100, score))

    if score >= 70:
        suggested = "Accepted"
    elif score <= 40:
        suggested = "Denied"
    else:
        suggested = "Review"

    return {
        "confidence_score": score,
        "suggested_status": suggested,
        "reasons": reasons,
        "source": "local",
        "inputs_echo": {
            "vehicle_type": args.vehicle_type,
            "incident_date": args.incident_date,
            "day_of_week": args.day,
            "policy_premium": premium,
            "location": args.location,
            "damage_done": args.damage,
            "garage_invoice_amount": invoice,
            "policy_plan": args.plan,
            "incident_type": args.incident_type,
        },
    }


def groq_score(args) -> dict:
    """Optional: call Groq text model once for this claim only."""
    try:
        from urllib.request import Request, urlopen
    except ImportError:
        return {"error": "urllib missing", "source": "groq"}

    api_key = os.environ.get("GROQ_API_KEY", "").strip()
    if not api_key:
        return {
            "error": "GROQ_API_KEY not set",
            "source": "groq",
            "hint": "export GROQ_API_KEY=... or use local mode without --use-groq",
        }

    prompt = f"""You are an Indian motor insurance claims assistant.
Given this claim summary, return ONLY valid JSON with keys:
  confidence_score (0-100 integer),
  suggested_status ("Accepted" or "Denied" or "Review"),
  reasons (array of short strings).

Claim:
- Vehicle type: {args.vehicle_type}
- Plan: {args.plan}
- Policy premium (Rs): {args.policy_premium}
- Incident type: {args.incident_type}
- Incident date: {args.incident_date} ({args.day})
- Location: {args.location}
- Damage: {args.damage}
- Garage invoice (Rs): {args.invoice}
- Description: {args.description}

Be conservative. Prefer Review when information is incomplete.
"""

    body = json.dumps({
        "model": os.environ.get("GROQ_MODEL", "openai/gpt-oss-20b"),
        "messages": [
            {"role": "system", "content": "Reply with JSON only. No markdown."},
            {"role": "user", "content": prompt},
        ],
        "temperature": 0.2,
        "max_tokens": 400,
    }).encode("utf-8")

    req = Request(
        "https://api.groq.com/openai/v1/chat/completions",
        data=body,
        headers={
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
        },
        method="POST",
    )

    try:
        with urlopen(req, timeout=60) as resp:
            data = json.loads(resp.read().decode("utf-8"))
        text = data["choices"][0]["message"]["content"]
        # extract JSON object
        start = text.find("{")
        end = text.rfind("}")
        if start >= 0 and end > start:
            parsed = json.loads(text[start : end + 1])
            parsed["source"] = "groq"
            return parsed
        return {"error": "No JSON in model response", "raw": text[:500], "source": "groq"}
    except Exception as e:
        return {"error": str(e), "source": "groq"}


def main():
    p = argparse.ArgumentParser(description="On-demand claim confidence score")
    p.add_argument("--vehicle-type", default="Car")
    p.add_argument("--incident-date", default="", help="DD_MM_YYYY")
    p.add_argument("--day", default="", help="Mon/Tue/...")
    p.add_argument("--policy-premium", type=int, default=0)
    p.add_argument("--location", default="")
    p.add_argument("--damage", default="")
    p.add_argument("--invoice", type=int, default=0)
    p.add_argument("--plan", default="Comprehensive Plus")
    p.add_argument("--incident-type", default="Collision")
    p.add_argument("--description", default="")
    p.add_argument("--use-groq", action="store_true", help="Call Groq API (uses credits)")
    args = p.parse_args()

    # Auto day-of-week if date given and day empty
    if args.incident_date and not args.day:
        dt = parse_date(args.incident_date)
        if dt:
            args.day = dt.strftime("%a")

    if args.use_groq:
        result = groq_score(args)
        # fallback to local if Groq fails
        if "error" in result:
            local = local_score(args)
            local["groq_error"] = result.get("error")
            result = local
    else:
        result = local_score(args)

    print(json.dumps(result, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
