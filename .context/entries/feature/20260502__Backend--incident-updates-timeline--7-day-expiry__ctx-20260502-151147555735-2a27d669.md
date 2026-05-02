---
entry_id: "ctx-20260502-151147555735-2a27d669"
title: "Backend: incident updates timeline, 7-day expiry, AI admin, reorganized menu"
category: "feature"
tags: ["backend", "wordpress", "admin", "lifecycle", "ai", "community", "rest-api"]
files: ["includes/incident-updates.php", "includes/incident-expiry.php", "includes/admin-dashboard.php", "includes/admin-ai.php", "includes/admin-updates.php", "includes/admin.php", "includes/admin-scraper.php", "includes/admin-review.php", "includes/admin-media-monitor.php", "includes/admin-location-tools.php", "mamboleoBack.php"]
commits: []
status: "active"
importance: "high"
created_at: "2026-05-02T15:11:47Z"
updated_at: "2026-05-02T15:11:47Z"
summary: "Major backend overhaul to support a community-driven situational platform. Added: (1) incident_update CPT with public POST /incidents/{id}/updates (rate-limited, queued for moderation) and /updates/trusted (admin/API-key auto-approved); approving an update bumps update_count, refreshes last_update_at, forces lifecycle=active and resets expires_at. (2) Hard 7-day expiry with hourly cron: warns at 24h, auto-trashes idle non-developing incidents, recoverable via WP trash. (3) Reorganized admin menu under single Mamboleo parent: Dashboard / Review Queue / Updates / AI Intelligence / Expiring Soon / Scraper / Media Monitor / Fix Locations. (4) Admin Dashboard with operational cards (pending review, pending updates, expiring soon, AI coverage). (5) AI Intelligence page: live Ollama health check, settings (host/model/timeout), per-incident Re-analyse action that strips ai_model so backfill picks it up. (6) Sidebar AI meta-box on incident edit screen. (7) Updates moderation page (Pending/Approved/Rejected tabs) and Expiring Soon page where admin extends lifetime by posting a quick note. GraphQL: Incident.updates field exposes the timeline."
retrieval_hints: ["incident updates moderation", "7-day expiry auto-trash incident", "admin dashboard mamboleo", "AI intelligence ollama re-analyse", "incident_update CPT", "community contribution backend", "mamboleo admin menu reorganization"]
---


