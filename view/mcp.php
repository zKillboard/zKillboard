<?php

function handler($request, $response, $args, $container) {
    $response = $response->withHeader('Cache-Control', 'no-store');
    $origin = $request->getHeaderLine('Origin');
    if ($origin !== '' && !in_array($origin, ['https://zkillboard.com', 'http://localhost'], true)) {
        return $response->withStatus(403);
    }
    if ($request->getMethod() !== 'POST') {
        return $response->withStatus(405)->withHeader('Allow', 'POST');
    }
    $protocol = $request->getHeaderLine('MCP-Protocol-Version');
    if ($protocol !== '' && $protocol !== '2025-11-25') return $response->withStatus(400);
    if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
        return $response->withStatus(415);
    }
    $accept = $request->getHeaderLine('Accept');
    if (strpos($accept, 'application/json') === false || strpos($accept, 'text/event-stream') === false) {
        return $response->withStatus(406);
    }

    $body = '';
    while (!$request->getBody()->eof() && strlen($body) < 65537) {
        $chunk = $request->getBody()->read(65537 - strlen($body));
        if ($chunk === '') break;
        $body .= $chunk;
    }
    if (strlen($body) > 65536) return $response->withStatus(413);
    $message = json_decode($body, true);
    $error = null;
    $result = null;
    $id = null;
    if (json_last_error() !== JSON_ERROR_NONE) {
        $error = ['code' => -32700, 'message' => 'Parse error'];
    } elseif (!is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0'
        || !is_string($message['method'] ?? null)
        || (array_key_exists('id', $message) && !is_int($message['id']) && !is_string($message['id']))) {
        $error = ['code' => -32600, 'message' => 'Invalid Request'];
    } elseif (!array_key_exists('id', $message)) {
        return $response->withStatus(202);
    } else {
        $id = $message['id'];
        $params = $message['params'] ?? [];
        if (!is_array($params)) {
            $error = ['code' => -32602, 'message' => 'Invalid params'];
        } else {
            $resources = [
                ['uri' => 'https://zkillboard.com/llms.txt', 'name' => 'instructions', 'description' => 'How to use zKillboard without scraping the site.', 'mimeType' => 'text/plain'],
                ['uri' => 'https://zkillboard.com/api/api.json', 'name' => 'api_documentation', 'description' => 'Documented API endpoints, parameters, examples, and cache guidance.', 'mimeType' => 'application/json'],
            ];
            switch ($message['method']) {
                case 'initialize':
                    if (!is_string($params['protocolVersion'] ?? null) || !is_array($params['capabilities'] ?? null)
                        || !is_string($params['clientInfo']['name'] ?? null) || !is_string($params['clientInfo']['version'] ?? null)) {
                        $error = ['code' => -32602, 'message' => 'Invalid initialization params'];
                        break;
                    }
                    $result = [
                        'protocolVersion' => '2025-11-25',
                        'capabilities' => ['resources' => (object) [], 'tools' => (object) []],
                        'serverInfo' => ['name' => 'zkillboard', 'version' => '1', 'websiteUrl' => 'https://zkillboard.com'],
                        'instructions' => file_get_contents(__DIR__ . '/../public/llms.txt'),
                    ];
                    break;
                case 'ping':
                    $result = (object) [];
                    break;
                case 'resources/list':
                    $result = ['resources' => $resources];
                    break;
                case 'resources/templates/list':
                    $result = ['resourceTemplates' => []];
                    break;
                case 'tools/list':
                    $result = ['tools' => []];
                    foreach ($resources as $resource) {
                        $result['tools'][] = [
                            'name' => 'get_' . $resource['name'],
                            'description' => $resource['description'] . ' Read this before requesting zKillboard data. Use APIs, never scrape HTML.',
                            'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
                            'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
                        ];
                    }
                    break;
                case 'resources/read':
                case 'tools/call':
                    $isTool = $message['method'] === 'tools/call';
                    if ($isTool && isset($params['arguments']) && $params['arguments'] !== []) {
                        $error = ['code' => -32602, 'message' => 'This tool takes no arguments'];
                        break;
                    }
                    foreach ($resources as $resource) {
                        if (($isTool ? ($params['name'] ?? null) : ($params['uri'] ?? null))
                            !== ($isTool ? 'get_' . $resource['name'] : $resource['uri'])) continue;
                        $text = file_get_contents(__DIR__ . '/../public/' . ($resource['name'] === 'instructions' ? 'llms.txt' : 'api/api.json'));
                        $result = $isTool
                            ? ['content' => [['type' => 'text', 'text' => $text]]]
                            : ['contents' => [['uri' => $resource['uri'], 'mimeType' => $resource['mimeType'], 'text' => $text]]];
                        break;
                    }
                    if ($result === null) $error = ['code' => $isTool ? -32602 : -32002, 'message' => $isTool ? 'Unknown tool' : 'Resource not found'];
                    break;
                default:
                    $error = ['code' => -32601, 'message' => 'Method not found'];
            }
        }
    }

    $payload = ['jsonrpc' => '2.0', 'id' => $id];
    $payload[$error === null ? 'result' : 'error'] = $error ?? $result;
    $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    return $response->withHeader('Content-Type', 'application/json');
}
