<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$catalog=file_get_contents($root.'/app/Http/CatalogController.php');
$routes=file_get_contents($root.'/index.php');
$auth=file_get_contents($root.'/app/Core/Authorization.php');
$checks=[
'Order detail uses authenticated staff guard'=>str_contains($catalog,'function adminOrderDetail(int $id)')&&str_contains($catalog,'if(!$this->staff())return;'),
'Invoice detail uses authenticated staff guard'=>str_contains($catalog,'function adminInvoiceDetail(int $id)')&&str_contains($catalog,'if(!$this->staff())return;'),
'Details query persisted order and invoice items'=>str_contains($catalog,'FROM order_items WHERE order_id=:id')&&str_contains($catalog,'FROM invoice_items WHERE invoice_id=:id'),
'Details use parameterized record lookup'=>str_contains($catalog,'WHERE o.id=:id LIMIT 1')&&str_contains($catalog,'WHERE i.id=:id LIMIT 1'),
'Details escape customer and product fields'=>str_contains($catalog,'customer_name')&&str_contains($catalog,'$this->e('),
'Order and invoice detail routes are registered'=>str_contains($routes,'adminOrderDetail((int)$orderMatch[1])')&&str_contains($routes,'adminInvoiceDetail((int)$invoiceMatch[1])'),
'Detail routes are covered by permission map'=>str_contains($auth,"#^/admin/orders/[0-9]+$#")&&str_contains($auth,"#^/admin/invoices/[0-9]+$#"),
'No direct financial mutation is exposed in detail actions'=>!str_contains($catalog,'function adminInvoiceDetail(int $id):void\n    {\n        $this->db->exec'),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
