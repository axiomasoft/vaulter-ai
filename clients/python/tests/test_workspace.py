"""Workspace endpoints (proposals, runs, chat): URLs, methods, bodies and endpoint derivation."""

from __future__ import annotations

import unittest

from support import LoopbackServer, Recorded, Reply

from vaulter_office import OfficeClient


def _ok(_request: Recorded) -> Reply:
    return Reply.json({"ok": True})


class WorkspaceRequestTest(unittest.TestCase):
    def test_requests_use_the_workspace_prefix_and_escape_ids(self) -> None:
        with LoopbackServer(_ok) as server:
            client = OfficeClient(f"{server.base_url}/tenant/api/vaulter-ai/v1/operations", dict)
            client.list_proposals()
            client.list_proposals(resource_id="doc 1", decision="pending")
            client.get_proposal("p/1")
            client.decide_proposal("p/1", "d" * 64, approved=True, command_id="cmd-1")
            client.decide_proposal("p-2", "e" * 64, approved=False)
            client.get_run("r/1")
            client.resume_run("r/1")
            client.resume_run("r-2", arguments_by_resource={"doc-1": {"x": 1}}, reread_only=True)
            client.open_chat_session()
            client.open_chat_session(retention_seconds=60)
            client.get_chat_session("s/1")
            client.post_chat_message("s/1", "m-1", "hi", ["doc-1"], selection={"from": 1})

        self.assertEqual(
            [(r.method, r.path, r.body) for r in server.requests],
            [
                ("GET", "/tenant/office/proposals", None),
                ("GET", "/tenant/office/proposals?resource_id=doc+1&decision=pending", None),
                ("GET", "/tenant/office/proposals/p%2F1", None),
                (
                    "POST",
                    "/tenant/office/proposals/p%2F1/decision",
                    {"digest": "d" * 64, "approved": True, "command_id": "cmd-1"},
                ),
                ("POST", "/tenant/office/proposals/p-2/decision", {"digest": "e" * 64, "approved": False}),
                ("GET", "/tenant/office/runs/r%2F1", None),
                ("POST", "/tenant/office/runs/r%2F1/resume", {"reread_only": False}),
                (
                    "POST",
                    "/tenant/office/runs/r-2/resume",
                    {"reread_only": True, "arguments_by_resource": {"doc-1": {"x": 1}}},
                ),
                ("POST", "/tenant/office/chat/sessions", {}),
                ("POST", "/tenant/office/chat/sessions", {"retention_seconds": 60}),
                ("GET", "/tenant/office/chat/sessions/s%2F1", None),
                (
                    "POST",
                    "/tenant/office/chat/sessions/s%2F1/messages",
                    {"message_id": "m-1", "content": "hi", "resource_ids": ["doc-1"], "selection": {"from": 1}},
                ),
            ],
        )
        self.assertTrue(all(r.headers["accept"] == "application/json" for r in server.requests))

    def test_unknown_operations_path_falls_back_to_the_root_workspace(self) -> None:
        with LoopbackServer(_ok) as server:
            OfficeClient(f"{server.base_url}/custom/ops", dict).get_run("r-1")
            OfficeClient(f"{server.base_url}/custom/ops", dict, workspace_endpoint=f"{server.base_url}/ws/").get_run(
                "r-2"
            )

        self.assertEqual([r.path for r in server.requests], ["/office/runs/r-1", "/ws/runs/r-2"])


class CommitRetryTest(unittest.TestCase):
    def test_temporarily_unavailable_checks_status_and_resends_only_unknown_commands(self) -> None:
        replies = iter(
            [
                Reply.json({"ok": False, "error": {"code": "temporarily_unavailable"}}),
                Reply.json({"ok": False, "error": {"code": "operation_unknown"}}),
                Reply.json({"ok": False, "error": {"code": "temporarily_unavailable"}}),
                Reply.json({"ok": True, "result": {"state": "committed"}}),
            ],
        )
        with LoopbackServer(lambda _r: next(replies)) as server:
            client = OfficeClient(server.operations_url, dict, max_retries=2, retry_backoff=0.0)
            result = client.commit("doc-1", "p-1", "d" * 64, "cmd-7")

        self.assertEqual(result, {"ok": True, "result": {"state": "committed"}})
        self.assertEqual(
            [(r.body["operation"], r.body["input"]["command_id"]) for r in server.requests],
            [
                ("office.commit", "cmd-7"),
                ("office.status", "cmd-7"),
                ("office.commit", "cmd-7"),
                ("office.status", "cmd-7"),
            ],
        )

    def test_last_attempt_returns_the_server_answer_without_another_status_call(self) -> None:
        unavailable = {"ok": False, "error": {"code": "temporarily_unavailable"}}
        with LoopbackServer(lambda _r: Reply.json(unavailable)) as server:
            client = OfficeClient(server.operations_url, dict, max_retries=0)
            self.assertEqual(client.commit("doc-1", "p-1", "d" * 64, "cmd-8"), unavailable)

        self.assertEqual([r.body["operation"] for r in server.requests], ["office.commit"])


if __name__ == "__main__":
    unittest.main()
