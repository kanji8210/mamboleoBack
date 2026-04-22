# Backend Scraping Automation Plan

## Goal
- Run all scrapers automatically on a schedule (e.g., every 15 minutes/hourly) in the backend.
- No manual trigger required; admin UI action can be added later for on-demand scraping.

## Implementation Steps
1. **Create a Python script/entrypoint** (e.g., `run_all_scrapers.py`) that imports and runs all source scrapers as per PIPELINE_PLAN.md.
2. **Schedule the script** using one of:
   - OS-level cron job (Linux/macOS)
   - Windows Task Scheduler (for XAMPP/Windows)
   - Or use a lightweight Python scheduler (e.g., `schedule` library) that runs as a background process/service.
3. **Logging & Error Handling**
   - Log all runs, errors, and number of new incidents found.
   - Optionally, send email/Slack alerts on failures.
4. **Admin UI (future)**
   - Add a WordPress admin action/button to trigger scraping manually via WP AJAX or REST endpoint.
   - Show last run time, status, and log summary in the admin panel.

## Security
- Ensure API keys/secrets are stored in `.env` and not exposed in logs or UI.

## Next Steps
- Implement `run_all_scrapers.py` to orchestrate all source scrapers.
- Add a scheduler (Task Scheduler or Python `schedule`).
- Test and monitor for errors.
