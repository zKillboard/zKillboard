<?php

declare(strict_types=1);

require_once "../init.php";


use Aws\S3\S3Client;
use Aws\Exception\AwsException;

/**
 * Expect these globals to already be defined:
 *
 * $CF_ACCOUNT_ID
 * $CF_R2_ACCESS_KEY
 * $CF_R2_SECRET_KEY
 * $CF_R2_BUCKET
 *
 * ($CF_TOKEN is NOT used for R2)
 */

if (
    empty($CF_ACCOUNT_ID) ||
    empty($CF_R2_ACCESS_KEY) ||
    empty($CF_R2_SECRET_KEY) ||
    empty($CF_R2_BUCKET)
) {
    fwrite(STDERR, "Missing required Cloudflare R2 globals\n");
    exit(1);
}

/**
 * Create R2 (S3-compatible) client
 */
$s3 = new S3Client([
    'region'      => 'auto',
    'version'     => 'latest',
    'endpoint'    => "https://{$CF_ACCOUNT_ID}.r2.cloudflarestorage.com",
    'credentials' => [
        'key'    => $CF_R2_ACCESS_KEY,
        'secret' => $CF_R2_SECRET_KEY,
    ],
    'use_path_style_endpoint' => true,
]);

echo "Emptying R2 bucket: {$CF_R2_BUCKET}\n";

try {
    do {
        $result = $s3->listObjectsV2([
            'Bucket'  => $CF_R2_BUCKET,
            'MaxKeys' => 1000,
        ]);

        if (empty($result['Contents'])) {
            break;
        }

        $objects = [];
        foreach ($result['Contents'] as $obj) {
            $objects[] = ['Key' => $obj['Key']];
        }

        $s3->deleteObjects([
            'Bucket' => $CF_R2_BUCKET,
            'Delete' => [
                'Objects' => $objects,
                'Quiet'   => true,
            ],
        ]);

        echo "Deleted " . count($objects) . " objects\n";

    } while (!empty($result['IsTruncated']) && $result['IsTruncated'] === true);

    echo "Bucket is now empty.\n";

} catch (AwsException $e) {
    fwrite(STDERR, "Error: " . $e->getAwsErrorMessage() . "\n");
    exit(1);
}

