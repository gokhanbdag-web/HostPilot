<?php
declare(strict_types=1);

namespace HostPilot\Payment;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Records refunds already confirmed in the external provider dashboard.
 * This class never calls PayTR, iyzico, or a bank API.
 */
final class RefundLedger
{
    public static function record(PDO $db,int $transactionId,int $adminId,string $amount,string $currency,string $providerReference,string $reason):array
    {
        $amount=trim($amount);
        $currency=strtoupper(trim($currency));
        $providerReference=trim($providerReference);
        $reason=trim($reason);
        if($transactionId<1||$adminId<1||!preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D',$amount)||!preg_match('/^[A-Z]{3}$/D',$currency)||$providerReference===''||strlen($providerReference)>190||$reason===''||strlen($reason)>500){
            throw new RuntimeException('İade tutarı, para birimi, sağlayıcı referansı veya açıklama geçersiz.');
        }
        $cents=(int)round((float)$amount*100);
        if($cents<1)throw new RuntimeException('İade tutarı sıfırdan büyük olmalıdır.');
        $normalized=number_format($cents/100,2,'.','');
        $owns=!$db->inTransaction();
        if($owns)$db->beginTransaction();
        try{
            $q=$db->prepare('SELECT pt.*,i.total AS invoice_total,i.amount_paid AS invoice_amount_paid,i.currency AS invoice_currency,i.status AS invoice_status,i.due_at FROM payment_transactions pt JOIN invoices i ON i.id=pt.invoice_id WHERE pt.id=:id FOR UPDATE');
            $q->execute([':id'=>$transactionId]);$tx=$q->fetch(PDO::FETCH_ASSOC);
            if(!$tx||!in_array((string)$tx['status'],['success','partially_refunded'],true))throw new RuntimeException('Yalnızca faturaya işlenmiş başarılı tahsilatlara iade kaydı eklenebilir.');
            if($currency!==(string)$tx['currency']||$currency!==(string)$tx['invoice_currency'])throw new RuntimeException('İade para birimi ödeme ve fatura para birimiyle eşleşmiyor.');
            if(in_array((string)$tx['invoice_status'],['cancelled','refunded'],true))throw new RuntimeException('İptal edilmiş veya tamamen iade edilmiş faturaya yeni iade eklenemez.');
            $sum=$db->prepare('SELECT COALESCE(SUM(amount),0) FROM payment_refunds WHERE payment_transaction_id=:id');
            $sum->execute([':id'=>$transactionId]);$already=(int)round((float)$sum->fetchColumn()*100);
            $captured=(int)round((float)$tx['amount']*100);
            $invoicePaid=(int)round((float)$tx['invoice_amount_paid']*100);
            if($cents+$already>$captured)throw new RuntimeException('Toplam iade tutarı bu tahsilat tutarını aşamaz.');
            if($cents>$invoicePaid)throw new RuntimeException('İade tutarı faturaya işlenmiş toplam tahsilatı aşamaz.');
            $now=gmdate('Y-m-d H:i:s');
            $ins=$db->prepare('INSERT INTO payment_refunds(payment_transaction_id,admin_user_id,provider_reference,amount,currency,reason,created_at) VALUES(:tx,:admin,:reference,:amount,:currency,:reason,:created)');
            $ins->execute([':tx'=>$transactionId,':admin'=>$adminId,':reference'=>$providerReference,':amount'=>$normalized,':currency'=>$currency,':reason'=>$reason,':created'=>$now]);
            $refundId=(int)$db->lastInsertId();
            $totalRefunded=$already+$cents;
            $newPaymentStatus=$totalRefunded===$captured?'refunded':'partially_refunded';
            $db->prepare('UPDATE payment_transactions SET status=:status,response_code=:code,safe_message=:message,updated_at=:updated WHERE id=:id')
                ->execute([':status'=>$newPaymentStatus,':code'=>$newPaymentStatus==='refunded'?'manual_full_refund_confirmed':'manual_partial_refund_confirmed',':message'=>$newPaymentStatus==='refunded'?'Sağlayıcı panelinde tam iade doğrulandı.':'Sağlayıcı panelinde kısmi iade doğrulandı.',':updated'=>$now,':id'=>$transactionId]);
            $newPaid=$invoicePaid-$cents;
            $invoiceTotal=(int)round((float)$tx['invoice_total']*100);
            $invoiceStatus=$newPaid>=$invoiceTotal?'paid':($newPaid>0?'partially_paid':(!empty($tx['due_at'])&&substr((string)$tx['due_at'],0,10)<gmdate('Y-m-d')?'overdue':'unpaid'));
            $db->prepare("UPDATE invoices SET amount_paid=:paid,status=:status,paid_at=IF(:paid_status='paid',paid_at,NULL),updated_at=:updated WHERE id=:id")
                ->execute([':paid'=>number_format($newPaid/100,2,'.',''),':status'=>$invoiceStatus,':paid_status'=>$invoiceStatus,':updated'=>$now,':id'=>$tx['invoice_id']]);
            $result=['refund_id'=>$refundId,'payment_transaction_id'=>$transactionId,'refund_amount'=>$normalized,'total_refunded'=>number_format($totalRefunded/100,2,'.',''),'payment_status'=>$newPaymentStatus,'invoice_status'=>$invoiceStatus,'invoice_amount_paid'=>number_format($newPaid/100,2,'.',''),'invoice_remaining'=>number_format(max(0,$invoiceTotal-$newPaid)/100,2,'.',''),'currency'=>$currency];
            if($owns)$db->commit();
            return $result;
        }catch(Throwable $e){if($owns&&$db->inTransaction())$db->rollBack();throw $e;}
    }
}
