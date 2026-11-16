<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Hosting/HostingTransport.php';

use HostPilot\Hosting\HostingTransport;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAIL '.$message);
    echo 'PASS '.$message.PHP_EOL;
};
$rejects = static function (callable $callback): bool {
    try { $callback(); return false; } catch (RuntimeException) { return true; }
};

$publicCheck = new ReflectionMethod(HostingTransport::class, 'isPublicAddress');
$resolve = new ReflectionMethod(HostingTransport::class, 'resolvePublicAddress');
$assert($publicCheck->invoke(null, '8.8.8.8') === true, 'public IPv4 address is accepted');
$assert($publicCheck->invoke(null, '1.1.1.1') === true, 'second public IPv4 address is accepted');
$assert($publicCheck->invoke(null, '2001:4860:4860::8888') === true, 'public global-unicast IPv6 address is accepted');
foreach ([
    '127.0.0.1','10.0.0.1','172.16.0.1','192.168.1.1','169.254.1.1',
    '100.64.0.1','192.0.2.1','192.88.99.1','198.18.0.1','198.51.100.1','203.0.113.1',
    '224.0.0.1','240.0.0.1','0.0.0.0',
    '::1','::','fe80::1','fc00::1','ff02::1','2001:db8::1','2002::1'
] as $ip) {
    $assert($publicCheck->invoke(null, $ip) === false, 'non-public IP is rejected: '.$ip);
}
$assert($rejects(static fn()=>HostingTransport::validateBaseUrl('http://panel.example.com')), 'plain HTTP panel URL is rejected');
$assert($rejects(static fn()=>HostingTransport::validateBaseUrl('https://user:pass@panel.example.com')), 'URL credentials are rejected');
$assert($rejects(static fn()=>HostingTransport::validateBaseUrl('https://127.0.0.1')), 'IPv4 loopback URL is rejected');
$assert($rejects(static fn()=>HostingTransport::validateBaseUrl('https://[::1]')), 'bracketed IPv6 loopback URL is rejected');
$assert($rejects(static fn()=>HostingTransport::validateBaseUrl('https://router.local')), 'local hostname suffix is rejected');
$assert(HostingTransport::validateBaseUrl('https://panel.example.com:8443/api/') === 'https://panel.example.com:8443/api',
    'valid HTTPS panel URL is normalized');
$assert($rejects(static fn()=>$resolve->invoke(null, 'localhost')), 'localhost DNS resolution is rejected');
$source = file_get_contents(__DIR__ . '/../app/Hosting/HostingTransport.php');
$assert(is_string($source) && str_contains($source, 'CURLOPT_RESOLVE'), 'validated DNS address is pinned for cURL');
$assert(str_contains($source, "CURLOPT_PROXY=>''"), 'environment outbound proxies are disabled');
$assert(str_contains($source, 'CURLOPT_FOLLOWLOCATION=>false'), 'HTTP redirects remain disabled');
$assert(str_contains($source, 'DNS_AAAA'), 'both IPv4 and IPv6 DNS answers are checked');
echo "HostPilot hosting transport SSRF tests passed.\n";
