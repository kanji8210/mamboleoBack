---
entry_id: "ctx-20260502-164659134623-ebffcab6"
title: "AI layer: provider-agnostic LLM client + WP-managed secret + setup guide"
category: "feature"
tags: ["ai", "llm", "security", "ollama", "groq", "openai", "scraper", "wordpress"]
files: ["scraper/api/llm_client.py", "scraper/config.py", "scraper/.env.example", "scraper/.env", "includes/admin-ai.php", "includes/admin-dashboard.php", "includes/rest-api.php", ".gitignore", "SETUP.md"]
commits: []
status: "active"
importance: "medium"
created_at: "2026-05-02T16:46:59Z"
updated_at: "2026-05-02T16:46:59Z"
summary: "Refactored the AI layer so it works without local Ollama and keeps secrets out of .env. The Python LLM client (scraper/api/llm_client.py) now dispatches to either ollama or any OpenAI-compatible endpoint (OpenAI, Groq, Together.ai, OpenRouter, LM Studio). Provider, endpoint, model and API key are configured in WP admin (Mamboleo -> AI Intelligence) and pulled by the scraper at startup via a new authenticated GET /wp-json/mamboleo/v1/llm-config endpoint -- the API key is intentionally not read from os.getenv so it cannot leak via .env. Added a Test connection admin button doing a real chat-completion round trip, and a SETUP.md single-source guide. .env now only carries MAMBOLEO_WP_URL + MAMBOLEO_API_KEY + optional Twitter/RSSHub. Added .gitignore to exclude .env."
retrieval_hints: ["LLM provider switch ollama vs openai-compatible", "where to set Groq / OpenAI API key for Mamboleo", "scraper config.py fetches /llm-config from WP", "AI Intelligence admin page test connection", "secrets policy LLM key not in .env"]
---


