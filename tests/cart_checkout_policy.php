<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$catalog=file_get_contents($root.'/app/Http/CatalogController.php');
$routes=file_get_contents($root.'/index.php');
if(!is_string($catalog)||!is_string($routes))throw new RuntimeException('Cart source files are missing.');
$checks=[
    'Cart page is registered as GET'=>str_contains($routes,'$requestPath === \'/cart\' && $method === \'GET\''),
    'Cart add is registered as POST'=>str_contains($routes,'$requestPath === \'/cart/add\' && $method === \'POST\''),
    'Cart remove is registered as POST'=>str_contains($routes,'$requestPath === \'/cart/remove\' && $method === \'POST\''),
    'Cart uses the server-side session'=>str_contains($catalog,'$_SESSION[\'hostpilot_cart\']'),
    'Cart mutations require CSRF validation'=>substr_count($catalog,'Auth::verifyCsrf($_POST[\'_token\']??null)')>=2,
    'Cart has a bounded item count'=>str_contains($catalog,'count($cart)>=10')&&str_contains($catalog,'count($cart)>10'),
    'Duplicate product-cycle entries are rejected'=>str_contains($catalog,'isset($cart[$key])'),
    'Cart enforces one currency per order'=>str_contains($catalog,'same para birimindeki ürünler')&&str_contains($catalog,'farklı para birimleri kullanılamaz'),
    'Cart limits an order to one domain'=>str_contains($catalog,'yalnızca bir domain bulunabilir'),
    'Cart re-reads active product prices'=>substr_count($catalog,"pp.is_active=1 LIMIT 1")>=3,
    'Cart includes setup fees in item totals'=>str_contains($catalog,'$setupCents=$this->moneyCents($p[\'setup_fee\'])')&&str_contains($catalog,'$lineCents=$baseCents+$setupCents'),
    'Order and invoice lines include every cart item'=>str_contains($catalog,'foreach($items as $item)')&&str_contains($catalog,'INSERT INTO order_items')&&str_contains($catalog,'INSERT INTO invoice_items'),
    'Setup fees are itemized on the invoice'=>str_contains($catalog,'Kurulum ücreti'),
    'Order creation is transactional'=>str_contains($catalog,'$this->db->beginTransaction()')&&str_contains($catalog,'$this->db->rollBack()'),
    'Cart clears only after successful commit'=>str_contains($catalog,'$this->db->commit();unset($_SESSION[\'hostpilot_cart\'])'),
    'Domain registrant data is validated and encrypted'=>str_contains($catalog,'normalizeDomainDetails')&&str_contains($catalog,'$vault->encrypt('),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
echo "HostPilot cart checkout policy tests passed.".PHP_EOL;
