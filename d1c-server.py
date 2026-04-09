#!/usr/bin/env python3
import json
import requests
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse

HOST = "0.0.0.0"
PORT = 8080
CF_API = "https://api.cloudflare.com/client/v4"


class Handler(BaseHTTPRequestHandler):

    def _cors(self):
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")

    def _json(self, status, data):
        body = json.dumps(data).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self._cors()
        self.end_headers()
        self.wfile.write(body)

    def _body(self):
        n = int(self.headers.get("Content-Length", 0))
        return json.loads(self.rfile.read(n)) if n else {}

    def log_message(self, fmt, *args):
        pass

    def do_OPTIONS(self):
        self.send_response(204)
        self._cors()
        self.end_headers()

    def do_GET(self):
        path = urlparse(self.path).path
        if path == "/health":
            self._json(200, {"ok": True})
        else:
            self._json(404, {"error": "not found"})

    def do_POST(self):
        path = urlparse(self.path).path
        if path != "/api/query":
            return self._json(404, {"error": "not found"})

        try:
            b = self._body()
            account_id  = b.get("account_id", "").strip()
            database_id = b.get("database_id", "").strip()
            api_token   = b.get("api_token", "").strip()
            sql         = b.get("sql", "").strip()
            params      = b.get("params", [])

            if not all([account_id, database_id, api_token, sql]):
                return self._json(400, {"error": "account_id, database_id, api_token and sql are required"})

            url = f"{CF_API}/accounts/{account_id}/d1/database/{database_id}/query"
            resp = requests.post(
                url,
                headers={
                    "Authorization": f"Bearer {api_token}",
                    "Content-Type": "application/json",
                },
                json={"sql": sql, "params": params},
                timeout=30,
            )
            resp.raise_for_status()
            self._json(200, resp.json())

        except requests.HTTPError as e:
            try:
                msg = e.response.json().get("errors", [{}])[0].get("message", str(e))
            except Exception:
                msg = str(e)
            self._json(502, {"error": f"Cloudflare error: {msg}"})
        except requests.RequestException as e:
            self._json(502, {"error": f"Network error: {e}"})
        except Exception as e:
            self._json(500, {"error": str(e)})


if __name__ == "__main__":
    server = HTTPServer((HOST, PORT), Handler)
    server.serve_forever()
