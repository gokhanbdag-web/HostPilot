<?php
declare(strict_types=1);
namespace HostPilot\Http;

use HostPilot\Core\Auth;
use HostPilot\Core\Authorization;
use HostPilot\Core\EmailService;
use HostPilot\Core\SecurityAudit;
use HostPilot\Core\SecretVault;
use HostPilot\Payment\GatewayTransport;
use HostPilot\Payment\GatewayTransportException;
use HostPilot\Payment\IyzicoSignature;
use HostPilot\Payment\PaytrRequest;
use HostPilot\Payment\PaytrSignature;
use PDO;
use Throwable;

final class PaymentController
{
    private SecretVault $vault;
    public function __construct(private readonly PDO $db,private readonly string $basePath,private readonly string $baseUrl,private readonly string $applicationKey)
    {$this->vault=new SecretVault($applicationKey);}

    public function adminFinance():void
    {
        if(!$this->isStaff()){http_response_code(403);echo 'Yetkisiz erişim.';return;}
        [$from,$to,$toExclusive,$rangeError]=$this->financeDateRange();
        $summary=\\HostPilot\\Payment\\FinanceReport::summary($this->db,$from.' 00:00:00',$toExclusive.' 00:00:00');
        $movements=\\HostPilot\\Payment\\FinanceReport::movements($this->db,$from.' 00:00:00',$toExclusive.' 00:00:00',150);
        $reconciliations=\\HostPilot\\Payment\\FinanceReport::reconciliations($this->db,$from.' 00:00:00',$toExclusive.' 00:00:00',75);
        $this->pageStart('Finansal hareket raporu');
        echo '<div class="admin"><aside><a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a><a href="'.$this->url('/admin').'">← Kontrol merkezi</a><a class="active" href="'.$this->url('/admin/finance').'">Finans raporları</a><a href="'.$this->url('/admin/payment-transactions').'">Ödeme işlemleri</a><a href="'.$this->url('/admin/payments').'">Ödeme ayarları</a><a href="'.$this->url('/admin/invoices').'">Faturalar</a></aside><main><header><small>FİNANS / MUTABAKAT</small><h1>Finansal hareket raporu</h1><p>Tahsilat, sağlayıcıda tamamlanmış iadeler, açık fatura bakiyeleri ve manuel inceleme kayıtları. Tutarlar para birimine göre ayrı hesaplanır.</p></header>';
        if($rangeError!=='')echo '<div class="alert">'.$this->e($rangeError).' Varsayılan son 30 günlük aralık gösteriliyor.</div>';
        echo '<section class="box"><form method="get" action="'.$this->url('/admin/finance').'" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap"><label>Başlangıç tarihi<input type="date" name="from" value="'.$this->e($from).'" required></label><label>Bitiş tarihi<input type="date" name="to" value="'.$this->e($to).'" required></label><button class="button" type="submit">Raporu görüntüle</button><a class="button" href="'.$this->url('/admin/finance/export').'?from='.rawurlencode($from).'&to='.rawurlencode($to).'">CSV indir</a></form><p class="muted">En fazla 366 günlük aralık. Tahsilat ve iade hareketleri kendi gerçekleşme tarihlerine göre raporlanır.</p></section>';
        echo '<section class="box"><h2>Seçili dönemin nakit hareketleri</h2>';
        if(!$summary)echo '<p class="muted">Seçilen aralıkta finansal hareket veya açık fatura bakiyesi bulunamadı.</p>';
        foreach($summary as $row){
            $currency=$this->e((string)$row['currency']);
            echo '<div style="border-top:1px solid #26354c;padding:15px 0"><h3>'.$currency.'</h3><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:12px">';
            echo '<div><small class="muted">Tahsilat · '.(int)$row['capture_count'].'</small><h2>'.number_format((float)$row['capture_amount'],2,',','.').' '.$currency.'</h2></div>';
            echo '<div><small class="muted">İade · '.(int)$row['refund_count'].'</small><h2>'.number_format((float)$row['refund_amount'],2,',','.').' '.$currency.'</h2></div>';
            echo '<div><small class="muted">Net hareket</small><h2>'.number_format((float)$row['net_amount'],2,',','.').' '.$currency.'</h2></div>';
            echo '<div><small class="muted">Açık fatura · '.(int)$row['outstanding_count'].'</small><h2>'.number_format((float)$row['outstanding_amount'],2,',','.').' '.$currency.'</h2></div>';
            echo '<div><small class="muted">Vadesi geçmiş bakiye</small><h2>'.number_format((float)$row['overdue_amount'],2,',','.').' '.$currency.'</h2></div>';
            echo '<div><small class="muted">Manuel inceleme · '.(int)$row['review_count'].'</small><h2>'.number_format((float)$row['review_amount'],2,',','.').' '.$currency.'</h2></div>';
            echo '</div></div>';
        }
        echo '</section><section class="box"><h2>Finansal hareket dökümü</h2><p class="muted">Son 150 kayıt. Tahsilatlar ve iade kayıtları ayrı hareketlerdir; aynı işlem birden fazla satırda görünebilir.</p><div style="overflow-x:auto"><table><thead><tr><th>Tarih</th><th>Tür</th><th>Fatura</th><th>Sağlayıcı</th><th>Referans</th><th>Tutar</th><th>Detay</th></tr></thead><tbody>';
        if(!$movements)echo '<tr><td colspan="7">Hareket bulunamadı.</td></tr>';
        foreach($movements as $movement){
            $type=$movement['movement_type']==='refund'?'İade':'Tahsilat';
            echo '<tr><td>'.date('d.m.Y H:i',strtotime((string)$movement['occurred_at'])).'</td><td>'.$type.'</td><td>'.$this->e((string)$movement['invoice_number']).'</td><td>'.$this->e(strtoupper((string)$movement['gateway'])).'</td><td>'.$this->e((string)$movement['provider_reference']).'</td><td>'.number_format((float)$movement['amount'],2,',','.').' '.$this->e((string)$movement['currency']).'</td><td>'.$this->e((string)$movement['detail']).'</td></tr>';
        }
        echo '</tbody></table></div></section><section class="box"><h2>Manuel mutabakat kararları</h2><p class="muted">Sağlayıcı panelinde yönetici tarafından doğrulanmış karar kayıtları. İptal veya henüz faturaya işlenmemiş iade onayları tahsilat toplamına dahil edilmez.</p><div style="overflow-x:auto"><table><thead><tr><th>Tarih</th><th>Karar</th><th>Fatura</th><th>Referans</th><th>Tutar</th><th>İşlemi yapan</th><th>Açıklama</th></tr></thead><tbody>';
        if(!$reconciliations)echo '<tr><td colspan="7">Manuel mutabakat kaydı bulunamadı.</td></tr>';
        $decisions=['payment_confirmed'=>'Tahsilat doğrulandı','refund_confirmed'=>'Tam iade doğrulandı','no_capture_confirmed'=>'Tahsilat yok / kapatıldı'];
        foreach($reconciliations as $item)echo '<tr><td>'.date('d.m.Y H:i',strtotime((string)$item['created_at'])).'</td><td>'.$this->e($decisions[$item['decision']]??(string)$item['decision']).'</td><td>'.$this->e((string)$item['invoice_number']).'</td><td>'.$this->e((string)$item['provider_reference']).'</td><td>'.number_format((float)$item['amount'],2,',','.').' '.$this->e((string)$item['currency']).'</td><td>'.$this->e((string)$item['admin_name']).'</td><td>'.$this->e((string)$item['reason']).'</td></tr>';
        echo '</tbody></table></div></section></main></div>';
        $this->pageEnd();
    }

    public function adminFinanceExport():void
    {
        if(!$this->isStaff()){http_response_code(403);echo 'Yetkisiz erişim.';return;}
        [$from,$to,$toExclusive,$rangeError]=$this->financeDateRange();
        if($rangeError!==''){$this->redirect('/admin/finance');}
        $rows=\\HostPilot\\Payment\\FinanceReport::movements($this->db,$from.' 00:00:00',$toExclusive.' 00:00:00',5000);
        $actor=Auth::user();
        SecurityAudit::record($this->db,'payment.finance_report_exported',isset($actor['id'])?(int)$actor['id']:null,['action'=>'export']);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="hostpilot-finans-'.$from.'-'.$to.'.csv"');
        header('Cache-Control: no-store, private');
        $out=fopen('php://output','wb');
        if($out===false){http_response_code(500);return;}
        fwrite($out,"\xEF\xBB\xBF");
        fputcsv($out,['Tarih UTC','Hareket türü','Fatura','Sağlayıcı','Sağlayıcı referansı','Tutar','Para birimi','Açıklama']);
        foreach($rows as $row){
            $values=[(string)$row['occurred_at'],$row['movement_type']==='refund'?'İade':'Tahsilat',(string)$row['invoice_number'],strtoupper((string)$row['gateway']),(string)$row['provider_reference'],number_format((float)$row['amount'],2,'.',''),(string)$row['currency'],(string)$row['detail']];
            fputcsv($out,array_map([$this,'csvSafeValue'],$values));
        }
        fclose($out);
    }

