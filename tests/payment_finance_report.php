<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/Payment/FinanceReport.php';

$host=getenv('HOSTPILOT_DB_HOST')?:'127.0.0.1';
$port=(int)(getenv('HOSTPILOT_DB_PORT')?:3306);
$name=getenv('HOSTPILOT_DB_NAME')?:'';
$user=getenv('HOSTPILOT_DB_USER')?:'';
$password=getenv('HOSTPILOT_DB_PASSWORD')?:'';
if($name!=='hostpilot_ci'||$user==='')throw new RuntimeException('Refusing to run finance report tests outside hostpilot_ci.');
$db=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$host,$port,$name),$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tag=bin2hex(random_bytes(6));$now=gmdate('Y-m-d H:i:s');$today=gmdate('Y-m-d');$old=gmdate('Y-m-d H:i:s',time()-45*86400);$yesterday=gmdate('Y-m-d H:i:s',time()-86400);
$ids=[];
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
try{
    $insertUser=$db->prepare("INSERT INTO users(name,email,username,password_hash,role,status,created_at,updated_at) VALUES(:name,:email,:username,'test-hash',:role,'active',:created,:updated)");
    foreach(['admin','customer'] as $role){$insertUser->execute([':name'=>'Finance Test '.$role,':email'=>"finance-$role-$tag@example.test",':username'=>"finance-$role-$tag",':role'=>$role,':created'=>$now,':updated'=>$now]);$ids[$role]=(int)$db->lastInsertId();}
    $order=$db->prepare("INSERT INTO orders(order_number,user_id,status,currency,subtotal,tax_total,total,created_at,updated_at) VALUES(:number,:user,'complete',:currency,:subtotal,0,:total,:created,:updated)");
    $invoice=$db->prepare("INSERT INTO invoices(invoice_number,order_id,user_id,status,currency,subtotal,tax_total,total,amount_paid,issued_at,due_at,paid_at,created_at,updated_at) VALUES(:number,:order,:user,:status,:currency,:subtotal,0,:total,:paid,:issued,:due,:paid_at,:created,:updated)");
    $txInsert=$db->prepare("INSERT INTO payment_transactions(invoice_id,user_id,gateway,gateway_transaction_id,idempotency_key,status,currency,amount,response_code,request_reference,created_at,updated_at) VALUES(:invoice,:user,'paytr',:gateway_tx,:key,:status,:currency,:amount,:response,:reference,:created,:updated)");
    $create=static function(string $amount,string $currency,string $status,string $paid,string $invoiceStatus,string $created,?string $response=null)use($db,$order,$invoice,$txInsert,$ids,$tag,$now):int{
        $suffix=bin2hex(random_bytes(4));
        $order->execute([':number'=>"FRO-$tag-$suffix",':user'=>$ids['customer'],':currency'=>$currency,':subtotal'=>$amount,':total'=>$amount,':created'=>$now,':updated'=>$now]);$orderId=(int)$db->lastInsertId();
        $invoice->execute([':number'=>"FRI-$tag-$suffix",':order'=>$orderId,':user'=>$ids['customer'],':status'=>$invoiceStatus,':currency'=>$currency,':subtotal'=>$amount,':total'=>$amount,':paid'=>$paid,':issued'=>$created,':due'=>$created,':paid_at'=>$paid!=='0.00'?$created:null,':created'=>$created,':updated'=>$created]);$invoiceId=(int)$db->lastInsertId();
        $txInsert->execute([':invoice'=>$invoiceId,':user'=>$ids['customer'],':gateway_tx'=>'gateway-'.$suffix,':key'=>'finance-'.$tag.'-'.$suffix,':status'=>$status,':currency'=>$currency,':amount'=>$amount,':response'=>$response,':reference'=>'request-'.$suffix,':created'=>$created,':updated'=>$created]);
        return (int)$db->lastInsertId();
    };
    $createInvoiceOnly=static function(string $amount,string $currency,string $status,string $paid,string $created)use($db,$order,$invoice,$ids,$tag,$now):void{
        $suffix=bin2hex(random_bytes(4));
        $order->execute([':number'=>"FRO-$tag-$suffix",':user'=>$ids['customer'],':currency'=>$currency,':total'=>$amount,':created'=>$now,':updated'=>$now]);$orderId=(int)$db->lastInsertId();
        $invoice->execute([':number'=>"FRI-$tag-$suffix",':order'=>$orderId,':user'=>$ids['customer'],':status'=>$status,':currency'=>$currency,':total'=>$amount,':paid'=>$paid,':issued'=>$created,':due'=>$created,':paid_at'=>$paid!=='0.00'?$created:null,':created'=>$created,':updated'=>$created]);
    };

    $success=$create('100.00','TRY','success','100.00','paid',$now);
    $partial=$create('50.00','TRY','partially_refunded','30.00','partially_paid',$now);
    $full=$create('40.00','TRY','refunded','0.00','unpaid',$now);
    $usd=$create('80.00','USD','partially_refunded','70.00','partially_paid',$now);
    $manualRefund=$create('999.00','TRY','refunded','0.00','unpaid',$now,'manual_refund_confirmed');
    $review=$create('200.00','TRY','review','0.00','unpaid',$now);
    $outside=$create('15.00','TRY','success','15.00','paid',$old);
    $createInvoiceOnly('70.00','TRY','unpaid','0.00',$yesterday);

    $refund=$db->prepare("INSERT INTO payment_refunds(payment_transaction_id,admin_user_id,provider_reference,amount,currency,reason,created_at) VALUES(:tx,:admin,:reference,:amount,:currency,:reason,:created)");
    foreach([[$partial,'refund-partial','20.00','TRY'],[$full,'refund-full','40.00','TRY'],[$usd,'refund-usd','10.00','USD']] as [$txId,$reference,$amount,$currency]){
        $refund->execute([':tx'=>$txId,':admin'=>$ids['admin'],':reference'=>$reference.'-'.$tag,':amount'=>$amount,':currency'=>$currency,':reason'=>'Finance report fixture',':created'=>$now]);
    }
    $refund->execute([':tx'=>$partial,':admin'=>$ids['admin'],':reference'=>'refund-old-'.$tag,':amount'=>'1.00',':currency'=>'TRY',':reason'=>'Outside selected period',':created'=>$old]);

    $reconcile=$db->prepare("INSERT INTO payment_reconciliations(payment_transaction_id,admin_user_id,decision,provider_reference,amount,currency,reason,created_at) VALUES(:tx,:admin,:decision,:reference,:amount,:currency,:reason,:created)");
    foreach([[$review,'no_capture_confirmed','manual-no-capture','200.00'],[$manualRefund,'refund_confirmed','manual-review-refund','999.00']] as [$txId,$decision,$reference,$amount]){
        $reconcile->execute([':tx'=>$txId,':admin'=>$ids['admin'],':decision'=>$decision,':reference'=>$reference.'-'.$tag,':amount'=>$amount,':currency'=>'TRY',':reason'=>'Manual reconciliation fixture',':created'=>$now]);
    }

    $from=$today.' 00:00:00';$to=(new DateTimeImmutable($today,new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d').' 00:00:00';
    $summary=\HostPilot\Payment\FinanceReport::summary($db,$from,$to);
    $byCurrency=[];foreach($summary as $row)$byCurrency[$row['currency']]=$row;
    $assert(isset($byCurrency['TRY'],$byCurrency['USD']),'Currencies were not reported independently.');
    $assert($byCurrency['TRY']['capture_count']===3&&$byCurrency['TRY']['capture_amount']==='190.00','TRY captured totals incorrectly include a review-only manual refund or omit a real capture.');
    $assert($byCurrency['TRY']['refund_count']===2&&$byCurrency['TRY']['refund_amount']==='60.00'&&$byCurrency['TRY']['net_amount']==='130.00','TRY refund and net movement totals are incorrect.');
    $assert($byCurrency['USD']['capture_amount']==='80.00'&&$byCurrency['USD']['refund_amount']==='10.00'&&$byCurrency['USD']['net_amount']==='70.00','USD totals were mixed with another currency.');
    $assert($byCurrency['TRY']['review_count']===1&&$byCurrency['TRY']['review_amount']==='200.00','Manual review amount was not separated from captured funds.');
    $assert($byCurrency['TRY']['outstanding_amount']==='1329.00','TRY open invoice balances do not reflect refund-restored balances.');
    $assert($byCurrency['USD']['outstanding_amount']==='10.00','USD open invoice balance is incorrect.');
    $assert($byCurrency['TRY']['overdue_amount']==='70.00','Overdue balance should use calendar-day boundaries, not the current time of day.');
    try{\HostPilot\Payment\FinanceReport::summary($db,'2026-02-30 00:00:00',$to);throw new RuntimeException('Invalid calendar date was accepted.');}catch(\InvalidArgumentException){}

    $movements=\HostPilot\Payment\FinanceReport::movements($db,$from,$to,100);
    $assert(count($movements)===7,'Expected four capture and three in-period refund movements; older activity or uncollected manual refund leaked in.');
    $assert(count(array_filter($movements,static fn($row)=>$row['movement_type']==='refund'))===3,'Refunds were not exposed as individual cash movements.');
    $assert(!in_array($outside,array_map('intval',array_column($movements,'payment_transaction_id')),true),'Out-of-period payment was included.');
    $decisions=\HostPilot\Payment\FinanceReport::reconciliations($db,$from,$to,100);
    $assert(count($decisions)===2,'Manual reconciliation decision history was not returned.');
    $assert(count(\HostPilot\Payment\FinanceReport::movements($db,$from,$to,1))===1,'Movement result limit was not enforced.');
    echo "PASS currency-separated capture/refund/net totals, restored invoice balances, manual review exclusion, date filtering, movement ledger and reconciliation history".PHP_EOL;
}finally{
    if(isset($ids['customer'])){
        $db->prepare('DELETE FROM payment_transactions WHERE user_id=:id')->execute([':id'=>$ids['customer']]);
        $db->prepare('DELETE FROM invoices WHERE user_id=:id')->execute([':id'=>$ids['customer']]);
        $db->prepare('DELETE FROM orders WHERE user_id=:id')->execute([':id'=>$ids['customer']]);
    }
    foreach($ids as $id)$db->prepare('DELETE FROM users WHERE id=:id')->execute([':id'=>$id]);
}
