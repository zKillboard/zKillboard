<?php

class XTwitterPoster
{
    private const KV_ACCESS_TOKEN = 'xtwitter-access-token';
    private const KV_REFRESH_TOKEN = 'xtwitter-refresh-token';

    public static function checkCredentials()
    {
        $creds = self::resolveCredentials();
        $missing = [];

        if ($creds['clientId'] === '') $missing[] = 'xtwitterClientId';
        if ($creds['clientSecret'] === '') $missing[] = 'xtwitterClientSecret';
        if ($creds['accessToken'] === '') $missing[] = 'xtwitterAccessToken';
        if ($creds['refreshToken'] === '') $missing[] = 'xtwitterRefreshToken';

        return [
            'ok' => count($missing) === 0,
            'missing' => $missing,
        ];
    }

    public static function post($message)
    {
        $status = self::checkCredentials();
        if (!$status['ok']) {
            return [
                'ok' => false,
                'skipped' => true,
                'error' => 'Missing OAuth 2.0 credentials',
                'missing' => $status['missing'],
            ];
        }

        $creds = self::resolveCredentials();

        $postResult = self::postWithAccessToken($message, $creds['accessToken']);
        if ($postResult['ok']) return $postResult;

        // Refresh on auth failures and retry once.
        if (in_array((int) ($postResult['status'] ?? 0), [401, 403])) {
            $refreshResult = self::refreshAccessToken($creds);
            if ($refreshResult['ok']) {
                $newToken = trim((string) ($refreshResult['accessToken'] ?? ''));
                if ($newToken !== '') {
                    self::saveTokens($newToken, (string) ($refreshResult['refreshToken'] ?? ''));
                    $retry = self::postWithAccessToken($message, $newToken);
                    if ($retry['ok']) {
                        $retry['tokenRefreshed'] = true;
                        return $retry;
                    }
                    $retry['tokenRefreshed'] = true;
                    $retry['error'] = 'Token refreshed, but retry failed: ' . ($retry['error'] ?? 'Unknown error');
                    return $retry;
                }
            }

            return [
                'ok' => false,
                'status' => (int) ($postResult['status'] ?? 0),
                'body' => (string) ($postResult['body'] ?? ''),
                'error' => 'Auth failed and token refresh did not return a usable access token',
            ];
        }

        return $postResult;
    }

    private static function postWithAccessToken($message, $accessToken)
    {
        $url = 'https://api.twitter.com/2/tweets';

        try {
            $client = new \GuzzleHttp\Client(['timeout' => 15, 'connect_timeout' => 10]);
            $response = $client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => ['text' => $message],
            ]);

            return [
                'ok' => true,
                'status' => $response->getStatusCode(),
                'body' => (string) $response->getBody(),
            ];
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $resp = $e->getResponse();
            return [
                'ok' => false,
                'status' => $resp ? $resp->getStatusCode() : 0,
                'body' => $resp ? (string) $resp->getBody() : '',
                'error' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    private static function refreshAccessToken($creds)
    {
        $url = 'https://api.twitter.com/2/oauth2/token';

        try {
            $client = new \GuzzleHttp\Client(['timeout' => 15, 'connect_timeout' => 10]);
            $response = $client->post($url, [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($creds['clientId'] . ':' . $creds['clientSecret']),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $creds['refreshToken'],
                    'client_id' => $creds['clientId'],
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            return [
                'ok' => true,
                'accessToken' => (string) ($data['access_token'] ?? ''),
                'refreshToken' => (string) ($data['refresh_token'] ?? ''),
                'raw' => $data,
            ];
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $resp = $e->getResponse();
            return [
                'ok' => false,
                'status' => $resp ? $resp->getStatusCode() : 0,
                'body' => $resp ? (string) $resp->getBody() : '',
                'error' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    private static function resolveCredentials()
    {
        global $xtwitterClientId, $xtwitterClientSecret, $xtwitterAccessToken, $xtwitterRefreshToken;
        global $mdb;

        $dbAccessToken = '';
        $dbRefreshToken = '';
        if (isset($mdb)) {
            $dbAccessToken = trim((string) ($mdb->findField('keyvalues', 'value', ['key' => self::KV_ACCESS_TOKEN]) ?? ''));
            $dbRefreshToken = trim((string) ($mdb->findField('keyvalues', 'value', ['key' => self::KV_REFRESH_TOKEN]) ?? ''));
        }

        return [
            'clientId' => trim((string) ($xtwitterClientId ?? '')),
            'clientSecret' => trim((string) ($xtwitterClientSecret ?? '')),
            // Prefer DB tokens so refreshes survive deploys/restarts.
            'accessToken' => $dbAccessToken !== '' ? $dbAccessToken : trim((string) ($xtwitterAccessToken ?? '')),
            'refreshToken' => $dbRefreshToken !== '' ? $dbRefreshToken : trim((string) ($xtwitterRefreshToken ?? '')),
        ];
    }

    private static function saveTokens($accessToken, $refreshToken = '')
    {
        global $mdb;

        if (!isset($mdb)) return;

        $accessToken = trim((string) $accessToken);
        $refreshToken = trim((string) $refreshToken);

        if ($accessToken !== '') {
            $mdb->insertUpdate('keyvalues', ['key' => self::KV_ACCESS_TOKEN], ['value' => $accessToken]);
        }
        if ($refreshToken !== '') {
            $mdb->insertUpdate('keyvalues', ['key' => self::KV_REFRESH_TOKEN], ['value' => $refreshToken]);
        }
    }
}
