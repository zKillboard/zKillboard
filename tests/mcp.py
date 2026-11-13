"""Focused read-only MCP checks against the existing localhost server.

Run: python3 tests/mcp.py
"""
import json
import unittest
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[1]


class McpTests(unittest.TestCase):
    def request(self, message=None, headers=None, method='POST', path='/mcp/'):
        data = None if message is None else (message.encode() if isinstance(message, str) else json.dumps(message).encode())
        request = Request('http://localhost' + path, data=data, method=method, headers={
            'Content-Type': 'application/json',
            'Accept': 'application/json, text/event-stream',
            'MCP-Protocol-Version': '2025-11-25',
            **(headers or {}),
        })
        try:
            response = urlopen(request, timeout=15)
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.headers, response.read().decode()

    def rpc(self, method, params=None):
        status, headers, body = self.request({'jsonrpc': '2.0', 'id': 1, 'method': method, 'params': params or {}})
        self.assertEqual(status, 200)
        self.assertEqual(headers['Cache-Control'], 'no-store')
        payload = json.loads(body)
        self.assertEqual(payload['id'], 1)
        return payload

    def test_initialization_and_notification(self):
        result = self.rpc('initialize', {'protocolVersion': '2025-11-25', 'capabilities': {}, 'clientInfo': {'name': 'test', 'version': '1'}})['result']
        self.assertEqual(result['protocolVersion'], '2025-11-25')
        self.assertEqual(result['instructions'], (ROOT / 'public/llms.txt').read_text())
        self.assertEqual(result['capabilities'], {'resources': {}, 'tools': {}})
        status, _, body = self.request({'jsonrpc': '2.0', 'method': 'notifications/initialized'})
        self.assertEqual((status, body), (202, ''))
        self.assertEqual(self.rpc('ping')['result'], {})

    def test_tools_and_resources_match_files(self):
        resources = self.rpc('resources/list')['result']['resources']
        tools = self.rpc('tools/list')['result']['tools']
        self.assertEqual(len(resources), 2)
        self.assertEqual(len(tools), 2)
        for resource, tool, filename in zip(resources, tools, ['llms.txt', 'api/api.json']):
            expected = (ROOT / 'public' / filename).read_text()
            self.assertEqual(tool['name'], 'get_' + resource['name'])
            self.assertTrue(tool['annotations']['readOnlyHint'])
            self.assertEqual(self.rpc('resources/read', {'uri': resource['uri']})['result']['contents'][0]['text'], expected)
            self.assertEqual(self.rpc('tools/call', {'name': tool['name'], 'arguments': {}})['result']['content'][0]['text'], expected)
        self.assertEqual(self.rpc('resources/templates/list')['result'], {'resourceTemplates': []})

    def test_errors(self):
        for method, params, code in [
            ('missing', {}, -32601),
            ('initialize', {}, -32602),
            ('resources/read', {'uri': 'file:///etc/passwd'}, -32002),
            ('tools/call', {'name': 'missing'}, -32602),
            ('tools/call', {'name': 'get_instructions', 'arguments': {'url': 'https://example.com'}}, -32602),
        ]:
            self.assertEqual(self.rpc(method, params)['error']['code'], code)
        for body, code in [('{', -32700), ('[]', -32600), ('null', -32600), ('{"jsonrpc":"2.0","id":true,"method":"ping"}', -32600)]:
            self.assertEqual(json.loads(self.request(body)[2])['error']['code'], code)
        self.assertEqual(self.request(' ' * 65537)[0], 413)

    def test_transport(self):
        message = {'jsonrpc': '2.0', 'id': 1, 'method': 'ping'}
        for headers, status in [
            ({'Origin': 'https://untrusted.example'}, 403),
            ({'MCP-Protocol-Version': 'invalid'}, 400),
            ({'Content-Type': 'text/plain'}, 415),
            ({'Accept': 'text/html'}, 406),
            ({'Origin': 'https://zkillboard.com'}, 200),
        ]:
            self.assertEqual(self.request(message, headers)[0], status)
        for method in ['GET', 'DELETE']:
            status, headers, _ = self.request(method=method)
            self.assertEqual(status, 405)
            self.assertEqual(headers['Allow'], 'POST')

    def test_discovery(self):
        for path in ['/llms.txt', '/api/api.json', '/robots.txt']:
            status, _, body = self.request(method='GET', path=path)
            self.assertEqual(status, 200)
            self.assertEqual(body, (ROOT / 'public' / path.lstrip('/')).read_text())
        status, _, body = self.request(method='GET', path='/api/docs/')
        self.assertEqual(status, 200)
        self.assertIn('https://zkillboard.com/mcp/', body)
        self.assertIn('href="/llms.txt"', body)
        self.assertIn('href="/api/api.json"', body)


if __name__ == '__main__':
    unittest.main()
