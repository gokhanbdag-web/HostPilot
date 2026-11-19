<?php
declare(strict_types=1);
$src=file_get_contents(dirname(__DIR__).'/app/Http/CatalogController.php');
$checks=[
 'Order detail has activity section'=>str_contains($src,'Sipariş işlem geçmişi'),
 'Audit timeline scoped to order'=>str_contains($src,"JSON_EXTRACT(l.metadata_json,'$.resource_id')")&&str_contains($src,":order_id"),
 'Audit timeline uses parameterized ID'=>str_contains($src,"[':order_id'=>(string)\$id]"),
 'Audit timeline is limited'=>str_contains($src,'ORDER BY l.id DESC LIMIT 50'),
 'Timeline escapes event timestamp'=>str_contains($src,"\$this->e((string)\$event['created_at'])"),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
