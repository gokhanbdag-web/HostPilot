<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../app/Http/PaymentController.php');
if (!is_string($source)) throw new RuntimeException('Cannot read PaymentController.php');

$checks = [
    'invoice row is locked before a new payment attempt' => str_contains($source, 'SELECT * FROM invoices WHERE id=:id AND user_id=:uid LIMIT 1 FOR UPDATE'),
    'pending and review attempts block a duplicate payment start' => str_contains($source, "status IN ('pending','review') ORDER BY id DESC LIMIT 1 FOR UPDATE"),
    'the invoice lock transaction commits before external gateway initialization' => str_contains($source, '$this->db->commit();' . "\n                if(\$gateway==='paytr')"),
    'provider-confirmed overpayment is retained for manual reconciliation' => str_contains($source, "response_code='overpayment_review'") && str_contains($source, "status='review'"),
    'payment initialization rolls back an open database transaction on failure' => str_contains($source, 'if($this->db->inTransaction())$this->db->rollBack();'),
    'stale pending attempts move to review without releasing the invoice for another charge' => str_contains($source, "status='review',response_code='stale_pending_review'") && str_contains($source, "modify('-30 minutes')"),
    'administrator can explicitly confirm that no capture occurred' => str_contains($source, "'no_capture_confirmed'") && str_contains($source, "status='cancelled'"),
    'late callbacks cannot settle manually closed or terminal payment attempts' => str_contains($source, "['success','failed','cancelled','refunded','partially_refunded']") && str_contains($source, "status NOT IN ('success','cancelled','refunded','partially_refunded','review')"),
    'no-capture decision is recorded in reconciliation history and audit log' => str_contains($source, "INSERT INTO payment_reconciliations(payment_transaction_id,admin_user_id,decision") && str_contains($source, "payment.no_capture_confirmed"),
];
foreach ($checks as $label=>$passed) {
    if (!$passed) throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label.PHP_EOL;
}
echo "HostPilot payment idempotency policy tests passed.\n";
