"""Chat event streams: SSE framing, ids, data decoding and the request the client sends."""

from __future__ import annotations

import unittest
from typing import Any

from support import LoopbackServer, Reply
from vaulter_office import AsyncOfficeClient, OfficeClient

_STREAM = (
    b": keep-alive comment\n"
    b"id: 1\n"
    b"event: assistant.delta\n"
    b'data: {"text": "Hel"}\n'
    b"\n"
    b"id: 2\r\n"
    b"event: assistant.delta\r\n"
    b'data: {"text":\r\n'
    b'data: "lo"}\r\n'
    b"\r\n"
    b"id: not-a-number\n"
    b"data: plain text\n"
    b"\n"
    b"\n"
    b"id: 3\n"
    b"event: run.finished\n"
    b"data:{}"
)

_EXPECTED: list[dict[str, Any]] = [
    {"seq": 1, "id": "1", "event": "assistant.delta", "type": "assistant.delta", "data": {"text": "Hel"}},
    {"seq": 2, "id": "2", "event": "assistant.delta", "type": "assistant.delta", "data": {"text": "lo"}},
    {"seq": None, "id": "not-a-number", "event": "message", "type": "message", "data": "plain text"},
    {"seq": 3, "id": "3", "event": "run.finished", "type": "run.finished", "data": {}},
]


def _stream_reply(_request: object) -> Reply:
    return Reply(body=_STREAM, content_type="text/event-stream")


class SyncStreamTest(unittest.TestCase):
    def test_decodes_events_and_flushes_the_unterminated_last_one(self) -> None:
        with LoopbackServer(_stream_reply) as server:
            client = OfficeClient(server.operations_url, lambda: {"Authorization": "Bearer t"})
            events = list(client.stream_chat_events("sess/1", cursor=7))

        self.assertEqual(events, _EXPECTED)
        (request,) = server.requests
        self.assertEqual(request.method, "GET")
        self.assertEqual(request.path, "/office/chat/sessions/sess%2F1/events?cursor=7")
        self.assertEqual(request.headers["accept"], "text/event-stream")
        self.assertEqual(request.headers["last-event-id"], "7")
        self.assertEqual(request.headers["authorization"], "Bearer t")


class AsyncStreamTest(unittest.IsolatedAsyncioTestCase):
    async def test_async_stream_yields_the_same_events(self) -> None:
        with LoopbackServer(_stream_reply) as server:
            client = AsyncOfficeClient(server.operations_url, lambda: {"Authorization": "Bearer a"})
            events = [event async for event in client.stream_chat_events("sess-1")]

        self.assertEqual(events, _EXPECTED)
        self.assertEqual(server.requests[0].headers["authorization"], "Bearer a")


if __name__ == "__main__":
    unittest.main()
