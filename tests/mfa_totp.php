<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Core/Mfa.php';

use HostPilot\Core\Mfa;

function assertMfa(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// RFC 6238 SHA-1 vector: secret "12345678901234567890", Unix time 59.
$secret = Mfa::base32Encode('12345678901234567890');
assertMfa($secret === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'Base32 encoding RFC vector failed.');
assertMfa(Mfa::base32Decode($secret) === '12345678901234567890', 'Base32 decoding failed.');
assertMfa(Mfa::codeAt($secret, 59) === '287082', 'RFC 6238 TOTP vector failed.');
assertMfa(Mfa::matchingStep($secret, '287082', 59) === 1, 'TOTP time-step verification failed.');
assertMfa(Mfa::matchingStep($secret, '000000', 59) === null, 'Invalid TOTP was accepted.');
assertMfa(Mfa::base32Decode('INVALID0') === null, 'Invalid Base32 input was accepted.');
$codes = Mfa::generateRecoveryCodes();
assertMfa(count($codes) === 10, 'Expected ten recovery codes.');
$hashes = [];
foreach ($codes as $code) {
    $hash = Mfa::recoveryCodeHash($code);
    assertMfa(is_string($hash) && strlen($hash) === 64, 'Recovery code hash was not generated.');
    assertMfa(!in_array($hash, $hashes, true), 'Duplicate recovery code was generated.');
    $hashes[] = $hash;
}
assertMfa(Mfa::recoveryCodeHash('not-a-recovery-code') === null, 'Malformed recovery code was accepted.');
assertMfa(Mfa::recoveryCodeHash(str_replace('-', '', $codes[0])) === Mfa::recoveryCodeHash($codes[0]), 'Recovery code normalization failed.');
echo "PASS RFC 6238 TOTP vectors, Base32 validation, time-step matching and recovery-code hashing.\n";
