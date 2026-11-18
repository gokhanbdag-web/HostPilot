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
     * Parse provider-native exports into the internal statement movement format.
     * PayTR's documented transaction report is JSON; iyzico's SFTP reports are CSV.
     */
    public static function parseFile(string $contents, string $source): array
    {
        return match ($source) {
            'bank' => self::parseCsv($contents),
            'paytr' => self::parsePaytrJson($contents),
            'iyzico' => (str_starts_with(ltrim($contents), '{') ? self::parseIyzicoJson($contents) : self::parseIyzicoCsv($contents)),
            default => throw new RuntimeException('Ekstre kaynağı geçersiz.'),
        };
    }

    /**
     * Convert a provider-native report into the canonical, spreadsheet-friendly CSV.
     * The same strict provider parsers used by import validate every movement.
     */
    public static function toCanonicalCsv(string $contents, string $source): string
    {
        $rows = self::parseFile($contents, $source);
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new RuntimeException('Standart CSV oluşturulamadı.');
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['date','reference','description','amount','currency','type'], ',', '"', '');
            foreach ($rows as $row) {
                $values = [];
                foreach (['date','reference','description','amount','currency','type'] as $field) {
                    $value = (string)$row[$field];
                    if (in_array($field, ['reference','description'], true)
                        && preg_match('/^[\s]*[=+@-]/u', $value)) {
                        $value = "'" . $value;
                    }
                    $values[] = $value;
                }
                fputcsv($stream, $values, ',', '"', '');
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            if (!is_string($csv)) throw new RuntimeException('Standart CSV okunamadı.');
            return $csv;
        } finally {
            fclose($stream);
        }
    }

    /** Parse the official iyzico daily reporting API response. */
    private static function parseIyzicoJson(string $contents): array
    {
        if ($contents === '' || strlen($contents) > self::MAX_FILE_BYTES) throw new RuntimeException('iyzico JSON raporu boş veya 2 MB sınırını aşıyor.');
        if (str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) throw new RuntimeException('iyzico JSON raporu geçerli UTF-8 olmalıdır.');
        try { $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException $exception) { throw new RuntimeException('iyzico raporu geçerli JSON değil.', 0, $exception); }
        if (!is_array($decoded) || strtolower((string)($decoded['status'] ?? '')) !== 'success' || !isset($decoded['transactions']) || !is_array($decoded['transactions']) || !array_is_list($decoded['transactions'])) {
            throw new RuntimeException('iyzico rapor yanıtı başarılı değil veya işlem listesi bulunamadı.');
        }
        if ($decoded['transactions'] === [] || count($decoded['transactions']) > self::MAX_ROWS) throw new RuntimeException('iyzico raporu boş veya 5.000 hareket sınırını aşıyor.');
        $rows = [];
        foreach ($decoded['transactions'] as $index => $transaction) {
            if (!is_array($transaction)) throw new RuntimeException('iyzico işlem kaydı geçersiz.');
            $providerType = strtoupper(trim((string)($transaction['transactionType'] ?? '')));
            $type = match ($providerType) {
                'PAYMENT' => (in_array((int)($transaction['transactionStatus'] ?? 0), [1, 2], true) ? 'capture' : 'unknown'),
                'REFUND' => 'refund',
                default => 'unknown',
            };
            // Use the parent payment ID so a refund can be matched to its
            // exact recorded refund event by payment reference and event amount.
            $reference = trim((string)($transaction['paymentId'] ?? $transaction['conversationId'] ?? ''));
            $currency = strtoupper(trim((string)($transaction['transactionCurrency'] ?? '')));
            $amountValue = $type === 'refund'
                ? (string)($transaction['price'] ?? '')
                : (string)($transaction['paidPrice'] ?? $transaction['price'] ?? '');
            $amount = trim($amountValue);
            if ($reference === '' || strlen($reference) > 190 || !preg_match('/^[A-Z]{3}$/D', $currency)) {
                throw new RuntimeException('iyzico API işlem '.($index + 1).' referans veya para birimi alanı geçersiz.');
            }
            if (!preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $amount)) {
                throw new RuntimeException('iyzico API işlem '.($index + 1).' tutarı geçersiz.');
            }
            $date = self::providerDateToUtc(trim((string)($transaction['transactionDate'] ?? '')), $index + 1, 'iyzico');
            $rows[] = [
                'date'=>$date,'reference'=>$reference,
                'description'=>'iyzico API '.strtolower($providerType),
                'amount'=>$amount,'currency'=>$currency,'type'=>$type,
            ];
        }
        return $rows;
    }

    /** @return list<array{date:string,reference:string,description:string,amount:string,currency:string,type:string}> */
    private static function parseIyzicoCsv(string $contents): array
    {
        if ($contents === '' || strlen($contents) > self::MAX_FILE_BYTES) throw new RuntimeException('iyzico CSV dosyası boş veya 2 MB sınırını aşıyor.');
        if (str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) throw new RuntimeException('iyzico CSV dosyası geçerli UTF-8 olmalıdır.');
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $firstLine = strtok($contents, "\r\n") ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new RuntimeException('iyzico CSV okunamadı.');
        try {
            fwrite($stream, $contents);
            rewind($stream);
            $header = fgetcsv($stream, 0, $delimiter, '"', '\\');
            if (!is_array($header)) throw new RuntimeException('iyzico CSV başlık satırı bulunamadı.');
            $header = array_map(static fn($v): string => strtolower(trim((string)$v)), $header);
            $columns = array_flip($header);
            foreach (['transactiondate','transactiontype','paymentid','paidprice','transactioncurrency'] as $required) {
                if (!array_key_exists($required, $columns)) throw new RuntimeException('iyzico raporunda gerekli kolon bulunamadı: '.$required);
            }
            $rows = [];
            $line = 1;
            while (($values = fgetcsv($stream, 0, $delimiter, '"', '\\')) !== false) {
                $line++;
                if ($values === [null] || $values === []) continue;
                $typeValue = isset($columns['transactiontype']) ? trim((string)($values[$columns['transactiontype']] ?? '')) : '';
                $normalizedType = strtolower($typeValue);
                $knownTypes = ['auth','preauth','postauth','refund','cancel'];
                $paymentReference = trim((string)($values[$columns['paymentid']] ?? ''));
                $transactionReference = isset($columns['paymenttxid']) ? trim((string)($values[$columns['paymenttxid']] ?? '')) : '';
                // Cut-off and settlement exports can append a summary block after the transaction rows.
                if (!in_array($normalizedType, $knownTypes, true) && $paymentReference === '' && $transactionReference === '') continue;
                if (count($values) !== count($header)) throw new RuntimeException('iyzico CSV satır '.$line.' sütun sayısı geçersiz.');
                if ($typeValue === '') throw new RuntimeException('iyzico CSV satır '.$line.' işlem türü eksik.');
                if (count($rows) >= self::MAX_ROWS) throw new RuntimeException('iyzico CSV en fazla 5.000 hareket içerebilir.');
                $value = static fn(string $key): string => isset($columns[$key]) ? trim((string)($values[$columns[$key]] ?? '')) : '';
                $providerType = strtolower($value('transactiontype'));
                $type = match ($providerType) {
                    'auth', 'postauth' => 'capture',
                    'refund' => 'refund',
                    'preauth', 'cancel' => 'unknown',
                    default => 'unknown',
                };
                // paymentId is the parent payment identifier returned by iyzico and
                // matches HostPilot's gateway_transaction_id. paymentTxId and
                // refundConversationId identify a provider-side item/refund request.
                $reference = $value('paymentid') !== '' ? $value('paymentid') : $value('conversationid');
                if ($reference === '' || strlen($reference) > 190) throw new RuntimeException('iyzico CSV satır '.$line.' işlem referansı geçersiz.');
                // In iyzico refund rows, price is the refund event amount; paidPrice
                // remains the original payment's final amount.
                $amount = $type === 'refund'
                    ? $value('price')
                    : ($value('paidprice') !== '' ? $value('paidprice') : $value('price'));
                if (!preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $amount)) throw new RuntimeException('iyzico CSV satır '.$line.' tutarı geçersiz.');
                $currency = strtoupper($value('transactioncurrency'));
                if (!preg_match('/^[A-Z]{3}$/D', $currency)) throw new RuntimeException('iyzico CSV satır '.$line.' para birimi geçersiz.');
                $date = self::providerDateToUtc($value('transactiondate'), $line, 'iyzico');
                $rows[] = [
                    'date' => $date,
                    'reference' => $reference,
                    'description' => 'iyzico '.$providerType,
                    'amount' => $amount,
                    'currency' => $currency,
                    'type' => $type,
                ];
            }
            if ($rows === []) throw new RuntimeException('iyzico CSV içinde içe aktarılabilir işlem bulunamadı.');
            return $rows;
        } finally {
            fclose($stream);
        }
    }

    /** @return list<array{date:string,reference:string,description:string,amount:string,currency:string,type:string}> */
    private static function parsePaytrJson(string $contents): array
    {
        if ($contents === '' || strlen($contents) > self::MAX_FILE_BYTES) throw new RuntimeException('PayTR JSON dosyası boş veya 2 MB sınırını aşıyor.');
        if (str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) throw new RuntimeException('PayTR JSON dosyası geçerli UTF-8 olmalıdır.');
        try {
            $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('PayTR işlem dökümü geçerli JSON değil.', 0, $exception);
        }
        if (!is_array($decoded)) throw new RuntimeException('PayTR işlem dökümü biçimi geçersiz.');
        if (isset($decoded['status']) && strtolower((string)$decoded['status']) !== 'success') {
            throw new RuntimeException('PayTR raporunda başarılı işlem verisi bulunmuyor.');
        }
        $records = array_is_list($decoded) ? $decoded : null;
        if ($records === null) {
            foreach (['data','transactions','islemler','result'] as $key) {
                if (isset($decoded[$key]) && is_array($decoded[$key]) && array_is_list($decoded[$key])) {
                    $records = $decoded[$key];
                    break;
                }
            }
        }
        if (!is_array($records)) throw new RuntimeException('PayTR JSON içinde işlem listesi bulunamadı.');
        $rows = [];
        foreach ($records as $index => $record) {
            if (!is_array($record)) throw new RuntimeException('PayTR JSON işlem satırı geçersiz.');
            if (count($rows) >= self::MAX_ROWS) throw new RuntimeException('PayTR raporu en fazla 5.000 hareket içerebilir.');
            $reference = trim((string)($record['siparis_no'] ?? ''));
            $providerType = strtoupper(trim((string)($record['islem_tipi'] ?? '')));
            $amount = trim((string)($record['islem_tutari'] ?? ''));
            $dateValue = trim((string)($record['islem_tarihi'] ?? ''));
            $currencyValue = strtoupper(trim((string)($record['para_birimi'] ?? '')));
            $currency = match ($currencyValue) {
                'TL', 'TRY' => 'TRY',
                'USD' => 'USD',
                'EUR' => 'EUR',
                'GBP' => 'GBP',
                'RUB' => 'RUB',
                default => '',
            };
            if ($reference === '' || strlen($reference) > 190) throw new RuntimeException('PayTR JSON işlem '.($index + 1).' sipariş referansı geçersiz.');
            if (!in_array($providerType, ['S','I'], true)) throw new RuntimeException('PayTR JSON işlem '.($index + 1).' işlem türü S veya I olmalıdır.');
            if (!preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $amount)) throw new RuntimeException('PayTR JSON işlem '.($index + 1).' tutarı geçersiz.');
            if ($currency === '') throw new RuntimeException('PayTR JSON işlem '.($index + 1).' para birimi desteklenmiyor.');
            $date = self::providerDateToUtc($dateValue, $index + 1, 'PayTR');
            $rows[] = [
                'date' => $date,
                'reference' => $reference,
                'description' => $providerType === 'S' ? 'PayTR satış işlemi' : 'PayTR iade işlemi',
                'amount' => $amount,
                'currency' => $currency,
                'type' => $providerType === 'S' ? 'capture' : 'refund',
            ];
        }
        if ($rows === []) throw new RuntimeException('PayTR JSON içinde içe aktarılabilir işlem bulunamadı.');
        return $rows;
    }

    private static function providerDateToUtc(string $value, int $line, string $provider): string
    {
        $timezone = new \DateTimeZone('Europe/Istanbul');
        foreach (['!Y-m-d H:i:s','!Y-m-d','!d.m.Y H:i:s','!d.m.Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, $timezone);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }
        throw new RuntimeException($provider.' raporu satır '.$line.' tarihi tanınan biçimde değil.');
    }

    /** Import rows and create match suggestions only; never changes payment or invoice state. */
    public static function import(PDO $db, string $source, string $filename, string $contents, int $userId): array
    {
        if (!in_array($source, ['bank','paytr','iyzico'], true)) throw new RuntimeException('Kaynak türü geçersiz.');
        $filename = trim(basename($filename));
        if ($filename === '' || strlen($filename) > 190 || $userId < 1) throw new RuntimeException('Dosya veya kullanıcı bilgisi geçersiz.');
        $rows = self::parseFile($contents, $source);
        $hash = hash('sha256', $contents);
        try {
            $db->beginTransaction();
            $insert = $db->prepare('INSERT INTO payment_statement_imports (source,original_filename,file_sha256,imported_by,total_rows,created_at) VALUES (:source,:filename,:hash,:user,:total,UTC_TIMESTAMP())');
            $insert->execute([':source'=>$source,':filename'=>$filename,':hash'=>$hash,':user'=>$userId,':total'=>count($rows)]);
            $importId = (int)$db->lastInsertId();
            $insertRow = $db->prepare('INSERT INTO payment_statement_movements (import_id,source_row_number,occurred_at,provider_reference,description,amount,currency,movement_type,match_status,suggested_payment_transaction_id,suggested_refund_id,created_at) VALUES (:import,:row,:date,:reference,:description,:amount,:currency,:type,:status,:payment,:refund,UTC_TIMESTAMP())');
            $counts = ['matched'=>0,'amount_mismatch'=>0,'ambiguous'=>0,'unmatched'=>0];
            foreach ($rows as $index => $row) {
                $candidate = self::findCandidate($db, $row);
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
    private static function findCandidate(PDO $db, array $row): array
    {
        if ($row['type'] === 'refund') {
            $q = $db->prepare('SELECT DISTINCT pr.id AS refund_id,pr.amount,pr.currency FROM payment_refunds pr JOIN payment_transactions pt ON pt.id=pr.payment_transaction_id WHERE (pr.provider_reference=:reference OR pt.request_reference=:order_reference OR pt.gateway_transaction_id=:gateway_reference OR pt.idempotency_key=:idempotency_reference) AND pr.currency=:currency AND pr.amount=:amount LIMIT 2');
            $q->execute([':reference'=>$row['reference'],':order_reference'=>$row['reference'],':gateway_reference'=>$row['reference'],':idempotency_reference'=>$row['reference'],':currency'=>$row['currency'],':amount'=>$row['amount']]);
            $matches = $q->fetchAll(PDO::FETCH_ASSOC);
            if (count($matches) === 0) {
                $fallback = $db->prepare('SELECT DISTINCT pr.id AS refund_id,pr.amount,pr.currency FROM payment_refunds pr JOIN payment_transactions pt ON pt.id=pr.payment_transaction_id WHERE (pr.provider_reference=:reference OR pt.request_reference=:order_reference OR pt.gateway_transaction_id=:gateway_reference OR pt.idempotency_key=:idempotency_reference) AND pr.currency=:currency LIMIT 2');
                $fallback->execute([':reference'=>$row['reference'],':order_reference'=>$row['reference'],':gateway_reference'=>$row['reference'],':idempotency_reference'=>$row['reference'],':currency'=>$row['currency']]);
                $matches = $fallback->fetchAll(PDO::FETCH_ASSOC);
            }
            $idField = 'refund_id';
        } elseif ($row['type'] === 'capture') {
            $q = $db->prepare("SELECT pt.id AS payment_id,pt.amount,pt.currency FROM payment_transactions pt WHERE (pt.gateway_transaction_id=:gateway_reference OR pt.request_reference=:request_reference OR pt.idempotency_key=:idempotency_reference) AND pt.currency=:currency AND pt.status IN ('success','partially_refunded','refunded') LIMIT 2");
            $q->execute([':gateway_reference'=>$row['reference'],':request_reference'=>$row['reference'],':idempotency_reference'=>$row['reference'],':currency'=>$row['currency']]);
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
