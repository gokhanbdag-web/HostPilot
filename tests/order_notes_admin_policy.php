<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$catalog=file_get_contents($root.'/app/Http/CatalogController.php');
$routes=file_get_contents($root.'/index.php');
$auth=file_get_contents($root.'/app/Core/Authorization.php');
$audit=file_get_contents($root.'/app/Core/SecurityAudit.php');
$checks=[
    'Order notes form uses CSRF token'=>str_contains($catalog,"/admin/orders/'.$id.'/notes")&&str_contains($catalog,'Auth::csrfToken()'),
    'Order notes mutation requires administrator role'=>str_contains($catalog,'function adminSaveOrderNotes(int $id)')&&str_contains($catalog,"['super_admin','admin']"),
    'Order notes mutation verifies CSRF'=>str_contains($catalog,"Auth::verifyCsrf("),
    'Only notes and update timestamp are modified'=>str_contains($catalog,'UPDATE orders SET notes=:notes,updated_at=:now WHERE id=:id'),
    'Order notes mutation is audited'=>str_contains($catalog,'order.notes_updated')&&str_contains($audit,"'order.notes_updated'"),
    'Order notes route is registered'=>str_contains($routes,'adminSaveOrderNotes((int)$orderNotesMatch[1])'),
    'Order notes route has dedicated manage permission'=>str_contains($auth,"return 'orders.manage'"),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
