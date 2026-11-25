"""Stdlib-only sync and async clients for Vaulter Office SDK v1 and workspace/chat endpoints."""

from __future__ import annotations

import asyncio
import copy
import http.client
import inspect
import json
import time
import uuid
from collections.abc import AsyncIterator, Awaitable, Callable, Iterator
from contextlib import contextmanager
from typing import Any, Concatenate
from urllib.error import HTTPError, URLError
from urllib.parse import quote, urlencode, urlsplit, urlunsplit
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


@contextmanager
def _transport_errors(kind: str) -> Iterator[None]:
    """Map socket/HTTP-library failures to TimeoutError or ConnectionError; HTTP status errors pass through."""
    try:
        yield
    except HTTPError:
        raise
    except TimeoutError as exc:
        raise TimeoutError(f"Office {kind} request timed out: {exc}") from exc
    except URLError as exc:
        if isinstance(exc.reason, TimeoutError):
            raise TimeoutError(f"Office {kind} request timed out: {exc.reason}") from exc
        raise ConnectionError(f"Office {kind} transport error: {exc.reason}") from exc
    except (http.client.HTTPException, OSError) as exc:
        raise ConnectionError(f"Office {kind} transport error: {exc}") from exc


def _drain(exc: HTTPError) -> bytes:
    try:
        return exc.read()
    finally:
        exc.close()


def _json_object(status: int, raw: bytes) -> dict[str, Any]:
    """Decode a JSON object body; anything else (HTML error page, bad UTF-8, a list) is a protocol error."""
    try:
        decoded = json.loads(raw.decode("utf-8"))
    except ValueError as exc:
        raise OfficeProtocolError(status, raw.decode("utf-8", errors="replace")) from exc
    if not isinstance(decoded, dict):
        raise OfficeProtocolError(status, decoded)
    return decoded


def _derive_workspace_endpoint(operations_endpoint: str) -> str:
    parsed = urlsplit(operations_endpoint)
    suffix = "/api/vaulter-ai/v1/operations"
    if parsed.path.endswith(suffix):
        prefix = parsed.path[: -len(suffix)]
        workspace_path = f"{prefix}/office"
    else:
        workspace_path = "/office"
    return urlunsplit((parsed.scheme, parsed.netloc, workspace_path, "", "")).rstrip("/")


def _parse_sse_block(block: str) -> dict[str, Any] | None:
    event_type = "message"
    raw_id: str | None = None
    data_lines: list[str] = []

    for raw_line in block.split("\n"):
        line = raw_line[:-1] if raw_line.endswith("\r") else raw_line
        if line == "" or line.startswith(":"):
            continue
        if ":" in line:
            field, value = line.split(":", 1)
            if value.startswith(" "):
                value = value[1:]
        else:
            field, value = line, ""

        if field == "event":
            event_type = value or "message"
        elif field == "data":
            data_lines.append(value)
        elif field == "id":
            raw_id = value

    if not data_lines and event_type == "message" and raw_id is None:
        return None

    seq: int | None = int(raw_id) if raw_id is not None and raw_id.isdigit() else None
    raw_data = "\n".join(data_lines)
    parsed_data: Any = {}
    if raw_data != "":
        try:
            parsed_data = json.loads(raw_data)
        except json.JSONDecodeError:
            parsed_data = raw_data

    return {
        "seq": seq,
        "id": raw_id,
        "event": event_type,
        "type": event_type,
        "data": parsed_data,
    }


class _SseDecoder:
    """Incremental text/event-stream decoder: feed raw lines, get an event at each blank line."""

    def __init__(self) -> None:
        self._lines: list[str] = []

    def feed(self, raw_line: bytes) -> dict[str, Any] | None:
        line = raw_line.decode("utf-8", errors="replace").rstrip("\r\n")
        if line != "":
            self._lines.append(line)
            return None
        return self.finish()

    def finish(self) -> dict[str, Any] | None:
        """Flush a trailing event when the stream ends without its blank line."""
        if not self._lines:
            return None
        block = "\n".join(self._lines)
        self._lines.clear()
        return _parse_sse_block(block)