    private function financeDateRange():array
    {
        $today=new \\DateTimeImmutable('today',new \\DateTimeZone('UTC'));
        $defaultFrom=$today->modify('-29 days');
        $fromRaw=trim((string)($_GET['from']??$defaultFrom->format('Y-m-d')));
        $toRaw=trim((string)($_GET['to']??$today->format('Y-m-d')));
        $from=\\DateTimeImmutable::createFromFormat('!Y-m-d',$fromRaw,new \\DateTimeZone('UTC'));
        $to=\\DateTimeImmutable::createFromFormat('!Y-m-d',$toRaw,new \\DateTimeZone('UTC'));
        $valid=$from!==false&&$to!==false&&$from->format('Y-m-d')===$fromRaw&&$to->format('Y-m-d')===$toRaw&&$from<=$to&&($to->getTimestamp()-$from->getTimestamp())<=366*86400;
        if(!$valid)return [$defaultFrom->format('Y-m-d'),$today->format('Y-m-d'),$today->modify('+1 day')->format('Y-m-d'),'Geçersiz tarih aralığı. Başlangıç ve bitiş tarihlerini kontrol edin.'];
        return [$fromRaw,$toRaw,$to->modify('+1 day')->format('Y-m-d'),''];
    }

    private function csvSafeValue(string $value):string
    {
        if($value!==''&&preg_match('/^[=+@\\-\\t\\r]/',$value))return "'".$value;
        return $value;
    }

