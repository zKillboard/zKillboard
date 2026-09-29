<?php

// composer require aws/aws-sdk-php

//declare(strict_types=1);

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use GuzzleHttp\Exception\RequestException;

class CloudFlare
{
    /**
     * create an R2 (S3-compatible) client
     */
    public static function getR2Client(
            string $accountId,
            string $accessKey,
            string $secretKey
            ): S3Client {
        return new S3Client([
                'region' => 'auto',
                'version' => 'latest',
                'endpoint' => "https://{$accountId}.r2.cloudflarestorage.com",
                'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
                ],
                'use_path_style_endpoint' => true,
        ]);
    }

    /**
     * Upload a local file to Cloudflare R2
     */
    public static function r2upload(
            S3Client $r2,
            string $bucket,
            string $localPath,
            string $remoteKey,
            array $options = []
            ): array {
        if (!is_readable($localPath)) {
            throw new RuntimeException("File not readable: {$localPath}");
        }

        $params = array_merge([
                'Bucket' => $bucket,
                'Key' => $remoteKey,
                'Body' => fopen($localPath, 'rb'),
        ], $options);

        return self::r2putObject($r2, $params);
    }

    /**
     * Upload an array as JSON to Cloudflare R2 (no temp file)
     */
    public static function r2sendArray(
            S3Client $r2,
            string $bucket,
            array $data,
            string $remoteKey,
            array $options = []
            ): array {
        try {
            $body = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                    'JSON encode failed: ' . $e->getMessage(),
                    (int) $e->getCode(),
                    $e
                    );
        }

        $params = array_merge([
                'Bucket'      => $bucket,
                'Key'         => $remoteKey,
                'Body'        => $body,
                'ContentType' => 'application/json',
        ], $options);

        return self::r2putObject($r2, $params);
    }

    private static function r2putObject(S3Client $r2, array $params): array
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return $r2->putObject($params)->toArray();
            } catch (AwsException | RequestException $e) {
                $status = $e instanceof AwsException
                    ? $e->getStatusCode()
                    : ($e->getResponse() ? $e->getResponse()->getStatusCode() : null);

                if ($attempt == 4 || ($status != 429 && ($status < 500 || $status >= 600))) {
                    $message = $e instanceof AwsException ? $e->getAwsErrorMessage() : $e->getMessage();
                    throw new RuntimeException('R2 upload failed: ' . $message, (int) $e->getCode(), $e);
                }

                sleep(2 ** $attempt);
                if (is_resource($params['Body'])) {
                    rewind($params['Body']);
                }
            }
        }
    }

    /**
     * Purge one or more explicit URLs from Cloudflare cache
     *
     * @param string $zoneId   Cloudflare Zone ID
     * @param string $apiToken Cloudflare API token
     * @param array  $urls     Array of absolute URLs to purge
     *
     * @throws RuntimeException on failure
     */
    public static function purgeUrls(
            string $zoneId,
            string $apiToken,
            array $urls
            ): void {
        $endpoint = "https://api.cloudflare.com/client/v4/zones/{$zoneId}/purge_cache";
        while (sizeof($urls) > 0) {
            $first25 = array_slice($urls, 0, 25);
            $payload = json_encode([
                    'files' => array_values($first25),
            ], JSON_THROW_ON_ERROR);

            $ch = curl_init($endpoint);

            curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $apiToken,
                    'Content-Type: application/json',
                    ],
                    CURLOPT_POSTFIELDS     => $payload,
            ]);

            $response = curl_exec($ch);

            if ($response === false) {
                $err = curl_error($ch);
                curl_close($ch);
                throw new RuntimeException("Cloudflare purge request failed: {$err}");
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $decoded = json_decode($response, true);

            if ($httpCode !== 200 || empty($decoded['success'])) {
                throw new RuntimeException('Cloudflare purge failed: ' . ($decoded['errors'][0]['message'] ?? 'Unknown error'));
            }
            $urls = array_slice($urls, 25);
            if (!empty($urls)) usleep(750000); // 1200 requests per 900 seconds
        }
    }

/**
 * Purge one or more cache tags from Cloudflare cache
 *
 * @param string $zoneId   Cloudflare Zone ID
 * @param string $apiToken Cloudflare API token
 * @param array  $tags     Array of cache tag strings to purge
 *
 * @throws RuntimeException on failure
 */
public static function purgeCacheTags(
        string $zoneId,
        string $apiToken,
        array $tags
        ): void {

    $endpoint = "https://api.cloudflare.com/client/v4/zones/{$zoneId}/purge_cache";

    // Cloudflare allows up to 25 tags per request
    while (count($tags) > 0) {
        $first25 = array_slice($tags, 0, 25);

        $payload = json_encode([
                'tags' => array_values($first25),
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init($endpoint);

        curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                        'Authorization: Bearer ' . $apiToken,
                        'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS     => $payload,
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("Cloudflare purge request failed: {$err}");
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);

        if ($httpCode !== 200 || empty($decoded['success'])) {
            throw new RuntimeException(
                    'Cloudflare cache-tag purge failed: ' .
                    ($decoded['errors'][0]['message'] ?? 'Unknown error')
            );
        }

        $tags = array_slice($tags, 25);

        // Respect Cloudflare rate limits (same cadence as URL purge)
        if (!empty($tags)) {
            usleep(250000); // ~1200 requests / 900 seconds
        }
    }
}

}
