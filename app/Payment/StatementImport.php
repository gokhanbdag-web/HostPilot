<?php
declare(strict_types=1);

namespace HostPilot\Payment;

use PDO;
use RuntimeException;
use Throwable;

final class StatementImport
{
    public const MAX_FILE_BYTES = 2097152;
    public const MAX_ROWS = 5000;

    /** @return list<array{date:string,reference:string,description:string,amount:string,currency:string,type:string}> */
    public static function parseCsv(string $contents): array
    {
        if ($contents === '' || strlen($contents) > self::MAX_FILE_BYTES) {
            throw new RuntimeException('CSV dosyası boş veya 2 MB sınırını aşıyor.');
        }
        if (str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
            throw new RuntimeException('CSV dosyası geçerli UTF-8 metin olmalıdır.');
        }
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new RuntimeException('CSV okunamadı.');
        try {
            fwrite($stream, $contents);
            rewind($stream);
            $header = fgetcsv($stream, 0, ',', '"', '\\');
            if (!is_array($header)) throw new RuntimeException('CSV başlık satırı bulunamadı.');
            $header = array_map(static fn($v): string => strtolower(trim((string)$v)), $header);
            $required = ['date','reference','description','amount','currency','type'];
            if ($header !== $required) {
                throw new RuntimeException('CSV başlıkları sırasıyla date,reference,description,amount,currency,type olmalıdır.');
            }
            $rows = [];
            $line = 1;
            while (($values = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
                $line++;
                if ($values === [null] || $values === []) continue;
                if (count($rows) >= self::MAX_ROWS) throw new RuntimeException('CSV en fazla 5.000 hareket içerebilir.');
                if (count($values) !== count($required)) throw new RuntimeException('CSV satır '.$line.' sütun sayısı geçersiz.');
                $row = array_combine($required, array_map(static fn($v): string => trim((string)$v), $values));
                if (!is_array($row)) throw new RuntimeException('CSV satırı okunamadı.');
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $row['date'], new \DateTimeZone('UTC'));
                if (!$date || $date->format('Y-m-d H:i:s') !== $row['date']) throw new RuntimeException('CSV satır '.$line.' tarihi UTC YYYY-MM-DD HH:MM:SS biçiminde olmalıdır.');
                if ($row['reference'] === '' || strlen($row['reference']) > 190) throw new RuntimeException('CSV satır '.$line.' referansı geçersiz.');
                if (strlen($row['description']) > 500) throw new RuntimeException('CSV satır '.$line.' açıklaması çok uzun.');
                if (!preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $row['amount'])) throw new RuntimeException('CSV satır '.$line.' tutarı geçersiz.');
                if (!preg_match('/^[A-Z]{3}$/D', strtoupper($row['currency']))) throw new RuntimeException('CSV satır '.$line.' para birimi geçersiz.');
                $row['date'] = $row['date'];
                $row['currency'] = strtoupper($row['currency']);
                $row['type'] = strtolower($row['type']);
                if (!in_array($row['type'], ['capture','refund','unknown'], true)) throw new RuntimeException('CSV satır '.$line.' hareket türü capture, refund veya unknown olmalıdır.');
                $rows[] = $row;
            }
            if ($rows === []) throw new RuntimeException('CSV içinde içe aktarılacak hareket bulunamadı.');
            return $rows;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Convert documented provider exports into the canonical movement schema.
     * PayTR's official sales/refund statement is JSON; iyzico transaction/cutoff
     * reports are transaction-level CSV files.
     */
    public static function parseProviderFile(string $source, string $contents): array
    {
        if (!in_array($source, ['bank','paytr','iyzico'], true)) throw new RuntimeException('Kaynak türü geçersiz.');
        $trimmed = ltrim($contents, "\xEF\xBB\xBF \t\n\r\0\x0B");
        if ($source === 'paytr' && $trimmed !== '' && in_array($trimmed[0], ['{','['], true)) {
            return self::parsePaytrJson($contents);
        }
        if ($source === 'bank') return self::parseCsv($contents);
        try {
            return self::parseCsv($contents);
        } catch (RuntimeException $canonicalError) {
            if ($source === 'iyzico') return self::parseIyzicoCsv($contents);
            return self::parsePaytrCsv($contents);
        }
    }

    /** @return list<array{date:string,reference:string,description:string,amount:string,currency:string,type:string}> */
    private static function parseIyzicoCsv(string $contents): array
    {
        $records = self::readProviderCsv($contents);
        $rows = [];
        foreach ($records as $line => $record) {
            if (trim((string)($record['transactiondate'] ?? '')) === '' && trim((string)($record['paymentid'] ?? '')) === '' && trim((string)($record['conversationid'] ?? '')) === '') continue;
            $typeValue = strtolower(trim((string)($record['transactiontype'] ?? $record['transactionstatus'] ?? $record['transaction'] ?? '')));
            $phase = strtoupper(trim((string)($record['paymentphase'] ?? '')));
            $type = match ($typeValue) {
                'refund' => 'refund',
                'payment', 'postauth' => 'capture',
                'auth' => $phase === 'PREAUTH' ? 'unknown' : 'capture',
                'preauth', 'cancel', 'cancellation' => 'unknown',
                default => 'unknown',
            };
            $reference = trim((string)($record['paymentid'] ?? ''));
            if ($reference === '') $reference = trim((string)($record['conversationid'] ?? ''));
            if ($type === 'refund' && trim((string)($record['paymentid'] ?? '')) !== '') {
                $reference = trim((string)$record['paymentid']);
            }
            $amountField = $type === 'refund' ? 'price' : 'paidprice';
            $amount = trim((string)($record[$amountField] ?? ''));
            if ($type !== 'refund' && $amount === '') $amount = trim((string)($record['price'] ?? ''));
            $currency = strtoupper(trim((string)($record['transactioncurrency'] ?? '')));
            $date = trim((string)($record['transactiondate'] ?? ''));
            if ($date === '' || $reference === '' || $amount === '' || $currency === '') {
                throw new RuntimeException('iyzico CSV satır '.$line.' için transactionDate, paymentId/conversationId, price/paidPrice ve transactionCurrency alanları zorunludur.');
            }
            $rows[] = self::providerMovement($date, $reference, 'iyzico '.($type === 'refund' ? 'iade' : ($type === 'capture' ? 'ödeme' : 'diğer hareket')), $amount, $currency, $type, $line);
        }
        if ($rows === []) throw new RuntimeException('iyzico CSV içinde işlem satırı bulunamadı.');
        if (count($rows) > self::MAX_ROWS) throw new RuntimeException('CSV en fazla 5.000 hareket içerebilir.');
        return $rows;
    }

    /** @return list<array{date:string,reference:string,description:string,amount:string,currency:string,type:string}> */
    private static function parsePaytrCsv(string $contents): array
    {
        $records = self::readProviderCsv($contents);
        $rows = [];
        foreach ($records as $line => $record) {
            $typeValue = strtoupper(trim((string)($record['islemtipi'] ?? $record['transactiontype'] ?? '')));
            $type = match ($typeValue) { 'S', 'SALE', 'PAYMENT', 'CAPTURE' => 'capture', 'I', 'REFUND' => 'refund', default => 'unknown' };
            $date = trim((string)($record['islemtarihi'] ?? $record['transactiondate'] ?? ''));
            $reference = trim((string)($record['siparisno'] ?? $record['merchantoid'] ?? $record['reference'] ?? ''));
            $amount = trim((string)($record['islemtutari'] ?? $record['odemetutari'] ?? $record['payment'] ?? ''));
            $currency = strtoupper(trim((string)($record['parabirimi'] ?? $record['currency'] ?? '')));
            if ($date === '' || $reference === '' || $amount === '' || $currency === '') {
                throw new RuntimeException('PayTR CSV satır '.$line.' için islem_tarihi, siparis_no, islem_tutari ve para_birimi alanları zorunludur.');
            }
            $rows[] = self::providerMovement($date, $reference, 'PayTR '.($type === 'refund' ? 'iade' : ($type === 'capture' ? 'satış' : 'diğer hareket')), $amount, $currency, $type, $line, true);
        }
        if ($rows === []) throw new RuntimeException('PayTR CSV içinde işlem satırı bulunamadı.');
        if (count($rows) > self::MAX_ROWS) throw new RuntimeException('CSV en fazla 5.000 hareket içerebilir.');
        return $rows;
    }

    /** @return list<array{date:string,reference:string,description:string,amount:string,currency:string,type:string}> */
    private static function parsePaytrJson(string $contents): array
    {
        if ($contents === '' || strlen($contents) > self::MAX_FILE_BYTES || str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
            throw new RuntimeException('PayTR JSON dosyası boş, geçersiz veya 2 MB sınırını aşıyor.');
        }
        try { $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new RuntimeException('PayTR JSON dosyası geçerli JSON değil.'); }
        if (!is_array($decoded) || ($decoded['status'] ?? '') !== 'success') {
            throw new RuntimeException('PayTR işlem dökümü status=success içermelidir.');
        }
        $records = $decoded['transactions'] ?? $decoded['data'] ?? $decoded['islemler'] ?? $decoded['payments'] ?? $decoded['records'] ?? $decoded['result'] ?? null;
        if (!is_array($records)) {
            $records = [];
            foreach ($decoded as $value) if (is_array($value) && (isset($value['siparis_no']) || isset($value['merchant_oid']))) $records[] = $value;
        }
        if ($records === []) throw new RuntimeException('PayTR JSON içinde işlem listesi bulunamadı.');
        if (isset($records['siparis_no']) || isset($records['merchant_oid'])) $records = [$records];
        $rows = [];
        foreach ($records as $line => $record) {
            if (!is_array($record)) throw new RuntimeException('PayTR JSON satır '.((int)$line+1).' geçersiz.');
            $typeValue = strtoupper(trim((string)($record['islem_tipi'] ?? $record['transaction_type'] ?? '')));
            $type = match ($typeValue) { 'S', 'SALE' => 'capture', 'I', 'REFUND' => 'refund', default => 'unknown' };
            $date = trim((string)($record['islem_tarihi'] ?? $record['transaction_date'] ?? ''));
            $reference = trim((string)($record['siparis_no'] ?? $record['merchant_oid'] ?? ''));
            $amount = trim((string)($record['islem_tutari'] ?? $record['odeme_tutari'] ?? ''));
            $currency = strtoupper(trim((string)($record['para_birimi'] ?? $record['currency'] ?? '')));
            if ($date === '' || $reference === '' || $amount === '' || $currency === '') {
                throw new RuntimeException('PayTR JSON satır '.((int)$line+1).' için islem_tarihi, siparis_no, islem_tutari ve para_birimi alanları zorunludur.');
            }
            $rows[] = self::providerMovement($date, $reference, 'PayTR '.($type === 'refund' ? 'iade' : ($type === 'capture' ? 'satış' : 'diğer hareket')), $amount, $currency, $type, (int)$line+1, true);
        }
        if ($rows === []) throw new RuntimeException('PayTR JSON içinde işlem satırı bulunamadı.');
        if (count($rows) > self::MAX_ROWS) throw new RuntimeException('PayTR en fazla 5.000 hareket içerebilir.');
        return $rows;
    }

    /** @return list<array<string,string>> */
    private static function readProviderCsv(string $contents): array
    {
        if ($contents === '' || strlen($contents) > self::MAX_FILE_BYTES || str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
            throw new RuntimeException('CSV dosyası boş, geçersiz veya 2 MB sınırını aşıyor.');
        }
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $firstLine = strtok($contents, "\r\n") ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new RuntimeException('CSV okunamadı.');
        try {
            fwrite($stream, $contents); rewind($stream);
            $header = fgetcsv($stream, 0, $delimiter, '"', '\\');
            if (!is_array($header) || count($header) < 2 || count($header) > 100) throw new RuntimeException('Sağlayıcı CSV başlık satırı geçersiz.');
            $keys = array_map(static fn($v): string => preg_replace('/[^a-z0-9]/', '', strtolower(trim((string)$v)) ) ?? '', $header);
            if (count(array_unique($keys)) !== count($keys)) throw new RuntimeException('Sağlayıcı CSV içinde yinelenen sütun başlığı var.');
            $records = []; $line = 1;
            while (($values = fgetcsv($stream, 0, $delimiter, '"', '\\')) !== false) {
                $line++;
                if ($values === [null] || $values === []) continue;
                if (count($records) >= self::MAX_ROWS) throw new RuntimeException('CSV en fazla 5.000 hareket içerebilir.');
                if (count($values) !== count($keys)) throw new RuntimeException('CSV satır '.$line.' sütun sayısı geçersiz.');
                $record = array_combine($keys, array_map(static fn($v): string => trim((string)$v), $values));
                if (!is_array($record)) throw new RuntimeException('CSV satırı okunamadı.');
                $records[$line] = $record;
            }
            return $records;
        } finally { fclose($stream); }
    }

    private static function providerMovement(string $date, string $reference, string $description, string $amount, string $currency, string $type, int $line, bool $dateOnlyAllowed = false): array
    {
        $date = self::normalizeProviderDate($date, $dateOnlyAllowed);
        $reference = trim($reference);
        if ($reference === '' || strlen($reference) > 190) throw new RuntimeException('Sağlayıcı satır '.$line.' referansı geçersiz.');
        if (!preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $amount)) throw new RuntimeException('Sağlayıcı satır '.$line.' tutarı geçersiz.');
        $currency = strtoupper(trim($currency));
        if ($currency === 'TL') $currency = 'TRY';
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) throw new RuntimeException('Sağlayıcı satır '.$line.' para birimi geçersiz.');
        if (!in_array($type, ['capture','refund','unknown'], true)) throw new RuntimeException('Sağlayıcı satır '.$line.' hareket türü geçersiz.');
        return ['date'=>$date,'reference'=>$reference,'description'=>substr($description,0,500),'amount'=>$amount,'currency'=>$currency,'type'=>$type];
    }

    private static function normalizeProviderDate(string $value, bool $dateOnlyAllowed): string
    {
        $value = trim($value);
        $formats = ['!Y-m-d H:i:s','!Y-m-d\TH:i:s','!Y-m-d\TH:i:sP','!Y-m-d\TH:i:s\Z','!d.m.Y H:i:s'];
        if ($dateOnlyAllowed) $formats[] = '!d.m.Y';
        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }
        throw new RuntimeException('Sağlayıcı tarihi desteklenen biçimde değil: YYYY-MM-DD HH:MM:SS veya DD.MM.YYYY.');
    }

    /** Import rows and create match suggestions only; never changes payment or invoice state. */
    public static function import(PDO $db, string $source, string $filename, string $contents, int $userId): array
    {
        if (!in_array($source, ['bank','paytr','iyzico'], true)) throw new RuntimeException('Kaynak türü geçersiz.');
        $filename = trim(basename($filename));
        if ($filename === '' || strlen($filename) > 190 || $userId < 1) throw new RuntimeException('Dosya veya kullanıcı bilgisi geçersiz.');
        $rows = self::parseProviderFile($source, $contents);
        $hash = hash('sha256', $contents);
        try {
            $db->beginTransaction();
            $insert = $db->prepare('INSERT INTO payment_statement_imports (source,original_filename,file_sha256,imported_by,total_rows,created_at) VALUES (:source,:filename,:hash,:user,:total,UTC_TIMESTAMP())');
            $insert->execute([':source'=>$source,':filename'=>$filename,':hash'=>$hash,':user'=>$userId,':total'=>count($rows)]);
            $importId = (int)$db->lastInsertId();
            $insertRow = $db->prepare('INSERT INTO payment_statement_movements (import_id,source_row_number,occurred_at,provider_reference,description,amount,currency,movement_type,match_status,suggested_payment_transaction_id,suggested_refund_id,created_at) VALUES (:import,:row,:date,:reference,:description,:amount,:currency,:type,:status,:payment,:refund,UTC_TIMESTAMP())');
            $counts = ['matched'=>0,'amount_mismatch'=>0,'ambiguous'=>0,'unmatched'=>0];
            foreach ($rows as $index => $row) {
                $candidate = self::findCandidate($db, $row, $source);
                $status = $candidate['status'];
                $counts[$status]++;
                $insertRow->execute([
                    ':import'=>$importId, ':row'=>$index+2, ':date'=>$row['date'],
                    ':reference'=>$row['reference'], ':description'=>$row['description'],
                    ':amount'=>$row['amount'], ':currency'=>$row['currency'], ':type'=>$row['type'],
                    ':status'=>$status, ':payment'=>$candidate['payment_id'], ':refund'=>$candidate['refund_id'],
                ]);
            }
            $db->commit();
            return ['import_id'=>$importId,'rows'=>count($rows),'counts'=>$counts,'file_sha256'=>$hash];
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            if ($exception instanceof \PDOException && (string)$exception->getCode() === '23000') {
                throw new RuntimeException('Bu dosya aynı kaynak için daha önce içe aktarılmış.', 0, $exception);
            }
            throw $exception;
        }
    }


    /** Record an administrator's review decision without changing payment or invoice state. */
    public static function review(PDO $db, int $movementId, string $action, int $userId, string $note = ''): array
    {
        if ($movementId < 1 || $userId < 1 || !in_array($action, ['confirm','reject','reviewed'], true)) {
            throw new RuntimeException('İnceleme bilgileri geçersiz.');
        }
        $note = trim($note);
        if (strlen($note) > 500) throw new RuntimeException('İnceleme notu en fazla 500 karakter olabilir.');
        if ($action !== 'confirm' && $note === '') throw new RuntimeException('Reddetme veya manuel inceleme için açıklama zorunludur.');
        try {
            $db->beginTransaction();
            $query = $db->prepare('SELECT id,match_status,review_status FROM payment_statement_movements WHERE id=:id FOR UPDATE');
            $query->execute([':id'=>$movementId]);
            $movement = $query->fetch(PDO::FETCH_ASSOC);
            if (!$movement) throw new RuntimeException('Ekstre hareketi bulunamadı.');
            if ($movement['review_status'] !== 'pending') throw new RuntimeException('Bu hareket daha önce incelenmiş.');
            if ($action === 'confirm' && $movement['match_status'] !== 'matched') {
                throw new RuntimeException('Yalnızca tutarı ve referansı tam eşleşen hareket onaylanabilir.');
            }
            if ($action === 'reviewed' && $movement['match_status'] === 'matched') {
                throw new RuntimeException('Tam eşleşen hareket için onayla veya reddet seçeneğini kullanın.');
            }
            $status = $action === 'confirm' ? 'confirmed' : ($action === 'reject' ? 'rejected' : 'reviewed');
            $update = $db->prepare('UPDATE payment_statement_movements SET review_status=:status,reviewed_by=:user,reviewed_at=UTC_TIMESTAMP(),review_note=:note WHERE id=:id AND review_status=\'pending\'');
            $update->execute([':status'=>$status,':user'=>$userId,':note'=>$note,':id'=>$movementId]);
            if ($update->rowCount() !== 1) throw new RuntimeException('Hareket eşzamanlı olarak başka bir yönetici tarafından incelendi.');
            $db->commit();
            return ['movement_id'=>$movementId,'review_status'=>$status,'action'=>$action];
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
    }

    private static function normalizeAmount(string $amount): string
    {
        $parts = explode('.', $amount, 2);
        $whole = ltrim($parts[0], '0');
        return ($whole === '' ? '0' : $whole) . '.' . str_pad($parts[1] ?? '', 2, '0', STR_PAD_RIGHT);
    }

    /** @return array{status:string,payment_id:?int,refund_id:?int} */
    private static function findCandidate(PDO $db, array $row, string $source): array
    {
        if ($row['type'] === 'refund') {
            if (in_array($source, ['paytr','iyzico'], true)) {
                $q = $db->prepare('SELECT pr.id AS refund_id,pr.amount,pr.currency FROM payment_refunds pr JOIN payment_transactions pt ON pt.id=pr.payment_transaction_id WHERE (pt.gateway_transaction_id=:gateway_reference OR pt.request_reference=:request_reference OR pt.idempotency_key=:idempotency_reference) AND pr.currency=:currency AND pr.amount=:amount LIMIT 2');
                $q->execute([':gateway_reference'=>$row['reference'],':request_reference'=>$row['reference'],':idempotency_reference'=>$row['reference'],':currency'=>$row['currency'],':amount'=>$row['amount']]);
            } else {
                $q = $db->prepare('SELECT pr.id AS refund_id,pr.amount,pr.currency FROM payment_refunds pr WHERE pr.provider_reference=:reference AND pr.currency=:currency LIMIT 2');
                $q->execute([':reference'=>$row['reference'],':currency'=>$row['currency']]);
            }
            $matches = $q->fetchAll(PDO::FETCH_ASSOC);
            $idField = 'refund_id';
        } elseif ($row['type'] === 'capture') {
            $q = $db->prepare("SELECT pt.id AS payment_id,pt.amount,pt.currency FROM payment_transactions pt WHERE (pt.gateway_transaction_id=:gateway_reference OR pt.request_reference=:request_reference OR (:source_iyzico='1' AND pt.idempotency_key=:idempotency_reference)) AND pt.currency=:currency AND pt.status IN ('success','partially_refunded','refunded') LIMIT 2");
            $q->execute([':gateway_reference'=>$row['reference'],':request_reference'=>$row['reference'],':source_iyzico'=>$source==='iyzico'?'1':'0',':idempotency_reference'=>$row['reference'],':currency'=>$row['currency']]);
            $matches = $q->fetchAll(PDO::FETCH_ASSOC);
            $idField = 'payment_id';
        } else {
            return ['status'=>'unmatched','payment_id'=>null,'refund_id'=>null];
        }
        if (count($matches) > 1) return ['status'=>'ambiguous','payment_id'=>null,'refund_id'=>null];
        if (count($matches) === 0) return ['status'=>'unmatched','payment_id'=>null,'refund_id'=>null];
        $match = $matches[0];
        $exactAmount = self::normalizeAmount((string)$match['amount']) === self::normalizeAmount((string)$row['amount']);
        if (!$exactAmount) return ['status'=>'amount_mismatch','payment_id'=>$idField==='payment_id'?(int)$match[$idField]:null,'refund_id'=>$idField==='refund_id'?(int)$match[$idField]:null];
        return ['status'=>'matched','payment_id'=>$idField==='payment_id'?(int)$match[$idField]:null,'refund_id'=>$idField==='refund_id'?(int)$match[$idField]:null];
    }
}
