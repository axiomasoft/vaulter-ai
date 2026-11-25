"""Loopback HTTP server shared by the client tests."""

from __future__ import annotations

import json
import threading
from collections.abc import Callable
from dataclasses import dataclass, field
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from types import TracebackType
from typing import Any, Self


@dataclass(frozen=True)
class Recorded:
    method: str
    path: str
    headers: dict[str, str]
    body: Any


@dataclass(frozen=True)
class Reply:
    status: int = 200
    body: bytes = b"{}"
    content_type: str = "application/json"
    extra_headers: dict[str, str] = field(default_factory=dict)

    @classmethod
    def json(cls, payload: object, status: int = 200) -> Reply:
        return cls(status=status, body=json.dumps(payload).encode("utf-8"))


Responder = Callable[[Recorded], Reply]


class LoopbackServer:
    """Threaded HTTP server on 127.0.0.1 that records every request and answers through a responder."""

    def __init__(self, responder: Responder) -> None:
        self.requests: list[Recorded] = []
        self._lock = threading.Lock()
        outer = self

        class _Handler(BaseHTTPRequestHandler):
            def _handle(self) -> None:
                length = int(self.headers.get("Content-Length", "0"))
                raw = self.rfile.read(length) if length else b""
                recorded = Recorded(
                    method=self.command,
                    path=self.path,
                    headers={k.lower(): v for k, v in self.headers.items()},
                    body=json.loads(raw) if raw else None,
                )
                with outer._lock:
                    outer.requests.append(recorded)
                reply = responder(recorded)
                try:
                    self.send_response(reply.status)
                    self.send_header("Content-Type", reply.content_type)
                    self.send_header("Content-Length", str(len(reply.body)))
                    for name, value in reply.extra_headers.items():
                        self.send_header(name, value)
                    self.end_headers()
                    self.wfile.write(reply.body)
                except (BrokenPipeError, ConnectionResetError):
                    # The client gave up first (timeout tests); nothing left to deliver.
                    return

            do_GET = _handle  # noqa: N815
            do_POST = _handle  # noqa: N815

            def log_message(self, format: str, *args: object) -> None:  # noqa: A002
                return

        self._server = ThreadingHTTPServer(("127.0.0.1", 0), _Handler)
        # A short poll interval keeps shutdown() (called once per test) from blocking for the 0.5 s default.
        self._thread = threading.Thread(target=self._server.serve_forever, kwargs={"poll_interval": 0.01}, daemon=True)

    @property
    def base_url(self) -> str:
        return f"http://127.0.0.1:{self._server.server_port}"

    @property
    def operations_url(self) -> str:
        return f"{self.base_url}/api/vaulter-ai/v1/operations"

    def __enter__(self) -> Self:
        self._thread.start()
        return self

    def __exit__(
        self,
        exc_type: type[BaseException] | None,
        exc: BaseException | None,
        tb: TracebackType | None,
    ) -> None:
        self._server.shutdown()
        self._server.server_close()
        self._thread.join(timeout=2.0)
