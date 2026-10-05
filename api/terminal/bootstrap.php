<?php

declare(strict_types=1);

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

function terminal_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function terminal_command_is_authorized(string $commandId): bool
{
    if (strlen($commandId) !== 64 || !ctype_xdigit($commandId)) {
        return false;
    }

    $known = $_SESSION['terminal_commands'] ?? [];
    $createdAt = $known[$commandId] ?? null;
    return is_numeric($createdAt) && (int) $createdAt >= time() - 3600;
}
