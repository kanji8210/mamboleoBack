"""Extract Kenya location names from article text using a curated regex list.

Returns Location objects sorted most-specific first (neighbourhood > town > county).
Coordinates are approximate geographic centres — used as fallback when Nominatim
is unavailable or rate-limited.
"""
from __future__ import annotations

import re
from dataclasses import dataclass


@dataclass
class Location:
    name: str
    lat: float
    lng: float
    specificity: int  # 3 = neighbourhood, 2 = town/city, 1 = county


# (name, lat, lng, specificity)
_RAW: list[tuple[str, float, float, int]] = [
    # ── Nairobi neighbourhoods ────────────────────────────────────────────────
    ("Westlands",      -1.2653,  36.8026, 3),
    ("Karen",          -1.3210,  36.7093, 3),
    ("Kibera",         -1.3122,  36.7888, 3),
    ("Mathare",        -1.2564,  36.8607, 3),
    ("Korogocho",      -1.2367,  36.8775, 3),
    ("Eastleigh",      -1.2736,  36.8448, 3),
    ("Kasarani",       -1.2198,  36.8893, 3),
    ("Embakasi",       -1.3236,  36.9022, 3),
    ("Langata",        -1.3426,  36.7640, 3),
    ("Parklands",      -1.2567,  36.8130, 3),
    ("Huruma",         -1.2574,  36.8540, 3),
    ("Kayole",         -1.2720,  36.9032, 3),
    ("Dandora",        -1.2550,  36.9015, 3),
    ("Githurai",       -1.1842,  36.9155, 3),
    ("Ruaraka",        -1.2378,  36.8700, 3),
    ("Kileleshwa",     -1.2820,  36.7950, 3),
    ("Lavington",      -1.2868,  36.7828, 3),
    ("South B",        -1.3200,  36.8270, 3),
    ("South C",        -1.3320,  36.8220, 3),
    ("Buruburu",       -1.2990,  36.8770, 3),
    ("Umoja",          -1.2876,  36.9005, 3),
    ("Industrial Area",-1.3100,  36.8450, 3),
    ("Nairobi CBD",    -1.2921,  36.8219, 3),
    ("Thika Road",     -1.2200,  36.8820, 3),
    ("Mombasa Road",   -1.3500,  36.8500, 3),
    ("Waiyaki Way",    -1.2650,  36.7700, 3),
    ("Gitaru",         -1.2500,  36.7200, 3),
    ("Rongai",         -1.3940,  36.7440, 3),
    ("Kikuyu",         -1.2480,  36.6700, 3),
    ("Ruiru",          -1.1445,  36.9621, 3),
    ("Juja",           -1.1000,  37.0167, 3),
    ("Ngong",          -1.3636,  36.6593, 3),
    ("Limuru",         -1.1142,  36.6386, 3),
    # ── Mombasa areas ─────────────────────────────────────────────────────────
    ("Nyali",          -4.0470,  39.6900, 3),
    ("Kisauni",        -3.9980,  39.6980, 3),
    ("Likoni",         -4.0900,  39.6650, 3),
    ("Changamwe",      -4.0350,  39.6400, 3),
    ("Old Town",       -4.0630,  39.6670, 3),
    ("Bamburi",        -3.9780,  39.7230, 3),
    ("Diani",          -4.3100,  39.5850, 3),
    ("Shanzu",         -3.9600,  39.7300, 3),
    ("Mtwapa",         -3.9500,  39.7300, 3),
    # ── Kisumu areas ──────────────────────────────────────────────────────────
    ("Kondele",        -0.1030,  34.7530, 3),
    ("Manyatta",       -0.0780,  34.7850, 3),
    ("Migosi",         -0.0900,  34.7700, 3),
    ("Kaloleni",       -0.0820,  34.7620, 3),
    ("Kisumu CBD",     -0.1022,  34.7617, 3),
    # ── Nakuru areas ──────────────────────────────────────────────────────────
    ("Kaptembwa",      -0.2951,  36.0543, 3),
    ("Lanet",          -0.2660,  36.0833, 3),
    ("Section 58",     -0.3050,  36.0700, 3),
    ("Free Area",      -0.3120,  36.0900, 3),
    # ── Eldoret areas ─────────────────────────────────────────────────────────
    ("Langas",          0.5390,  35.2970, 3),
    ("Pioneer",         0.5200,  35.2790, 3),
    ("Kapseret",        0.5550,  35.3040, 3),
    # ── Major cities / towns ──────────────────────────────────────────────────
    ("Nairobi",        -1.2921,  36.8219, 2),
    ("Mombasa",        -4.0435,  39.6682, 2),
    ("Kisumu",         -0.1022,  34.7617, 2),
    ("Nakuru",         -0.3031,  36.0800, 2),
    ("Eldoret",         0.5143,  35.2698, 2),
    ("Thika",          -1.0332,  37.0693, 2),
    ("Malindi",        -3.2175,  40.1169, 2),
    ("Kitale",          1.0154,  35.0062, 2),
    ("Garissa",        -0.4532,  39.6401, 2),
    ("Kakamega",        0.2820,  34.7519, 2),
    ("Nyeri",          -0.4167,  36.9500, 2),
    ("Meru",            0.0470,  37.6490, 2),
    ("Embu",           -0.5330,  37.4580, 2),
    ("Machakos",       -1.5177,  37.2634, 2),
    ("Kericho",        -0.3689,  35.2863, 2),
    ("Kisii",          -0.6817,  34.7660, 2),
    ("Bungoma",         0.5635,  34.5607, 2),
    ("Busia",           0.4610,  34.0960, 2),
    ("Lamu",           -2.2694,  40.9021, 2),
    ("Voi",            -3.3958,  38.5552, 2),
    ("Naivasha",       -0.7149,  36.4302, 2),
    ("Nanyuki",         0.0167,  37.0739, 2),
    ("Isiolo",          0.3530,  37.5826, 2),
    ("Wajir",           1.7471,  40.0573, 2),
    ("Mandera",         3.9366,  41.8670, 2),
    ("Marsabit",        2.3364,  37.9897, 2),
    ("Lodwar",          3.1190,  35.5970, 2),
    ("Migori",         -1.0634,  34.4731, 2),
    ("Homa Bay",       -0.5273,  34.4571, 2),
    ("Siaya",           0.0607,  34.2878, 2),
    ("Kilifi",         -3.6305,  39.8499, 2),
    ("Kwale",          -4.1730,  39.4573, 2),
    ("Bomet",          -0.7792,  35.3419, 2),
    ("Kabarnet",        0.4921,  35.7423, 2),
    ("Maralal",         1.0993,  36.7007, 2),
    ("Kajiado",        -1.8524,  36.7765, 2),
    ("Kapenguria",      1.2399,  35.1120, 2),
    ("Mumias",          0.3371,  34.4875, 2),
    ("Webuye",          0.6144,  34.7706, 2),
    ("Malaba",          0.6399,  34.2826, 2),
    ("Moyale",          3.5229,  39.0599, 2),
    ("Wote",           -1.7845,  37.6342, 2),
    ("Kitui",          -1.3674,  38.0126, 2),
    ("Mwingi",         -0.9350,  38.0605, 2),
    ("Taveta",         -3.3925,  37.6776, 2),
    ("Vihiga",          0.0760,  34.7213, 2),
    ("Keroka",         -0.7220,  34.9470, 2),
    ("Nyamira",        -0.5671,  34.9346, 2),
    ("Rongo",          -1.1604,  34.5995, 2),
    ("Awendo",         -0.9950,  34.5905, 2),
    # ── Counties ──────────────────────────────────────────────────────────────
    ("Nairobi County",  -1.2921,  36.8219, 1),
    ("Mombasa County",  -4.0435,  39.6682, 1),
    ("Kisumu County",   -0.1022,  34.7617, 1),
    ("Nakuru County",   -0.3031,  36.0800, 1),
    ("Uasin Gishu",      0.5143,  35.2698, 1),
    ("Kiambu",          -1.0312,  36.7913, 1),
    ("Kajiado County",  -1.8524,  36.7765, 1),
    ("Machakos County", -1.5177,  37.2634, 1),
    ("Makueni",         -2.2560,  37.8937, 1),
    ("Murang'a",        -0.7154,  37.1522, 1),
    ("Nyandarua",       -0.4167,  36.3500, 1),
    ("Kirinyaga",       -0.6558,  37.3826, 1),
    ("Tharaka-Nithi",    0.2970,  37.9230, 1),
    ("Laikipia",         0.3569,  36.7813, 1),
    ("Samburu",          1.6290,  37.0000, 1),
    ("Trans Nzoia",      1.0563,  35.0062, 1),
    ("West Pokot",       1.6204,  35.1167, 1),
    ("Turkana",          3.1190,  35.5970, 1),
    ("Baringo",          0.5000,  36.0000, 1),
    ("Nandi",            0.1836,  35.1020, 1),
    ("Kericho County",  -0.3689,  35.2863, 1),
    ("Bomet County",    -0.7792,  35.3419, 1),
    ("Nyamira County",  -0.5671,  34.9346, 1),
    ("Kisii County",    -0.6817,  34.7660, 1),
    ("Migori County",   -1.0634,  34.4731, 1),
    ("Homa Bay County", -0.5273,  34.4571, 1),
    ("Siaya County",     0.0607,  34.2878, 1),
    ("Busia County",     0.4610,  34.0960, 1),
    ("Bungoma County",   0.5635,  34.5607, 1),
    ("Kakamega County",  0.2820,  34.7519, 1),
    ("Vihiga County",    0.0760,  34.7213, 1),
    ("Garissa County",  -0.4532,  39.6401, 1),
    ("Wajir County",     1.7471,  40.0573, 1),
    ("Mandera County",   3.9366,  41.8670, 1),
    ("Isiolo County",    0.3530,  37.5826, 1),
    ("Marsabit County",  2.3364,  37.9897, 1),
    ("Meru County",      0.0470,  37.6490, 1),
    ("Embu County",     -0.5330,  37.4580, 1),
    ("Kitui County",    -1.3674,  38.0126, 1),
    ("Taita-Taveta",    -3.3167,  38.3500, 1),
    ("Tana River",      -1.5000,  40.0000, 1),
    ("Lamu County",     -2.2694,  40.9021, 1),
    ("Kilifi County",   -3.6305,  39.8499, 1),
    ("Kwale County",    -4.1730,  39.4573, 1),
]

# Pre-compile all patterns once at import time
_LOCATIONS = [Location(name=n, lat=la, lng=lo, specificity=s) for n, la, lo, s in _RAW]
_PATTERNS  = [
    (loc, re.compile(r"\b" + re.escape(loc.name) + r"\b", re.IGNORECASE))
    for loc in _LOCATIONS
]


def extract_locations(text: str) -> list[Location]:
    """Return all matched Location objects, sorted most-specific first."""
    matched = [loc for loc, pat in _PATTERNS if pat.search(text)]
    matched.sort(key=lambda l: l.specificity, reverse=True)
    return matched


def best_location(text: str) -> Location | None:
    """Return the single most-specific Kenya location found in text."""
    results = extract_locations(text)
    return results[0] if results else None
