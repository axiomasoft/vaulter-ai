"""Unit tests for the thin stdlib OfficeClient wire serialization and HTTP error boundaries."""

from __future__ import annotations

import json
import threading
import unittest
from http.server import BaseHTTPRequestHandler, HTTPServer
from typing import Any

from vaulter_office import OfficeClient, OfficeProtocolError


class _MockOfficeHandler(BaseHTTPRequestHandler):
    captured_requests: list[tuple[dict[str, str], dict[str, Any]]] = []
    next_response: tuple[int, dict[str, Any]] = (200, {})

    def do_POST(self) -> None:  # noqa: N802
        length = int(self.headers.get("Content-Length", "0"))
        body = json.loads(self.rfile.read(length).decode("utf-8"))
        headers = {k.lower(): v for k, v in self.headers.items()}
        type(self).captured_requests.append((headers, body))

        status, response = type(self).next_response
        encoded = json.dumps(response, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)

    def log_message(self, format: str, *args: object) -> None:  # noqa: A002
        return


class OfficeClientWireTest(unittest.TestCase):
    def setUp(self) -> None:
        _MockOfficeHandler.captured_requests = []
        self.server = HTTPServer(("127.0.0.1", 0), _MockOfficeHandler)
        self.port = self.server.server_port
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()

    def tearDown(self) -> None:
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=2.0)

    def test_serializes_search_and_headers_without_leaking_credentials(self) -> None:
        _MockOfficeHandler.next_response = (
            200,
            {
                "version": 1,
                "operation": "office.search",
                "ok": True,
                "result": {
                    "items": [
                        {
                            "resource_id": "contract-17",
                            "resource_type": "text",
                            "title": "Договор поставки",
                        }
                    ],
                    "truncated": False,
                    "next_cursor": None,
                },
            },
        )
        client = OfficeClient(
            endpoint=f"http://127.0.0.1:{self.port}/api/vaulter-ai/v1/operations",
            credentials_provider=lambda: {"Authorization": "Bearer host-token-123"},
        )

        res = client.search("договор", resource_type="text", limit=5)
        self.assertTrue(res["ok"])
        self.assertEqual(res["result"]["items"][0]["resource_id"], "contract-17")

        headers, sent = _MockOfficeHandler.captured_requests[-1]
        self.assertEqual(headers["authorization"], "Bearer host-token-123")
        self.assertEqual(
            sent,
            {
                "version": 1,
                "operation": "office.search",
                "input": {"query": "договор", "resource_type": "text", "limit": 5},
            },
        )

    def test_handles_http_404_and_400_envelopes(self) -> None:
        _MockOfficeHandler.next_response = (
            404,
            {
                "version": 1,
                "operation": "office.read",
                "ok": False,
                "error": {
                    "code": "resource_unavailable",
                    "retryable": False,
                    "next_action": "stop",
                },
            },
        )
        client = OfficeClient(
            endpoint=f"http://127.0.0.1:{self.port}/api/vaulter-ai/v1/operations",
            credentials_provider=lambda: {},
        )

        res = client.read("hidden-node", {"kind": "text_outline", "query": "срок"})
        self.assertFalse(res["ok"])
        self.assertEqual(res["error"]["code"], "resource_unavailable")

        _MockOfficeHandler.next_response = (400, {"error": "invalid_request"})
        bad = client.call("office.unknown", {})
        self.assertEqual(bad, {"error": "invalid_request"})

    def test_refuses_redirects_without_forwarding_credentials_to_other_origin(self) -> None:
        captured_second: list[dict[str, str]] = []

        class _RedirectHandler(BaseHTTPRequestHandler):
            redirect_target = ""
            redirect_code = 302

            def do_POST(self) -> None:  # noqa: N802
                length = int(self.headers.get("Content-Length", "0"))
                self.rfile.read(length)
                body = json.dumps({"ok": True}).encode("utf-8")
                self.send_response(type(self).redirect_code)
                self.send_header("Location", type(self).redirect_target)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def log_message(self, format: str, *args: object) -> None:  # noqa: A002
                return

        class _SecondOriginHandler(BaseHTTPRequestHandler):
            def _record(self) -> None:
                captured_second.append({k.lower(): v for k, v in self.headers.items()})
                self.send_response(200)
                self.end_headers()

            def do_GET(self) -> None:  # noqa: N802
                self._record()

            def do_POST(self) -> None:  # noqa: N802
                self._record()

            def log_message(self, format: str, *args: object) -> None:  # noqa: A002
                return

        origin_one = HTTPServer(("127.0.0.1", 0), _RedirectHandler)
        origin_two = HTTPServer(("127.0.0.1", 0), _SecondOriginHandler)
        _RedirectHandler.redirect_target = f"http://127.0.0.1:{origin_two.server_port}/steal"

        t1 = threading.Thread(target=origin_one.serve_forever, daemon=True)
        t2 = threading.Thread(target=origin_two.serve_forever, daemon=True)
        t1.start()
        t2.start()

        try:
            client = OfficeClient(
                endpoint=f"http://127.0.0.1:{origin_one.server_port}/api/vaulter-ai/v1/operations",
                credentials_provider=lambda: {"Authorization": "Bearer top-secret-host-token"},
            )
            for code in (302, 307):
                _RedirectHandler.redirect_code = code
                with self.assertRaises(OfficeProtocolError) as ctx:
                    client.search("договор")
                self.assertEqual(ctx.exception.status, code)

            self.assertEqual(captured_second, [])
        finally:
            origin_one.shutdown()
            origin_one.server_close()
            origin_two.shutdown()
            origin_two.server_close()
            t1.join(timeout=2.0)
            t2.join(timeout=2.0)


if __name__ == "__main__":
    unittest.main()
