"""Twitter/X API v2 scraper — requires a Bearer Token (free Basic tier).

Searches for recent tweets about security incidents in Kenya.
Converts matching tweets into the same article dict format as HTML scrapers.
"""
from __future__ import annotations

import logging
import os
from datetime import datetime, timezone
from typing import Iterator

from config import TWITTER_BEARER

log = logging.getLogger("scraper.twitter")

# Search query — targets safety-relevant Kenya content
_QUERY = (
    "(fire OR flood OR accident OR crash OR protest OR shooting OR robbery OR "
    "explosion OR stabbing OR teargas OR arrested OR kidnap OR landslide OR "
    "cholera OR blast) "
    "(Kenya OR Nairobi OR Mombasa OR Kisumu OR Nakuru OR Eldoret) "
    "lang:en -is:retweet -is:reply"
)


class TwitterScraper:
    NAME = "twitter"

    def __init__(self) -> None:
        self.log = logging.getLogger("scraper.twitter")

    def fetch_articles(self, limit: int = 20) -> Iterator[dict]:
        if not TWITTER_BEARER:
            self.log.info("No TWITTER_BEARER_TOKEN set — skipping Twitter.")
            return

        try:
            import tweepy
        except ImportError:
            self.log.warning("tweepy not installed — pip install tweepy")
            return

        client = tweepy.Client(bearer_token=TWITTER_BEARER, wait_on_rate_limit=True)

        try:
            resp = client.search_recent_tweets(
                query=_QUERY,
                max_results=min(limit, 100),
                tweet_fields=["created_at", "author_id", "entities", "text"],
                expansions=["author_id"],
                user_fields=["username"],
            )
        except tweepy.TweepyException as exc:
            self.log.error("Twitter API error: %s", exc)
            return

        if not resp.data:
            return

        # Build author_id → username map
        users: dict[int, str] = {}
        if resp.includes and resp.includes.get("users"):
            for u in resp.includes["users"]:
                users[u.id] = u.username

        for tweet in resp.data:
            username = users.get(tweet.author_id, "unknown")
            url = f"https://twitter.com/{username}/status/{tweet.id}"
            created = (
                tweet.created_at.isoformat()
                if tweet.created_at
                else datetime.now(timezone.utc).isoformat()
            )
            yield {
                "url":          url,
                "title":        tweet.text[:100],
                "excerpt":      tweet.text,
                "content":      tweet.text,
                "published_at": created,
                "source":       "twitter.com",
            }
