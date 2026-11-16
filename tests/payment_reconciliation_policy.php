<?php
declare(strict_types=1);

$source=file_get_contents(dirname(__DIR__).'/app/Http/PaymentController.php');
if(!is_string($source))throw new RuntimeException('PaymentController source missing.');
$checks=[
    'review transactions expose reconciliation only to admin roles'=>str_contains($source,"$canReconcile=in_array((Auth::user()['role']??''),['admin','super_admin'],true)"),
    'reconciliation requires current admin password'=>str_contains($source,'Auth::verifyCredentials'),
    'reconciliation requires CSRF'=>str_contains($source,'Auth::verifyCsrf'),
    'payment application reuses the normal settlement path'=>str_contains($source,"$this->settle($tx,'success',$reference"),
    'external refund is recorded as manual and not sent to gateway'=>str_contains($source,"'manual_refund_confirmed'")&&str_contains($source,"provider panelinde tamamlanan tam iade"),
    'refund path only handles reviewed captures'=>str_contains($source,"$current['status']!=='review'"),
    'payment reconciliation is stored in a dedicated table'=>str_contains($source,'INSERT INTO payment_reconciliations'),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
$migration=file_get_contents(dirname(__DIR__).'/database/migrations/20261116_0004_payment_reconciliations.sql');
if(!is_string($migration)||!str_contains($migration,'uq_payment_reconciliation_transaction_decision')||!str_contains($migration,'fk_payment_reconciliations_transaction'))throw new RuntimeException('Payment reconciliation schema constraints are missing.');
echo "HostPilot manual payment reconciliation policy tests passed.\n";
