"""Bounded breadth-first e-mail discovery, with a source-page crawl log."""
import argparse
from collections import deque
import html
import json
import re
import sys
import time
from urllib.parse import unquote, urljoin, urlsplit, urlunsplit

from scrapling.fetchers import Fetcher
from scrape import validate_url
from email_errors import FetchError, describe_error


MAX_DISCOVERED = 500
EMAIL = re.compile(r"[a-z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+", re.I)
ASSET = re.compile(r"\.(?:pdf|jpe?g|png|gif|svg|webp|ico|css|js|zip|gz|mp[34]|avi|woff2?|ttf|xml)$", re.I)


def canonical_url(url):
    """Deduplicate fragments, host casing and default ports; preserve query semantics."""
    url = url.strip()
    if len(url) > 2048 or any(character.isspace() for character in url):
        raise ValueError("Invalid page address")
    parsed = urlsplit(url)
    if parsed.scheme not in {"http", "https"} or not parsed.hostname or parsed.username is not None or parsed.password is not None:
        raise ValueError("An HTTP or HTTPS URL without embedded credentials is required")
    host = parsed.hostname.encode("idna").decode("ascii").lower()
    if ":" in host:
        host = f"[{host}]"
    port = parsed.port
    if port and port != (443 if parsed.scheme == "https" else 80):
        host += f":{port}"
    return urlunsplit((parsed.scheme, host, parsed.path or "/", parsed.query, ""))


def site_key(url):
    parsed = urlsplit(url)
    port = parsed.port
    if port in {80 if parsed.scheme == "http" else 443, None}:
        port = None
    return parsed.hostname, port


def fetch_page(url, public_only, scope, deadline, visited, request_timeout=10):
    """Validate every redirect before fetching, including same-site boundaries."""
    hops = set()
    for _ in range(6):
        if scope is not None and site_key(url) != scope:
            raise FetchError("redirect_error", "Redirect leaves the starting website")
        if url in hops:
            raise FetchError("redirect_error", "Target returned a redirect loop")
        if url in visited:
            return None, url
        validate_url(url, public_only)
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise TimeoutError("Crawl time limit reached")
        visited.add(url)
        hops.add(url)
        page = Fetcher.get(url, timeout=min(request_timeout, remaining), follow_redirects=False, retries=0)
        if page.status in {301, 302, 303, 307, 308}:
            location = page.headers.get("location") or page.headers.get("Location")
            if not location:
                raise FetchError("redirect_error", "Target returned a redirect without a destination")
            url = canonical_url(urljoin(url, location))
            continue
        if page.status >= 400:
            raise FetchError("http_error", f"Target returned HTTP {page.status}", page.status)
        return page, canonical_url(str(page.url))
    raise FetchError("redirect_error", "Target returned too many redirects")


def extract_emails(page):
    # Read text and mailto recipients, excluding scripts/styles and mailto subjects.
    text = " ".join(page.xpath("//text()[not(ancestor::script) and not(ancestor::style)]").getall())
    recipients = []
    for href in page.css("a::attr(href)").getall():
        if href.lower().startswith("mailto:"):
            recipients.append(unquote(href[7:].split("?", 1)[0]))
    matches = EMAIL.findall(html.unescape(text + " " + " ".join(recipients)))
    return sorted({email.strip(".").lower() for email in matches})


def crawl(url, website=False, max_pages=20, max_depth=3, public_only=True, time_limit=30, delay=0.25, request_timeout=10):
    if not 1 <= max_pages <= 50 or not 0 <= max_depth <= 5:
        raise ValueError("Use 1–50 pages and a link depth of 0–5")
    if not 0 < time_limit <= 30 or not 0 < request_timeout <= 10:
        raise ValueError("Use a crawl budget up to 30 seconds and a request timeout up to 10 seconds")
    start = canonical_url(url)
    deadline = time.monotonic() + time_limit
    pages = []
    queue = deque()
    known = set()
    visited = set()
    emails = {}
    limits = set()
    scope = None
    attempted = 0

    def discover(address, depth, parent):
        if address in known:
            return
        if len(pages) >= MAX_DISCOVERED:
            limits.add("discovery")
            return
        known.add(address)
        record = {"url": address, "final_url": None, "depth": depth, "discovered_from": parent,
                  "status": "pending", "http_status": None, "emails": [], "error": None}
        pages.append(record)
        if depth > max_depth:
            record["status"] = "skipped"
            record["error"] = "Maximum link depth reached"
            limits.add("depth")
        else:
            queue.append(record)

    discover(start, 0, None)
    while queue:
        if queue[0]["url"] in visited:
            queue.popleft()["status"] = "duplicate"
            continue
        if attempted >= (max_pages if website else 1):
            limits.add("pages")
            break
        if time.monotonic() >= deadline:
            limits.add("time")
            break
        record = queue.popleft()
        attempted += 1
        try:
            page, final = fetch_page(record["url"], public_only, scope, deadline, visited, request_timeout)
            record["final_url"] = final
            if page is None:
                record["status"] = "duplicate"
                continue
            # The initial page may redirect to the site's canonical host (e.g. www).
            if scope is None:
                scope = site_key(final)
            record["http_status"] = page.status
            content_type = (page.headers.get("content-type") or page.headers.get("Content-Type") or "text/html").lower()
            if not any(kind in content_type for kind in ("text/html", "application/xhtml+xml", "text/plain")):
                record["status"] = "skipped"
                record["error"] = "Not an HTML or text page"
                continue
            record["status"] = "fetched"
            record["emails"] = extract_emails(page)
            for email in record["emails"]:
                emails.setdefault(email, set()).add(final)
            if not website:
                continue
            base = urljoin(final, page.css("base::attr(href)").get() or final)
            for href in page.css("a::attr(href)").getall():
                try:
                    address = canonical_url(urljoin(base, href))
                    if site_key(address) != scope or ASSET.search(urlsplit(address).path) or address in visited or address in known:
                        continue
                    discover(address, record["depth"] + 1, final)
                except (ValueError, UnicodeError):
                    continue
        except Exception as exc:
            if attempted == 1:
                raise
            record["status"] = "failed"
            error = describe_error(exc)
            record["error"] = error["message"]
            record["error_code"] = error["code"]
            record["http_status"] = error.get("http_status")
            if isinstance(exc, TimeoutError) or time.monotonic() >= deadline:
                limits.add("time")
        if queue and delay:
            time.sleep(min(delay, max(0, deadline - time.monotonic())))

    return {
        "url": start, "mode": "website" if website else "page", "pages": pages,
        "emails": [{"email": email, "sources": sorted(sources)} for email, sources in sorted(emails.items())],
        "attempted": attempted, "fetched": sum(page["status"] == "fetched" for page in pages),
        "pending": sum(page["status"] == "pending" for page in pages),
        "limits": sorted(limits), "complete": not limits and not any(page["status"] == "failed" for page in pages),
    }


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("url")
    parser.add_argument("--website", action="store_true")
    parser.add_argument("--max-pages", type=int, default=20)
    parser.add_argument("--max-depth", type=int, default=3)
    parser.add_argument("--public-only", action="store_true")
    parser.add_argument("--time-limit", type=float, default=30)
    parser.add_argument("--request-timeout", type=float, default=10)
    args = parser.parse_args()
    try:
        print(json.dumps(crawl(args.url, args.website, args.max_pages, args.max_depth, args.public_only,
                               time_limit=args.time_limit, request_timeout=args.request_timeout)))
    except Exception as exc:
        print(json.dumps({"error": describe_error(exc)}), file=sys.stderr)
        sys.exit(1)
