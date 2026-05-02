# Mamboleo Backend — Setup

## 1. WordPress plugin

```powershell
# From the repo root
cd C:\xampp\htdocs\wordpress\wp-content\plugins\mamboleoBack
```

The plugin auto-registers its menus, REST routes and CPTs on activation.
Activate **Mamboleo Backend** in WP admin → Plugins.

The ingestion API key is hard-coded in [`mamboleoBack.php`](mamboleoBack.php)
as the `MAMBOLEO_API_KEY` constant. To rotate, edit that constant **and** the
matching value in `scraper/.env` (it's the only secret that has to live on
both sides — everything else is one-sided).

## 2. AI provider (one-time, in WP admin)

Go to **Mamboleo → AI Intelligence**.

| Field         | Recommended value                          |
| ------------- | ------------------------------------------ |
| Provider      | `OpenAI-compatible`                        |
| Base URL      | `https://api.groq.com/openai/v1` (Groq, free tier) |
| Model         | `llama-3.1-8b-instant`                     |
| API key       | Paste your `gsk_…` key from console.groq.com |

Save → click **Run test now**. You should see `✓ Live — N ms, reply: OK`.

Other supported endpoints:

| Provider     | Base URL                              | Notes                  |
| ------------ | ------------------------------------- | ---------------------- |
| OpenAI       | `https://api.openai.com/v1`           | `gpt-4o-mini` is cheap |
| OpenRouter   | `https://openrouter.ai/api/v1`        | many free models       |
| Together.ai  | `https://api.together.xyz/v1`         | strict on JSON mode    |
| LM Studio    | `http://localhost:1234/v1`            | no API key needed      |
| Ollama       | (switch Provider to `Ollama (local)`) | fully offline          |

## 3. Scraper (Python)

```powershell
cd scraper
python -m venv .venv
.venv\Scripts\Activate.ps1
pip install -r requirements.txt
python -m spacy download en_core_web_sm   # one-off, ~50 MB
copy .env.example .env                    # fill in the 2 fields
```

Edit `scraper/.env` and set:

```
MAMBOLEO_WP_URL=https://your-site.com
MAMBOLEO_API_KEY=<same value as the constant in mamboleoBack.php>
```

That's it — the LLM key is **not** in `.env`. The scraper fetches it from
WP at startup via `GET /wp-json/mamboleo/v1/llm-config` (auth: `X-API-Key`).

### Run

```powershell
python main.py                  # all enabled sources
python main.py --sources social # just X/Facebook handles
python backfill_ai.py           # re-analyse incidents missing AI metadata
```

### Scheduled task (Windows)

```powershell
schtasks /Create /XML windows_scrape_task_fast.xml /TN MamboleoScraperFast
schtasks /Create /XML windows_scrape_task_slow.xml /TN MamboleoScraperSlow
```

## 4. Optional integrations

- **Twitter/X handles** — get a free Basic-tier Bearer Token from
  developer.x.com, paste into `scraper/.env` as `TWITTER_BEARER_TOKEN`.
  Then enable handles in `scraper/social_sources.yaml`.
- **Facebook pages** — stand up [RSSHub](https://docs.rsshub.app) and set
  `RSSHUB_HOST=https://rsshub.yourdomain.com` in `scraper/.env`.

## 5. Where secrets live (single source of truth)

| Secret                 | Where it lives                          | Why                       |
| ---------------------- | --------------------------------------- | ------------------------- |
| `MAMBOLEO_API_KEY`     | `mamboleoBack.php` constant + `scraper/.env` | shared symmetric key      |
| OpenAI / Groq API key  | **WP admin only** (`mamboleo_openai_api_key` option) | never written to disk on the scraper side |
| Twitter Bearer Token   | `scraper/.env`                          | only the scraper uses it  |
| RSSHUB_HOST            | `scraper/.env`                          | only the scraper uses it  |

`.env` is in `.gitignore`. If a secret was ever committed, rotate it
immediately and `git filter-repo` the file out of history.

## 6. Health checks

| Page                                      | Shows                                              |
| ----------------------------------------- | -------------------------------------------------- |
| Mamboleo → Operations                     | pending review / updates / expiring / AI coverage  |
| Mamboleo → AI Intelligence                | provider live status, models, last 15 analyses     |
| Mamboleo → Social Sources                 | X / Facebook / YouTube handles & enabled status    |
| Mamboleo → Scraper                        | last run, articles ingested, errors                |
