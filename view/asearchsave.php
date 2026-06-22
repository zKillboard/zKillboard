<?php

function handler($request, $response, $args, $container) {
    global $mdb;

    try {
        $queryParams = $request->getQueryParams();
        $url = (string) ($queryParams['url'] ?? '');
        $uri = $request->getUri();
        $requestOrigin = $uri->getScheme() . '://' . $uri->getHost();
        if ($uri->getPort() !== null && !in_array($uri->getPort(), [80, 443])) $requestOrigin .= ':' . $uri->getPort();

        $parsedUrl = parse_url($url);
        if ($parsedUrl === false) throw new Exception("invalid url");
        $urlOrigin = ($parsedUrl['scheme'] ?? '') . '://' . ($parsedUrl['host'] ?? '');
        if (isset($parsedUrl['port'])) $urlOrigin .= ':' . $parsedUrl['port'];
        $urlPath = $parsedUrl['path'] ?? '';

        if (!in_array($urlOrigin, [$requestOrigin, 'https://zkillboard.com'])) throw new Exception("invalid domain: $url");
        if ($urlPath !== '/asearch/' && strpos($urlPath, '/asearch/') !== 0) throw new Exception("invalid path: $url");

        $record = $mdb->findDoc("shortener", ['url' => $url]);
        if ($record == null) {
            $mdb->insert("shortener", ['url' => $url]);
            $record = $mdb->findDoc("shortener", ['url' => $url]);
        }
        $id = (string) $record['_id'];
        $output = "/asearchsaved/$id/";
    } catch (Exception $ex) {
        $output = $ex->getMessage();
        $response = $response->withStatus(400);
    }
    
    $response->getBody()->write($output);
    return $response->withHeader('Content-Type', 'text/plain; charset=utf-8')->withHeader('Cache-Tag', 'asearch,asearchsave');
}
