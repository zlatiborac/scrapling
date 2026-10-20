from http.server import BaseHTTPRequestHandler, HTTPServer


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
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
