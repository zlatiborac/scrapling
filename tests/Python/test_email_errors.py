from pathlib import Path
import socket
import sys
import unittest

from curl_cffi.curl import CurlError

sys.path.insert(0, str(Path(__file__).parents[2] / "scripts"))
from email_errors import describe_error


class EmailErrorsTest(unittest.TestCase):
    def test_network_failures_have_distinct_safe_categories(self):
        for curl_code, expected in ((5, "dns_error"), (6, "dns_error"), (7, "connection_error"),
                                    (28, "request_timeout"), (35, "tls_error"), (60, "tls_error")):
            with self.subTest(curl_code=curl_code):
                result = describe_error(CurlError("https://example.com/?token=private-token", code=curl_code))
                self.assertEqual(expected, result["code"])
                self.assertEqual(curl_code, result["curl_code"])
                self.assertEqual("CurlError", result["exception_type"])
                self.assertNotIn("private-token", str(result))

    def test_dns_crawl_and_unknown_errors_are_safe(self):
        self.assertEqual("dns_error", describe_error(socket.gaierror(-3, "private details"))["code"])
        self.assertEqual("crawl_timeout", describe_error(TimeoutError("private details"))["code"])
        error = describe_error(RuntimeError("private details"))
        self.assertEqual("fetch_error", error["code"])
        self.assertNotIn("private details", str(error))
