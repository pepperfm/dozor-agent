#!/usr/bin/env python3
"""Minimal CI-only HTTP receiver for PHP Payload -> Go agent -> HTTP 202."""
import json
import os
from http.server import BaseHTTPRequestHandler, HTTPServer

class Handler(BaseHTTPRequestHandler):
    def do_POST(self):
        if self.path != "/api/v1/ingest" or self.headers.get("Authorization") != "Bearer " + os.environ["DOZOR_TEST_TOKEN"]:
            self.send_response(401)
            self.end_headers()
            return
        try:
            length = int(self.headers["Content-Length"])
            if not 0 < length <= 1048576:
                raise ValueError("invalid size")
            body = self.rfile.read(length)
            data = json.loads(body)
            if data["v"] != 1 or data["app"]["token"] != os.environ["DOZOR_TEST_TOKEN"]:
                raise ValueError("unexpected payload")
            if not data.get("traces") or not data["traces"][0].get("spans"):
                raise ValueError("no transformed traces")
            with open(os.environ["DOZOR_RECEIVED_FILE"], "w", encoding="utf-8") as f:
                json.dump(data, f)
        except (KeyError, ValueError, TypeError, json.JSONDecodeError) as e:
            self.send_response(422)
            self.end_headers()
            return
        self.send_response(202)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(b'{"ok":true,"queued":1}')

    def log_message(self, format, *args):
        # Avoid logging payloads or the app token.
        pass

HTTPServer(("127.0.0.1", 18082), Handler).serve_forever()
