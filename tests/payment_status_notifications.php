<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Core/SecretVault.php';
require dirname(__DIR__) . '/app/Core/EmailService.php';

$host = getenv('HOSTPILOT_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('HOSTPILOT_DB_PORT') ?: 3306);
$name = getenv('HOSTPILOT_DB_NAME') ?: '';
$user = getenv('HOSTPILOT_DB_USER') ?: '';
$password = getenv('HOSTPILOT_DB_PASSWORD') ?: '';
if ($name !== 'hostpilot_ci' || $user === '') {
    throw new RuntimeException('Refusing to run payment email tests outside the dedicated hostpilot_ci database.');
}
$db = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name), $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$service = new \HostPilot\Core\EmailService($db, 'hostpilot-ci-payment-email-key');
$transactionId = random_int(100000, 2000000000);
$recipient = 'payment-notice-' . bin2hex(random_bytes(8)) . '@example.test';
$invoiceNumber = 'CI-' . strtoupper(bin2hex(random_bytes(4)));
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

try {
    $assert($service->queuePaymentStatusNotice($recipient, 'Test Customer', $invoiceNumber, 149.90, 'TRY', 'pending', $transactionId), 'Pending payment notice was not queued.');
    $assert(!$service->queuePaymentStatusNotice($recipient, 'Test Customer', $invoiceNumber, 149.90, 'TRY', 'pending', $transactionId), 'Duplicate pending notice was not suppressed.');
    $assert($service->queuePaymentStatusNotice($recipient, 'Test Customer', $invoiceNumber, 149.90, 'TRY', 'review', $transactionId), 'Review transition notice was not queued.');
    $assert($service->queuePaymentStatusNotice($recipient, 'Test Customer', $invoiceNumber, 149.90, 'TRY', 'success', $transactionId), 'Successful payment notice was not queued.');
    $assert($service->queuePaymentStatusNotice($recipient, 'Test Customer', $invoiceNumber, 149.90, 'TRY', 'failed', $transactionId), 'Failed payment notice was not queued.');
    $assert($service->queuePaymentStatusNotice($recipient, 'Test Customer', $invoiceNumber, 149.90, 'TRY', 'refunded', $transactionId), 'Refund notice was not queued.');
    $assert($service->queuePaymentStatusNotice($recipient, 'Test Customer', $invoiceNumber, 149.90, 'TRY', 'cancelled', $transactionId), 'Cancellation notice was not queued.');
    $assert(!$service->queuePaymentStatusNotice($recipient, 'Test Customer', $invoiceNumber, 149.90, 'TRY', 'refunded', $transactionId), 'Duplicate refund notice was not suppressed.');
    $assert(!$service->queuePaymentStatusNotice($recipient, 'Test Customer', $invoiceNumber, 149.90, 'TRY', 'cancelled', $transactionId), 'Duplicate cancellation notice was not suppressed.');
    $assert($service->queueRefundNotice($recipient, 'Test Customer', $invoiceNumber, '30.00', '30.00', 'TRY', 'partially_refunded', $transactionId + 1), 'Partial refund notice was not queued.');
    $assert(!$service->queueRefundNotice($recipient, 'Test Customer', $invoiceNumber, '30.00', '30.00', 'TRY', 'partially_refunded', $transactionId + 1), 'Duplicate refund notice was not suppressed.');
    $assert($service->queueRefundNotice($recipient, 'Test Customer', $invoiceNumber, '20.00', '50.00', 'TRY', 'refunded', $transactionId + 2), 'Full refund notice after partial refunds was not queued.');
    $assert(!$service->queueRefundNotice($recipient, 'Test Customer', $invoiceNumber, '20.00', '50.00', 'TRY', 'pending', $transactionId + 3), 'Unsupported refund state was accepted.');
    $assert(!$service->queuePaymentStatusNotice($recipient, 'Test Customer', $invoiceNumber, 149.90, 'TRY', 'provider_debug', $transactionId), 'Unsupported event was accepted.');
    $assert(!$service->queuePaymentStatusNotice('invalid-recipient', 'Test Customer', $invoiceNumber, 149.90, 'TRY', 'review', $transactionId), 'Invalid recipient was accepted.');

    $query = $db->prepare('SELECT event_type,subject,body,idempotency_key FROM email_outbox WHERE recipient=:recipient ORDER BY id');
    $query->execute([':recipient' => $recipient]);
    $rows = $query->fetchAll();
    $assert(count($rows) === 8, 'Expected payment state notices and one notice per refund record.');
    $events = array_column($rows, 'event_type');
    sort($events);
    $assert($events === ['payment_cancelled', 'payment_failed', 'payment_pending', 'payment_refund', 'payment_refund', 'payment_refunded', 'payment_review', 'payment_success'], 'Payment state and refund event types were not stored correctly.');
    $keys = array_column($rows, 'idempotency_key');
    $assert(count(array_unique($keys)) === 8, 'Payment states and individual refunds must have distinct idempotency keys.');
    foreach ($rows as $row) {
        $assert(str_contains($row['body'], $invoiceNumber), 'Customer notice omitted the invoice reference.');
        if ($row['event_type'] === 'payment_refund') { $assert(str_contains($row['body'], 'Bu iade: ') && str_contains($row['body'], 'Toplam iade: '), 'Refund notice omitted individual or cumulative refund amounts.'); }
        else { $assert(str_contains($row['body'], '149,90 TRY'), 'Customer notice omitted the formatted amount.'); }
        $assert(!str_contains(strtolower($row['body']), 'merchant_key') && !str_contains(strtolower($row['body']), 'stack trace'), 'Customer notice exposed internal diagnostics or secrets.');
    }

    $source = file_get_contents(dirname(__DIR__) . '/app/Http/PaymentController.php');
    $assert(is_string($source), 'Cannot read PaymentController.php.');
    $assert(str_contains($source, "\$this->queuePaymentStatusForTransaction(\$txId,'pending')"), 'Initial pending transition is not queued.');
    $assert(str_contains($source, "\$this->queuePaymentStatusForTransaction((int)\$tx['id'],'success')"), 'Successful settlement is not queued.');
    $assert(str_contains($source, "\$this->queuePaymentStatusForTransaction(\$id,'review')"), 'Stale pending review is not queued.');
    $assert(str_contains($source, "\$this->queuePaymentStatusForTransaction(\$id,'refunded')"), 'Confirmed full refund is not queued.');
    $assert(str_contains($source, "\$this->queuePaymentStatusForTransaction(\$id,'cancelled')"), 'Confirmed no-capture cancellation is not queued.');
    $assert(str_contains($source, 'if($q->rowCount()===1&&isset($fields[\'status\']))'), 'Repeated status updates are not gated by a real row transition.');
    echo "PASS payment status email outbox idempotency and state transition coverage" . PHP_EOL;
} finally {
    $cleanup = $db->prepare('DELETE FROM email_outbox WHERE recipient=:recipient');
    $cleanup->execute([':recipient' => $recipient]);
}
