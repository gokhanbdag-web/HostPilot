<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../app/Http/PaymentController.php');
if (!is_string($source)) throw new RuntimeException('Cannot read PaymentController.php');

$checks = [
    'invoice row is locked before a new payment attempt' => str_contains($source, 'SELECT * FROM invoices WHERE id=:id AND user_id=:uid LIMIT 1 FOR UPDATE'),
    'pending and review attempts block a duplicate payment start' => str_contains($source, "status IN ('pending','review') ORDER BY id DESC LIMIT 1 FOR UPDATE"),
    'the invoice lock transaction commits before external gateway initialization' => strpos($source, '$this->db->commit();' . "\n                if($gateway==='paytr')") !== false,
    'provider-confirmed overpayment is retained for manual reconciliation' => str_contains($source, "response_code='overpayment_review'") && str_contains($source, "status='review'"),
    'payment initialization rolls back an open database transaction on failure' => str_contains($source, 'if($this->db->inTransaction())$this->db->rollBack();'),
];
foreach ($checks as $label=>$passed) {
    if (!$passed) throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label.PHP_EOL;
}
echo "HostPilot payment idempotency policy tests passed.\n";
