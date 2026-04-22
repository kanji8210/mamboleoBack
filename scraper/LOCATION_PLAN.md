# Location Fallback & User Guidance Plan

## Location Fallback Logic
- If a scraped article does NOT contain a precise location:
  - For official/government sources: Use the office HQ location (e.g., KDF HQ, Police HQ, Ministry HQ) as the default.
  - For accidents or region-wide events: Use a broad bounding box (e.g., center of county, city, or Kenya if unknown).
  - Tag these as "approximate" or "needs user confirmation" in the database.

## User Guidance for Precise Location
- When users report or corroborate an incident:
  - If the location is approximate, prompt: "Help us pinpoint the exact location."
  - Show a map with the current (approximate) location centered.
  - Allow user to drag a marker or click to select the precise spot.
  - Optionally, provide a search box for place names.
  - Display a warning if the selected location is outside Kenya bounds.

## Implementation Notes
- Frontend: Use React-Leaflet for map selection in the report/corroborate modal.
- Backend: Store a flag (e.g., `is_approximate`) and the source of the fallback (e.g., `location_source: 'KDF HQ'`).
- Show a badge or icon for "approximate" locations in the UI.
