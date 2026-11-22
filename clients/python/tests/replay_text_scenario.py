"""Replay a shared Office fixture with the production stdlib client."""

from __future__ import annotations

import hashlib
import json
import sys
from http.client import RemoteDisconnected
from pathlib import Path
from typing import Any

from vaulter_office import OfficeClient


def substitute(value: Any, ids: dict[str, str]) -> Any:
    if isinstance(value, dict):
        return {key: substitute(item, ids) for key, item in value.items()}
    if isinstance(value, list):
        return [substitute(item, ids) for item in value]
    return ids.get(value, value) if isinstance(value, str) else value


def replay(endpoint: str, fixture: str, scenario_id: str, resource_id: str) -> None:
    scenarios = json.loads(Path(fixture).read_text(encoding="utf-8"))["scenarios"]
    scenario = next(s for s in scenarios if s["id"] == scenario_id)
    drops = {event["before_step"] for event in scenario["events"] if event["type"] == "drop_next_response"}
    client = OfficeClient(endpoint, lambda: {"Authorization": "Bearer scenario-test-token"})
    ids = {"contract-17": resource_id}
    for index, step in enumerate(scenario["steps"], 1):
        request = substitute(step["request"], ids)
        try:
            actual = client.call(request["operation"], request["input"], version=request["version"])
        except (ConnectionError, RemoteDisconnected):
            if index not in drops:
                raise
            continue
        assert index not in drops, "The fixture must drop the first commit response"
        expected = step["response"]
        if request["operation"] == "office.prepare":
            result = dict(actual["result"])
            digest = result.pop("digest")
            record = {"version": 1, "prepare_input": request["input"], "plan": result}
            encoded = json.dumps(record, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
            assert digest == hashlib.sha256(encoded).hexdigest()
            arguments = request["input"]["arguments"]
            assert arguments["expected"] in result["summary"] and arguments["replacement"] in result["summary"]
            expected["result"]["summary"] = result["summary"]
        for name in ("read_token", "proposal_id", "digest", "receipt_id"):
            placeholder = expected.get("result", {}).get(name)
            if placeholder is not None and placeholder not in ids:
                ids[placeholder] = actual["result"][name]
        if expected.get("result", {}).get("data", {}).get("kind") == "text_outline":
            actual_blocks = {block["block_id"]: block for block in actual["result"]["data"]["blocks"]}
            for target in expected["result"]["data"]["blocks"]:
                got = actual_blocks[target["block_id"]]
                assert got["path"][0] == target["path"][0] and got["path"][1].startswith(target["path"][1])
                assert got["snippet"]
            expected["result"]["data"]["blocks"] = actual["result"]["data"]["blocks"]
        assert actual == substitute(expected, ids), f"{scenario_id} step {index}: {actual}"


if __name__ == "__main__":
    replay(*sys.argv[1:])
