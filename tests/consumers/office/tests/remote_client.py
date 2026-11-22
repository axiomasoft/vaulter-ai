import json
import os
import unittest
import urllib.request

from vaulter_office import OfficeClient


def _http_post(url: str, payload: dict[str, object], headers: dict[str, str]) -> dict[str, object]:
    data = json.dumps(payload).encode('utf-8')
    req = urllib.request.Request(
        url,
        data=data,
        headers={'Content-Type': 'application/json', 'Accept': 'application/json', **headers},
        method='POST',
    )
    with urllib.request.urlopen(req, timeout=15) as resp:
        return json.loads(resp.read().decode('utf-8'))


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
        receipt_id = commit_res['result']['receipt_id']

        # Resume after simulated lost client response: status/verify recover the exact same receipt_id.
        resumed = writer_client.call('office.status', {
            'resource_id': resource_id,
            'command_id': command_id,
        })
        self.assertTrue(resumed['ok'], f'Status recovery failed: {resumed}')
        self.assertEqual(resumed['result']['state'], 'committed')
        self.assertEqual(resumed['result']['receipt_id'], receipt_id)

        verify = writer_client.call('office.verify', {
            'resource_id': resource_id,
            'receipt_id': receipt_id,
            'mode': 'at_commit',
        })
        self.assertTrue(verify['ok'], f'Verify failed: {verify}')
        self.assertEqual(verify['result']['outcome'], 'passed')

        # Foreign tenant fails closed on both status and verify.
        foreign_status = foreign_client.call('office.status', {
            'resource_id': resource_id,
            'command_id': command_id,
        })
        self.assertFalse(foreign_status['ok'])
        self.assertEqual(foreign_status['error']['code'], 'resource_unavailable')

        foreign_verify = foreign_client.call('office.verify', {
            'resource_id': resource_id,
            'receipt_id': receipt_id,
            'mode': 'at_commit',
        })
        self.assertFalse(foreign_verify['ok'])
        self.assertEqual(foreign_verify['error']['code'], 'resource_unavailable')

        # Reader without write permission cannot prepare or recover another subject's command receipt via status.
        reader_status = reader_client.call('office.status', {
            'resource_id': resource_id,
            'command_id': command_id,
        })
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


if __name__ == '__main__':
    unittest.main()
