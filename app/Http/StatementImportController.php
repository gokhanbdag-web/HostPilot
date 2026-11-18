<?php
declare(strict_types=1);

namespace HostPilot\Http;

use HostPilot\Core\Auth;
use HostPilot\Core\SecurityAudit;
use HostPilot\Payment\StatementImport;
use PDO;
use Throwable;

final class StatementImportController
{
    public function __construct(private readonly PDO $db, private readonly string $basePath) {}

    public function index(): void
    {
        $user=Auth::user();
        if(!$user || !in_array(($user['role']??''),['admin','super_admin','staff'],true)){http_response_code(403);echo 'Yetkisiz erişim.';return;}
        $notice=(string)($_SESSION['hostpilot_statement_notice']??'');
        $error=(string)($_SESSION['hostpilot_statement_error']??'');
        unset($_SESSION['hostpilot_statement_notice'],$_SESSION['hostpilot_statement_error']);
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
        if(in_array(($user['role']??''),['admin','super_admin'],true)) echo '<section class="box"><h2>CSV dosyası yükle</h2><p class="muted">Banka: ortak CSV. iyzico: işlem veya cut-off raporunun işlem satırlarını içeren CSV dosyası. PayTR: Satış ve İade İşlem Dökümü Servisi JSON yanıtı (status=success). Maksimum 2 MB / 5.000 satır. Sağlayıcı dosyaları otomatik tanınır; ortak CSV de desteklenir.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>Kaynak<select name="source" required><option value="bank">Banka ekstresi</option><option value="paytr">PayTR raporu</option><option value="iyzico">iyzico raporu</option></select></label><label>CSV / JSON dosyası<input type="file" name="statement" accept=".csv,.json,text/csv,application/json" required></label><button class="button" type="submit">İçe aktar ve eşleşmeleri öner</button></form></section>';
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
