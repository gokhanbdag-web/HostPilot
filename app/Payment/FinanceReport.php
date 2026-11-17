<?php
declare(strict_types=1);

namespace HostPilot\Payment;

use PDO;
use InvalidArgumentException;

final class FinanceReport
{
    private const CAPTURED_STATES = "'success','partially_refunded','refunded'";

    public static function summary(PDO $db, string $from, string $toExclusive): array
    {
        self::assertRange($from, $toExclusive);
        $result = [];

        $captures = $db->prepare("SELECT currency, COUNT(*) AS movement_count, COALESCE(SUM(amount),0) AS amount
            FROM payment_transactions
            WHERE status IN (".self::CAPTURED_STATES.")
              AND COALESCE(response_code,'') <> 'manual_refund_confirmed'
              AND created_at >= :capture_from AND created_at < :capture_to
            GROUP BY currency");
        $captures->execute([':capture_from'=>$from, ':capture_to'=>$toExclusive]);
        foreach ($captures->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $currency = (string)$row['currency'];
            $result[$currency] = self::emptyCurrency($currency);
            $result[$currency]['capture_count'] = (int)$row['movement_count'];
            $result[$currency]['capture_cents'] = self::cents($row['amount']);
        }

        $refunds = $db->prepare("SELECT currency, COUNT(*) AS movement_count, COALESCE(SUM(amount),0) AS amount
            FROM payment_refunds
            WHERE created_at >= :refund_from AND created_at < :refund_to
            GROUP BY currency");
        $refunds->execute([':refund_from'=>$from, ':refund_to'=>$toExclusive]);
        foreach ($refunds->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $currency = (string)$row['currency'];
            $result[$currency] ??= self::emptyCurrency($currency);
            $result[$currency]['refund_count'] = (int)$row['movement_count'];
            $result[$currency]['refund_cents'] = self::cents($row['amount']);
        }

        $outstanding = $db->prepare("SELECT currency, COUNT(*) AS invoice_count,
                COALESCE(SUM(GREATEST(total-amount_paid,0)),0) AS amount,
                COALESCE(SUM(CASE WHEN due_at IS NOT NULL AND due_at < :overdue_today THEN GREATEST(total-amount_paid,0) ELSE 0 END),0) AS overdue_amount
            FROM invoices
            WHERE status NOT IN ('paid','cancelled') AND total > amount_paid
            GROUP BY currency");
        $outstanding->execute([':overdue_today'=>gmdate('Y-m-d 00:00:00')]);
        foreach ($outstanding->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $currency = (string)$row['currency'];
            $result[$currency] ??= self::emptyCurrency($currency);
            $result[$currency]['outstanding_count'] = (int)$row['invoice_count'];
            $result[$currency]['outstanding_cents'] = self::cents($row['amount']);
            $result[$currency]['overdue_cents'] = self::cents($row['overdue_amount']);
        }

        $review = $db->prepare("SELECT currency, COUNT(*) AS movement_count, COALESCE(SUM(amount),0) AS amount
            FROM payment_transactions WHERE status='review'
              AND created_at >= :review_from AND created_at < :review_to
            GROUP BY currency");
        $review->execute([':review_from'=>$from, ':review_to'=>$toExclusive]);
        foreach ($review->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $currency = (string)$row['currency'];
            $result[$currency] ??= self::emptyCurrency($currency);
            $result[$currency]['review_count'] = (int)$row['movement_count'];
            $result[$currency]['review_cents'] = self::cents($row['amount']);
        }

        ksort($result);
        foreach ($result as &$row) {
            $row['net_cents'] = $row['capture_cents'] - $row['refund_cents'];
            foreach (['capture','refund','net','outstanding','overdue','review'] as $key) {
                $row[$key.'_amount'] = self::money($row[$key.'_cents']);
            }
            unset($row['capture_cents'],$row['refund_cents'],$row['net_cents'],$row['outstanding_cents'],$row['overdue_cents'],$row['review_cents']);
        }
        unset($row);
        return array_values($result);
    }

    public static function movements(PDO $db, string $from, string $toExclusive, int $limit=200): array
    {
        self::assertRange($from, $toExclusive);
        $limit = max(1, min(5000, $limit));
        $captures = $db->prepare("SELECT pt.id AS payment_transaction_id, pt.created_at AS occurred_at, 'capture' AS movement_type,
                i.invoice_number, pt.gateway, COALESCE(pt.gateway_transaction_id,'') AS provider_reference,
                pt.amount, pt.currency, pt.status AS detail
            FROM payment_transactions pt
            JOIN invoices i ON i.id=pt.invoice_id
            WHERE pt.status IN (".self::CAPTURED_STATES.")
              AND COALESCE(pt.response_code,'') <> 'manual_refund_confirmed'
              AND pt.created_at >= :from_date AND pt.created_at < :to_date
            ORDER BY pt.created_at DESC LIMIT ".$limit);
        $captures->execute([':from_date'=>$from, ':to_date'=>$toExclusive]);
        $refunds = $db->prepare("SELECT pr.payment_transaction_id, pr.created_at AS occurred_at, 'refund' AS movement_type,
                i.invoice_number, pt.gateway, pr.provider_reference, pr.amount, pr.currency, pr.reason AS detail
            FROM payment_refunds pr
            JOIN payment_transactions pt ON pt.id=pr.payment_transaction_id
            JOIN invoices i ON i.id=pt.invoice_id
            WHERE pr.created_at >= :refund_from AND pr.created_at < :refund_to
            ORDER BY pr.created_at DESC LIMIT ".$limit);
        $refunds->execute([':refund_from'=>$from, ':refund_to'=>$toExclusive]);
        $rows = array_merge($captures->fetchAll(PDO::FETCH_ASSOC), $refunds->fetchAll(PDO::FETCH_ASSOC));
        usort($rows, static fn(array $a,array $b): int => strcmp((string)$b['occurred_at'],(string)$a['occurred_at']));
        return array_slice($rows,0,$limit);
    }

    public static function reconciliations(PDO $db, string $from, string $toExclusive, int $limit=100): array
    {
        self::assertRange($from, $toExclusive);
        $limit = max(1, min(500, $limit));
        $q = $db->prepare("SELECT r.created_at,r.decision,r.provider_reference,r.amount,r.currency,r.reason,
                i.invoice_number,u.name AS admin_name
            FROM payment_reconciliations r
            JOIN payment_transactions pt ON pt.id=r.payment_transaction_id
            JOIN invoices i ON i.id=pt.invoice_id
            JOIN users u ON u.id=r.admin_user_id
            WHERE r.created_at >= :from_date AND r.created_at < :to_date
            ORDER BY r.created_at DESC LIMIT ".$limit);
        $q->execute([':from_date'=>$from, ':to_date'=>$toExclusive]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function emptyCurrency(string $currency): array
    {
        return ['currency'=>$currency,'capture_count'=>0,'capture_cents'=>0,'refund_count'=>0,'refund_cents'=>0,
            'net_cents'=>0,'outstanding_count'=>0,'outstanding_cents'=>0,'overdue_cents'=>0,'review_count'=>0,'review_cents'=>0];
    }

    private static function cents(mixed $amount): int
    {
        $raw=trim((string)$amount);
        if(!preg_match('/^(-?)([0-9]+)(?:\\.([0-9]{1,2}))?$/D',$raw,$parts))return 0;
        $cents=((int)$parts[2])*100+(int)str_pad($parts[3]??'',2,'0',STR_PAD_RIGHT);
        return ($parts[1]??'')==='-'?-$cents:$cents;
    }

    private static function money(int $cents): string
    {
        $absolute=abs($cents);
        return ($cents<0?'-':'').number_format(intdiv($absolute,100),0,'.','').'.'.str_pad((string)($absolute%100),2,'0',STR_PAD_LEFT);
    }

    private static function assertRange(string $from, string $toExclusive): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2} 00:00:00$/D',$from)
            || !preg_match('/^\d{4}-\d{2}-\d{2} 00:00:00$/D',$toExclusive)
            || $from >= $toExclusive) {
            throw new InvalidArgumentException('Geçersiz rapor tarih aralığı.');
        }
    }
}
