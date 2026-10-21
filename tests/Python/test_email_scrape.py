from pathlib import Path
import socket
import sys
from types import SimpleNamespace
import unittest
from unittest.mock import patch

from scrapling.parser import Selector

sys.path.insert(0, str(Path(__file__).parents[2] / "scripts"))
import email_scrape


def page(url, markup="", status=200, headers=None):
    selector = Selector(markup or "<html></html>")
    return SimpleNamespace(url=url, status=status, headers=headers or {"content-type": "text/html"}, css=selector.css, xpath=selector.xpath)


def address(ip):
    return [(socket.AF_INET, socket.SOCK_STREAM, 6, "", (ip, 443))]


class EmailCrawlerTest(unittest.TestCase):
    def crawl(self, *args, **kwargs):
        return email_scrape.crawl(*args, public_only=False, delay=0, **kwargs)

    def test_single_page_extracts_text_and_mailto_and_does_not_follow_links(self):
        document = page("https://example.com/", '''<html><p>INFO@Example.com &lt;help@example.com&gt;</p>
            <a href="mailto:info@example.com,sales%2Bweb@example.com?subject=ignore@example.org">Contact</a>
            <script>hidden@example.org</script><style>hidden2@example.org</style>
            <a href="/next">Next</a></html>''')
        with patch.object(email_scrape.Fetcher, "get", return_value=document) as fetch:
            result = self.crawl("https://example.com")
        self.assertEqual(["help@example.com", "info@example.com", "sales+web@example.com"], [item["email"] for item in result["emails"]])
        self.assertEqual(["https://example.com/"], result["emails"][0]["sources"])
        self.assertEqual(1, fetch.call_count)

    def test_breadth_first_crawl_tracks_parents_sources_and_avoids_loops_and_assets(self):
        documents = {
            "https://example.com/": page("https://example.com/", '''<p>info@example.com</p>
                <a href="/contact#one">Contact</a><a href="/contact#two">Again</a>
                <a href="/about">About</a><a href="https://elsewhere.com/">Outside</a>
                <a href="/file.pdf">PDF</a><a href="javascript:alert(1)">Invalid</a>'''),
            "https://example.com/contact": page("https://example.com/contact", '<p>INFO@example.com</p><a href="/">Home</a><a href="team">Team</a>'),
            "https://example.com/about": page("https://example.com/about"),
            "https://example.com/team": page("https://example.com/team", '<p>team@example.com</p>'),
        }
        with patch.object(email_scrape.Fetcher, "get", side_effect=lambda url, **kwargs: documents[url]) as fetch:
            result = self.crawl("https://example.com", website=True)
        self.assertEqual(list(documents), [call.args[0] for call in fetch.call_args_list])
        self.assertEqual(["https://example.com/", "https://example.com/contact"], result["emails"][0]["sources"])
        self.assertEqual(2, result["pages"][3]["depth"])
        self.assertEqual("https://example.com/contact", result["pages"][3]["discovered_from"])
        self.assertTrue(result["complete"])

    def test_page_limit_returns_partial_results_and_pending_pages(self):
        with patch.object(email_scrape.Fetcher, "get", return_value=page("https://example.com/", '<p>a@example.com</p><a href="/next">Next</a>')) as fetch:
            result = self.crawl("https://example.com", website=True, max_pages=1)
        self.assertEqual(1, fetch.call_count)
        self.assertEqual(1, result["pending"])
        self.assertEqual("pending", result["pages"][1]["status"])
        self.assertEqual(["pages"], result["limits"])
        self.assertFalse(result["complete"])

    def test_depth_limit_tracks_skipped_links_without_fetching_them(self):
        with patch.object(email_scrape.Fetcher, "get", return_value=page("https://example.com/", '<a href="/next">Next</a>')) as fetch:
            result = self.crawl("https://example.com", website=True, max_depth=0)
        self.assertEqual(1, fetch.call_count)
        self.assertEqual(["depth"], result["limits"])
        self.assertEqual("skipped", result["pages"][1]["status"])

    def test_time_limit_keeps_already_collected_results(self):
        with patch.object(email_scrape.time, "monotonic", side_effect=[0, 0, 0, 31]), patch.object(email_scrape.Fetcher, "get", return_value=page("https://example.com/", '<p>a@example.com</p><a href="/next">Next</a>')):
            result = self.crawl("https://example.com", website=True)
        self.assertEqual("a@example.com", result["emails"][0]["email"])
        self.assertEqual(["time"], result["limits"])
        self.assertEqual(1, result["pending"])

    def test_failed_child_does_not_discard_emails_or_stop_other_pages(self):
        documents = [page("https://example.com/", '<a href="/missing">Missing</a><a href="/ok">OK</a>'),
                     page("https://example.com/missing", status=404), page("https://example.com/ok", '<p>ok@example.com</p>')]
        with patch.object(email_scrape.Fetcher, "get", side_effect=documents):
            result = self.crawl("https://example.com", website=True)
        self.assertEqual("failed", result["pages"][1]["status"])
        self.assertIn("HTTP 404", result["pages"][1]["error"])
        self.assertEqual("ok@example.com", result["emails"][0]["email"])
        self.assertFalse(result["complete"])

    def test_public_policy_applies_to_start_redirect_and_each_discovered_page(self):
        with patch.object(email_scrape, "validate_url", side_effect=ValueError("private destination")), patch.object(email_scrape.Fetcher, "get") as fetch:
            with self.assertRaisesRegex(ValueError, "private"):
                email_scrape.crawl("https://example.com", delay=0)
            fetch.assert_not_called()
        redirect = page("https://example.com/", status=302, headers={"Location": "http://127.0.0.1/"})
        with patch("scrape.socket.getaddrinfo", side_effect=[address("93.184.216.34"), address("127.0.0.1")]), patch.object(email_scrape.Fetcher, "get", return_value=redirect) as fetch:
            with self.assertRaisesRegex(ValueError, "local and private"):
                email_scrape.crawl("https://example.com", delay=0)
            self.assertEqual(1, fetch.call_count)
        with patch("scrape.socket.getaddrinfo", side_effect=[address("93.184.216.34"), address("10.0.0.1")]), patch.object(email_scrape.Fetcher, "get", return_value=page("https://example.com/", '<a href="/private">Private</a>')) as fetch:
            result = email_scrape.crawl("https://example.com", website=True, delay=0)
            self.assertEqual(1, fetch.call_count)
            self.assertEqual("failed", result["pages"][1]["status"])

    def test_child_redirect_cannot_escape_site_and_initial_canonical_redirect_can(self):
        documents = [page("https://example.com/", status=301, headers={"location": "https://www.example.com/"}),
                     page("https://www.example.com/", '<a href="/contact">Contact</a>'),
                     page("https://www.example.com/contact", status=302, headers={"location": "https://outside.com/"})]
        with patch.object(email_scrape.Fetcher, "get", side_effect=documents) as fetch:
            result = self.crawl("https://example.com", website=True)
        self.assertEqual(3, fetch.call_count)
        self.assertEqual("https://www.example.com/", result["pages"][0]["final_url"])
        self.assertIn("leaves the starting website", result["pages"][1]["error"])

    def test_redirect_aliases_are_not_fetched_twice(self):
        documents = [page("https://example.com/", '<a href="/alias">Alias</a><a href="/contact">Contact</a>'),
                     page("https://example.com/alias", status=301, headers={"location": "/contact"}),
                     page("https://example.com/contact", '<p>info@example.com</p>')]
        with patch.object(email_scrape.Fetcher, "get", side_effect=documents) as fetch:
            result = self.crawl("https://example.com", website=True, max_pages=2)
        self.assertEqual(3, fetch.call_count)
        self.assertEqual("duplicate", result["pages"][2]["status"])
        self.assertEqual(1, len(result["emails"]))
        self.assertEqual([], result["limits"])

    def test_redirect_loops_fail_clearly(self):
        with patch.object(email_scrape.Fetcher, "get", return_value=page("https://example.com/", status=301, headers={"location": "/"})):
            with self.assertRaisesRegex(ValueError, "redirect loop"):
                self.crawl("https://example.com")

    def test_non_text_responses_are_skipped(self):
        with patch.object(email_scrape.Fetcher, "get", return_value=page("https://example.com/", '<p>fake@example.com</p>', headers={"content-type": "application/pdf"})):
            result = self.crawl("https://example.com")
        self.assertEqual([], result["emails"])
        self.assertEqual("skipped", result["pages"][0]["status"])

    def test_discovery_is_bounded(self):
        markup = "".join(f'<a href="/page/{number}">Page</a>' for number in range(600))
        with patch.object(email_scrape.Fetcher, "get", return_value=page("https://example.com/", markup)):
            result = self.crawl("https://example.com", website=True, max_pages=1)
        self.assertEqual(500, len(result["pages"]))
        self.assertIn("discovery", result["limits"])

    def test_normalization_and_invalid_inputs(self):
        self.assertEqual("https://example.com/?b=2&a=1", email_scrape.canonical_url("https://EXAMPLE.com:443?b=2&a=1#part"))
        for url in ("file:///tmp/test", "https://user:secret@example.com", "https://@example.com", "https://example.com:invalid", "https://example .com"):
            with self.subTest(url=url), self.assertRaises(ValueError):
                email_scrape.canonical_url(url)
        for pages, depth in ((0, 3), (51, 3), (20, -1), (20, 6)):
            with self.subTest(pages=pages, depth=depth), self.assertRaises(ValueError):
                self.crawl("https://example.com", max_pages=pages, max_depth=depth)


if __name__ == "__main__":
    unittest.main()
