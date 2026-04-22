# Scraper/Monitoring Pipeline Plan

## Pipeline Overview
- For each source, select the best method (RSS, X API, Facebook API, YouTube API, or HTML scraping)
- Normalize all fetched items to a common schema: title, url, published_at, source, type, excerpt/content, location (if any)
- Run classification and location extraction (existing pipeline)
- If location missing, apply fallback logic (see LOCATION_PLAN.md)
- Post to WordPress via REST API

## Implementation by Source Type

### 1. RSS/Website Scraping
- Use feedparser (RSS) or BeautifulSoup/Playwright (HTML)
- Parse article list, extract title, url, date, excerpt/content
- For each article, run NLP classification and location extraction
- If location missing, fallback to office/region

### 2. X (Twitter) Handles
- Use X API (if available) or scraping (e.g., snscrape)
- Fetch recent tweets, extract text, url, timestamp
- Classify and extract location as above
- Fallback: use office location for official handles

### 3. Facebook Pages/Groups
- Use Facebook Graph API (if available) or scraping tools
- Fetch recent posts, extract text, url, timestamp
- Classify and extract location as above
- Fallback: use page location or Kenya center

### 4. YouTube Channels
- Use YouTube Data API
- Fetch recent videos, extract title, description, url, published_at
- Classify and extract location as above
- Fallback: use channel location or Kenya center

## Aggregation & Deduplication
- Store all seen URLs in SQLite (as in current pipeline)
- Deduplicate before posting

## Tagging & Trust Ranking
- Tag each item with tier (1/2/3) and source
- Show trust badge in frontend

---

See SOURCE_MAP.md for full source list and LOCATION_PLAN.md for fallback logic.
