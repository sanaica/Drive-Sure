#!/usr/bin/env python3
"""
DriveSure – simple EXIF / image authenticity helper.

Usage:
  python analyze_evidence.py <image_path> [incident_date_YYYY-MM-DD]

Prints one JSON object to stdout, for example:
  {
    "confidence_score": 72,
    "is_blurry": false,
    "has_exif": true,
    "camera_make": "Apple",
    "camera_model": "iPhone 13",
    "exif_timestamp": "2026-09-22 14:32:01",
    "exif_latitude": 19.065,
    "exif_longitude": 72.877,
    "notes": ["EXIF present", "GPS present", ...]
  }

Requires: Pillow  (pip install Pillow)
Optional:  numpy  (better blur check; falls back without it)
"""

import json
import sys
import os
from datetime import datetime

try:
    from PIL import Image, ExifTags
except ImportError:
    print(json.dumps({
        "error": "Pillow not installed. Run: pip install Pillow",
        "confidence_score": 0,
        "notes": ["Pillow missing"]
    }))
    sys.exit(0)

# Reverse map: numeric EXIF tag id -> name
_TAG_NAMES = {v: k for k, v in ExifTags.TAGS.items()}


def _get_exif_dict(img):
    raw = img._getexif() if hasattr(img, "_getexif") else None
    if not raw:
        # Pillow 6+ sometimes stores via getexif()
        try:
            exif = img.getexif()
            if exif:
                raw = {k: v for k, v in exif.items()}
        except Exception:
            raw = None
    if not raw:
        return {}
    return {_TAG_NAMES.get(k, str(k)): v for k, v in raw.items()}


def _dms_to_decimal(dms, ref):
    """Convert GPS DMS tuple to decimal degrees."""
    try:
        deg = float(dms[0])
        minute = float(dms[1])
        sec = float(dms[2])
        val = deg + minute / 60.0 + sec / 3600.0
        if ref in ("S", "W"):
            val = -val
        return round(val, 7)
    except Exception:
        return None


def _parse_gps(exif):
    gps_info = exif.get("GPSInfo")
    if not gps_info or not isinstance(gps_info, dict):
        return None, None

    # GPSInfo keys are often numeric; map with GPSTAGS if available
    try:
        from PIL.ExifTags import GPSTAGS
        gps = {GPSTAGS.get(k, k): v for k, v in gps_info.items()}
    except Exception:
        gps = gps_info

    lat = lon = None
    if "GPSLatitude" in gps and "GPSLatitudeRef" in gps:
        lat = _dms_to_decimal(gps["GPSLatitude"], str(gps["GPSLatitudeRef"]))
    if "GPSLongitude" in gps and "GPSLongitudeRef" in gps:
        lon = _dms_to_decimal(gps["GPSLongitude"], str(gps["GPSLongitudeRef"]))
    return lat, lon


def _parse_timestamp(exif):
    for key in ("DateTimeOriginal", "DateTimeDigitized", "DateTime"):
        val = exif.get(key)
        if not val:
            continue
        try:
            # EXIF format: "YYYY:MM:DD HH:MM:SS"
            s = str(val).strip()
            dt = datetime.strptime(s, "%Y:%m:%d %H:%M:%S")
            return dt.strftime("%Y-%m-%d %H:%M:%S")
        except Exception:
            continue
    return None


def _blur_score(img):
    """
    Rough blur check.
    Higher Laplacian variance => sharper.
    Returns (is_blurry: bool, variance: float|None)
    """
    try:
        import numpy as np
        gray = img.convert("L")
        # Downscale large images for speed
        gray.thumbnail((800, 800))
        arr = np.asarray(gray, dtype=np.float64)
        # Simple Laplacian kernel approximation via gradients
        gy, gx = np.gradient(arr)
        lap = np.abs(gx) + np.abs(gy)
        var = float(lap.var())
        # Threshold is heuristic; photos from phones are usually > 50-100
        return var < 40.0, round(var, 2)
    except Exception:
        # Without numpy, skip blur detection
        return False, None


