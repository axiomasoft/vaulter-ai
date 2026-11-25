"""AsyncOfficeClient: credential isolation between concurrent calls and awaitable providers."""

from __future__ import annotations

import asyncio
import time
import unittest
from typing import Any
from unittest import mock

from support import LoopbackServer, Recorded, Reply
from vaulter_office import AsyncOfficeClient, OfficeClient


def _echo(request: Recorded) -> Reply:
    return Reply.json({"ok": True, "result": {"query": request.body["input"]["query"]}})


class AsyncCredentialIsolationTest(unittest.IsolatedAsyncioTestCase):
    async def test_concurrent_calls_never_send_another_callers_credentials(self) -> None:
        # Each task authenticates as its own tenant. Delaying the worker thread before it builds
        # the request widens the window in which another task could swap the credentials.
        async def provider() -> dict[str, str]:
            task = asyncio.current_task()
            assert task is not None
            return {"Authorization": f"Bearer {task.get_name()}"}

        original = OfficeClient._send_json

        def slow_send(self: OfficeClient, *args: Any, **kwargs: Any) -> dict[str, Any]:
            time.sleep(0.02)
            return original(self, *args, **kwargs)

        with LoopbackServer(_echo) as server, mock.patch.object(OfficeClient, "_send_json", slow_send):
            client = AsyncOfficeClient(server.operations_url, provider)
            tenants = [f"tenant-{i}" for i in range(8)]
            results = await asyncio.gather(
                *(asyncio.create_task(client.search(tenant), name=tenant) for tenant in tenants),
            )

        self.assertEqual([r["result"]["query"] for r in results], tenants)
        self.assertEqual(len(server.requests), len(tenants))
        for request in server.requests:
            self.assertEqual(request.headers["authorization"], f"Bearer {request.body['input']['query']}")

    async def test_sync_and_async_providers_are_both_accepted(self) -> None:
        async def async_provider() -> dict[str, str]:
            await asyncio.sleep(0)
            return {"Authorization": "Bearer async"}

        with LoopbackServer(_echo) as server:
            await AsyncOfficeClient(server.operations_url, lambda: {"Authorization": "Bearer sync"}).search("a")
            await AsyncOfficeClient(server.operations_url, async_provider).search("b")

        self.assertEqual(
            [r.headers["authorization"] for r in server.requests],
            ["Bearer sync", "Bearer async"],
        )


if __name__ == "__main__":
    unittest.main()
