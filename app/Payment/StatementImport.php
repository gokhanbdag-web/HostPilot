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
     * Parse provider-native exports into the canonical movement shape.
     * PayTR's official transaction detail endpoint returns JSON; iyzico SFTP reports are CSV.
     */
    public static function parseUpload(string $source, string $contents): array
    {
        return match ($source) {
            'bank' => self::parseCsv($contents),
            'paytr' => self::parsePaytrJson($contents),
            'iyzico' => self::parseIyzicoCsv($contents),
            default => throw new RuntimeException('Ekstre kaynağı geçersiz.'),
        };
    }

    /** @return list<array{date:string,reference:string,description:string,amount:string,currency:string,type:string}> */
    public static function parsePaytrJson(string $contents): array
    {
        self::assertFileContents($contents, 'PayTR JSON');
        $data = json_decode(preg_replace('/^\\xEF\\xBB\\xBF/', '', $contents) ?? $contents, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
            throw new RuntimeException('PayTR dosyası başarılı bir Satış ve İade İşlem Dökümü yanıtı değil.');
        }
        $records = null;
        foreach (['transactions', 'islemler', 'data', 'result', 'details', 'islem_detaylari'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $records = array_is_list($data[$key]) ? $data[$key] : null;
                if ($records !== null) break;
            }
        }
        if ($records === null && array_is_list($data)) $records = $data;
        if ($records === null) {
            foreach (['islem_tipi','siparis_no','islem_tarihi','islem_tutari','para_birimi'] as $key) {
                if (!array_key_exists($key, $data)) throw new RuntimeException('PayTR işlem listesi bulunamadı veya dosya biçimi tanınmadı.');
            }
            $records = [$data];
        }
        if ($records === [] || count($records) > self::MAX_ROWS) throw new RuntimeException('PayTR raporu boş veya 5.000 hareket sınırını aşıyor.');
        $rows = [];
        foreach ($records as $index => $record) {
            if (!is_array($record)) throw new RuntimeException('PayTR raporunda geçersiz kayıt bulundu.');
            $typeCode = strtoupper(trim((string)($record['islem_tipi'] ?? '')));
            $type = match ($typeCode) { 'S' => 'capture', 'I' => 'refund', default => 'unknown' };
            $currency = strtoupper(trim((string)($record['para_birimi'] ?? '')));
            if ($currency === 'TL') $currency = 'TRY';
            $date = self::providerDate((string)($record['islem_tarihi'] ?? ''));
            $reference = trim((string)($record['siparis_no'] ?? ''));
            $amount = self::providerAmount((string)($record['islem_tutari'] ?? ''));
            if ($reference === '' || strlen($reference) > 190 || !preg_match('/^[A-Z]{3}$/D', $currency)) {
                throw new RuntimeException('PayTR raporu satır '.($index+1).' referans veya para birimi alanı geçersiz.');
            }
            $description = 'PayTR '.($typeCode === 'S' ? 'satış' : ($typeCode === 'I' ? 'iade' : 'tanımsız hareket'));
            $paymentType = trim((string)($record['odeme_tipi'] ?? ''));
            if ($paymentType !== '') $description .= ' · '.substr($paymentType, 0, 40);
            $rows[] = ['date'=>$date,'reference'=>$reference,'description'=>$description,'amount'=>$amount,'currency'=>$currency,'type'=>$type];
        }
        return $rows;
    }

    /** @return list<array{date:string,reference:string,description:string,amount:string,currency:string,type:string}> */
    public static function parseIyzicoCsv(string $contents): array
    {
        self::assertFileContents($contents, 'iyzico CSV');
        $contents = preg_replace('/^\\xEF\\xBB\\xBF/', '', $contents) ?? $contents;
        $firstLine = strtok($contents, "\r\n");
        if (!is_string($firstLine) || $firstLine === '') throw new RuntimeException('iyzico CSV başlık satırı bulunamadı.');
        $delimiters = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\\t" => substr_count($firstLine, "\\t")];
        arsort($delimiters);
        $delimiter = (string)array_key_first($delimiters);
        if (($delimiters[$delimiter] ?? 0) < 2) throw new RuntimeException('iyzico CSV ayıracı tanınamadı.');
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new RuntimeException('iyzico CSV okunamadı.');
        try {
            fwrite($stream, $contents);
            rewind($stream);
            $header = fgetcsv($stream, 0, $delimiter, '"', '\\');
            if (!is_array($header)) throw new RuntimeException('iyzico CSV başlıkları okunamadı.');
            $header = array_map(static fn($v): string => strtolower(trim((string)$v)), $header);
            $positions = [];
            foreach ($header as $index => $name) $positions[$name] = $index;
            foreach (['transactiondate','transactiontype','paymentid','transactioncurrency'] as $required) {
                if (!array_key_exists($required, $positions)) throw new RuntimeException('iyzico SFTP raporu zorunlu alanı içermiyor: '.$required);
            }
            if (!isset($positions['paidprice']) && !isset($positions['price'])) throw new RuntimeException('iyzico SFTP raporunda paidPrice veya price alanı bulunamadı.');
            $rows = [];
            $line = 1;
            while (($values = fgetcsv($stream, 0, $delimiter, '"', '\\')) !== false) {
                $line++;
                if ($values === [null] || $values === []) continue;
                if (count($rows) >= self::MAX_ROWS) throw new RuntimeException('iyzico raporu 5.000 hareket sınırını aşıyor.');
                $get = static function (string $key) use ($positions, $values): string {
                    return isset($positions[$key]) ? trim((string)($values[$positions[$key]] ?? '')) : '';
                };
                $rawDate = $get('transactiondate');
                if ($rawDate === '') continue; // iyzico cut-off/settlement CSV may append a summary row.
                $date = self::providerDate($rawDate);
                $providerType = strtolower($get('transactiontype'));
                $type = match ($providerType) { 'auth','postauth' => 'capture', 'refund' => 'refund', default => 'unknown' };
                $reference = $providerType === 'refund'
                    ? ($get('refundconversationid') !== '' ? $get('refundconversationid') : ($get('paymenttxid') !== '' ? $get('paymenttxid') : $get('paymentid')))
                    : $get('paymentid');
                $currency = strtoupper($get('transactioncurrency'));
                $amount = self::providerAmount($get('paidprice') !== '' ? $get('paidprice') : $get('price'));
                if ($reference === '' || strlen($reference) > 190 || !preg_match('/^[A-Z]{3}$/D', $currency)) {
                    throw new RuntimeException('iyzico CSV satır '.$line.' referans veya para birimi alanı geçersiz.');
                }
                $rows[] = [
                    'date'=>$date,'reference'=>$reference,
                    'description'=>'iyzico '.substr($providerType, 0, 40).' · ödeme '.substr($get('paymentid'), 0, 80),
                    'amount'=>$amount,'currency'=>$currency,'type'=>$type,
                ];
            }
            if ($rows === []) throw new RuntimeException('iyzico CSV içinde içe aktarılacak hareket bulunamadı.');
            return $rows;
        } finally {
            fclose($stream);
        }
    }

    private static function assertFileContents(string $contents, string $label): void
    {
        if ($contents === '' || strlen($contents) > self::MAX_FILE_BYTES) throw new RuntimeException($label.' dosyası boş veya 2 MB sınırını aşıyor.');
        if (str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) throw new RuntimeException($label.' dosyası geçerli UTF-8 metin olmalıdır.');
    }

    private static function providerAmount(string $amount): string
    {
        $amount = trim($amount);
        if (!preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\\.[0-9]{1,2})?$/D', $amount)) throw new RuntimeException('Sağlayıcı raporunda tutar biçimi geçersiz.');
        return self::normalizeAmount($amount);
    }

    private static function providerDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') throw new RuntimeException('Sağlayıcı raporunda işlem tarihi bulunamadı.');
        try {
            $parsed = date_parse($value);
            if (($parsed['error_count'] ?? 0) > 0 || ($parsed['warning_count'] ?? 0) > 0) throw new RuntimeException('invalid date');
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw new RuntimeException('Sağlayıcı raporunda işlem tarihi tanınamadı.');
        }
    }

    /** Import rows and create match suggestions only; never changes payment or invoice state. */
    public static function import(PDO $db, string $source, string $filename, string $contents, int $userId): array
    {
        if (!in_array($source, ['bank','paytr','iyzico'], true)) throw new RuntimeException('Kaynak türü geçersiz.');
        $filename = trim(basename($filename));
        if ($filename === '' || strlen($filename) > 190 || $userId < 1) throw new RuntimeException('Dosya veya kullanıcı bilgisi geçersiz.');
        $rows = self::parseUpload($source, $contents);
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
            $q = $db->prepare('SELECT DISTINCT pr.id AS refund_id,pr.amount,pr.currency FROM payment_refunds pr JOIN payment_transactions pt ON pt.id=pr.payment_transaction_id WHERE (pr.provider_reference=:reference OR pt.request_reference=:order_reference OR pt.gateway_transaction_id=:gateway_reference) AND pr.currency=:currency LIMIT 2');
            $q->execute([':reference'=>$row['reference'],':order_reference'=>$row['reference'],':gateway_reference'=>$row['reference'],':currency'=>$row['currency']]);
            $matches = $q->fetchAll(PDO::FETCH_ASSOC);
            $idField = 'refund_id';
        } elseif ($row['type'] === 'capture') {
            $q = $db->prepare("SELECT pt.id AS payment_id,pt.amount,pt.currency FROM payment_transactions pt WHERE (pt.gateway_transaction_id=:gateway_reference OR pt.request_reference=:request_reference) AND pt.currency=:currency AND pt.status IN ('success','partially_refunded','refunded') LIMIT 2");
            $q->execute([':gateway_reference'=>$row['reference'],':request_reference'=>$row['reference'],':currency'=>$row['currency']]);
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
