"""Transport boundaries: every failure surfaces as OfficeProtocolError, TimeoutError or ConnectionError."""

from __future__ import annotations

import socket
import time
import unittest

from support import LoopbackServer, Recorded, Reply
from vaulter_office import OfficeClient, OfficeProtocolError


def _client(server: LoopbackServer, timeout: float = 5.0) -> OfficeClient:
    return OfficeClient(server.operations_url, lambda: {"Authorization": "Bearer t"}, timeout=timeout)


def _unused_port() -> int:
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port: int = sock.getsockname()[1]
        return port


class ResponseBodyTest(unittest.TestCase):
    def assert_protocol_error(self, reply: Reply, status: int, body: object) -> None:
        with LoopbackServer(lambda _r: reply) as server, self.assertRaises(OfficeProtocolError) as ctx:
            _client(server).search("q")
        self.assertEqual(ctx.exception.status, status)
        self.assertEqual(ctx.exception.body, body)

    def test_html_success_page_is_a_protocol_error(self) -> None:
        self.assert_protocol_error(Reply(body=b"<html>proxy</html>", content_type="text/html"), 200, "<html>proxy</html>")

    def test_invalid_utf8_is_a_protocol_error(self) -> None:
        self.assert_protocol_error(Reply(body=b'{"ok": "\xff"}'), 200, '{"ok": "\ufffd"}')

    def test_json_that_is_not_an_object_is_a_protocol_error(self) -> None:
        self.assert_protocol_error(Reply.json([1, 2]), 200, [1, 2])

    def test_non_json_gateway_error_is_a_protocol_error(self) -> None:
        self.assert_protocol_error(Reply(status=502, body=b"Bad Gateway", content_type="text/plain"), 502, "Bad Gateway")

    def test_json_error_envelope_is_returned_to_the_caller(self) -> None:
        envelope = {"ok": False, "error": {"code": "invalid_input"}}
        with LoopbackServer(lambda _r: Reply.json(envelope, status=422)) as server:
            self.assertEqual(_client(server).search("q"), envelope)


class TransportFailureTest(unittest.TestCase):
    def test_refused_connection_is_a_connection_error(self) -> None:
        client = OfficeClient(f"http://127.0.0.1:{_unused_port()}/api/vaulter-ai/v1/operations", dict)
        with self.assertRaisesRegex(ConnectionError, "Office HTTP transport error"):
            client.search("q")
        with self.assertRaisesRegex(ConnectionError, "Office SSE transport error"):
            next(client.stream_chat_events("s-1"))

    def test_slow_server_is_a_timeout(self) -> None:
        def slow(_request: Recorded) -> Reply:
            time.sleep(0.5)
            return Reply.json({"ok": True})

        with LoopbackServer(slow) as server, self.assertRaisesRegex(TimeoutError, "Office HTTP request timed out"):
            _client(server, timeout=0.1).search("q")

    def test_sse_http_error_carries_the_decoded_envelope(self) -> None:
        envelope = {"ok": False, "error": {"code": "resource_unavailable"}}
        with LoopbackServer(lambda _r: Reply.json(envelope, status=404)) as server:
            with self.assertRaises(OfficeProtocolError) as ctx:
                next(_client(server).stream_chat_events("s-1"))
        self.assertEqual(ctx.exception.status, 404)
        self.assertEqual(ctx.exception.body, envelope)


if __name__ == "__main__":
    unittest.main()
