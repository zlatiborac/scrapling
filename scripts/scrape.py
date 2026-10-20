"""Fetch one HTTP(S) page and return JSON for Laravel's Scrapling command."""
import json
import sys
from urllib.parse import urlsplit

from scrapling.fetchers import Fetcher


def scrape(url):
    if urlsplit(url).scheme not in {"http", "https"} or not urlsplit(url).hostname:
        raise ValueError("An HTTP or HTTPS URL is required")
    page = Fetcher.get(url, timeout=30)
    if page.status >= 400:
        raise ValueError(f"Target returned HTTP {page.status}")
    return {
        "url": str(page.url),
        "status": page.status,
        "title": page.css("title::text").get(),
        "headings": page.css("h1::text").getall(),
    }


if __name__ == "__main__":
    try:
        if len(sys.argv) != 2:
            raise ValueError("Usage: scrape.py URL")
        print(json.dumps(scrape(sys.argv[1])))
    except Exception as exc:
        print(str(exc), file=sys.stderr)
        sys.exit(1)