def analyze(path, incident_date=None):
    notes = []
    score = 40  # start neutral-low

    if not os.path.isfile(path):
        return {
            "confidence_score": 0,
            "is_blurry": None,
            "has_exif": False,
            "notes": [f"File not found: {path}"]
        }

    ext = os.path.splitext(path)[1].lower()
    if ext not in (".jpg", ".jpeg", ".png", ".webp", ".tiff", ".tif", ".bmp"):
        return {
            "confidence_score": 30,
            "is_blurry": None,
            "has_exif": False,
            "file_type": ext,
            "notes": ["Not an image file – skip EXIF check (e.g. PDF invoice)"]
        }

    try:
        img = Image.open(path)
    except Exception as e:
        return {
            "confidence_score": 10,
            "is_blurry": None,
            "has_exif": False,
            "notes": [f"Could not open image: {e}"]
        }

    exif = _get_exif_dict(img)
    has_exif = bool(exif)

    camera_make = str(exif.get("Make", "") or "").strip() or None
    camera_model = str(exif.get("Model", "") or "").strip() or None
    software = str(exif.get("Software", "") or "").strip() or None
    timestamp = _parse_timestamp(exif)
    lat, lon = _parse_gps(exif)
    is_blurry, blur_var = _blur_score(img)

    # ---- scoring ----
    if has_exif:
        score += 20
        notes.append("EXIF metadata present")
    else:
        score -= 15
        notes.append("No EXIF metadata (possible screenshot or heavily edited)")

    if camera_make or camera_model:
        score += 15
        notes.append(f"Camera: {(camera_make or '')} {(camera_model or '')}".strip())
    else:
        notes.append("No camera make/model in EXIF")

    if timestamp:
        score += 15
        notes.append(f"Photo timestamp: {timestamp}")
        if incident_date:
            try:
                photo_day = timestamp[:10]
                if photo_day == incident_date:
                    score += 10
                    notes.append("Timestamp matches incident date")
                else:
                    # within 3 days is still ok-ish
                    d1 = datetime.strptime(photo_day, "%Y-%m-%d")
                    d2 = datetime.strptime(incident_date, "%Y-%m-%d")
                    delta = abs((d1 - d2).days)
                    if delta <= 3:
                        score += 5
                        notes.append(f"Timestamp within {delta} day(s) of incident")
                    else:
                        score -= 10
                        notes.append(f"Timestamp differs from incident by {delta} day(s)")
            except Exception:
                pass
    else:
        notes.append("No DateTimeOriginal in EXIF")

    if lat is not None and lon is not None:
        score += 15
        notes.append(f"GPS present: {lat}, {lon}")
    else:
        notes.append("No GPS coordinates in EXIF")

    if software:
        soft_lower = software.lower()
        editors = ("photoshop", "gimp", "snapseed", "lightroom", "picsart", "canva")
        if any(e in soft_lower for e in editors):
            score -= 15
            notes.append(f"Editing software detected: {software}")
        else:
            notes.append(f"Software tag: {software}")

    if is_blurry:
        score -= 10
        notes.append(f"Image looks blurry (var={blur_var})")
    elif blur_var is not None:
        notes.append(f"Image sharpness OK (var={blur_var})")

    # clamp
    score = max(0, min(100, score))

    return {
        "confidence_score": score,
        "is_blurry": is_blurry,
        "blur_variance": blur_var,
        "has_exif": has_exif,
        "camera_make": camera_make,
        "camera_model": camera_model,
        "software": software,
        "exif_timestamp": timestamp,
        "exif_latitude": lat,
        "exif_longitude": lon,
        "notes": notes
    }


def main():
    if len(sys.argv) < 2:
        print(json.dumps({"error": "Usage: analyze_evidence.py <path> [incident_date]", "confidence_score": 0}))
        sys.exit(0)

    path = sys.argv[1]
    incident_date = sys.argv[2] if len(sys.argv) > 2 else None
    result = analyze(path, incident_date)
    print(json.dumps(result, default=str))


if __name__ == "__main__":
    main()
