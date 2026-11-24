"""Unit tests for the thin stdlib OfficeClient wire serialization and HTTP error boundaries."""

from __future__ import annotations

import json
import socket
import sys
import threading
import unittest
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
from typing import Any

# `unittest discover -s clients/python/tests` puts the tests directory on
# sys.path, not the sibling package, and the pre-gate does not install it.
_PACKAGE_ROOT = Path(__file__).resolve().parents[1]
if str(_PACKAGE_ROOT) not in sys.path:
    sys.path.insert(0, str(_PACKAGE_ROOT))

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

    def test_resume_calls_status_first_and_returns_hidden_resource_error(self) -> None:
        _MockOfficeHandler.next_response = (
            404,
            {
                "version": 1,
                "operation": "office.status",
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
            credentials_provider=lambda: {"Authorization": "Bearer host-token-123"},
        )

        resumed = client.resume("hidden-node", "cmd-hidden")
        self.assertFalse(resumed["ok"])
        self.assertEqual(resumed["error"]["code"], "resource_unavailable")

        headers, sent = _MockOfficeHandler.captured_requests[-1]
        self.assertEqual(headers["authorization"], "Bearer host-token-123")
        self.assertEqual(
            sent,
            {
                "version": 1,
                "operation": "office.status",
                "input": {"resource_id": "hidden-node", "command_id": "cmd-hidden"},
            },
        )
        self.assertEqual([body["operation"] for _, body in _MockOfficeHandler.captured_requests], ["office.status"])

    def test_lost_commit_response_resumes_with_the_known_command_id(self) -> None:
        class _DropCommitHandler(BaseHTTPRequestHandler):
            seen: list[dict[str, Any]] = []

            def do_POST(self) -> None:  # noqa: N802
                length = int(self.headers.get("Content-Length", "0"))
                body = json.loads(self.rfile.read(length).decode("utf-8"))
                type(self).seen.append(body)
                if body["operation"] == "office.commit":
                    self.close_connection = True
                    try:
                        self.connection.shutdown(socket.SHUT_RDWR)
                    except OSError:
                        pass
                    return

                command_id = body["input"]["command_id"]
                response = {
                    "version": 1,
                    "operation": "office.status",
                    "ok": True,
                    "result": {
                        "command_id": command_id,
                        "state": "committed",
                        "receipt_id": "rcpt-kept",
                    },
                }
                encoded = json.dumps(response).encode("utf-8")
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(encoded)))
                self.end_headers()
                self.wfile.write(encoded)

            def log_message(self, format: str, *args: object) -> None:  # noqa: A002
                return

        _DropCommitHandler.seen = []
        server = HTTPServer(("127.0.0.1", 0), _DropCommitHandler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        command_id = "cmd-known-after-loss"
        try:
            client = OfficeClient(
                endpoint=f"http://127.0.0.1:{server.server_port}/api/vaulter-ai/v1/operations",
                credentials_provider=lambda: {"Authorization": "Bearer host-token-123"},
                timeout=2.0,
            )
            with self.assertRaises(ConnectionError):
                client.call(
                    "office.commit",
                    {
                        "resource_id": "doc-1",
                        "proposal_id": "prop-1",
                        "digest": "abc",
                        "command_id": command_id,
                    },
                )

            resumed = client.resume("doc-1", command_id)
            self.assertTrue(resumed["ok"])
            self.assertEqual(resumed["result"]["state"], "committed")
            self.assertEqual(resumed["result"]["receipt_id"], "rcpt-kept")
            self.assertEqual(resumed["result"]["command_id"], command_id)
            self.assertEqual(
                [body["operation"] for body in _DropCommitHandler.seen],
                ["office.commit", "office.status"],
            )
            self.assertEqual(_DropCommitHandler.seen[1]["input"]["command_id"], command_id)
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=2.0)


if __name__ == "__main__":
    unittest.main()
