<?php
declare(strict_types=1);

namespace HostPilot\Http;

use HostPilot\Core\Auth;
use HostPilot\Core\SecretVault;
use HostPilot\Core\SecurityAudit;
use HostPilot\Payment\GatewayTransport;
use HostPilot\Payment\StatementImport;
use PDO;
use Throwable;

final class StatementImportController
{
    private SecretVault $vault;
    public function __construct(private readonly PDO $db, private readonly string $basePath, string $applicationKey)
    {
        $this->vault = new SecretVault($applicationKey);
    }

    public function index(): void
    {
        $user=Auth::user();
        if(!$user || !in_array(($user['role']??''),['admin','super_admin','staff'],true)){http_response_code(403);echo 'Yetkisiz erişim.';return;}
        $notice=(string)($_SESSION['hostpilot_statement_notice']??'');
        $error=(string)($_SESSION['hostpilot_statement_error']??'');
        unset($_SESSION['hostpilot_statement_notice'],$_SESSION['hostpilot_statement_error']);
        $paymentSettings = $this->paymentSettings();
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!in_array(($user['role']??''),['admin','super_admin'],true)){http_response_code(403);echo 'Ekstre içe aktarma işlemi yalnızca admin tarafından yapılabilir.';return;}
            if(!Auth::verifyCsrf($_POST['_token']??null))$error='Güvenlik doğrulaması başarısız.';
            elseif(!isset($_FILES['statement'])||!is_array($_FILES['statement'])||(int)($_FILES['statement']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)$error='CSV dosyası yüklenemedi.';
            else{
                $file=$_FILES['statement'];
                if((int)($file['size']??0)>StatementImport::MAX_FILE_BYTES)$error='Dosya 2 MB sınırını aşıyor.';
                elseif(!is_uploaded_file((string)($file['tmp_name']??'')))$error='Geçersiz dosya yüklemesi.';
                else{
                    $contents=file_get_contents((string)$file['tmp_name']);
                    try{
                        if(!is_string($contents))throw new \RuntimeException('Dosya okunamadı.');
                        $source=(string)($_POST['source']??'');
                        $result=StatementImport::import($this->db,$source,(string)($file['name']??''),$contents,(int)$user['id']);
                        SecurityAudit::record($this->db,'payment.statement_imported',(int)$user['id'],['resource_id'=>(int)$result['import_id'],'action'=>'created']);
                        $_SESSION['hostpilot_statement_notice']='İçe aktarma tamamlandı. '.$result['rows'].' satır: '.json_encode($result['counts'],JSON_UNESCAPED_UNICODE);
                        header('Location: '.$this->url('/admin/finance/import'));exit;
                    }catch(Throwable $exception){$error=$exception->getMessage();}
                }
            }
        }
        $imports=$this->db->query('SELECT si.id,si.source,si.original_filename,si.total_rows,si.created_at,u.name AS admin_name,COUNT(sm.id) AS movement_count,SUM(sm.match_status=\'matched\') AS matched_count,SUM(sm.match_status=\'amount_mismatch\') AS mismatch_count,SUM(sm.match_status=\'ambiguous\') AS ambiguous_count,SUM(sm.match_status=\'unmatched\') AS unmatched_count FROM payment_statement_imports si JOIN users u ON u.id=si.imported_by LEFT JOIN payment_statement_movements sm ON sm.import_id=si.id GROUP BY si.id,si.source,si.original_filename,si.total_rows,si.created_at,u.name ORDER BY si.id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
        $movements=$this->db->query('SELECT sm.id,si.source,sm.occurred_at,sm.provider_reference,sm.amount,sm.currency,sm.movement_type,sm.match_status,sm.review_status,sm.review_note,sm.suggested_payment_transaction_id,sm.suggested_refund_id,reviewer.name AS reviewer_name FROM payment_statement_movements sm JOIN payment_statement_imports si ON si.id=sm.import_id LEFT JOIN users reviewer ON reviewer.id=sm.reviewed_by ORDER BY sm.id DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
        $this->page('Ekstre içe aktarma');
        echo '<div class="admin"><aside><a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a><a href="'.$this->url('/admin/finance').'">Finans raporları</a><a class="active" href="'.$this->url('/admin/finance/import').'">Ekstre içe aktarma</a><a href="'.$this->url('/admin/payment-transactions').'">Ödeme işlemleri</a></aside><main><header><small>FİNANS / DIŞ KAYITLAR</small><h1>Ekstre içe aktarma</h1><p>Banka, PayTR veya iyzico dışa aktarımlarını ortak CSV biçimiyle alın. Eşleşmeler yalnızca öneridir; ödeme ve fatura durumları değiştirilmez.</p></header>';
        if($notice!=='')echo '<div class="notice">'.$this->e($notice).'</div>';
        if($error!=='')echo '<div class="alert">'.$this->e($error).'</div>';
        if(in_array(($user['role']??''),['admin','super_admin'],true)) echo '<section class="box"><h2>CSV dosyası yükle</h2><p class="muted">Maksimum 2 MB / 5.000 hareket. Banka: ortak CSV başlıkları date,reference,description,amount,currency,type. PayTR: resmî Satış ve İade İşlem Dökümü servisinden kaydedilen JSON yanıtı. iyzico: SFTP işlem, cut-off veya para gönderim CSV raporu. Sağlayıcı tarihleri Europe/Istanbul kabul edilerek UTC’ye dönüştürülür. Kart sahibi adı/e-postası ve kart verileri içe aktarılmaz.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>Kaynak<select name="source" required><option value="bank">Banka ekstresi (CSV)</option><option value="paytr">PayTR işlem dökümü (JSON)</option><option value="iyzico">iyzico raporu (CSV)</option></select></label><label>Rapor dosyası<input type="file" name="statement" accept=".csv,.json,text/csv,application/json" required></label><button class="button" type="submit">İçe aktar ve eşleşmeleri öner</button></form></section>';
        if(in_array(($user['role']??''),['admin','super_admin'],true)) {
            $localToday = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
            $localStart = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Istanbul')))->modify('-1 day')->format('Y-m-d');
            if ($paymentSettings['paytr_merchant_id'] !== '' && $paymentSettings['paytr_merchant_key'] !== '' && $paymentSettings['paytr_merchant_salt'] !== '') {
                echo '<section class="box"><h2>PayTR raporunu API üzerinden getir</h2><p class="muted">Kayıtlı ve şifreli PayTR mağaza anahtarları kullanılır. İstek yalnızca bu formu gönderdiğinde yapılır. PayTR en fazla 3 günlük aralığı destekler.</p><form method="post" action="'.$this->url('/admin/finance/import/paytr-fetch').'"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>Başlangıç tarihi<input type="date" name="from" value="'.$this->e($localStart).'" required></label><label>Bitiş tarihi<input type="date" name="to" value="'.$this->e($localToday).'" required></label><button class="button" type="submit">PayTR raporunu getir ve içe aktar</button></form></section>';
            }
            if ($paymentSettings['iyzico_api_key'] !== '' && $paymentSettings['iyzico_secret_key'] !== '') {
                echo '<section class="box"><h2>iyzico günlük raporunu API üzerinden getir</h2><p class="muted">Kayıtlı ve şifreli iyzico API anahtarları kullanılır. Her günün raporu sayfalanarak alınır. En fazla 7 günlük aralık; istekler yalnızca formu gönderdiğinde yapılır.</p><form method="post" action="'.$this->url('/admin/finance/import/iyzico-fetch').'"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>Başlangıç tarihi<input type="date" name="from" value="'.$this->e($localStart).'" required></label><label>Bitiş tarihi<input type="date" name="to" value="'.$this->e($localToday).'" required></label><button class="button" type="submit">iyzico raporunu getir ve içe aktar</button></form></section>';
            }
            if (($paymentSettings['paytr_merchant_id'] === '' || $paymentSettings['paytr_merchant_key'] === '' || $paymentSettings['paytr_merchant_salt'] === '') && ($paymentSettings['iyzico_api_key'] === '' || $paymentSettings['iyzico_secret_key'] === '')) {
                echo '<section class="box"><p class="muted">API üzerinden rapor almak için önce <a href="'.$this->url('/admin/payments').'">Ödeme ayarları</a> bölümünde sağlayıcı bilgilerini kaydetmelisin. Anahtarlar tekrar görüntülenmez.</p></section>';
            }
        }
        echo '<section class="box"><h2>Son içe aktarımlar</h2><div style="overflow-x:auto"><table><thead><tr><th>Tarih</th><th>Kaynak</th><th>Dosya</th><th>Satır</th><th>Eşleşti</th><th>Tutar farklı</th><th>Belirsiz</th><th>Eşleşmedi</th></tr></thead><tbody>';
        if(!$imports)echo '<tr><td colspan="8">Henüz ekstre içe aktarılmadı.</td></tr>';
        foreach($imports as $item)echo '<tr><td>'.$this->e((string)$item['created_at']).'</td><td>'.$this->e(strtoupper((string)$item['source'])).'</td><td>'.$this->e((string)$item['original_filename']).'</td><td>'.(int)$item['total_rows'].'</td><td>'.(int)$item['matched_count'].'</td><td>'.(int)$item['mismatch_count'].'</td><td>'.(int)$item['ambiguous_count'].'</td><td>'.(int)$item['unmatched_count'].'</td></tr>';
        echo '</tbody></table></div></section>';
        echo '<section class="box"><h2>Hareket eşleşme incelemesi</h2><p class="muted">Son 200 kayıt. Onay yalnızca iç eşleşme önerisini doğrular; ödeme, iade veya fatura kayıtlarını değiştirmez. Tutar farkı ve belirsiz eşleşmeler manuel notla incelenebilir.</p><div style="overflow-x:auto"><table><thead><tr><th>Tarih</th><th>Kaynak / referans</th><th>Tutar</th><th>Hareket</th><th>Eşleşme</th><th>İnceleme</th><th>Karar</th></tr></thead><tbody>';
        if(!$movements)echo '<tr><td colspan="7">İçe aktarılmış hareket bulunamadı.</td></tr>';
        $matchLabels=['matched'=>'Tam eşleşme önerisi','amount_mismatch'=>'Tutar farklı','ambiguous'=>'Birden fazla aday','unmatched'=>'Eşleşme bulunamadı'];
        $reviewLabels=['pending'=>'Bekliyor','confirmed'=>'Eşleşme onaylandı','rejected'=>'Öneri reddedildi','reviewed'=>'Manuel incelendi'];
        foreach($movements as $movement){
            $reference=$this->e((string)$movement['provider_reference']);
            $target=$movement['suggested_payment_transaction_id']?'Ödeme #'.(int)$movement['suggested_payment_transaction_id']:($movement['suggested_refund_id']?'İade #'.(int)$movement['suggested_refund_id']:'—');
            echo '<tr><td>'.$this->e((string)$movement['occurred_at']).'</td><td>'.$this->e(strtoupper((string)$movement['source'])).'<br>'.$reference.'</td><td>'.number_format((float)$movement['amount'],2,',','.').' '.$this->e((string)$movement['currency']).'</td><td>'.$this->e((string)$movement['movement_type']).'</td><td>'.$this->e($matchLabels[$movement['match_status']]??(string)$movement['match_status']).'<br>'.$this->e($target).'</td><td>'.$this->e($reviewLabels[$movement['review_status']]??(string)$movement['review_status']).($movement['reviewer_name']?' · '.$this->e((string)$movement['reviewer_name']):'').($movement['review_note']?'<br>'.$this->e((string)$movement['review_note']):'').'</td><td>';
            if($movement['review_status']==='pending'&&in_array(($user['role']??''),['admin','super_admin'],true)){
                echo '<form method="post" action="'.$this->url('/admin/finance/import/movements/review').'"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="movement_id" value="'.(int)$movement['id'].'"><select name="action" required>';
                if($movement['match_status']==='matched')echo '<option value="confirm">Eşleşmeyi onayla</option><option value="reject">Öneriyi reddet</option>';
                else echo '<option value="reviewed">Manuel incelemeyi tamamla</option><option value="reject">Öneriyi reddet</option>';
                echo '</select><input name="note" maxlength="500" placeholder="Not (reddetme / manuel inceleme için zorunlu)"><button class="button" type="submit">Kararı kaydet</button></form>';
            }else echo '—';
            echo '</td></tr>';
        }
        echo '</tbody></table></div></section></main></div>';
        $this->endPage();
    }


    public function fetchPaytr(): void
    {
        $user = Auth::user();
        if (!$user || !in_array(($user['role'] ?? ''), ['admin','super_admin'], true)) { http_response_code(403); echo 'Bu işlem yalnızca yönetici tarafından yapılabilir.'; return; }
        if (!Auth::verifyCsrf($_POST['_token'] ?? null)) { http_response_code(419); echo 'Güvenlik doğrulaması başarısız.'; return; }
        try {
            $settings = $this->paymentSettings();
            if ($settings['paytr_merchant_id'] === '' || $settings['paytr_merchant_key'] === '' || $settings['paytr_merchant_salt'] === '') {
                throw new \RuntimeException('PayTR rapor erişimi için ödeme ayarlarında mağaza bilgileri eksik.');
            }
            [$from,$to] = $this->validatedReportRange(3);
            $start = $from.' 00:00:00';
            $end = $to.' 23:59:59';
            $merchantId = $settings['paytr_merchant_id'];
            $merchantKey = $this->vault->decrypt($settings['paytr_merchant_key']);
            $merchantSalt = $this->vault->decrypt($settings['paytr_merchant_salt']);
            $token = base64_encode(hash_hmac('sha256', $merchantId.$start.$end.$merchantSalt, $merchantKey, true));
            $response = GatewayTransport::postForm('https://www.paytr.com/rapor/islem-dokumu', [
                'merchant_id'=>$merchantId,'start_date'=>$start,'end_date'=>$end,'paytr_token'=>$token,
            ]);
            $status = strtolower((string)($response['status'] ?? ''));
            if ($status === 'failed') {
                $providerError = trim((string)($response['err_no'] ?? ''));
                if ($providerError !== '') throw new \RuntimeException('PayTR rapor servisi isteği reddetti (sağlayıcı kodu: '.substr($providerError,0,40).').');
                $_SESSION['hostpilot_statement_notice'] = 'Seçilen PayTR tarih aralığında işlem bulunamadı.';
            } elseif ($status !== 'success') {
                throw new \RuntimeException('PayTR rapor servisi isteği kabul etmedi. Mağaza yetkilerini ve tarih aralığını kontrol edin.');
            } else {
                $contents = json_encode($response, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                $result = StatementImport::import($this->db, 'paytr', 'paytr-api-'.$from.'-'.$to.'.json', $contents, (int)$user['id']);
                SecurityAudit::record($this->db,'payment.statement_imported',(int)$user['id'],['resource_id'=>(int)$result['import_id'],'action'=>'created']);
                $_SESSION['hostpilot_statement_notice'] = 'PayTR raporu alındı. '.$result['rows'].' hareket içe aktarıldı: '.json_encode($result['counts'],JSON_UNESCAPED_UNICODE);
            }
        } catch (Throwable $exception) {
            $_SESSION['hostpilot_statement_error'] = $exception->getMessage();
        }
        header('Location: '.$this->url('/admin/finance/import'));
        exit;
    }

    public function fetchIyzico(): void
    {
        $user = Auth::user();
        if (!$user || !in_array(($user['role'] ?? ''), ['admin','super_admin'], true)) { http_response_code(403); echo 'Bu işlem yalnızca yönetici tarafından yapılabilir.'; return; }
        if (!Auth::verifyCsrf($_POST['_token'] ?? null)) { http_response_code(419); echo 'Güvenlik doğrulaması başarısız.'; return; }
        try {
            $settings = $this->paymentSettings();
            if ($settings['iyzico_api_key'] === '' || $settings['iyzico_secret_key'] === '') {
                throw new \RuntimeException('iyzico rapor erişimi için ödeme ayarlarında API bilgileri eksik.');
            }
            [$from,$to,$days] = $this->validatedReportRange(7);
            $apiKey = $this->vault->decrypt($settings['iyzico_api_key']);
            $secretKey = $this->vault->decrypt($settings['iyzico_secret_key']);
            $baseUrl = $settings['iyzico_test_mode'] === '1' ? 'https://sandbox-api.iyzipay.com' : 'https://api.iyzipay.com';
            $allTransactions = [];
            $apiRequests = 0;
            for ($offset = 0; $offset < $days; $offset++) {
                $date = (new \DateTimeImmutable($from, new \DateTimeZone('Europe/Istanbul')))->modify('+'.$offset.' days')->format('Y-m-d');
                if (++$apiRequests > 50) throw new \RuntimeException('iyzico raporu 50 sayfa API sınırını aşıyor; daha kısa tarih aralığı seçin.');
                $first = GatewayTransport::getJson($baseUrl,'/v2/reporting/payment/transactions',[
                    'locale'=>'tr','page'=>1,'transactionDate'=>$date,
                ],$apiKey,$secretKey);
                if (strtolower((string)($first['status'] ?? '')) !== 'success' || !isset($first['transactions']) || !is_array($first['transactions']) || !array_is_list($first['transactions'])) {
                    throw new \RuntimeException('iyzico günlük raporu alınamadı. API yetkilerini ve seçilen tarihi kontrol edin.');
                }
                if ($first['transactions'] === []) continue;
                $totalPages = (int)($first['totalPageCount'] ?? 1);
                if ($totalPages < 1 || $totalPages > 100) throw new \RuntimeException('iyzico rapor sayfa sayısı güvenli sınırı aşıyor.');
                $allowedFields = array_flip(['transactionType','transactionDate','transactionId','paymentTxId','paymentId','paidPrice','price','transactionCurrency','transactionStatus']);
                foreach ($first['transactions'] as $transaction) {
                    if (!is_array($transaction)) throw new \RuntimeException('iyzico raporunda geçersiz hareket bulundu.');
                    $allTransactions[] = array_intersect_key($transaction,$allowedFields);
                }
                if (count($allTransactions) > StatementImport::MAX_ROWS) throw new \RuntimeException('iyzico raporu 5.000 hareket sınırını aşıyor; daha kısa tarih aralığı seçin.');
                for ($page = 2; $page <= $totalPages; $page++) {
                    if (++$apiRequests > 50) throw new \RuntimeException('iyzico raporu 50 sayfa API sınırını aşıyor; daha kısa tarih aralığı seçin.');
                    $response = GatewayTransport::getJson($baseUrl,'/v2/reporting/payment/transactions',[
                        'locale'=>'tr','page'=>$page,'transactionDate'=>$date,
                    ],$apiKey,$secretKey);
                    if (strtolower((string)($response['status'] ?? '')) !== 'success' || !isset($response['transactions']) || !is_array($response['transactions']) || !array_is_list($response['transactions'])) {
                        throw new \RuntimeException('iyzico raporunun sonraki sayfası alınamadı.');
                    }
                    foreach ($response['transactions'] as $transaction) {
                        if (!is_array($transaction)) throw new \RuntimeException('iyzico raporunda geçersiz hareket bulundu.');
                        $allTransactions[] = array_intersect_key($transaction,$allowedFields);
                    }
                    if (count($allTransactions) > StatementImport::MAX_ROWS) throw new \RuntimeException('iyzico raporu 5.000 hareket sınırını aşıyor; daha kısa tarih aralığı seçin.');
                }
                if (count($allTransactions) > StatementImport::MAX_ROWS) throw new \RuntimeException('iyzico raporu 5.000 hareket sınırını aşıyor; daha kısa tarih aralığı seçin.');
            }
            if ($allTransactions === []) {
                $_SESSION['hostpilot_statement_notice'] = 'Seçilen iyzico tarih aralığında işlem bulunamadı.';
            } else {
                $contents = json_encode(['status'=>'success','transactions'=>$allTransactions],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                $result = StatementImport::import($this->db,'iyzico','iyzico-api-'.$from.'-'.$to.'.json',$contents,(int)$user['id']);
                SecurityAudit::record($this->db,'payment.statement_imported',(int)$user['id'],['resource_id'=>(int)$result['import_id'],'action'=>'created']);
                $_SESSION['hostpilot_statement_notice'] = 'iyzico raporu alındı. '.$result['rows'].' hareket içe aktarıldı: '.json_encode($result['counts'],JSON_UNESCAPED_UNICODE);
            }
        } catch (Throwable $exception) {
            $_SESSION['hostpilot_statement_error'] = $exception->getMessage();
        }
        header('Location: '.$this->url('/admin/finance/import'));
        exit;
    }

    /** @return array{0:string,1:string,2:int} */
    private function validatedReportRange(int $maxDays): array
    {
        $fromRaw = trim((string)($_POST['from'] ?? ''));
        $toRaw = trim((string)($_POST['to'] ?? ''));
        $zone = new \DateTimeZone('Europe/Istanbul');
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d',$fromRaw,$zone);
        $fromErrors = \DateTimeImmutable::getLastErrors();
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d',$toRaw,$zone);
        $toErrors = \DateTimeImmutable::getLastErrors();
        if ($from === false || $to === false || ($fromErrors !== false && ($fromErrors['warning_count']>0 || $fromErrors['error_count']>0)) || ($toErrors !== false && ($toErrors['warning_count']>0 || $toErrors['error_count']>0)) || $from->format('Y-m-d') !== $fromRaw || $to->format('Y-m-d') !== $toRaw || $from > $to) {
            throw new \RuntimeException('Başlangıç ve bitiş tarihlerini kontrol edin.');
        }
        $days = (int)$from->diff($to)->days + 1;
        if ($days > $maxDays) throw new \RuntimeException('Seçilen sağlayıcı raporu en fazla '.$maxDays.' günlük aralık içerebilir.');
        return [$fromRaw,$toRaw,$days];
    }

    private function paymentSettings(): array
    {
        $defaults = ['paytr_merchant_id'=>'','paytr_merchant_key'=>'','paytr_merchant_salt'=>'','iyzico_api_key'=>'','iyzico_secret_key'=>'','iyzico_test_mode'=>'1'];
        $query = $this->db->query("SELECT setting_key,setting_value FROM settings WHERE setting_group='payment'");
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (array_key_exists((string)$row['setting_key'],$defaults)) $defaults[(string)$row['setting_key']] = (string)$row['setting_value'];
        }
        return $defaults;
    }

    public function review(): void
    {
        $user = Auth::user();
        if (!$user || !in_array(($user['role'] ?? ''), ['admin','super_admin'], true)) {
            http_response_code(403);
            echo 'Ekstre hareketlerini yalnızca yönetici inceleyebilir.';
            return;
        }
        if (!Auth::verifyCsrf($_POST['_token'] ?? null)) {
            http_response_code(419);
            echo 'Güvenlik doğrulaması başarısız.';
            return;
        }
        try {
            $result = StatementImport::review($this->db, (int)($_POST['movement_id'] ?? 0), (string)($_POST['action'] ?? ''), (int)$user['id'], (string)($_POST['note'] ?? ''));
            SecurityAudit::record($this->db, 'payment.statement_movement_reviewed', (int)$user['id'], ['resource_id'=>(int)$result['movement_id'],'action'=>(string)$result['action']]);
            $_SESSION['hostpilot_statement_notice'] = 'Ekstre hareketi inceleme kararı kaydedildi.';
        } catch (Throwable $exception) {
            $_SESSION['hostpilot_statement_error'] = $exception->getMessage();
        }
        header('Location: '.$this->url('/admin/finance/import'));
        exit;
    }

    private function page(string $title):void{echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->e($title).' · HostPilot</title><style>body{margin:0;background:#0a1020;color:#e8edf7;font:15px system-ui}*{box-sizing:border-box}.admin{display:flex;min-height:100vh}.admin aside{width:240px;background:#0e1729;padding:24px}.admin aside a{display:block;color:#aab7cc;padding:12px;text-decoration:none}.admin aside .active{background:#1b2a44}.admin main{padding:30px;max-width:1100px;width:100%}.admin header small,.muted{color:#8fa6d8}.admin .box{background:#111c2f;border:1px solid #26354c;border-radius:14px;padding:24px;margin:18px 0}.admin label{display:block;margin:14px 0}.admin input,.admin select{display:block;width:100%;padding:12px;margin-top:6px;background:#0b1425;border:1px solid #34445e;border-radius:8px;color:#fff}.button{background:#5278ed;color:white;border:0;border-radius:8px;padding:12px 18px}.notice,.alert{padding:12px;border-radius:8px;margin:12px 0}.notice{background:#183b32}.alert{background:#4b202b}.admin table{width:100%;border-collapse:collapse}.admin th,.admin td{text-align:left;padding:10px;border-bottom:1px solid #26354c}@media(max-width:700px){.admin aside{width:65px;padding:10px}.admin aside a{font-size:0}.admin main{padding:14px}}</style></head><body>';}
    private function endPage():void{echo '</body></html>';}
    private function url(string $path):string{return htmlspecialchars(rtrim($this->basePath,'/').$path,ENT_QUOTES,'UTF-8');}
    private function e(string $value):string{return \HostPilot\Core\Html::escape($value);}
}