    public function adminSettings():void
    {
        if(!$this->isStaff()){http_response_code(403);echo 'Yetkisiz erişim.';return;}
        $notice='';$error='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!Auth::verifyCsrf($_POST['_token']??null))$error='Güvenlik doğrulaması başarısız.';
            else try{
                $fields=[
                    'paytr_enabled'=>isset($_POST['paytr_enabled'])?'1':'0',
                    'paytr_test_mode'=>isset($_POST['paytr_test_mode'])?'1':'0',
                    'paytr_merchant_id'=>trim((string)($_POST['paytr_merchant_id']??'')),
                    'paytr_merchant_key'=>trim((string)($_POST['paytr_merchant_key']??'')),
                    'paytr_merchant_salt'=>trim((string)($_POST['paytr_merchant_salt']??'')),
                    'iyzico_enabled'=>isset($_POST['iyzico_enabled'])?'1':'0',
                    'iyzico_test_mode'=>isset($_POST['iyzico_test_mode'])?'1':'0',
                    'iyzico_api_key'=>trim((string)($_POST['iyzico_api_key']??'')),
                    'iyzico_secret_key'=>trim((string)($_POST['iyzico_secret_key']??'')),
                ];
                foreach(['paytr_merchant_key','paytr_merchant_salt','iyzico_api_key','iyzico_secret_key'] as $secret){
                    if($fields[$secret]==='')unset($fields[$secret]);
                    else $fields[$secret]=$this->vault->encrypt($fields[$secret]);
                }
                foreach($fields as $key=>$value){
                    $s=$this->db->prepare("INSERT INTO settings(setting_group,setting_key,setting_value,is_secret,updated_at) VALUES('payment',:key,:value,:secret,:updated) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_secret=VALUES(is_secret),updated_at=VALUES(updated_at)");
                    $s->execute([':key'=>$key,':value'=>$value,':secret'=>in_array($key,['paytr_merchant_key','paytr_merchant_salt','iyzico_api_key','iyzico_secret_key'],true)?1:0,':updated'=>gmdate('Y-m-d H:i:s')]);
                }
                $actor=Auth::user();SecurityAudit::record($this->db,'payment.gateway_settings_updated',isset($actor['id'])?(int)$actor['id']:null,['action'=>'updated']);
                $notice='Ödeme ayarları kaydedildi. Gizli anahtarlar şifreli saklanır.';
            }catch(Throwable $e){$error='Ayarlar kaydedilemedi: '.$e->getMessage();}
        }
        $v=$this->settings();
        $this->pageStart('Ödeme yöntemleri');
        echo '<div class="admin"><aside><a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a><a href="'.$this->url('/admin').'">← Kontrol merkezi</a><a class="active" href="'.$this->url('/admin/payments').'">Ödeme yöntemleri</a><a href="'.$this->url('/admin/invoices').'">Faturalar</a></aside><main><header><small>FİNANS / YAPILANDIRMA</small><h1>Ödeme yöntemleri</h1><p>PayTR ve iyzico mağaza bilgilerinizi girin. Anahtarlar şifreli saklanır ve tekrar görüntülenmez.</p></header>';
        if($notice!=='')echo '<div class="notice">'.$this->e($notice).'</div>';if($error!=='')echo '<div class="alert">'.$this->e($error).'</div>';
        echo '<form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><section class="box"><h2>PayTR iFrame</h2><label><input type="checkbox" name="paytr_enabled" value="1" '.($v['paytr_enabled']==='1'?'checked':'').'> PayTR ödeme yöntemini etkinleştir</label><label><input type="checkbox" name="paytr_test_mode" value="1" '.($v['paytr_test_mode']==='1'?'checked':'').'> Test modu</label><label>Mağaza numarası<input name="paytr_merchant_id" value="'.$this->e($v['paytr_merchant_id']).'" autocomplete="off"></label><label>Mağaza anahtarı<input type="password" name="paytr_merchant_key" placeholder="'.($v['paytr_merchant_key']?'Kayıtlı anahtar korunuyor; değiştirmek için yeni anahtar girin':'Mağaza anahtarını girin').'" autocomplete="new-password"></label><label>Mağaza tuzu<input type="password" name="paytr_merchant_salt" placeholder="'.($v['paytr_merchant_salt']?'Kayıtlı tuz korunuyor; değiştirmek için yeni tuz girin':'Mağaza tuzunu girin').'" autocomplete="new-password"></label><p class="muted">Bildirim URL: <code>'.$this->e($this->absolute('/payment/paytr/callback')).'</code></p></section>';
        echo '<section class="box"><h2>iyzico Checkout Form</h2><label><input type="checkbox" name="iyzico_enabled" value="1" '.($v['iyzico_enabled']==='1'?'checked':'').'> iyzico ödeme yöntemini etkinleştir</label><label><input type="checkbox" name="iyzico_test_mode" value="1" '.($v['iyzico_test_mode']==='1'?'checked':'').'> Sandbox test ortamı</label><label>API anahtarı<input type="password" name="iyzico_api_key" placeholder="'.($v['iyzico_api_key']?'Kayıtlı anahtar korunuyor; değiştirmek için yeni anahtar girin':'API anahtarını girin').'" autocomplete="new-password"></label><label>Gizli anahtar<input type="password" name="iyzico_secret_key" placeholder="'.($v['iyzico_secret_key']?'Kayıtlı anahtar korunuyor; değiştirmek için yeni anahtar girin':'Gizli anahtarı girin').'" autocomplete="new-password"></label><p class="muted">Callback URL: <code>'.$this->e($this->absolute('/payment/iyzico/callback')).'</code></p></section><button class="button">Ayarları kaydet</button></form></main></div>';
        $this->pageEnd();
    }

    private function flagStalePendingAttempts(?int $invoiceId=null):int
    {
        // A timeout is ambiguous: lock each row and enqueue its review notice atomically.
        $now=gmdate('Y-m-d H:i:s');
        $cutoff=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->modify('-30 minutes')->format('Y-m-d H:i:s');
        $sql="SELECT id FROM payment_transactions WHERE status='pending' AND created_at<=:cutoff";
        $params=[':cutoff'=>$cutoff];
        if($invoiceId!==null){$sql.=' AND invoice_id=:invoice_id';$params[':invoice_id']=$invoiceId;}
        $this->db->beginTransaction();
        try{
            $select=$this->db->prepare($sql.' ORDER BY id FOR UPDATE');$select->execute($params);
            $ids=array_map('intval',$select->fetchAll(PDO::FETCH_COLUMN));
            $changed=0;
            $update=$this->db->prepare("UPDATE payment_transactions SET status='review',response_code='stale_pending_review',safe_message='Ödeme oturumu 30 dakikadan uzun süredir sonuçlanmadı. Sağlayıcı panelinde kontrol edilmelidir.',updated_at=:updated WHERE id=:id AND status='pending'");
            foreach($ids as $id){
                $update->execute([':updated'=>$now,':id'=>$id]);
                if($update->rowCount()===1){$changed++;$this->queuePaymentStatusForTransaction($id,'review');}
            }
            $this->db->commit();
            return $changed;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function adminTransactions():void
    {
        if(!$this->isStaff()){http_response_code(403);echo 'Yetkisiz erişim.';return;}
        $this->flagStalePendingAttempts();
        $rows=$this->db->query("SELECT pt.id,pt.invoice_id,pt.gateway,pt.gateway_transaction_id,pt.status,pt.currency,pt.amount,pt.response_code,pt.safe_message,pt.created_at,i.invoice_number,u.name,u.email
            FROM payment_transactions pt JOIN invoices i ON i.id=pt.invoice_id JOIN users u ON u.id=pt.user_id
            ORDER BY pt.created_at DESC LIMIT 500")->fetchAll();
        $refundHistory=$this->db->query('SELECT pr.payment_transaction_id,pr.amount,pr.currency,pr.provider_reference,pr.reason,pr.created_at,u.name AS admin_name FROM payment_refunds pr JOIN users u ON u.id=pr.admin_user_id ORDER BY pr.id DESC LIMIT 1000')->fetchAll(PDO::FETCH_ASSOC);
        $refundsByTransaction=[];
        foreach($refundHistory as $refundRow)$refundsByTransaction[(int)$refundRow['payment_transaction_id']][]=$refundRow;
        $notice=(string)($_SESSION['hostpilot_payment_reconcile_notice']??'');$error=(string)($_SESSION['hostpilot_payment_reconcile_error']??'');
        unset($_SESSION['hostpilot_payment_reconcile_notice'],$_SESSION['hostpilot_payment_reconcile_error']);
        $canReconcile=in_array((Auth::user()['role']??''),['admin','super_admin'],true);
        $csrf=Auth::csrfToken();
        $this->pageStart('Ödeme işlemleri');
        echo '<div class="admin"><aside><a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a><a href="'.$this->url('/admin').'">← Kontrol merkezi</a><a href="'.$this->url('/admin/payments').'">Ödeme ayarları</a><a class="active" href="'.$this->url('/admin/payment-transactions').'">Ödeme işlemleri</a><a href="'.$this->url('/admin/invoices').'">Faturalar</a></aside><main><header><small>FİNANS / İŞLEM KAYITLARI</small><h1>Ödeme işlemleri</h1><p>Sağlayıcı dönüşleri, işlem referansları ve tahsilat durumları. En son 500 işlem gösterilir.</p></header>';
        if($notice!=='')echo '<div class="notice">'.$this->e($notice).'</div>';
        if($error!=='')echo '<div class="alert">'.$this->e($error).'</div>';
        echo '<section class="box"><div style="overflow-x:auto"><table><thead><tr><th>Tarih</th><th>Fatura / Müşteri</th><th>Sağlayıcı</th><th>Tutar</th><th>Durum</th><th>Referans</th></tr></thead><tbody>';
        if(!$rows)echo '<tr><td colspan="6">Henüz ödeme işlemi bulunmuyor.</td></tr>';
        foreach($rows as $v){
            echo '<tr><td>'.date('d.m.Y H:i',strtotime($v['created_at'])).'</td><td>#'.$this->e($v['invoice_number']).'<small>'.$this->e($v['name']).' · '.$this->e($v['email']).'</small></td><td>'.$this->e(strtoupper($v['gateway'])).'</td><td>'.number_format((float)$v['amount'],2,',','.').' '.$this->e($v['currency']).'</td><td>'.$this->e($this->transactionStatus($v['status'])).'<small>'.$this->e((string)$v['response_code']).'</small></td><td>'.$this->e((string)($v['gateway_transaction_id']??'—')).'<small>'.$this->e((string)($v['safe_message']??'')).'</small></td></tr>';
            if(!empty($refundsByTransaction[(int)$v['id']])){
                $refundedTotal=0.0;
                foreach($refundsByTransaction[(int)$v['id']] as $refundRow)$refundedTotal+=(float)$refundRow['amount'];
                echo '<tr><td colspan="6"><b>Toplam iade: '.number_format($refundedTotal,2,',','.').' '.$this->e((string)$v['currency']).'</b>';
                foreach($refundsByTransaction[(int)$v['id']] as $refundRow)echo '<p>'.number_format((float)$refundRow['amount'],2,',','.').' '.$this->e((string)$refundRow['currency']).' · '.date('d.m.Y H:i',strtotime($refundRow['created_at'])).' · '.$this->e((string)$refundRow['provider_reference']).' · '.$this->e((string)$refundRow['reason']).' · '.$this->e((string)$refundRow['admin_name']).'</p>';
                echo '</td></tr>';
            }
            if($canReconcile&&$v['status']==='review'){
                echo '<tr><td colspan="6"><form method="post" action="'.$this->url('/admin/payment-transactions/reconcile').'" style="display:grid;gap:9px;padding:12px;background:#f6f8fc;border-radius:8px">';
                echo '<input type="hidden" name="_token" value="'.$this->e($csrf).'"><input type="hidden" name="payment_transaction_id" value="'.(int)$v['id'].'">';
                echo '<b>Manuel mutabakat · #'.(int)$v['id'].'</b><p>Sağlayıcı panelinden doğrulama yapmadan işlem seçmeyin. Bu ekran sağlayıcıya otomatik iade çağrısı göndermez.</p>';
                echo '<label>Sağlayıcı işlem / iade referansı<input name="provider_reference" required maxlength="190" autocomplete="off"></label>';
                echo '<label>İşlem açıklaması<input name="reason" required maxlength="500"></label>';
                echo '<label>Super admin / admin parolası<input type="password" name="password" required maxlength="4096" autocomplete="current-password"></label>';
                echo '<label><input type="checkbox" name="confirm_external_action" value="1" required> Sağlayıcı panelinde seçtiğim sonucu doğruladım; bu işlem sağlayıcıya otomatik istek göndermez.</label>';
                echo '<div style="display:flex;gap:8px;flex-wrap:wrap"><button type="submit" name="decision" value="payment_confirmed">Tahsilatı faturaya işle</button><button type="submit" name="decision" value="refund_confirmed">Tam iadenin yapıldığını kaydet</button><button type="submit" name="decision" value="no_capture_confirmed">Tahsilat olmadığını doğrula ve kapat</button></div></form></td></tr>';
            }


            }
            if($canReconcile&&in_array($v['status'],['success','partially_refunded'],true)){
                echo '<tr><td colspan="6"><form method="post" action="'.$this->url('/admin/payment-transactions/refund').'" style="display:grid;gap:9px;padding:12px;background:#f6f8fc;border-radius:8px">';
                echo '<input type="hidden" name="_token" value="'.$this->e($csrf).'"><input type="hidden" name="payment_transaction_id" value="'.(int)$v['id'].'"><input type="hidden" name="currency" value="'.$this->e((string)$v['currency']).'">';
                echo '<b>Sağlayıcıda tamamlanmış iadeyi kaydet · #'.(int)$v['id'].'</b><p>Bu ekran PayTR/iyzico API çağrısı yapmaz. Önce sağlayıcı panelinde iadenin tamamlandığını doğrulayın.</p>';
                echo '<label>İade tutarı<input name="refund_amount" inputmode="decimal" required pattern="[0-9]+([.][0-9]{1,2})?" maxlength="13" placeholder="0.00"></label>';
                echo '<label>Sağlayıcı iade referansı<input name="provider_reference" required maxlength="190" autocomplete="off"></label>';
                echo '<label>İade açıklaması<input name="reason" required maxlength="500"></label>';
                echo '<label>Yönetici parolası<input type="password" name="password" required maxlength="4096" autocomplete="current-password"></label>';
                echo '<label><input type="checkbox" name="confirm_external_action" value="1" required> İadenin sağlayıcı panelinde tamamlandığını doğruladım.</label><button type="submit">İadeyi kaydet</button></form></td></tr>';
            }
        echo '</tbody></table></div></section></main></div>';$this->pageEnd();
    }

    public function adminReconcile():void
    {
        $actor=Auth::user();
        if(!$actor||!in_array(($actor['role']??''),['admin','super_admin'],true)){http_response_code(403);echo 'Bu işlem yalnızca admin tarafından yapılabilir.';return;}
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){$this->redirect('/admin/payment-transactions');}
        if(!Auth::verifyCsrf($_POST['_token']??null)){$this->flashReconcileError('Güvenlik doğrulaması başarısız.');$this->redirect('/admin/payment-transactions');}
        $id=(int)($_POST['payment_transaction_id']??0);
        $decision=(string)($_POST['decision']??'');
        $reference=trim((string)($_POST['provider_reference']??''));
        $reason=trim((string)($_POST['reason']??''));
        if($id<1||!in_array($decision,['payment_confirmed','refund_confirmed','no_capture_confirmed'],true)||$reference===''||strlen($reference)>190||$reason===''||strlen($reason)>500||(string)($_POST['confirm_external_action']??'')!=='1'){
            $this->flashReconcileError('Mutabakat alanları eksik veya geçersiz.');$this->redirect('/admin/payment-transactions');
        }
        $verified=Auth::verifyCredentials($this->db,(string)($actor['email']??''),(string)($_POST['password']??''));
        if(!is_array($verified)||(int)($verified['id']??0)!==(int)$actor['id']||!in_array(($verified['role']??''),['admin','super_admin'],true)){
            $this->flashReconcileError('Yönetici parolası doğrulanamadı.');$this->redirect('/admin/payment-transactions');
        }
        try{
            $q=$this->db->prepare('SELECT pt.*,i.total AS invoice_total,i.amount_paid AS invoice_amount_paid,i.currency AS invoice_currency,i.invoice_number FROM payment_transactions pt JOIN invoices i ON i.id=pt.invoice_id WHERE pt.id=:id LIMIT 1');
            $q->execute([':id'=>$id]);$tx=$q->fetch();
            if(!$tx||$tx['status']!=='review')throw new \RuntimeException('Yalnızca manuel incelemede bekleyen işlemler uzlaştırılabilir.');
            if($decision==='payment_confirmed'){
                $remaining=round((float)$tx['invoice_total']-(float)$tx['invoice_amount_paid'],2);
                if((float)$tx['amount']>$remaining+0.005)throw new \RuntimeException('İşlem tutarı kalan fatura borcunu aşıyor. Bu tahsilat faturaya işlenemez; sağlayıcıdan iade sürecini tamamlayın.');
                $this->settle($tx,'success',$reference,'Admin tarafından sağlayıcı panelinde doğrulandı',[
                    'admin_user_id'=>(int)$actor['id'],'decision'=>'payment_confirmed','provider_reference'=>$reference,'reason'=>$reason
                ]);
                $check=$this->db->prepare('SELECT status FROM payment_transactions WHERE id=:id');$check->execute([':id'=>$id]);
                if($check->fetchColumn()!=='success')throw new \RuntimeException('Tahsilat faturaya işlenemedi; işlem incelemede bırakıldı.');
                SecurityAudit::record($this->db,'payment.transaction_reconciled',(int)$actor['id'],['resource_id'=>$id,'action'=>'payment_confirmed']);
                $this->logPaymentReconciliation('PAYMENT_CONFIRMED',$id,(int)$actor['id'],$reference);
                $_SESSION['hostpilot_payment_reconcile_notice']='Sağlayıcı tahsilatı doğrulandı ve fatura bakiyesine işlendi.';
            }elseif($decision==='refund_confirmed'){
                $this->db->beginTransaction();
                $lock=$this->db->prepare('SELECT * FROM payment_transactions WHERE id=:id FOR UPDATE');$lock->execute([':id'=>$id]);$current=$lock->fetch();
                if(!$current||$current['status']!=='review')throw new \RuntimeException('İşlem başka bir oturum tarafından güncellenmiş.');
                $exists=$this->db->prepare("SELECT 1 FROM payment_reconciliations WHERE payment_transaction_id=:id AND decision='refund_confirmed' LIMIT 1");$exists->execute([':id'=>$id]);
                if($exists->fetchColumn())throw new \RuntimeException('Bu işlem için tam iade zaten kaydedilmiş.');
                $insert=$this->db->prepare("INSERT INTO payment_reconciliations(payment_transaction_id,admin_user_id,decision,provider_reference,amount,currency,reason,created_at) VALUES(:tx,:admin,'refund_confirmed',:reference,:amount,:currency,:reason,:created)");
                $insert->execute([':tx'=>$id,':admin'=>$actor['id'],':reference'=>$reference,':amount'=>$current['amount'],':currency'=>$current['currency'],':reason'=>$reason,':created'=>gmdate('Y-m-d H:i:s')]);
                $update=$this->db->prepare("UPDATE payment_transactions SET status='refunded',response_code='manual_refund_confirmed',safe_message='Dış ödeme sağlayıcısında tam iade admin tarafından doğrulandı.',updated_at=:updated WHERE id=:id AND status='review'");
                $update->execute([':updated'=>gmdate('Y-m-d H:i:s'),':id'=>$id]);
                if($update->rowCount()!==1)throw new \RuntimeException('İşlem durumu güncellenemedi.');
                $this->queuePaymentStatusForTransaction($id,'refunded');
                $this->db->commit();
                SecurityAudit::record($this->db,'payment.manual_refund_confirmed',(int)$actor['id'],['resource_id'=>$id,'action'=>'refund_confirmed']);
                $this->logPaymentReconciliation('REFUND_CONFIRMED',$id,(int)$actor['id'],$reference);
                $_SESSION['hostpilot_payment_reconcile_notice']='Sağlayıcı panelinde tamamlanan tam iade kaydedildi. Fatura bakiyesi değiştirilmedi; bu işlem daha önce faturaya işlenmemiş bir tahsilat incelemesiydi.';
            }else{
                $this->db->beginTransaction();
                $lock=$this->db->prepare('SELECT * FROM payment_transactions WHERE id=:id FOR UPDATE');$lock->execute([':id'=>$id]);$current=$lock->fetch();
                if(!$current||$current['status']!=='review')throw new \RuntimeException('İşlem başka bir oturum tarafından güncellenmiş.');
                $exists=$this->db->prepare("SELECT 1 FROM payment_reconciliations WHERE payment_transaction_id=:id AND decision='no_capture_confirmed' LIMIT 1");$exists->execute([':id'=>$id]);
                if($exists->fetchColumn())throw new \RuntimeException('Bu işlem için tahsilat olmadığı zaten kaydedilmiş.');
                $insert=$this->db->prepare("INSERT INTO payment_reconciliations(payment_transaction_id,admin_user_id,decision,provider_reference,amount,currency,reason,created_at) VALUES(:tx,:admin,'no_capture_confirmed',:reference,:amount,:currency,:reason,:created)");
                $insert->execute([':tx'=>$id,':admin'=>$actor['id'],':reference'=>$reference,':amount'=>$current['amount'],':currency'=>$current['currency'],':reason'=>$reason,':created'=>gmdate('Y-m-d H:i:s')]);
                $update=$this->db->prepare("UPDATE payment_transactions SET status='cancelled',response_code='manual_no_capture_confirmed',safe_message='Sağlayıcı panelinde tahsilat olmadığı admin tarafından doğrulandı; geç gelen callback tahsilat oluşturamaz.',updated_at=:updated WHERE id=:id AND status='review'");
                $update->execute([':updated'=>gmdate('Y-m-d H:i:s'),':id'=>$id]);
                if($update->rowCount()!==1)throw new \RuntimeException('Ödeme işlemi güvenli biçimde kapatılamadı.');
                $this->queuePaymentStatusForTransaction($id,'cancelled');
                $this->db->commit();
                SecurityAudit::record($this->db,'payment.no_capture_confirmed',(int)$actor['id'],['resource_id'=>$id,'action'=>'no_capture_confirmed']);
                $this->logPaymentReconciliation('NO_CAPTURE_CONFIRMED',$id,(int)$actor['id'],$reference);
                $_SESSION['hostpilot_payment_reconcile_notice']='Sağlayıcıda tahsilat olmadığı doğrulandı ve ödeme oturumu kapatıldı. Geç gelen sağlayıcı bildirimi faturaya tahsilat işleyemez.';
            }
        }catch(Throwable $exception){
            if($this->db->inTransaction())$this->db->rollBack();
            error_log('[HostPilot] Payment reconciliation: '.$exception->getMessage());
            $this->flashReconcileError($exception->getMessage());
        }
        $this->redirect('/admin/payment-transactions');
    }


    public function adminRecordRefund():void
    {
        $actor=Auth::user();
        if(!$actor||!in_array(($actor['role']??''),['admin','super_admin'],true)){http_response_code(403);echo 'Bu işlem yalnızca admin tarafından yapılabilir.';return;}
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')$this->redirect('/admin/payment-transactions');
        if(!Auth::verifyCsrf($_POST['_token']??null)){$this->flashReconcileError('Güvenlik doğrulaması başarısız.');$this->redirect('/admin/payment-transactions');}
        $id=(int)($_POST['payment_transaction_id']??0);
        $amount=trim((string)($_POST['refund_amount']??''));
        $currency=strtoupper(trim((string)($_POST['currency']??'')));
        $reference=trim((string)($_POST['provider_reference']??''));
        $reason=trim((string)($_POST['reason']??''));
        if($id<1||$amount===''||$currency===''||$reference===''||$reason===''||(string)($_POST['confirm_external_action']??'')!=='1'){
            $this->flashReconcileError('İade alanları eksik. Sağlayıcıda tamamlanmış iadeyi doğrulayıp tüm alanları doldurun.');$this->redirect('/admin/payment-transactions');
        }
        $verified=Auth::verifyCredentials($this->db,(string)($actor['email']??''),(string)($_POST['password']??''));
        if(!is_array($verified)||(int)($verified['id']??0)!==(int)$actor['id']||!in_array(($verified['role']??''),['admin','super_admin'],true)){
            $this->flashReconcileError('Yönetici parolası doğrulanamadı.');$this->redirect('/admin/payment-transactions');
        }
        try{
            $this->db->beginTransaction();
            $result=\HostPilot\Payment\RefundLedger::record($this->db,$id,(int)$actor['id'],$amount,$currency,$reference,$reason);
            $q=$this->db->prepare('SELECT i.invoice_number,u.name,u.email FROM payment_transactions pt JOIN invoices i ON i.id=pt.invoice_id JOIN users u ON u.id=pt.user_id WHERE pt.id=:id LIMIT 1');
            $q->execute([':id'=>$id]);$customer=$q->fetch(PDO::FETCH_ASSOC);
            if(!$customer)throw new \RuntimeException('İade kaydı oluşturuldu ancak müşteri/fatura bildirimi için kayıt bulunamadı.');
            (new EmailService($this->db,$this->applicationKey))->queueRefundNotice((string)$customer['email'],(string)$customer['name'],(string)$customer['invoice_number'],(string)$result['refund_amount'],(string)$result['total_refunded'],$currency,(string)$result['payment_status'],(int)$result['refund_id']);
            SecurityAudit::record($this->db,'payment.partial_refund_recorded',(int)$actor['id'],['resource_id'=>$id,'action'=>'refund_confirmed','refund_id'=>(int)$result['refund_id']]);
            $this->db->commit();
            $_SESSION['hostpilot_payment_reconcile_notice']='Sağlayıcıda doğrulanmış iade kaydedildi. Fatura bakiyesi güncellendi: kalan '.number_format((float)$result['invoice_remaining'],2,',','.').' '.$currency.'.';
        }catch(Throwable $exception){
            if($this->db->inTransaction())$this->db->rollBack();
            error_log('[HostPilot] Refund ledger: '.$exception->getMessage());
            $safeMessage=$exception instanceof \PDOException&&$exception->getCode()==='23000'?'Bu sağlayıcı iade referansı bu ödeme için daha önce kaydedilmiş.':$exception->getMessage();
            $this->flashReconcileError($safeMessage);
        }
        $this->redirect('/admin/payment-transactions');
    }

    private function flashReconcileError(string $message):void
    {
        $_SESSION['hostpilot_payment_reconcile_error']=$message;
    }

    private function logPaymentReconciliation(string $event,int $transactionId,int $adminId,string $reference):void
    {
        $directory=HOSTPILOT_STORAGE.DIRECTORY_SEPARATOR.'logs';
        if(!is_dir($directory)&&!mkdir($directory,0750,true)&&!is_dir($directory))return;
        $safeReference=preg_replace('/[^A-Za-z0-9_.:-]/','',(string)$reference)??'';
        $line='['.gmdate('Y-m-d H:i:s').' UTC] '.$event.' transaction='.$transactionId.' admin='.$adminId.' provider_reference='.$safeReference.PHP_EOL;
        @file_put_contents($directory.DIRECTORY_SEPARATOR.'payment-reconciliation.log',$line,FILE_APPEND|LOCK_EX);
        @chmod($directory.DIRECTORY_SEPARATOR.'payment-reconciliation.log',0640);
    }

    private function transactionStatus(string $status):string
    {return ['pending'=>'Bekliyor','success'=>'Başarılı','failed'=>'Başarısız','review'=>'İncelemede','refunded'=>'İade edildi','partially_refunded'=>'Kısmi iade','cancelled'=>'İptal'][strtolower($status)]??$status;}

    public function payInvoice(int $invoiceId):void
    {
        $user=Auth::user();
        if(!$user||($user['role']??'')!=='customer'){$this->redirect('/client/login');}
        $q=$this->db->prepare('SELECT * FROM invoices WHERE id=:id AND user_id=:uid LIMIT 1');$q->execute([':id'=>$invoiceId,':uid'=>$user['id']]);$invoice=$q->fetch();
        if(!$invoice){http_response_code(404);echo 'Fatura bulunamadı.';return;}
        $this->flagStalePendingAttempts($invoiceId);
        if($invoice['status']==='paid'){$this->redirect('/client/invoices');}
        if(in_array($invoice['status'],['cancelled','refunded'],true)){http_response_code(409);echo 'İptal edilmiş veya iade edilmiş fatura için ödeme alınamaz.';return;}
        $v=$this->settings();$enabled=[];
        if($v['paytr_enabled']==='1'&&$v['paytr_merchant_id']!==''&&$v['paytr_merchant_key']!==''&&$v['paytr_merchant_salt']!=='')$enabled[]='paytr';
        if($v['iyzico_enabled']==='1'&&$v['iyzico_api_key']!==''&&$v['iyzico_secret_key']!=='')$enabled[]='iyzico';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!str_starts_with(strtolower($this->baseUrl),'https://')){http_response_code(400);$this->paymentPage($invoice,$enabled,'Ödeme işlemleri için sitenizde geçerli HTTPS/SSL etkin olmalıdır.');return;}
            if(!Auth::verifyCsrf($_POST['_token']??null)){http_response_code(400);echo 'Güvenlik doğrulaması başarısız.';return;}
            $gateway=(string)($_POST['gateway']??'');
            if(!in_array($gateway,$enabled,true)){http_response_code(422);echo 'Bu ödeme yöntemi etkin değil.';return;}
            $txId=0;
            try{
                // Serialize every payment start for this invoice before checking or creating an attempt.
                $this->db->beginTransaction();
                $invoiceLock=$this->db->prepare('SELECT * FROM invoices WHERE id=:id AND user_id=:uid LIMIT 1 FOR UPDATE');
                $invoiceLock->execute([':id'=>$invoiceId,':uid'=>$user['id']]);
                $lockedInvoice=$invoiceLock->fetch();
                if(!$lockedInvoice){$this->db->commit();http_response_code(404);echo 'Fatura bulunamadı.';return;}
                $invoice=$lockedInvoice;
                if($invoice['status']==='paid'){$this->db->commit();$this->redirect('/client/invoices');}
                if(in_array($invoice['status'],['cancelled','refunded'],true)){$this->db->commit();http_response_code(409);echo 'İptal edilmiş veya iade edilmiş fatura için ödeme alınamaz.';return;}
                $active=$this->db->prepare("SELECT id,status FROM payment_transactions WHERE invoice_id=:invoice AND status IN ('pending','review') ORDER BY id DESC LIMIT 1 FOR UPDATE");
                $active->execute([':invoice'=>$invoiceId]);
                $activeAttempt=$active->fetch();
                if($activeAttempt){
                    $this->db->commit();
                    http_response_code(409);
                    $this->paymentPage($invoice,$enabled,'',(string)$activeAttempt['status']);
                    return;
                }
                $amount=round((float)$invoice['total']-(float)$invoice['amount_paid'],2);
                if($amount<=0)throw new \RuntimeException('Ödenecek tutar bulunamadı.');
                $ref='HPP'.gmdate('ymdHis').strtoupper(bin2hex(random_bytes(5)));
                $now=gmdate('Y-m-d H:i:s');
                $ins=$this->db->prepare("INSERT INTO payment_transactions(invoice_id,user_id,gateway,idempotency_key,status,currency,amount,request_reference,created_at,updated_at) VALUES(:invoice,:user,:gateway,:key,'pending',:currency,:amount,:reference,:created,:updated)");
                $ins->execute([':invoice'=>$invoiceId,':user'=>$user['id'],':gateway'=>$gateway,':key'=>bin2hex(random_bytes(24)),':currency'=>$invoice['currency'],':amount'=>$amount,':reference'=>$ref,':created'=>$now,':updated'=>$now]);
                $txId=(int)$this->db->lastInsertId();
                $this->queuePaymentStatusForTransaction($txId,'pending');
                $this->db->commit();
                if($gateway==='paytr')$this->startPaytr($txId,$ref,$invoice,$user,$amount,$v);
                else $this->startIyzico($txId,$ref,$invoice,$user,$amount,$v);
                return;
            }catch(Throwable $e){
                if($this->db->inTransaction())$this->db->rollBack();
                $uncertain=$e instanceof GatewayTransportException;
                if($txId>0)$this->updateTransaction($txId,[
                    'status'=>$uncertain?'review':'failed',
                    'response_code'=>$uncertain?'init_result_uncertain':'init_failed',
                    'safe_message'=>$uncertain?'Sağlayıcı isteğinin sonucu kesin olarak doğrulanamadı. Mükerrer tahsilatı önlemek için manuel inceleme gerekiyor.':'Ödeme oturumu sağlayıcı tarafından başlatılamadı'
                ]);
                error_log('[HostPilot] Payment initialization: '.$e->getMessage());
                http_response_code(502);
                $this->paymentPage($invoice,$enabled,$uncertain?'Ödeme sağlayıcısından kesin sonuç alınamadı. Yeni ödeme başlatmayın; işlem sağlayıcı panelinde kontrol edilerek sonuçlandırılacaktır.':'Ödeme başlatılamadı. Bilgileri kontrol edip tekrar deneyin.',$uncertain?'review':null);
                return;
            }
        }
        $activeQuery=$this->db->prepare("SELECT status FROM payment_transactions WHERE invoice_id=:invoice AND status IN ('pending','review') ORDER BY id DESC LIMIT 1");
        $activeQuery->execute([':invoice'=>$invoiceId]);
        $activeStatus=$activeQuery->fetchColumn();
        $this->paymentPage($invoice,$enabled,'',is_string($activeStatus)?$activeStatus:null);
    }

    private function paymentPage(array $invoice,array $enabled,string $error,?string $activeStatus=null):void
    {
        $this->pageStart('Fatura ödemesi');
        echo '<main class="detail"><a class="brand" href="'.$this->url('/client').'">HOST<span>PILOT</span></a><a class="back" href="'.$this->url('/client/invoices').'">← Faturalarıma dön</a><section class="productdetail"><small>GÜVENLİ ÖDEME</small><h1>#'.$this->e($invoice['invoice_number']).'</h1><h2>'.number_format((float)$invoice['total']-(float)$invoice['amount_paid'],2,',','.').' '.$this->e($invoice['currency']).'</h2><p>Ödeme sağlayıcısına yönlendirilmeden önce bilgilerinizi kontrol edin.</p>';
        if($activeStatus==='review')echo '<div class="alert">Bu ödeme işlemi manuel incelemede. Sağlayıcı sonucu doğrulanmadan yeni bir ödeme başlatılamaz.</div>';
        elseif($activeStatus==='pending')echo '<div class="notice">Bu fatura için bir ödeme işlemi bekliyor. Sonuç netleşene kadar yeni ödeme başlatılamaz.</div>';
        elseif($error!=='')echo '<div class="alert">'.$this->e($error).'</div>';
        if(!$enabled)echo '<p>Şu anda etkin bir çevrimiçi ödeme yöntemi bulunmuyor. Lütfen yönetimle iletişime geçin.</p>';
        elseif(in_array($activeStatus,['pending','review'],true))echo '<p>Ödeme durumunu müşteri panelindeki fatura sayfasından takip edebilirsiniz. Yardım gerekirse destek ekibiyle iletişime geçin.</p>';
        else{
            echo '<form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>Ödeme yöntemi<select name="gateway" required>';
            foreach($enabled as $g)echo '<option value="'.$g.'">'.($g==='paytr'?'PayTR':'iyzico Checkout Form').'</option>';
            echo '</select></label><h3>Ödeme ve fatura bilgileri</h3><label>Telefon<input name="phone" type="tel" required maxlength="25" placeholder="+90 5xx xxx xx xx"></label><label>T.C. kimlik numarası (iyzico için)<input name="identity_number" inputmode="numeric" minlength="11" maxlength="11" placeholder="11 haneli numara"></label><label>Adres<input name="address" maxlength="240" required></label><label>Şehir<input name="city" maxlength="80" required></label><label>Posta kodu<input name="zip" maxlength="16" required></label><button class="button">Güvenli ödemeye geç</button></form>';
        }
        echo '</section></main>';$this->pageEnd();
    }

    private function startPaytr(int $txId,string $merchantOid,array $invoice,array $user,float $amount,array $settings):void
    {
        if($invoice['currency']!=='TRY')throw new \RuntimeException('PayTR için bu ilk sürümde yalnızca TRY para birimi desteklenmektedir.');
        $key=$this->vault->decrypt($settings['paytr_merchant_key']);$salt=$this->vault->decrypt($settings['paytr_merchant_salt']);
        $ip=$this->clientIp();$email=$this->asciiEmail((string)$user['email']);
        $basketQ=$this->db->prepare('SELECT description,quantity,unit_price FROM invoice_items WHERE invoice_id=:id');$basketQ->execute([':id'=>$invoice['id']]);
        $basket=[];foreach($basketQ->fetchAll() as $item)$basket[]=[substr((string)$item['description'],0,90),number_format((float)$item['unit_price'],2,'.',''),(int)$item['quantity']];
        if(!$basket)$basket=[['HostPilot invoice',number_format($amount,2,'.',''),1]];
        $payload=PaytrRequest::buildPayload([
            'merchant_id'=>(string)$settings['paytr_merchant_id'],'user_ip'=>$ip,'merchant_oid'=>$merchantOid,'email'=>$email,
            'payment_amount'=>(string)round($amount*100),'basket'=>$basket,'test_mode'=>(string)$settings['paytr_test_mode'],
            'user_name'=>(string)$user['name'],'user_address'=>(string)($_POST['address']??''),'user_phone'=>(string)($_POST['phone']??''),
            'ok_url'=>$this->absolute('/payment/return'),'fail_url'=>$this->absolute('/payment/return')
        ],$key,$salt);
        $result=GatewayTransport::postForm('https://www.paytr.com/odeme/api/get-token',$payload);
        $providerStatus=strtolower((string)($result['status']??''));
        if(in_array($providerStatus,['failed','failure','error'],true))throw new \RuntimeException((string)($result['reason']??'PayTR iframe token üretilemedi.'));
        if($providerStatus!=='success')throw new GatewayTransportException('PayTR yanıtı ödeme oturumunun durumunu doğrulamıyor.');
        try{
            if(empty($result['token']))throw new \RuntimeException('PayTR başarılı yanıtında ödeme formu anahtarı bulunamadı.');
            $this->updateTransaction($txId,['request_reference'=>$merchantOid]);
        }catch(Throwable $e){throw new GatewayTransportException('PayTR ödeme oturumu oluşturuldu ancak kullanılabilir form bilgisi kaydedilemedi.',$e->getCode(),$e);}
        $this->pageStart('PayTR güvenli ödeme');
        echo '<main class="detail"><a class="brand" href="'.$this->url('/client').'">HOST<span>PILOT</span></a><section class="productdetail"><h1>PayTR güvenli ödeme</h1><iframe src="https://www.paytr.com/odeme/guvenli/'.$this->e((string)$result['token']).'" id="paytriframe" frameborder="0" scrolling="no" style="width:100%;min-height:720px"></iframe><script src="https://www.paytr.com/js/iframeResizer.min.js?v2"></script><script>if(window.iFrameResize){iFrameResize({},\"#paytriframe\")}</script></section></main>';$this->pageEnd();
    }

    private function startIyzico(int $txId,string $conversationId,array $invoice,array $user,float $amount,array $settings):void
    {
        $api=$this->vault->decrypt($settings['iyzico_api_key']);$secret=$this->vault->decrypt($settings['iyzico_secret_key']);
        $base=$settings['iyzico_test_mode']==='1'?'https://sandbox-api.iyzipay.com':'https://api.iyzipay.com';
        $phone=preg_replace('/[^0-9+]/','',(string)($_POST['phone']??''));$identity=preg_replace('/\D/','',(string)($_POST['identity_number']??''));
        $address=trim((string)($_POST['address']??''));$city=trim((string)($_POST['city']??''));$zip=trim((string)($_POST['zip']??''));
        if(!$this->validTurkishId($identity)||$phone===''||$address===''||$city===''||$zip==='')throw new \RuntimeException('iyzico için geçerli 11 haneli T.C. kimlik numarası, telefon ve adres bilgileri gereklidir.');
        $parts=preg_split('/\s+/',trim((string)$user['name']),2)?:[];$first=$parts[0]??'Müşteri';$last=$parts[1]??'HostPilot';
        $email=(string)$user['email'];$now=gmdate('Y-m-d H:i:s');
        $itemsQ=$this->db->prepare('SELECT id,description,line_total FROM invoice_items WHERE invoice_id=:id');$itemsQ->execute([':id'=>$invoice['id']]);$basket=[];
        foreach($itemsQ->fetchAll() as $i)$basket[]=['id'=>(string)$i['id'],'price'=>number_format((float)$i['line_total'],2,'.',''),'name'=>substr((string)$i['description'],0,90),'category1'=>'Hosting Hizmetleri','itemType'=>'VIRTUAL'];
        if(!$basket)$basket=[['id'=>(string)$invoice['id'],'price'=>number_format($amount,2,'.',''),'name'=>'HostPilot invoice','category1'=>'Hosting Hizmetleri','itemType'=>'VIRTUAL']];
        $body=[
            'locale'=>'tr','conversationId'=>$conversationId,'price'=>number_format($amount,2,'.',''),'paidPrice'=>number_format($amount,2,'.',''),
            'currency'=>$invoice['currency'],'basketId'=>(string)$invoice['invoice_number'],'paymentGroup'=>'PRODUCT',
            'callbackUrl'=>$this->absolute('/payment/iyzico/callback'),'enabledInstallments'=>[1],
            'buyer'=>['id'=>(string)$user['id'],'name'=>$first,'surname'=>$last,'identityNumber'=>$identity,'email'=>$email,'gsmNumber'=>$phone,
                'registrationDate'=>$now,'lastLoginDate'=>$now,'registrationAddress'=>$address,'city'=>$city,'country'=>'Turkey','zipCode'=>$zip,'ip'=>$this->clientIp()],
            'billingAddress'=>['address'=>$address,'zipCode'=>$zip,'contactName'=>substr((string)$user['name'],0,80),'city'=>$city,'country'=>'Turkey'],
            'basketItems'=>$basket
        ];
        $path='/payment/iyzipos/checkoutform/initialize/auth/ecom';
        $result=GatewayTransport::postJson($base.$path,$path,$body,$api,$secret);
        $providerStatus=strtolower((string)($result['status']??''));
        if(in_array($providerStatus,['failed','failure','error'],true))throw new \RuntimeException((string)($result['errorMessage']??'iyzico ödeme formu oluşturulamadı.'));
        if($providerStatus!=='success')throw new GatewayTransportException('iyzico yanıtı ödeme oturumunun durumunu doğrulamıyor.');
        try{
            if(empty($result['token']))throw new \RuntimeException('iyzico başarılı yanıtında ödeme formu anahtarı bulunamadı.');
            $token=(string)$result['token'];$this->updateTransaction($txId,['request_reference'=>$token]);
            if(!empty($result['paymentPageUrl'])){$this->redirectExternal((string)$result['paymentPageUrl']);}
            $html=base64_decode((string)($result['checkoutFormContent']??''),true);
            if($html===false||$html==='')throw new \RuntimeException('iyzico ödeme formu içeriği alınamadı.');
        }catch(Throwable $e){if($e instanceof GatewayTransportException)throw $e;throw new GatewayTransportException('iyzico ödeme oturumu oluşturuldu ancak form bilgisi güvenle kullanılamadı.',$e->getCode(),$e);}
        $this->pageStart('iyzico güvenli ödeme');echo '<main class="detail"><a class="brand" href="'.$this->url('/client').'">HOST<span>PILOT</span></a><section class="productdetail"><h1>iyzico güvenli ödeme</h1><div id="iyzipay-checkout-form" class="responsive">'.$html.'</div></section></main>';$this->pageEnd();
    }

    public function paytrCallback():void
    {
        $oid=(string)($_POST['merchant_oid']??'');$status=(string)($_POST['status']??'');$total=(string)($_POST['total_amount']??'');$hash=(string)($_POST['hash']??'');
        if($oid===''||$hash===''){http_response_code(400);echo 'BAD';return;}
        $v=$this->settings();if($v['paytr_merchant_key']===''||$v['paytr_merchant_salt']===''){http_response_code(503);echo 'NO_CONFIG';return;}
        try{$key=$this->vault->decrypt($v['paytr_merchant_key']);$salt=$this->vault->decrypt($v['paytr_merchant_salt']);}
        catch(Throwable){http_response_code(503);echo 'NO_CONFIG';return;}
        if(!PaytrSignature::verifyCallback($oid,$salt,$status,$total,$hash,$key)){http_response_code(400);echo 'BAD_HASH';return;}
        $q=$this->db->prepare("SELECT pt.*,i.total AS invoice_total,i.currency AS invoice_currency,i.invoice_number FROM payment_transactions pt JOIN invoices i ON i.id=pt.invoice_id WHERE pt.gateway='paytr' AND pt.request_reference=:ref LIMIT 1");
        $q->execute([':ref'=>$oid]);$tx=$q->fetch();
        if(!$tx){http_response_code(404);echo 'NOT_FOUND';return;}
        $paid=(int)$total;
        $expectedAmount=(int)round((float)$tx['amount']*100);
        $callbackCurrency=strtoupper(trim((string)($_POST['currency']??'')));
        if($status==='success'){
            // PayTR documents that collected total_amount can differ from payment_amount
            // (for example, installment/alternative payment flows). A verified capture
            // with a different amount must never be labelled failed or silently settled.
            if(!ctype_digit($total)||$paid<1||$paid!==$expectedAmount||($callbackCurrency!==''&&$callbackCurrency!=='TL')){
                $this->updateTransaction((int)$tx['id'],[
                    'status'=>'review',
                    'response_code'=>'amount_mismatch_review',
                    'safe_message'=>'PayTR başarılı ödeme bildirdi ancak tahsilat tutarı veya para birimi beklenen değerle eşleşmiyor. Sağlayıcı panelinde mutabakat gerekiyor.'
                ]);
            }else{
                $this->settle($tx,'success',$oid,'PayTR callback doğrulandı');
            }
        }else{
            $this->updateTransaction((int)$tx['id'],['status'=>'failed','response_code'=>$status,'safe_message'=>'PayTR ödeme onaylanmadı']);
        }
        header('Content-Type: text/plain; charset=utf-8');echo 'OK';
    }

    public function iyzicoCallback():void
    {
        $token=trim((string)($_POST['token']??''));if($token===''){http_response_code(400);echo 'Eksik token';return;}
        $q=$this->db->prepare("SELECT pt.*,i.total AS invoice_total,i.currency AS invoice_currency,i.invoice_number FROM payment_transactions pt JOIN invoices i ON i.id=pt.invoice_id WHERE pt.gateway='iyzico' AND pt.request_reference=:token LIMIT 1");
        $q->execute([':token'=>$token]);$tx=$q->fetch();
        if(!$tx){http_response_code(404);echo 'İşlem bulunamadı.';return;}
        $v=$this->settings();try{
            $api=$this->vault->decrypt($v['iyzico_api_key']);$secret=$this->vault->decrypt($v['iyzico_secret_key']);
            $base=$v['iyzico_test_mode']==='1'?'https://sandbox-api.iyzipay.com':'https://api.iyzipay.com';
            $path='/payment/iyzipos/checkoutform/auth/ecom/detail';
            $result=GatewayTransport::postJson($base.$path,$path,['locale'=>'tr','conversationId'=>$tx['idempotency_key'],'token'=>$token],$api,$secret);
            $assessment=IyzicoSignature::assess($result,[
                'currency'=>(string)$tx['currency'],
                'amount'=>(float)$tx['amount'],
                'invoice_number'=>(string)$tx['invoice_number'],
                'idempotency_key'=>(string)$tx['idempotency_key']
            ],$token,$secret);
            $gatewayId=(string)($result['paymentId']??$token);
            if($assessment['decision']==='success'){
                $this->settle($tx,'success',$gatewayId,$assessment['message']);
            }elseif($assessment['decision']==='pending'){
                $this->updateTransaction((int)$tx['id'],['status'=>'pending','gateway_transaction_id'=>$gatewayId,'response_code'=>$assessment['code'],'safe_message'=>$assessment['message']]);
            }elseif($assessment['decision']==='review'){
                $this->updateTransaction((int)$tx['id'],['status'=>'review','gateway_transaction_id'=>$gatewayId,'response_code'=>$assessment['code'],'safe_message'=>$assessment['message']]);
            }else{
                $this->updateTransaction((int)$tx['id'],['status'=>'failed','gateway_transaction_id'=>$gatewayId,'response_code'=>$assessment['code'],'safe_message'=>$assessment['message']]);
            }
            $this->redirect('/client/invoices');
        }catch(Throwable $e){error_log('[HostPilot] iyzico callback: '.$e->getMessage());http_response_code(502);echo 'Ödeme sonucu sağlayıcıdan doğrulanamadı. Lütfen daha sonra faturalarınızı kontrol edin.';}
    }

    public function returnPage():void
    {$this->redirect('/client/invoices');}

    private function settle(array $tx,string $status,string $gatewayId,string $message,?array $reconciliation=null):void
    {
        $packageNotice=null;
        $this->db->beginTransaction();
        try{
            $lock=$this->db->prepare('SELECT * FROM payment_transactions WHERE id=:id FOR UPDATE');$lock->execute([':id'=>$tx['id']]);$current=$lock->fetch();
            if(!$current||in_array($current['status'],['success','failed','cancelled','refunded','partially_refunded'],true)){$this->db->commit();return;}
            if($reconciliation!==null&&$current['status']!=='review')throw new \RuntimeException('Only a reviewed payment can be manually reconciled.');
            $inv=$this->db->prepare('SELECT * FROM invoices WHERE id=:id FOR UPDATE');$inv->execute([':id'=>$tx['invoice_id']]);$invoice=$inv->fetch();
            $remaining=$invoice?round((float)$invoice['total']-(float)$invoice['amount_paid'],2):0.0;
            if(!$invoice||round((float)$tx['amount'],2)>$remaining+0.005){
                // The provider may already have captured a duplicate attempt. Acknowledge the callback,
                // retain a visible manual-reconciliation state, and never silently lose the capture.
                $review=$this->db->prepare("UPDATE payment_transactions SET status='review',response_code='overpayment_review',safe_message=:message,updated_at=:updated WHERE id=:id");
                $review->execute([':message'=>'Sağlayıcı ödemeyi onayladı ancak fatura bakiyesi yetersiz. Manuel iade / mutabakat gerekiyor.',':updated'=>gmdate('Y-m-d H:i:s'),':id'=>$tx['id']]);
                $this->queuePaymentStatusForTransaction((int)$tx['id'],'review');
                $this->db->commit();
                SecurityAudit::record($this->db,'payment.transaction_reviewed',null,['resource_id'=>(int)$tx['id'],'action'=>'review']);
                return;
            }
            $newPaid=round((float)$invoice['amount_paid']+(float)$tx['amount'],2);$newStatus=$newPaid+0.005>=(float)$invoice['total']?'paid':'partially_paid';$now=gmdate('Y-m-d H:i:s');
            $u=$this->db->prepare('UPDATE invoices SET amount_paid=:paid,status=:status,paid_at=:paid_at,updated_at=:updated WHERE id=:id');
            $u->execute([':paid'=>$newPaid,':status'=>$newStatus,':paid_at'=>$newStatus==='paid'?$now:null,':updated'=>$now,':id'=>$invoice['id']]);
            $p=$this->db->prepare("UPDATE payment_transactions SET status='success',gateway_transaction_id=:gateway_id,response_code='success',safe_message=:message,updated_at=:updated WHERE id=:id");
            $p->execute([':gateway_id'=>$gatewayId,':message'=>$message,':updated'=>$now,':id'=>$tx['id']]);
            $this->queuePaymentStatusForTransaction((int)$tx['id'],'success');
            if($reconciliation!==null){
                $record=$this->db->prepare("INSERT INTO payment_reconciliations(payment_transaction_id,admin_user_id,decision,provider_reference,amount,currency,reason,created_at) VALUES(:tx,:admin,:decision,:reference,:amount,:currency,:reason,:created)");
                $record->execute([':tx'=>$tx['id'],':admin'=>$reconciliation['admin_user_id'],':decision'=>$reconciliation['decision'],':reference'=>$reconciliation['provider_reference'],':amount'=>$tx['amount'],':currency'=>$tx['currency'],':reason'=>$reconciliation['reason'],':created'=>$now]);
            }
            if($newStatus==='paid'){
                $this->db->prepare("UPDATE orders SET status='paid',updated_at=:updated WHERE id=:id")->execute([':updated'=>$now,':id'=>$invoice['order_id']]);
                $packageInvoice=$this->db->prepare('SELECT id FROM service_package_changes WHERE invoice_id=:invoice LIMIT 1');$packageInvoice->execute([':invoice'=>$invoice['id']]);$isPackageInvoice=(bool)$packageInvoice->fetchColumn();
                $exists=$this->db->prepare('SELECT COUNT(*) FROM services WHERE order_id=:id');$exists->execute([':id'=>$invoice['order_id']]);
                if(!$isPackageInvoice&&(int)$exists->fetchColumn()===0){
                    $items=$this->db->prepare('SELECT o.user_id,oi.product_id,oi.billing_cycle,oi.line_total FROM orders o JOIN order_items oi ON oi.order_id=o.id JOIN products pr ON pr.id=oi.product_id WHERE o.id=:order_id AND pr.product_type<>\'domain\'');
                    $items->execute([':order_id'=>$invoice['order_id']]);
                    $service=$this->db->prepare("INSERT INTO services(user_id,product_id,order_id,status,billing_cycle,amount,next_due_date,provisioning_status,created_at,updated_at) VALUES(:user,:product,:order_id,'pending',:cycle,:amount,:due,'waiting_input',:created,:updated)");
                    foreach($items->fetchAll() as $item)$service->execute([':user'=>$item['user_id'],':product'=>$item['product_id'],':order_id'=>$invoice['order_id'],':cycle'=>$item['billing_cycle'],':amount'=>$item['line_total'],':due'=>$this->nextDueDate((string)$item['billing_cycle']),':created'=>$now,':updated'=>$now]);
                }
                $domainQueue=$this->db->prepare("UPDATE domain_registrations SET status='queued',available_at=:available,updated_at=:updated WHERE invoice_id=:invoice AND status='awaiting_payment'");
                $domainQueue->execute([':available'=>$now,':updated'=>$now,':invoice'=>$invoice['id']]);
                $renewalQueue=$this->db->prepare("UPDATE domain_renewals SET status='queued',available_at=:available,updated_at=:updated WHERE invoice_id=:invoice AND status='awaiting_payment'");
                $renewalQueue->execute([':available'=>$now,':updated'=>$now,':invoice'=>$invoice['id']]);
                $changeQuery=$this->db->prepare("SELECT * FROM service_package_changes WHERE invoice_id=:invoice AND status='pending_payment' FOR UPDATE");
                $changeQuery->execute([':invoice'=>$invoice['id']]);$packageChange=$changeQuery->fetch();
                if($packageChange){
                    $noticeQuery=$this->db->prepare('SELECT u.email,u.name,s.remote_domain,fp.name AS from_name,tp.name AS to_name FROM services s JOIN users u ON u.id=s.user_id JOIN products fp ON fp.id=:from_product JOIN products tp ON tp.id=:to_product WHERE s.id=:service LIMIT 1');
                    $noticeQuery->execute([':from_product'=>$packageChange['from_product_id'],':to_product'=>$packageChange['to_product_id'],':service'=>$packageChange['service_id']]);$packageNotice=$noticeQuery->fetch()?:null;
                    $jobNow=gmdate('Y-m-d H:i:s');$packageJob='package_'.(int)$packageChange['id'];
                    $this->db->prepare("UPDATE service_package_changes SET status='queued',updated_at=:updated WHERE id=:id")->execute([':updated'=>$jobNow,':id'=>$packageChange['id']]);
                    $job=$this->db->prepare("INSERT INTO provisioning_jobs(service_id,job_type,status,attempts,available_at,created_at,updated_at) VALUES(:service,:type,'queued',0,:available,:created,:updated) ON DUPLICATE KEY UPDATE status=IF(status IN ('failed','queued'),'queued',status),available_at=IF(status='failed',VALUES(available_at),available_at),last_error=IF(status='failed',NULL,last_error),updated_at=VALUES(updated_at)");
                    $job->execute([':service'=>$packageChange['service_id'],':type'=>$packageJob,':available'=>$jobNow,':created'=>$jobNow,':updated'=>$jobNow]);
                }
                if(!empty($invoice['service_id'])){
                    $jobNow=gmdate('Y-m-d H:i:s');
                    $jobType='unsuspend_'.(int)$invoice['id'];
                    $job=$this->db->prepare("INSERT INTO provisioning_jobs(service_id,job_type,status,attempts,available_at,created_at,updated_at) VALUES(:service,:type,'queued',0,:available,:created,:updated) ON DUPLICATE KEY UPDATE status=IF(status IN ('failed','queued'),'queued',status),available_at=IF(status='failed',VALUES(available_at),available_at),last_error=IF(status='failed',NULL,last_error),updated_at=VALUES(updated_at)");
                    $job->execute([':service'=>$invoice['service_id'],':type'=>$jobType,':available'=>$jobNow,':created'=>$jobNow,':updated'=>$jobNow]);
                }
            }
            $this->db->commit();
            SecurityAudit::record($this->db,'payment.transaction_settled',null,['resource_id'=>(int)$tx['id'],'action'=>$newStatus]);
            if($newStatus==='paid'&&is_array($packageNotice))(new EmailService($this->db,$this->applicationKey))->sendPackageChange((string)$packageNotice['email'],(string)$packageNotice['name'],(string)($packageNotice['remote_domain']??''),(string)$packageNotice['from_name'],(string)$packageNotice['to_name'],'payment');
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function nextDueDate(string $cycle):?string
    {
        $modify=['monthly'=>'+1 month','quarterly'=>'+3 months','semiannually'=>'+6 months','annually'=>'+1 year','biennially'=>'+2 years','triennially'=>'+3 years'];
        if(!isset($modify[$cycle]))return null;
        return (new \DateTimeImmutable('today',new \DateTimeZone('UTC')))->modify($modify[$cycle])->format('Y-m-d');
    }

    private function queuePaymentStatusForTransaction(int $id,string $event):void
    {
        if(!in_array($event,['pending','success','review','failed'],true)||$id<1)return;
        $q=$this->db->prepare('SELECT pt.id,pt.amount,i.invoice_number,i.currency,u.name,u.email FROM payment_transactions pt JOIN invoices i ON i.id=pt.invoice_id JOIN users u ON u.id=pt.user_id WHERE pt.id=:id LIMIT 1');
        $q->execute([':id'=>$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)return;
        (new EmailService($this->db,$this->applicationKey))->queuePaymentStatusNotice((string)$row['email'],(string)$row['name'],(string)$row['invoice_number'],(float)$row['amount'],(string)$row['currency'],$event,(int)$row['id']);
    }

    private function updateTransaction(int $id,array $fields):void
    {
        $allowed=['status','gateway_transaction_id','request_reference','response_code','safe_message'];
        $sets=[];$params=[':id'=>$id];foreach($fields as $k=>$v)if(in_array($k,$allowed,true)){$sets[]=$k.'=:'.$k;$params[':'.$k]=$v;}
        if(!$sets)return;
        $sets[]='updated_at=:updated';$params[':updated']=gmdate('Y-m-d H:i:s');
        $ownsTransaction=!$this->db->inTransaction();
        if($ownsTransaction)$this->db->beginTransaction();
        try{
            $where=' WHERE id=:id';
            if(isset($fields['status'])&&$fields['status']!=='success')$where.=" AND status NOT IN ('success','cancelled','refunded','partially_refunded','review')";
            $q=$this->db->prepare('UPDATE payment_transactions SET '.implode(',',$sets).$where);$q->execute($params);
            if($q->rowCount()===1&&isset($fields['status'])){
                $event=(string)$fields['status'];
                if(in_array($event,['pending','review','failed'],true))$this->queuePaymentStatusForTransaction($id,$event);
                if($event==='failed')SecurityAudit::record($this->db,'payment.transaction_failed',null,['resource_id'=>$id,'action'=>'failed']);
            }
            if($ownsTransaction)$this->db->commit();
        }catch(Throwable $e){if($ownsTransaction&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function settings():array
    {
        $defaults=['paytr_enabled'=>'0','paytr_test_mode'=>'1','paytr_merchant_id'=>'','paytr_merchant_key'=>'','paytr_merchant_salt'=>'','iyzico_enabled'=>'0','iyzico_test_mode'=>'1','iyzico_api_key'=>'','iyzico_secret_key'=>''];
        $q=$this->db->query("SELECT setting_key,setting_value FROM settings WHERE setting_group='payment'");
        foreach($q->fetchAll() as $row)if(array_key_exists($row['setting_key'],$defaults))$defaults[$row['setting_key']]=(string)$row['setting_value'];
        return $defaults;
    }
    private function isStaff():bool
    {
        return Authorization::allowsCurrentRequest(Auth::user(),$this->db,$this->basePath);
    }
    private function clientIp():string
    {$ip=(string)($_SERVER['REMOTE_ADDR']??'');return filter_var($ip,FILTER_VALIDATE_IP)?$ip:'127.0.0.1';}
    private function asciiEmail(string $email):string
    {return substr(preg_replace('/[^\\x20-\\x7E]/','',$email)??$email,0,100);}
    private function validTurkishId(string $number):bool
    {
        if(!preg_match('/^[1-9][0-9]{10}$/',$number))return false;
        $d=array_map('intval',str_split($number));
        $odd=$d[0]+$d[2]+$d[4]+$d[6]+$d[8];$even=$d[1]+$d[3]+$d[5]+$d[7];
        return (($odd*7-$even)%10)===$d[9]&&(array_sum(array_slice($d,0,10))%10)===$d[10];
    }

    private function normalizeMoney(mixed $v):string
    {$s=rtrim(rtrim(number_format((float)$v,8,'.',''),'0'),'.');return $s===''?'0':$s;}
    private function pageStart(string $title):void
    {echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->e($title).' · HostPilot</title><style>body{margin:0;background:#0a1020;color:#e8edf7;font:15px system-ui}*{box-sizing:border-box}.detail{max-width:900px;margin:45px auto;padding:22px}.brand{font-weight:900;letter-spacing:.12em;color:#fff;text-decoration:none}.brand span{color:#7898ff}.back{display:block;margin:25px 0;color:#9fb4dd}.productdetail,.box{background:#111c2f;border:1px solid #26354c;border-radius:14px;padding:25px;margin:14px 0}.productdetail h1{font-size:28px}.productdetail label{display:block;margin:15px 0;color:#bac7da;font-size:13px}.productdetail input,.productdetail select{display:block;width:100%;margin-top:7px;padding:12px;background:#0b1425;color:#fff;border:1px solid #34445e;border-radius:8px}.button{display:inline-block;background:#5278ed;color:#fff;border:0;border-radius:8px;padding:12px 18px;text-decoration:none;cursor:pointer}.alert{padding:12px;background:#4b202b;color:#ffc3ca;border-radius:8px}.notice,.muted{color:#9aabc2;font-size:12px}.admin{display:flex;min-height:100vh}.admin aside{width:240px;background:#0e1729;padding:24px}.admin aside a{display:block;color:#aab7cc;padding:12px;text-decoration:none}.admin main{padding:30px;max-width:1100px;width:100%}.admin header small{color:#8fa6d8}.admin header h1{font-size:28px}.admin .box{max-width:760px}.admin label{display:block;margin:14px 0}.admin input:not([type=checkbox]){display:block;width:100%;padding:12px;background:#0b1425;border:1px solid #34445e;border-radius:8px;color:#fff}.admin input[type=checkbox]{margin-right:8px}@media(max-width:700px){.admin aside{width:65px;padding:10px}.admin aside a{font-size:0}.admin main{padding:15px}.detail{padding:12px;margin:20px auto}}</style></head><body>';}
    private function pageEnd():void{echo '</body></html>';}
    private function url(string $path):string{return htmlspecialchars(rtrim($this->basePath,'/').$path,ENT_QUOTES,'UTF-8');}
    private function absolute(string $path):string{return rtrim($this->baseUrl,'/').$path;}
    private function redirect(string $path):never{header('Location: '.$this->url($path));exit;}
    private function redirectExternal(string $url):never
    {$host=strtolower((string)(parse_url($url,PHP_URL_HOST)??''));if(parse_url($url,PHP_URL_SCHEME)!=='https'||!($host==='api.iyzipay.com'||$host==='sandbox-api.iyzipay.com'||str_ends_with($host,'.iyzipay.com')))throw new \RuntimeException('Ödeme sağlayıcısının dönüş adresi geçersiz.');header('Location: '.$url);exit;}
    private function e(string $s):string{return \HostPilot\Core\Html::escape($s);}
}
