"""Fetch a page with Scrapling and return JSON to Laravel."""
import argparse
import ipaddress
import json
import socket
import sys
from urllib.parse import urljoin, urlsplit

from scrapling.fetchers import Fetcher


def validate_url(url, public_only=False):
    parsed = urlsplit(url)
    if parsed.scheme not in {"http", "https"} or not parsed.hostname or parsed.username or parsed.password:
        raise ValueError("An HTTP or HTTPS URL without embedded credentials is required")
    if public_only:
        addresses = socket.getaddrinfo(parsed.hostname, parsed.port or (443 if parsed.scheme == "https" else 80), type=socket.SOCK_STREAM)
        if not addresses or any(not ipaddress.ip_address(address[4][0]).is_global for address in addresses):
            raise ValueError("The dashboard only accepts public URLs; local and private addresses are blocked.")


def scrape(url, public_only=False):
    for _ in range(6):
        validate_url(url, public_only)
        # Handle redirects explicitly so the destination policy also applies to every hop.
        page = Fetcher.get(url, timeout=30, follow_redirects=False, retries=1)
        if page.status in {301, 302, 303, 307, 308}:
            location = page.headers.get("location") or page.headers.get("Location")
            if not location:
                raise ValueError("Target returned a redirect without a destination")
            url = urljoin(url, location)
            continue
        if page.status >= 400:
            raise ValueError(f"Target returned HTTP {page.status}")
        return {
            "url": str(page.url),
            "status": page.status,
            "title": page.css("title::text").get(),
            "headings": page.css("h1::text").getall(),
        }
    raise ValueError("Target returned too many redirects")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("url")
    parser.add_argument("--public-only", action="store_true")
    args = parser.parse_args()
    try:
        print(json.dumps(scrape(args.url, args.public_only)))
    except Exception as exc:
        print(str(exc), file=sys.stderr)
        sys.exit(1)
