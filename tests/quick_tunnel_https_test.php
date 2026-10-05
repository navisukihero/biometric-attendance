<?php

declare(strict_types=1);

require __DIR__ . '/../includes/employee_auth.php';

function tunnel_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

tunnel_assert(ucchr_request_is_https(['HTTPS' => 'on']), 'Direct HTTPS should be secure.');
tunnel_assert(!ucchr_request_is_https(['HTTPS' => 'off']), 'Local HTTP should stay HTTP.');
$protectedRequest = [
    'REMOTE_ADDR' => '127.0.0.1',
    'HTTP_HOST' => 'demo.trycloudflare.com',
    'HTTP_X_FORWARDED_PROTO' => 'https',
];
tunnel_assert(ucchr_request_is_https($protectedRequest), 'Quick Tunnel HTTPS was not recognized.');
tunnel_assert(!ucchr_request_is_https(array_replace($protectedRequest, [
    'REMOTE_ADDR' => '192.168.1.9',
])), 'A LAN client must not be able to spoof proxy HTTPS.');
tunnel_assert(!ucchr_request_is_https(array_replace($protectedRequest, [
    'HTTP_HOST' => 'attacker.example',
])), 'An arbitrary host must not be able to spoof proxy HTTPS.');
tunnel_assert(!ucchr_request_is_https(array_replace($protectedRequest, [
    'HTTP_X_FORWARDED_PROTO' => 'http',
])), 'Forwarded HTTP must not be treated as HTTPS.');

$originalServer = $_SERVER;
$_SERVER = array_replace($_SERVER, $protectedRequest);
unset($_SERVER['HTTPS']);
tunnel_assert(str_starts_with(employee_portal_base_url(), 'https://demo.trycloudflare.com'),
    'Employee links should use the public HTTPS URL.');
$_SERVER = $originalServer;

echo "Quick Tunnel HTTPS tests passed.\n";
