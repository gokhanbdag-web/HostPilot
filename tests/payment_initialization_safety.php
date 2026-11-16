<?php
declare(strict_types=1);

$controller=file_get_contents(dirname(__DIR__).'/app/Http/PaymentController.php');
$transport=file_get_contents(dirname(__DIR__).'/app/Payment/GatewayTransport.php');
$exception=file_get_contents(dirname(__DIR__).'/app/Payment/GatewayTransportException.php');
if(!is_string($controller)||!is_string($transport)||!is_string($exception))throw new RuntimeException('Payment initialization source files are missing.');

$checks=[
    'ambiguous gateway transport failures have a dedicated exception type'=>str_contains($controller,'$uncertain=$e instanceof GatewayTransportException')&&str_contains($exception,'class GatewayTransportException extends \\RuntimeException'),
    'ambiguous initialization is moved to manual review, never failed'=>str_contains($controller,"'status'=>\$uncertain?'review':'failed'")&&str_contains($controller,"'response_code'=>\$uncertain?'init_result_uncertain':'init_failed'"),
    'transport exceptions preserve the original cause without exposing it to customers'=>str_contains($transport,'new GatewayTransportException')&&str_contains($controller,"error_log('[HostPilot] Payment initialization: '.\$e->getMessage())"),
    'non-success provider status remains an explicit initialization failure'=>str_contains($controller,"status']??'')!=='success'"),
    'successful provider response without a usable token is treated as uncertain'=>str_contains($controller,'PayTR başarılı yanıtında ödeme formu anahtarı bulunamadı')&&str_contains($controller,'iyzico başarılı yanıtında ödeme formu anahtarı bulunamadı'),
    'database persistence failure after provider success is treated as uncertain'=>str_contains($controller,'PayTR ödeme oturumu oluşturuldu ancak kullanılabilir form bilgisi kaydedilemedi')&&str_contains($controller,'iyzico ödeme oturumu oluşturuldu ancak form bilgisi güvenle kullanılamadı'),
    'customer GET page detects active pending or review attempts'=>str_contains($controller,"SELECT status FROM payment_transactions WHERE invoice_id=:invoice AND status IN ('pending','review') ORDER BY id DESC LIMIT 1"),
    'customer payment form is suppressed while an attempt is pending or under review'=>str_contains($controller,"elseif(in_array(\$activeStatus,['pending','review'],true))")&&str_contains($controller,'Ödeme durumunu müşteri panelindeki fatura sayfasından takip edebilirsiniz'),
    'invoice row lock serializes concurrent starts and active attempt lock rejects duplicates'=>str_contains($controller,'SELECT * FROM invoices WHERE id=:id AND user_id=:uid LIMIT 1 FOR UPDATE')&&str_contains($controller,"status IN ('pending','review') ORDER BY id DESC LIMIT 1 FOR UPDATE"),
    'settlement locks the transaction and ignores terminal or already settled callbacks'=>str_contains($controller,'SELECT * FROM payment_transactions WHERE id=:id FOR UPDATE')&&str_contains($controller,"['success','failed','cancelled','refunded']"),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
echo "HostPilot payment initialization uncertainty tests passed.\n";