def _is_error(response: dict[str, Any], code: str) -> bool:
    error = response.get("error")
    return response.get("ok") is False and isinstance(error, dict) and error.get("code") == code


def _retry_delay(backoff: float, attempt: int) -> float:
    return backoff * (1 << attempt)


class OfficeClient:
    """Thin stdlib HTTP client without ORM, DSN, or owner credentials."""

    def __init__(
        self,
        endpoint: str,
        credentials_provider: Callable[[], dict[str, str]],
        timeout: float = 10.0,
        *,
        workspace_endpoint: str | None = None,
        max_retries: int = 2,
        retry_backoff: float = 0.1,
    ) -> None:
        self._endpoint = endpoint
        self._workspace_endpoint = (
            workspace_endpoint.rstrip("/")
            if workspace_endpoint is not None
            else _derive_workspace_endpoint(endpoint)
        )
        self._credentials_provider = credentials_provider
        self._timeout = timeout
        self._max_retries = max(0, max_retries)
        self._retry_backoff = max(0.0, retry_backoff)
        self._opener = build_opener(_NoRedirectHandler())

    def _with_credentials(self, headers: dict[str, str]) -> OfficeClient:
        """Return a shallow copy that sends fixed credentials, sharing endpoint, opener and retry settings."""
        bound = copy.copy(self)
        bound._credentials_provider = lambda: headers
        return bound

    def _send_json(
        self,
        url: str,
        method: str = "POST",
        payload: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        headers = {
            "Accept": "application/json",
            **self._credentials_provider(),
        }
        data: bytes | None = None
        if payload is not None:
            headers["Content-Type"] = "application/json"
            data = json.dumps(payload, ensure_ascii=False).encode("utf-8")

        req = Request(url, data=data, headers=headers, method=method)

        try:
            with _transport_errors("HTTP"), self._opener.open(req, timeout=self._timeout) as resp:
                status: int = resp.status
                return _json_object(status, resp.read())
        except HTTPError as exc:
            raw = _drain(exc)
            if 300 <= exc.code < 400:
                raise OfficeProtocolError(exc.code, raw.decode("utf-8", errors="replace")) from exc
            # The Office endpoint reports domain errors as JSON envelopes with a 4xx/5xx status.
            return _json_object(exc.code, raw)

    def _workspace_url(self, path: str, query: dict[str, str] | None = None) -> str:
        url = f"{self._workspace_endpoint}{path}"
        if query:
            return f"{url}?{urlencode(query)}"
        return url

    def call(self, operation: str, payload_input: dict[str, Any], *, version: int = 1) -> dict[str, Any]:
        envelope = {
            "version": version,
            "operation": operation,
            "input": payload_input,
        }
        return self._send_json(self._endpoint, method="POST", payload=envelope)

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

    def prepare(
        self,
        resource_id: str,
        command: str,
        read_token: str,
        arguments: dict[str, Any],
    ) -> dict[str, Any]:
        return self.call(
            "office.prepare",
            {
                "resource_id": resource_id,
                "command": command,
                "read_token": read_token,
                "arguments": arguments,
            },
        )

    def commit(
        self,
        resource_id: str,
        proposal_id: str,
        digest: str,
        command_id: str | None = None,
        *,
        max_retries: int | None = None,
        retry_backoff: float | None = None,
    ) -> dict[str, Any]:
        resolved_command_id = command_id if command_id is not None else f"cmd-{uuid.uuid4()}"
        retries = self._max_retries if max_retries is None else max(0, max_retries)
        backoff = self._retry_backoff if retry_backoff is None else max(0.0, retry_backoff)
        payload = {
            "resource_id": resource_id,
            "proposal_id": proposal_id,
            "digest": digest,
            "command_id": resolved_command_id,
        }

        for attempt in range(retries + 1):
            try:
                res = self.call("office.commit", payload)
            except (TimeoutError, ConnectionError):
                if attempt >= retries:
                    raise
                time.sleep(_retry_delay(backoff, attempt))
                continue

            if attempt < retries and _is_error(res, "temporarily_unavailable"):
                # The commit may have landed before the failure: ask for its status under the same
                # command_id and only re-send it when the server has no record of the command.
                time.sleep(_retry_delay(backoff, attempt))
                status_res = self.status(resource_id, resolved_command_id)
                if _is_error(status_res, "operation_unknown"):
                    continue
                return status_res

            return res

        raise AssertionError("the last commit attempt always returns or raises")

    def status(self, resource_id: str, command_id: str) -> dict[str, Any]:
        return self.call(
            "office.status",
            {"resource_id": resource_id, "command_id": command_id},
        )

    def resume(self, resource_id: str, command_id: str) -> dict[str, Any]:
        """Recover a command after the commit response was lost."""
        return self.status(resource_id, command_id)

    def verify(self, resource_id: str, receipt_id: str, mode: str = "at_commit") -> dict[str, Any]:
        return self.call(
            "office.verify",
            {"resource_id": resource_id, "receipt_id": receipt_id, "mode": mode},
        )

    def list_proposals(
        self,
        *,
        resource_id: str | None = None,
        decision: str | None = None,
    ) -> dict[str, Any]:
        query: dict[str, str] = {}
        if resource_id is not None:
            query["resource_id"] = resource_id
        if decision is not None:
            query["decision"] = decision
        return self._send_json(self._workspace_url("/proposals", query or None), method="GET")

    def get_proposal(self, proposal_id: str) -> dict[str, Any]:
        return self._send_json(self._workspace_url(f"/proposals/{quote(proposal_id, safe='')}"), method="GET")

    def decide_proposal(
        self,
        proposal_id: str,
        digest: str,
        approved: bool,
        *,
        command_id: str | None = None,
    ) -> dict[str, Any]:
        body: dict[str, Any] = {"digest": digest, "approved": approved}
        if command_id is not None:
            body["command_id"] = command_id
        return self._send_json(
            self._workspace_url(f"/proposals/{quote(proposal_id, safe='')}/decision"),
            method="POST",
            payload=body,
        )

    def get_run(self, run_id: str) -> dict[str, Any]:
        return self._send_json(self._workspace_url(f"/runs/{quote(run_id, safe='')}"), method="GET")

    def resume_run(
        self,
        run_id: str,
        *,
        arguments_by_resource: dict[str, dict[str, Any]] | None = None,
        reread_only: bool = False,
    ) -> dict[str, Any]:
        body: dict[str, Any] = {"reread_only": reread_only}
        if arguments_by_resource is not None:
            body["arguments_by_resource"] = arguments_by_resource
        return self._send_json(
            self._workspace_url(f"/runs/{quote(run_id, safe='')}/resume"),
            method="POST",
            payload=body,
        )

    def open_chat_session(self, *, retention_seconds: int | None = None) -> dict[str, Any]:
        body: dict[str, Any] = {}
        if retention_seconds is not None:
            body["retention_seconds"] = retention_seconds
        return self._send_json(self._workspace_url("/chat/sessions"), method="POST", payload=body)

    def get_chat_session(self, session_id: str) -> dict[str, Any]:
        return self._send_json(self._workspace_url(f"/chat/sessions/{quote(session_id, safe='')}"), method="GET")

    def post_chat_message(
        self,
        session_id: str,
        message_id: str,
        content: str,
        resource_ids: list[str],
        *,
        selection: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        body: dict[str, Any] = {
            "message_id": message_id,
            "content": content,
            "resource_ids": resource_ids,
        }
        if selection is not None:
            body["selection"] = selection
        return self._send_json(
            self._workspace_url(f"/chat/sessions/{quote(session_id, safe='')}/messages"),
            method="POST",
            payload=body,
        )

    def _open_chat_event_stream(self, session_id: str, cursor: int) -> http.client.HTTPResponse:
        url = self._workspace_url(
            f"/chat/sessions/{quote(session_id, safe='')}/events",
            {"cursor": str(cursor)},
        )
        headers = {
            "Accept": "text/event-stream",
            "Last-Event-ID": str(cursor),
            **self._credentials_provider(),
        }
        req = Request(url, headers=headers, method="GET")
        try:
            with _transport_errors("SSE"):
                resp: http.client.HTTPResponse = self._opener.open(req, timeout=self._timeout)
                return resp
        except HTTPError as exc:
            text = _drain(exc).decode("utf-8", errors="replace")
            try:
                body: Any = json.loads(text)
            except ValueError:
                body = text
            raise OfficeProtocolError(exc.code, body) from exc

    def stream_chat_events(self, session_id: str, *, cursor: int = 0) -> Iterator[dict[str, Any]]:
        resp = self._open_chat_event_stream(session_id, cursor)
        decoder = _SseDecoder()
        try:
            while raw_line := resp.readline():
                if (event := decoder.feed(raw_line)) is not None:
                    yield event
            if (event := decoder.finish()) is not None:
                yield event
        finally:
            resp.close()


class AsyncOfficeClient:
    """Asyncio stdlib wrapper over OfficeClient using asyncio.to_thread without extra dependencies."""

    def __init__(
        self,
        endpoint: str,
        credentials_provider: Callable[[], dict[str, str] | Awaitable[dict[str, str]]],
        timeout: float = 10.0,
        *,
        workspace_endpoint: str | None = None,
        max_retries: int = 2,
        retry_backoff: float = 0.1,
    ) -> None:
        self._credentials_provider = credentials_provider
        self._sync = OfficeClient(
            endpoint=endpoint,
            credentials_provider=lambda: {},
            timeout=timeout,
            workspace_endpoint=workspace_endpoint,
            max_retries=max_retries,
            retry_backoff=retry_backoff,
        )
        self._max_retries = max(0, max_retries)
        self._retry_backoff = max(0.0, retry_backoff)

    async def __aenter__(self) -> AsyncOfficeClient:
        return self

    async def __aexit__(self, *_args: object) -> None:
        return None

    async def _resolve_headers(self) -> dict[str, str]:
        result = self._credentials_provider()
        if inspect.isawaitable(result):
            return await result
        return result

    async def _call[**P, R](
        self,
        method: Callable[Concatenate[OfficeClient, P], R],
        *args: P.args,
        **kwargs: P.kwargs,
    ) -> R:
        """Run a sync client method in a worker thread with credentials resolved for this call only.

        Every call gets its own credential-bound copy of the sync client: swapping the provider on
        one shared client let a concurrent task's worker thread send another caller's credentials.
        """
        client = self._sync._with_credentials(await self._resolve_headers())
        return await asyncio.to_thread(method, client, *args, **kwargs)

    async def call(self, operation: str, payload_input: dict[str, Any], *, version: int = 1) -> dict[str, Any]:
        return await self._call(OfficeClient.call, operation, payload_input, version=version)

    async def search(
        self,
        query: str,
        *,
        resource_type: str | None = None,
        limit: int | None = None,
        cursor: str | None = None,
    ) -> dict[str, Any]:
        return await self._call(
            OfficeClient.search,
            query,
            resource_type=resource_type,
            limit=limit,
            cursor=cursor,
        )

    async def read(
        self,
        resource_id: str,
        selector: dict[str, Any],
        *,
        limit: int | None = None,
        cursor: str | None = None,
        after_receipt: str | None = None,
    ) -> dict[str, Any]:
        return await self._call(
            OfficeClient.read,
            resource_id,
            selector,
            limit=limit,
            cursor=cursor,
            after_receipt=after_receipt,
        )

    async def describe(self, resource_id: str, command: str) -> dict[str, Any]:
        return await self._call(OfficeClient.describe, resource_id, command)

    async def prepare(
        self,
        resource_id: str,
        command: str,
        read_token: str,
        arguments: dict[str, Any],
    ) -> dict[str, Any]:
        return await self._call(
            OfficeClient.prepare,
            resource_id,
            command,
            read_token,
            arguments,
        )

    async def commit(
        self,
        resource_id: str,
        proposal_id: str,
        digest: str,
        command_id: str | None = None,
        *,
        max_retries: int | None = None,
        retry_backoff: float | None = None,
    ) -> dict[str, Any]:
        resolved_command_id = command_id if command_id is not None else f"cmd-{uuid.uuid4()}"
        retries = self._max_retries if max_retries is None else max(0, max_retries)
        backoff = self._retry_backoff if retry_backoff is None else max(0.0, retry_backoff)
        payload = {
            "resource_id": resource_id,
            "proposal_id": proposal_id,
            "digest": digest,
            "command_id": resolved_command_id,
        }

        for attempt in range(retries + 1):
            try:
                res = await self.call("office.commit", payload)
            except (TimeoutError, ConnectionError):
                if attempt >= retries:
                    raise
                await asyncio.sleep(_retry_delay(backoff, attempt))
                continue

            if attempt < retries and _is_error(res, "temporarily_unavailable"):
                # The commit may have landed before the failure: ask for its status under the same
                # command_id and only re-send it when the server has no record of the command.
                await asyncio.sleep(_retry_delay(backoff, attempt))
                status_res = await self.status(resource_id, resolved_command_id)
                if _is_error(status_res, "operation_unknown"):
                    continue
                return status_res

            return res

        raise AssertionError("the last commit attempt always returns or raises")

    async def status(self, resource_id: str, command_id: str) -> dict[str, Any]:
        return await self._call(OfficeClient.status, resource_id, command_id)

    async def resume(self, resource_id: str, command_id: str) -> dict[str, Any]:
        return await self.status(resource_id, command_id)

    async def verify(self, resource_id: str, receipt_id: str, mode: str = "at_commit") -> dict[str, Any]:
        return await self._call(OfficeClient.verify, resource_id, receipt_id, mode)

    async def list_proposals(
        self,
        *,
        resource_id: str | None = None,
        decision: str | None = None,
    ) -> dict[str, Any]:
        return await self._call(
            OfficeClient.list_proposals,
            resource_id=resource_id,
            decision=decision,
        )

    async def get_proposal(self, proposal_id: str) -> dict[str, Any]:
        return await self._call(OfficeClient.get_proposal, proposal_id)

    async def decide_proposal(
        self,
        proposal_id: str,
        digest: str,
        approved: bool,
        *,
        command_id: str | None = None,
    ) -> dict[str, Any]:
        return await self._call(
            OfficeClient.decide_proposal,
            proposal_id,
            digest,
            approved,
            command_id=command_id,
        )

    async def get_run(self, run_id: str) -> dict[str, Any]:
        return await self._call(OfficeClient.get_run, run_id)

    async def resume_run(
        self,
        run_id: str,
        *,
        arguments_by_resource: dict[str, dict[str, Any]] | None = None,
        reread_only: bool = False,
    ) -> dict[str, Any]:
        return await self._call(
            OfficeClient.resume_run,
            run_id,
            arguments_by_resource=arguments_by_resource,
            reread_only=reread_only,
        )

    async def open_chat_session(self, *, retention_seconds: int | None = None) -> dict[str, Any]:
        return await self._call(OfficeClient.open_chat_session, retention_seconds=retention_seconds)

    async def get_chat_session(self, session_id: str) -> dict[str, Any]:
        return await self._call(OfficeClient.get_chat_session, session_id)

    async def post_chat_message(
        self,
        session_id: str,
        message_id: str,
        content: str,
        resource_ids: list[str],
        *,
        selection: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        return await self._call(
            OfficeClient.post_chat_message,
            session_id,
            message_id,
            content,
            resource_ids,
            selection=selection,
        )

    async def stream_chat_events(self, session_id: str, *, cursor: int = 0) -> AsyncIterator[dict[str, Any]]:
        client = self._sync._with_credentials(await self._resolve_headers())
        resp = await asyncio.to_thread(client._open_chat_event_stream, session_id, cursor)
        decoder = _SseDecoder()
        try:
            while raw_line := await asyncio.to_thread(resp.readline):
                if (event := decoder.feed(raw_line)) is not None:
                    yield event
            if (event := decoder.finish()) is not None:
                yield event
        finally:
            await asyncio.to_thread(resp.close)
