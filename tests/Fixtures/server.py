from http.server import BaseHTTPRequestHandler, HTTPServer


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        fixtures = {
            "/emails": '<html><p>INFO@example.com</p><a href="/emails/contact#top">Contact</a><a href="/emails/contact#bottom">Contact again</a><a href="/emails/missing">Missing</a><a href="https://outside.example/">Outside</a></html>',
            "/emails/contact": '<html><a href="mailto:info@example.com,sales%2Bweb@example.com?subject=ignore@example.net">E-mail</a><a href="/emails">Home</a><a href="/emails/team">Team</a></html>',
            "/emails/team": '<html><p>team@example.com</p></html>',
        }
        if self.path in fixtures:
            self.send_response(200)
            self.send_header("Content-Type", "text/html; charset=utf-8")
            self.end_headers()
            self.wfile.write(fixtures[self.path].encode())
            return
        if self.path != "/":
            self.send_error(404)
            return
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.end_headers()
        self.wfile.write(b"<html><title>Scrapling fixture</title><h1>Laravel + Scrapling</h1></html>")

    def log_message(self, *args):
        pass


server = HTTPServer(("127.0.0.1", 0), Handler)
print(f"http://127.0.0.1:{server.server_port}", flush=True)
server.serve_forever()
