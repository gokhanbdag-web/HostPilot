<?php
declare(strict_types=1);
$src=file_get_contents(dirname(__DIR__).'/app/Http/CatalogController.php');
$start=strpos($src,'public function adminOrders():void');
$end=strpos($src,'public function adminOrderDetail', $start);
if($start===false||$end===false)throw new RuntimeException('Order list handler missing');
$part=substr($src,$start,$end-$start);
$checks=[
 'Search term read from query'=>str_contains($part,"\$_GET['q']"),
 'Status filter read from query'=>str_contains($part,"\$_GET['status']"),
 'Search uses placeholders'=>str_contains($part,'o.order_number LIKE :number')&&str_contains($part,'u.email LIKE :email'),
 'Status uses placeholder'=>str_contains($part,'o.status=:status'),
 'Query prepared and executed'=>str_contains($part,'$this->db->prepare($sql)')&&str_contains($part,'$query->execute($params)'),
 'Search output escaped'=>str_contains($part,'$this->e($search)')&&str_contains($part,'$this->e($status)'),
 'Result count bounded'=>str_contains($part,'LIMIT 25 OFFSET '),
 'Count query uses matching filters'=>str_contains($part,'$countQuery->execute($params)'),
 'Pagination retains filters'=>str_contains($part,"http_build_query(['q'=>$search,'status'=>$status])"),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
