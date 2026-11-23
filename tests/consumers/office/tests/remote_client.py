import json
import os
import time
import unittest
import urllib.error
import urllib.request

from vaulter_office import OfficeClient


def _http_json(
    method: str,
    url: str,
    payload: dict[str, object] | None,
    headers: dict[str, str],
    timeout: float = 30,
) -> tuple[int, dict[str, object]]:
    data = None if payload is None else json.dumps(payload).encode('utf-8')
    req = urllib.request.Request(
        url,
        data=data,
        headers={'Accept': 'application/json', **headers},
        method=method,
    )
    if data is not None:
        req.add_header('Content-Type', 'application/json')
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            body = json.loads(resp.read().decode('utf-8'))
            return resp.status, body if isinstance(body, dict) else {'data': body}
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode('utf-8')
        try:
            decoded = json.loads(raw)
        except json.JSONDecodeError:
            decoded = {'raw': raw}
        return exc.code, decoded if isinstance(decoded, dict) else {'data': decoded}


def _http_post(url: str, payload: dict[str, object], headers: dict[str, str]) -> dict[str, object]:
    status, body = _http_json('POST', url, payload, headers, timeout=15)
    if status >= 400:
        raise AssertionError(f'HTTP {status} from {url}: {body}')
    return body


class RemoteOfficeClientTest(unittest.TestCase):
    def test_remote_http_isolation_lost_commit_resume_and_acl(self) -> None:
        # Process isolation: Python client MUST NOT hold PostgreSQL, app secret, or LLM credentials.
        for forbidden_env in ('PGHOST', 'PGPASSWORD', 'DB_PASSWORD', 'APP_KEY', 'OPENAI_API_KEY'):
            self.assertNotIn(forbidden_env, os.environ, f'Unexpected secret/DB env leaked: {forbidden_env}')

        endpoint = os.environ['OFFICE_BASE_URL']
        fixture_url = os.environ['STAND_FIXTURE_URL']
        fixture_token = os.environ['FIXTURE_TOKEN']

        seed = _http_post(f'{fixture_url}/seed', {}, {'X-Fixture-Token': fixture_token})
        resource_id = str(seed['resource_id'])
        writer_token = str(seed['writer_token'])
        reader_token = str(seed['reader_token'])
        foreign_token = str(seed['foreign_token'])

        writer_client = OfficeClient(endpoint, lambda: {'Authorization': f'Bearer {writer_token}'}, timeout=15.0)
        reader_client = OfficeClient(endpoint, lambda: {'Authorization': f'Bearer {reader_token}'}, timeout=15.0)
        foreign_client = OfficeClient(endpoint, lambda: {'Authorization': f'Bearer {foreign_token}'}, timeout=15.0)

        read_res = writer_client.read(resource_id, {'kind': 'text_block', 'block_id': 'p42'})
        self.assertTrue(read_res['ok'], f'Read failed: {read_res}')
        read_token = read_res['result']['read_token']

        prep_res = writer_client.call('office.prepare', {
            'resource_id': resource_id,
            'command': 'text.replace_exact@1',
            'read_token': read_token,
            'arguments': {'block_id': 'p42', 'expected': '15', 'replacement': '30'},
        })
        self.assertTrue(prep_res['ok'], f'Prepare failed: {prep_res}')
        proposal_id = prep_res['result']['proposal_id']
        digest = prep_res['result']['digest']

        command_id = f'py-remote-{proposal_id[:8]}'
        commit_res = writer_client.call('office.commit', {
            'resource_id': resource_id,
            'proposal_id': proposal_id,
            'digest': digest,
            'command_id': command_id,
        })
        self.assertTrue(commit_res['ok'], f'Commit failed: {commit_res}')
        # The commit finished on the server. The next session does not keep that body;
        # it only has the command_id it already sent.
        oracle_receipt = str(commit_res['result']['receipt_id'])

        next_session = OfficeClient(endpoint, lambda: {'Authorization': f'Bearer {writer_token}'}, timeout=15.0)
        resumed = next_session.resume(resource_id, command_id)
        self.assertTrue(resumed['ok'], f'Status recovery failed: {resumed}')
        self.assertEqual(resumed['result']['state'], 'committed')
        self.assertEqual(resumed['result']['receipt_id'], oracle_receipt)
        receipt_id = oracle_receipt

        verify = writer_client.call('office.verify', {
            'resource_id': resource_id,
            'receipt_id': receipt_id,
            'mode': 'at_commit',
        })
        self.assertTrue(verify['ok'], f'Verify failed: {verify}')
        self.assertEqual(verify['result']['outcome'], 'passed')

        # Foreign tenant fails closed on both resume (status) and verify.
        foreign_status = foreign_client.resume(resource_id, command_id)
        self.assertFalse(foreign_status['ok'])
        self.assertEqual(foreign_status['error']['code'], 'resource_unavailable')

        foreign_verify = foreign_client.call('office.verify', {
            'resource_id': resource_id,
            'receipt_id': receipt_id,
            'mode': 'at_commit',
        })
        self.assertFalse(foreign_verify['ok'])
        self.assertEqual(foreign_verify['error']['code'], 'resource_unavailable')

        # Reader without write permission cannot prepare or recover another subject's command receipt via resume.
        reader_status = reader_client.resume(resource_id, command_id)
        self.assertFalse(reader_status['ok'])
        self.assertIn(reader_status['error']['code'], ('resource_unavailable', 'operation_unknown'))

        reader_prepare = reader_client.call('office.prepare', {
            'resource_id': resource_id,
            'command': 'text.replace_exact@1',
            'read_token': read_token,
            'arguments': {'block_id': 'p42', 'expected': '30', 'replacement': '45'},
        })
        self.assertFalse(reader_prepare['ok'])
        self.assertEqual(reader_prepare['error']['code'], 'resource_unavailable')

        # Same channel contract over HTTP: partial publishes nothing, final + duplicate is one run,
        # reconnect reads that run and a foreign subject cannot.
        second_resource_id = str(seed['second_resource_id'])
        origin = fixture_url.rstrip('/')
        if origin.endswith('/_fixture'):
            origin = origin[: -len('/_fixture')]
        channel_url = origin + '/office/channel/events'

        def post_channel(token: str, event_id: str, phase: str) -> tuple[int, dict[str, object]]:
            return _http_json('POST', channel_url, {
                'session_id': 'session-1',
                'event_id': event_id,
                'phase': phase,
                'resource_id': second_resource_id,
            }, {'Authorization': f'Bearer {token}'}, timeout=60)

        partial_status, partial = post_channel(writer_token, 'event-2', 'partial')
        self.assertEqual(partial_status, 200, partial)
        self.assertIs(partial['published'], False)
        self.assertIsNone(partial['run_id'])
        self.assertEqual(partial['run_count'], 0)

        final_status, final = post_channel(writer_token, 'event-3', 'final')
        self.assertEqual(final_status, 200, final)
        self.assertIs(final['published'], True)
        self.assertIs(final['duplicate'], False)
        run_id = str(final['run_id'])
        self.assertEqual(final['run_count'], 1)
        self.assertEqual(final['source_event_id'], 'event-3')

        duplicate_status, duplicate = post_channel(writer_token, 'event-3', 'final')
        self.assertEqual(duplicate_status, 200, duplicate)
        self.assertIs(duplicate['duplicate'], True)
        self.assertEqual(duplicate['run_id'], run_id)
        self.assertEqual(duplicate['run_count'], 1)

        hijack_status, hijack = post_channel(reader_token, 'event-4', 'partial')
        self.assertEqual(hijack_status, 404, hijack)
        self.assertEqual(hijack['error']['code'], 'resource_unavailable')

        foreign_run_status, foreign_run = _http_json(
            'GET',
            f'{origin}/office/runs/{run_id}',
            None,
            {'Authorization': f'Bearer {foreign_token}'},
        )
        self.assertEqual(foreign_run_status, 404, foreign_run)
        self.assertNotIn(run_id, json.dumps(foreign_run))

        deadline = time.monotonic() + 60
        observed: dict[str, object] = {}
        while time.monotonic() < deadline:
            probe_status, observed = _http_json(
                'GET',
                f'{fixture_url.rstrip("/")}/runs/{run_id}',
                None,
                {'X-Fixture-Token': fixture_token},
            )
            self.assertEqual(probe_status, 200, observed)
            if str(observed.get('state')) in ('completed', 'rejected', 'partially_applied'):
                break
            time.sleep(0.5)
        self.assertEqual(observed.get('state'), 'completed', observed)

        reconnect_status, reconnect = _http_json(
            'GET',
            f'{origin}/office/runs/{run_id}',
            None,
            {'Authorization': f'Bearer {writer_token}'},
        )
        self.assertEqual(reconnect_status, 200, reconnect)
        data = reconnect['data']
        self.assertIsInstance(data, dict)
        assert isinstance(data, dict)
        self.assertEqual(data['run_id'], run_id)
        confirmed = data['confirmed_steps']
        self.assertIsInstance(confirmed, list)
        self.assertEqual(len(confirmed), 1)


if __name__ == '__main__':
    unittest.main()
