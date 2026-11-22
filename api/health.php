<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

try {
    pulseDatabase()->query('SELECT 1');
    pulseRespond(['ok' => true]);
} catch (Throwable $error) {
    error_log('Pulse health check: ' . $error->getMessage());
    pulseRespond(['ok' => false], 503);
}
