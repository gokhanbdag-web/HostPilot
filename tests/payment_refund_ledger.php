<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/Payment/RefundLedger.php';

$host=getenv('HOSTPILOT_DB_HOST')?:'127.0.0.1';
$port=(int)(getenv('HOSTPILOT_DB_PORT')?:3306);
$name=getenv('HOSTPILOT_DB_NAME')?:'';
$user=getenv('HOSTPILOT_DB_USER')?:'';
$password=getenv('HOSTPILOT_DB_PASSWORD')?:'';
if($name!=='hostpilot_ci'||$user==='')throw new RuntimeException('Refusing to run refund ledger tests outside hostpilot_ci.');
$db=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$host,$port,$name),$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tag=bin2hex(random_bytes(6));$now=gmdate('Y-m-d H:i:s');
$insertUser=$db->prepare("INSERT INTO users(name,email,username,password_hash,role,status,created_at,updated_at) VALUES(:name,:email,:username,'test-hash',:role,'active',:created,:updated)");
$ids=[];
try{
    foreach(['admin','customer'] as $role){$insertUser->execute([':name'=>'Refund Test '.$role,':email'=>"refund-$role-$tag@example.test",':username'=>"refund-$role-$tag",':role'=>$role,':created'=>$now,':updated'=>$now]);$ids[$role]=(int)$db->lastInsertId();}
    $order=$db->prepare("INSERT INTO orders(order_number,user_id,status,currency,subtotal,tax_total,total,created_at,updated_at) VALUES(:number,:user,'complete','TRY',100,0,100,:created,:updated)");
    $invoice=$db->prepare("INSERT INTO invoices(invoice_number,order_id,user_id,status,currency,subtotal,tax_total,total,amount_paid,issued_at,due_at,paid_at,created_at,updated_at) VALUES(:number,:order,:user,'paid','TRY',:subtotal,0,:total,:paid,:issued,NULL,:paid_at,:created,:updated)");
    $tx=$db->prepare("INSERT INTO payment_transactions(invoice_id,user_id,gateway,idempotency_key,status,currency,amount,request_reference,created_at,updated_at) VALUES(:invoice,:user,'paytr',:key,'success','TRY',:amount,:reference,:created,:updated)");
    $create=static function(float $amount)use($db,$order,$invoice,$tx,$ids,$tag,$now):int{
        $suffix=bin2hex(random_bytes(3));
        $order->execute([':number'=>"RFO-$tag-$suffix",':user'=>$ids['customer'],':created'=>$now,':updated'=>$now]);$orderId=(int)$db->lastInsertId();
        $invoice->execute([':number'=>"RFI-$tag-$suffix",':order'=>$orderId,':user'=>$ids['customer'],':subtotal'=>number_format($amount,2,'.',''),':total'=>number_format($amount,2,'.',''),':paid'=>number_format($amount,2,'.',''),':paid_at'=>$now,':issued'=>$now,':created'=>$now,':updated'=>$now]);$invoiceId=(int)$db->lastInsertId();
        $tx->execute([':invoice'=>$invoiceId,':user'=>$ids['customer'],':key'=>'refund-'.$tag.'-'.$suffix,':amount'=>number_format($amount,2,'.',''),':reference'=>'gateway-'.$suffix,':created'=>$now,':updated'=>$now]);
        return (int)$db->lastInsertId();
    };
    $assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
    $expectFailure=static function(callable $fn,string $message):void{try{$fn();}catch(Throwable){return;}throw new RuntimeException($message);};

    $paymentId=$create(100.00);
    $first=\HostPilot\Payment\RefundLedger::record($db,$paymentId,$ids['admin'],'30.00','TRY','refund-001','İlk kısmi iade');
    $assert($first['payment_status']==='partially_refunded'&&$first['invoice_status']==='partially_paid'&&$first['invoice_remaining']==='30.00','First partial refund did not update payment and invoice balances.');
    $second=\HostPilot\Payment\RefundLedger::record($db,$paymentId,$ids['admin'],'20','TRY','refund-002','İkinci kısmi iade');
    $assert($second['total_refunded']==='50.00'&&$second['invoice_remaining']==='50.00','Second partial refund total is incorrect.');
    $expectFailure(static fn()=>\HostPilot\Payment\RefundLedger::record($db,$paymentId,$ids['admin'],'60','TRY','refund-003','Aşırı iade'),'Over-refund was accepted.');
    $expectFailure(static fn()=>\HostPilot\Payment\RefundLedger::record($db,$paymentId,$ids['admin'],'1','TRY','refund-002','Tekrarlı referans'),'Duplicate provider refund reference was accepted.');
    $expectFailure(static fn()=>\HostPilot\Payment\RefundLedger::record($db,$paymentId,$ids['admin'],'1','USD','refund-004','Para birimi uyuşmazlığı'),'Currency mismatch was accepted.');
    $full=\HostPilot\Payment\RefundLedger::record($db,$paymentId,$ids['admin'],'50.00','TRY','refund-005','Kalan tutarın iadesi');
    $assert($full['payment_status']==='refunded'&&$full['invoice_status']==='unpaid'&&$full['invoice_amount_paid']==='0.00','Full refund after partial refunds did not restore invoice balance.');
    $status=$db->prepare('SELECT status FROM payment_transactions WHERE id=:id');$status->execute([':id'=>$paymentId]);
    $assert($status->fetchColumn()==='refunded','Payment was not marked fully refunded.');
    $count=$db->prepare('SELECT COUNT(*) FROM payment_refunds WHERE payment_transaction_id=:id');$count->execute([':id'=>$paymentId]);
    $assert((int)$count->fetchColumn()===3,'Expected three distinct refund ledger records.');

    $rollbackId=$create(40.00);
    $db->beginTransaction();
    \HostPilot\Payment\RefundLedger::record($db,$rollbackId,$ids['admin'],'10.00','TRY','rollback-refund','Rollback test');
    $db->rollBack();
    $count->execute([':id'=>$rollbackId]);$assert((int)$count->fetchColumn()===0,'Outer transaction rollback left a refund ledger row.');
    $invoicePaid=$db->prepare('SELECT i.amount_paid FROM invoices i JOIN payment_transactions pt ON pt.invoice_id=i.id WHERE pt.id=:id');$invoicePaid->execute([':id'=>$rollbackId]);
    $assert((float)$invoicePaid->fetchColumn()===40.00,'Outer transaction rollback changed invoice balance.');
    $rollbackStatus=$db->prepare('SELECT status FROM payment_transactions WHERE id=:id');$rollbackStatus->execute([':id'=>$rollbackId]);
    $assert($rollbackStatus->fetchColumn()==='success','Outer transaction rollback changed payment status.');

    $source=file_get_contents(dirname(__DIR__).'/app/Http/CatalogController.php');
    $assert(is_string($source)&&str_contains($source,'pt.user_id=:uid')&&str_contains($source,'İade geçmişi'),'Customer refund history is not scoped to invoice owner.');
    echo "PASS partial/multiple/full refunds, duplicate references, currency and over-refund guards, invoice balance restoration, rollback, and customer ownership policy".PHP_EOL;
}finally{
    if(isset($ids['customer'])){
        $db->prepare('DELETE FROM payment_transactions WHERE user_id=:id')->execute([':id'=>$ids['customer']]);
        $db->prepare('DELETE FROM invoices WHERE user_id=:id')->execute([':id'=>$ids['customer']]);
        $db->prepare('DELETE FROM orders WHERE user_id=:id')->execute([':id'=>$ids['customer']]);
    }
    foreach($ids as $id)$db->prepare('DELETE FROM users WHERE id=:id')->execute([':id'=>$id]);
}
