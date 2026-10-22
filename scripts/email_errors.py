"""Safe error categories for the Laravel/Python protocol; never include URL secrets."""
import socket

from curl_cffi.curl import CurlError


class FetchError(ValueError):
    def __init__(self, code, message, http_status=None):
        super().__init__(message)
        self.code = code
        self.http_status = http_status


def describe_error(exc):
    return _describe_error(exc) | {"exception_type": type(exc).__name__}


def _describe_error(exc):
    if isinstance(exc, FetchError):
        return {"code": exc.code, "message": str(exc), "http_status": exc.http_status}
    if isinstance(exc, socket.gaierror):
        return {"code": "dns_error", "message": "DNS lookup failed"}
    if isinstance(exc, TimeoutError):
        return {"code": "crawl_timeout", "message": "Crawl time limit reached"}
    if isinstance(exc, CurlError):
        code = {
            5: "dns_error", 6: "dns_error", 7: "connection_error", 28: "request_timeout",
            35: "tls_error", 51: "tls_error", 58: "tls_error", 60: "tls_error", 77: "tls_error", 83: "tls_error",
        }.get(int(exc.code), "fetch_error")
        return {"code": code, "curl_code": int(exc.code), "message": {
            "dns_error": "DNS lookup failed", "connection_error": "Connection failed",
            "request_timeout": "Website request timed out", "tls_error": "TLS verification or negotiation failed",
        }.get(code, "Could not fetch this page")}
    if "local and private" in str(exc):
        return {"code": "blocked_url", "message": "Local and private addresses are blocked"}
    return {"code": "fetch_error", "message": "Could not fetch this page"}
