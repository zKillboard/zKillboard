<?php

require_once __DIR__ . '/../init.php';

/**
 * Small XTwitter posting test helper.
 *
 * Usage:
 *   php scratch/xtwitter_post_test.php "Test message"
 *   php scratch/xtwitter_post_test.php "Test message" --send
 */

$argv = $_SERVER['argv'] ?? [];
array_shift($argv); // script name

$send = false;
$messageParts = [];
foreach ($argv as $arg) {
    if ($arg === '--send') {
        $send = true;
        continue;
    }
    $messageParts[] = $arg;
}

$message = trim(implode(' ', $messageParts));
if ($message === '') {
    $message = 'zKillboard XTwitter test ' . date('c');
}

if (strlen($message) > 280) {
    fwrite(STDERR, "Message is too long (" . strlen($message) . " chars, max 280).\n");
    exit(2);
}

$status = XTwitterPoster::checkCredentials();
if (!$status['ok']) {
    fwrite(STDERR, "Missing OAuth 2.0 credentials: " . implode(', ', $status['missing']) . "\n");
    fwrite(STDERR, "Set xtwitter* variables in config.php.\n");
    exit(3);
}

if (!$send) {
    echo "Dry run mode. No post sent.\n";
    echo "Message: {$message}\n";
    echo "Use --send to publish.\n";
    exit(0);
}

$result = XTwitterPoster::post($message);
if ($result['ok']) {
    echo "Posted. HTTP " . $result['status'] . "\n";
    echo $result['body'] . "\n";
    if (!empty($result['tokenRefreshed'])) {
        echo "Access token was refreshed during this request.\n";
        echo "Update config.php with these new values:\n";
        echo "xtwitterAccessToken=" . ($result['newAccessToken'] ?? '') . "\n";
        if (!empty($result['newRefreshToken'])) {
            echo "xtwitterRefreshToken=" . $result['newRefreshToken'] . "\n";
        }
    }
    exit(0);
}

fwrite(STDERR, "XTwitter request failed: " . $result['error'] . "\n");
if (!empty($result['body'])) {
    fwrite(STDERR, "Response body: " . $result['body'] . "\n");
}
exit(1);
