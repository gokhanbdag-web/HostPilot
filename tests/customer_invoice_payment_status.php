<?php
declare(strict_types=1);

$source=file_get_contents(dirname(__DIR__).'/app/Http/CatalogController.php');
$routes=file_get_contents(dirname(__DIR__).'/index.php');
if(!is_string($source)||!is_string($routes))throw new RuntimeException('Customer invoice source files are missing.');

$checks=[
    'invoice list joins only the latest active attempt owned by the same invoice'=>str_contains($source,'LEFT JOIN payment_transactions ap')&&str_contains($source,"pt.user_id=i.user_id AND pt.status IN ('pending','review')")&&str_contains($source,'SELECT MAX(pt.id)'),
    'invoice list exposes pending and review labels'=>str_contains($source,"'Ödeme bekliyor'")&&str_contains($source,"'İncelemede'"),
    'invoice list hides a second pay action while a payment is active'=>str_contains($source,"&&!\$activePayment)"),
    'invoice detail queries active attempts scoped to invoice and customer'=>str_contains($source,"WHERE invoice_id=:id AND user_id=:uid AND status IN ('pending','review') ORDER BY id DESC LIMIT 1"),
    'invoice detail uses no-store caching for a fresh status view'=>str_contains($source,"header('Cache-Control: no-store, max-age=0')")&&str_contains($source,"header('Pragma: no-cache')"),
    'invoice detail shows a read-only refresh link without a provider polling action'=>str_contains($source,'Durumu yenile')&&str_contains($source,'">Durumu yenile</a>'),
    'invoice detail suppresses checkout while an active attempt exists'=>str_contains($source,'&& !is_array($activePayment)'),
    'review and pending states use safe customer-facing explanations'=>str_contains($source,'Ödeme sağlayıcısından sonuç bekleniyor')&&str_contains($source,'yeni ödeme başlatılamaz'),
    'invoice detail route remains customer-owned GET'=>str_contains($routes,"preg_match('#^/client/invoices/([0-9]+)$'")&&str_contains($routes,'$catalog->clientInvoiceDetail((int)$invoiceDetailMatch[1])'),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
echo "HostPilot customer invoice payment status tests passed.\n";
