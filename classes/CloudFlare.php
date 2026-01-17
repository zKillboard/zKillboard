<?php

// composer require aws/aws-sdk-php

//declare(strict_types=1);

use Aws\Exception\AwsException;
use Aws\S3\S3Client;

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

        try {
            return $r2->putObject($params)->toArray();
        } catch (AwsException $e) {
            throw new RuntimeException(
                    'R2 upload failed: ' . $e->getAwsErrorMessage(),
                    (int) $e->getCode(),
                    $e
                    );
        }
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
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Failed to open temp stream');
        }

        try {
            fwrite($stream, json_encode($data, JSON_THROW_ON_ERROR));
            rewind($stream);
        } catch (JsonException $e) {
            fclose($stream);
            throw new RuntimeException(
                    'JSON encode failed: ' . $e->getMessage(),
                    (int) $e->getCode(),
                    $e
                    );
        }

        $params = array_merge([
                'Bucket'      => $bucket,
                'Key'         => $remoteKey,
                'Body'        => $stream,
                'ContentType' => 'application/json',
        ], $options);

        try {
            $result = $r2->putObject($params)->toArray();
            fclose($stream);
            return $result;
        } catch (AwsException $e) {
            fclose($stream);
            throw new RuntimeException(
                    'R2 upload failed: ' . $e->getAwsErrorMessage(),
                    (int) $e->getCode(),
                    $e
                    );
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
            $first30 = array_slice($urls, 0, 30);
            $payload = json_encode([
                    'files' => array_values($first30),
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
            $urls = array_slice($urls, 30);
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

    // Cloudflare allows up to 30 tags per request
    while (count($tags) > 0) {
        $first30 = array_slice($tags, 0, 30);

        $payload = json_encode([
                'tags' => array_values($first30),
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

        $tags = array_slice($tags, 30);

        // Respect Cloudflare rate limits (same cadence as URL purge)
        if (!empty($tags)) {
            usleep(750000); // ~1200 requests / 900 seconds
        }
    }
}

}
