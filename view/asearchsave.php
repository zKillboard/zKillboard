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

        if (User::isLoggedIn()) {
            $title = trim(str_replace(['<', '>'], '', (string) ($queryParams['title'] ?? '')));
            $title = preg_replace('/^\s*Advanced Search:\s*/', '', $title);
            if ($title == '') $title = AdvancedSearch::summarizeSavedUrl($url) ?: 'Advanced Search';
            if (strlen($title) > 180) $title = substr($title, 0, 177) . '...';
            $now = $mdb->now();
            $mdb->getCollection('searches')->updateOne(
                ['characterID' => (int) User::getUserID(), 'shortenerID' => $record['_id']],
                [
                    '$set' => ['url' => $url, 'path' => $output, 'title' => $title, 'updatedTime' => $now],
                    '$setOnInsert' => ['createdTime' => $now],
                ],
                ['upsert' => true]
            );
        }
    } catch (Exception $ex) {
        $output = $ex->getMessage();
        $response = $response->withStatus(400);
    }
    
    $response->getBody()->write($output);
    return $response->withHeader('Content-Type', 'text/plain; charset=utf-8')->withHeader('Cache-Control', 'no-store')->withHeader('Cache-Tag', 'www,asearch,asearchsave');
}
