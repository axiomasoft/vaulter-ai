"""Stdlib-only client for POST /api/vaulter-ai/v1/operations."""

from __future__ import annotations

import json
from typing import Any, Callable
from urllib.error import HTTPError, URLError
from urllib.request import HTTPRedirectHandler, Request, build_opener


class _NoRedirectHandler(HTTPRedirectHandler):
    """Refuse all HTTP redirects so Authorization headers never leave the configured origin."""

    def redirect_request(
        self,
        req: Request,
        fp: Any,
        code: int,
        msg: str,
        headers: Any,
        newurl: str,
    ) -> None:
        return None


class OfficeProtocolError(RuntimeError):
    """Raised when the Office HTTP endpoint returns a non-JSON or malformed transport error."""

    def __init__(self, status: int, body: Any) -> None:
        self.status = status
        self.body = body
        super().__init__(f"Office HTTP error {status}: {body!r}")


class OfficeClient:
    """Thin stdlib HTTP client without ORM, DSN, or owner credentials."""

    def __init__(
        self,
        endpoint: str,
        credentials_provider: Callable[[], dict[str, str]],
        timeout: float = 10.0,
    ) -> None:
        self._endpoint = endpoint
        self._credentials_provider = credentials_provider
        self._timeout = timeout
        self._opener = build_opener(_NoRedirectHandler())

    def call(self, operation: str, payload_input: dict[str, Any], *, version: int = 1) -> dict[str, Any]:
        envelope = {
            "version": version,
            "operation": operation,
            "input": payload_input,
        }
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            **self._credentials_provider(),
        }
        data = json.dumps(envelope, ensure_ascii=False).encode("utf-8")
        req = Request(self._endpoint, data=data, headers=headers, method="POST")

        try:
            with self._opener.open(req, timeout=self._timeout) as resp:
                raw = resp.read().decode("utf-8")
                decoded = json.loads(raw)
                if not isinstance(decoded, dict):
                    raise OfficeProtocolError(resp.status, decoded)
                return decoded
        except HTTPError as exc:
            try:
                raw = exc.read().decode("utf-8", errors="replace")
            finally:
                exc.close()
            if 300 <= exc.code < 400:
                raise OfficeProtocolError(exc.code, raw) from exc
            try:
                decoded = json.loads(raw)
            except json.JSONDecodeError as parse_err:
                raise OfficeProtocolError(exc.code, raw) from parse_err
            if not isinstance(decoded, dict):
                raise OfficeProtocolError(exc.code, decoded)
            return decoded
        except URLError as exc:
            raise ConnectionError(f"Office HTTP transport error: {exc.reason}") from exc

    def search(
        self,
        query: str,
        *,
        resource_type: str | None = None,
        limit: int | None = None,
        cursor: str | None = None,
    ) -> dict[str, Any]:
        body: dict[str, Any] = {"query": query}
        if resource_type is not None:
            body["resource_type"] = resource_type
        if limit is not None:
            body["limit"] = limit
        if cursor is not None:
            body["cursor"] = cursor
        return self.call("office.search", body)

    def read(
        self,
        resource_id: str,
        selector: dict[str, Any],
        *,
        limit: int | None = None,
        cursor: str | None = None,
        after_receipt: str | None = None,
    ) -> dict[str, Any]:
        body: dict[str, Any] = {"resource_id": resource_id, "selector": selector}
        if limit is not None:
            body["limit"] = limit
        if cursor is not None:
            body["cursor"] = cursor
        if after_receipt is not None:
            body["after_receipt"] = after_receipt
        return self.call("office.read", body)

    def describe(self, resource_id: str, command: str) -> dict[str, Any]:
        return self.call("office.describe", {"resource_id": resource_id, "command": command})
