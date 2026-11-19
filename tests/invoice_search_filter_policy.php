<?php
declare(strict_types=1);
$src=file_get_contents(dirname(__DIR__).'/app/Http/CatalogController.php');
$start=strpos($src,'public function adminInvoices():void');
$end=strpos($src,'private function cartSession()', $start);
if($start===false||$end===false)throw new RuntimeException('Invoice list handler missing');
$part=substr($src,$start,$end-$start);
$checks=[
 'Invoice search query input'=>str_contains($part,"\$_GET['q']"),
 'Invoice status query input'=>str_contains($part,"\$_GET['status']"),
 'Invoice number and customer search'=>str_contains($part,'i.invoice_number LIKE :number')&&str_contains($part,'u.email LIKE :email'),
 'Invoice status placeholder'=>str_contains($part,'i.status=:status'),
 'Parameterized query'=>str_contains($part,'$this->db->prepare($sql)')&&str_contains($part,'$query->execute($params)'),
 'Escaped filter values'=>str_contains($part,'$this->e($search)')&&str_contains($part,'$this->e($status)'),
 'Bounded results'=>str_contains($part,'LIMIT 300'),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
