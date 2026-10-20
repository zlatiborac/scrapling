import importlib.util
from pathlib import Path
import socket
import unittest
from types import SimpleNamespace
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("scrape", Path(__file__).parents[2] / "scripts/scrape.py")
scrape = importlib.util.module_from_spec(spec)
spec.loader.exec_module(scrape)


def address(ip):
    return [(socket.AF_INET, socket.SOCK_STREAM, 6, "", (ip, 443))]


class PublicUrlPolicyTest(unittest.TestCase):
    def test_private_destination_is_rejected_before_fetching(self):
        for ip in ("127.0.0.1", "10.0.0.1", "169.254.169.254", "::1", "::ffff:127.0.0.1"):
            with self.subTest(ip=ip), patch.object(scrape.socket, "getaddrinfo", return_value=address(ip)), patch.object(scrape.Fetcher, "get") as fetch:
                with self.assertRaisesRegex(ValueError, "local and private"):
                    scrape.scrape("https://example.com", public_only=True)
                fetch.assert_not_called()

    def test_redirect_to_private_destination_is_not_followed(self):
        redirect = SimpleNamespace(status=302, headers={"location": "http://127.0.0.1/secret"})
        with patch.object(scrape.socket, "getaddrinfo", side_effect=[address("93.184.216.34"), address("127.0.0.1")]), patch.object(scrape.Fetcher, "get", return_value=redirect) as fetch:
            with self.assertRaisesRegex(ValueError, "local and private"):
                scrape.scrape("https://example.com", public_only=True)
            self.assertEqual(1, fetch.call_count)

    def test_schemes_and_embedded_credentials_are_rejected(self):
        for url in ("file:///etc/passwd", "https://user:password@example.com", "ftp://example.com"):
            with self.subTest(url=url), self.assertRaises(ValueError):
                scrape.validate_url(url, public_only=True)

    def test_public_destination_is_accepted(self):
        with patch.object(scrape.socket, "getaddrinfo", return_value=address("93.184.216.34")):
            scrape.validate_url("https://example.com", public_only=True)


if __name__ == "__main__":
    unittest.main()
