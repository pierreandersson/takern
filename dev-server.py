#!/usr/bin/env python3
"""Local dev server that serves static files and proxies API calls to live server."""

import http.server
import urllib.request
import os
import sys

DEPLOY_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "deploy")
LIVE_API = "https://pierrea.se/takern"

class DevHandler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=DEPLOY_DIR, **kwargs)

    def do_GET(self):
        # Proxy PHP API calls to live server
        if self.path.startswith("/stats-api.php") or self.path.startswith("/api-proxy.php"):
            url = LIVE_API + self.path
            try:
                req = urllib.request.Request(url)
                with urllib.request.urlopen(req, timeout=30) as resp:
                    data = resp.read()
                    self.send_response(resp.status)
                    self.send_header("Content-Type", resp.headers.get("Content-Type", "application/json"))
                    self.send_header("Access-Control-Allow-Origin", "*")
                    self.end_headers()
                    self.wfile.write(data)
            except Exception as e:
                self.send_error(502, f"Proxy error: {e}")
            return
        # Serve static files from deploy/
        super().do_GET()

if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8080
    server = http.server.HTTPServer(("", port), DevHandler)
    print(f"Dev server: http://localhost:{port} (static from deploy/, API proxied to {LIVE_API})")
    server.serve_forever()
