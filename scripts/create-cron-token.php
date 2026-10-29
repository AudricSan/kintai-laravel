<?php

/**
 * CLI helper to issue a token for the generic cron runner (`/cron/run/{job}`).
 *
 * Usage:
 *   php scripts/create-cron-token.php \
 *       --user-id=1 \
 *       [--job=backup|auto-validate]   (omit to allow any registered job) \
 *       [--name=Label] \
 *       [--expires-in=SECONDS]         (omit for a token that never expires)
 *
 * Prints the raw token once — store it, it cannot be recovered afterwards
 * (only its SHA-256 hash is persisted, same as API tokens).
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/bootstrap/standalone.php';
require BASE_PATH . '/app/Support/helpers.php';

use App\Repositories\CronTokenRepositoryInterface;
use App\Repositories\UserRepositoryInterface;

$opts = getopt('', ['user-id:', 'job::', 'name::', 'expires-in::']);

$userId = isset($opts['user-id']) ? (int) $opts['user-id'] : 0;
if ($userId <= 0) {
    fwrite(STDERR, "Error: --user-id is required.\n");
    exit(1);
}

$jobName  = $opts['job'] ?? '';
$name     = $opts['name'] ?? null;
$expiresIn = isset($opts['expires-in']) ? (int) $opts['expires-in'] : null;

$app = require BASE_PATH . '/bootstrap/boot.php';

$users = $app->make(UserRepositoryInterface::class);
if ($users->findById($userId) === null) {
    fwrite(STDERR, "Error: no user with id {$userId}.\n");
    exit(1);
}

$rawToken = bin2hex(random_bytes(32));

$cronTokens = $app->make(CronTokenRepositoryInterface::class);
$cronTokens->save([
    'user_id'    => $userId,
    'job_name'   => $jobName,
    'token'      => hash_token($rawToken),
    'name'       => $name,
    'expires_at' => $expiresIn !== null ? date('Y-m-d H:i:s', time() + $expiresIn) : null,
]);

echo "Cron token created" . ($jobName !== '' ? " for job '{$jobName}'" : ' (any job)') . ".\n";
echo "Token (store it now, it will not be shown again):\n";
echo $rawToken . "\n";
echo "\nExample usage:\n";
echo "  curl \"https://your-site.example/cron/run/" . ($jobName !== '' ? $jobName : '{job}') . "?token={$rawToken}\"\n";
