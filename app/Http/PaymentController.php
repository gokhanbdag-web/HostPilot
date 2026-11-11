<?php
declare(strict_types=1);
namespace HostPilot\Http;

use HostPilot\Core\Auth;
use HostPilot\Core\SecretVault;
use HostPilot\Payment\GatewayTransport;
use HostPilot\Payment\PaytrSignature;
use PDO;
use Throwable;

final class PaymentController
{
    private SecretVault $vault;
    public function __construct(private readonly PDO $db,private readonly string $basePath,private readonly string $baseUrl,string $appKey)
    {$this->vault=new SecretVault($appKey);}

    public function adminSettings():void
    {
        if(!$this->isStaff()){http_response_code(403);echo 'Yetkisiz erişim.';return;}
        $notice='';$error='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!str_starts_with(strtolower($this->baseUrl),'https://')){http_response_code(400);$this->paymentPage($invoice,$enabled,'Ödeme işlemleri için sitenizde geçerli HTTPS/SSL etkin olmalıdır.');return;}
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

    public function payInvoice(int $invoiceId):void
    {
        $user=Auth::user();
        if(!$user||($user['role']??'')!=='customer'){$this->redirect('/client/login');}
        $q=$this->db->prepare('SELECT * FROM invoices WHERE id=:id AND user_id=:uid LIMIT 1');$q->execute([':id'=>$invoiceId,':uid'=>$user['id']]);$invoice=$q->fetch();
        if(!$invoice){http_response_code(404);echo 'Fatura bulunamadı.';return;}
        if($invoice['status']==='paid'){$this->redirect('/client/invoices');}
        $v=$this->settings();$enabled=[];
        if($v['paytr_enabled']==='1'&&$v['paytr_merchant_id']!==''&&$v['paytr_merchant_key']!==''&&$v['paytr_merchant_salt']!=='')$enabled[]='paytr';
        if($v['iyzico_enabled']==='1'&&$v['iyzico_api_key']!==''&&$v['iyzico_secret_key']!=='')$enabled[]='iyzico';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!Auth::verifyCsrf($_POST['_token']??null)){http_response_code(400);echo 'Güvenlik doğrulaması başarısız.';return;}
            $gateway=(string)($_POST['gateway']??'');
            if(!in_array($gateway,$enabled,true)){http_response_code(422);echo 'Bu ödeme yöntemi etkin değil.';return;}
            try{
                $amount=round((float)$invoice['total']-(float)$invoice['amount_paid'],2);
                if($amount<=0)throw new \RuntimeException('Ödenecek tutar bulunamadı.');
                $ref='HPP'.gmdate('ymdHis').strtoupper(bin2hex(random_bytes(5)));
                $now=gmdate('Y-m-d H:i:s');
                $ins=$this->db->prepare("INSERT INTO payment_transactions(invoice_id,user_id,gateway,idempotency_key,status,currency,amount,request_reference,created_at,updated_at) VALUES(:invoice,:user,:gateway,:key,'pending',:currency,:amount,:reference,:created,:updated)");
                $ins->execute([':invoice'=>$invoiceId,':user'=>$user['id'],':gateway'=>$gateway,':key'=>bin2hex(random_bytes(24)),':currency'=>$invoice['currency'],':amount'=>$amount,':reference'=>$ref,':created'=>$now,':updated'=>$now]);
                $txId=(int)$this->db->lastInsertId();
                if($gateway==='paytr')$this->startPaytr($txId,$ref,$invoice,$user,$amount,$v);
                else $this->startIyzico($txId,$ref,$invoice,$user,$amount,$v);
                return;
            }catch(Throwable $e){http_response_code(502);$this->paymentPage($invoice,$enabled,'Ödeme başlatılamadı: '.$e->getMessage());return;}
        }
        $this->paymentPage($invoice,$enabled,'');
    }

    private function paymentPage(array $invoice,array $enabled,string $error):void
    {
        $this->pageStart('Fatura ödemesi');
        echo '<main class="detail"><a class="brand" href="'.$this->url('/client').'">HOST<span>PILOT</span></a><a class="back" href="'.$this->url('/client/invoices').'">← Faturalarıma dön</a><section class="productdetail"><small>GÜVENLİ ÖDEME</small><h1>#'.$this->e($invoice['invoice_number']).'</h1><h2>'.number_format((float)$invoice['total']-(float)$invoice['amount_paid'],2,',','.').' '.$this->e($invoice['currency']).'</h2><p>Ödeme sağlayıcısına yönlendirilmeden önce bilgilerinizi kontrol edin.</p>';
        if($error!=='')echo '<div class="alert">'.$this->e($error).'</div>';
        if(!$enabled)echo '<p>Şu anda etkin bir çevrimiçi ödeme yöntemi bulunmuyor. Lütfen yönetimle iletişime geçin.</p>';
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
        $basketEncoded=base64_encode(json_encode($basket,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $merchantId=$settings['paytr_merchant_id'];$paymentAmount=(string)round($amount*100);$noInstallment='0';$maxInstallment='0';$currency='TL';$test=$settings['paytr_test_mode']==='1'?'1':'0';
        $hash=$merchantId.$ip.$merchantOid.$email.$paymentAmount.$basketEncoded.$noInstallment.$maxInstallment.$currency.$test;
        $token=base64_encode(hash_hmac('sha256',$hash.$salt,$key,true));
        $payload=[
            'merchant_id'=>$merchantId,'user_ip'=>$ip,'merchant_oid'=>$merchantOid,'email'=>$email,
            'payment_amount'=>$paymentAmount,'paytr_token'=>$token,'user_basket'=>$basketEncoded,
            'debug_on'=>$test,'no_installment'=>$noInstallment,'max_installment'=>$maxInstallment,
            'user_name'=>substr((string)$user['name'],0,60),'user_address'=>substr(trim((string)($_POST['address']??'')),0,400),
            'user_phone'=>substr(trim((string)($_POST['phone']??'')),0,20),
            'merchant_ok_url'=>$this->absolute('/payment/return'),'merchant_fail_url'=>$this->absolute('/payment/return'),
            'timeout_limit'=>'30','currency'=>$currency,'test_mode'=>$test,'lang'=>'tr','iframe_v2'=>'1'
        ];
        $result=GatewayTransport::postForm('https://www.paytr.com/odeme/api/get-token',$payload);
        if(($result['status']??'')!=='success'||empty($result['token']))throw new \RuntimeException((string)($result['reason']??'PayTR iframe token üretilemedi.'));
        $this->updateTransaction($txId,['request_reference'=>$merchantOid]);
        $this->pageStart('PayTR güvenli ödeme');
        echo '<main class="detail"><a class="brand" href="'.$this->url('/client').'">HOST<span>PILOT</span></a><section class="productdetail"><h1>PayTR güvenli ödeme</h1><iframe src="https://www.paytr.com/odeme/guvenli/'.$this->e((string)$result['token']).'" id="paytriframe" frameborder="0" scrolling="no" style="width:100%;min-height:720px"></iframe><script src="https://www.paytr.com/js/iframeResizer.min.js?v2"></script><script>if(window.iFrameResize){iFrameResize({},\"#paytriframe\")}</script></section></main>';$this->pageEnd();
    }

    private function startIyzico(int $txId,string $conversationId,array $invoice,array $user,float $amount,array $settings):void
    {
        $api=$this->vault->decrypt($settings['iyzico_api_key']);$secret=$this->vault->decrypt($settings['iyzico_secret_key']);
        $base=$settings['iyzico_test_mode']==='1'?'https://sandbox-api.iyzipay.com':'https://api.iyzipay.com';
        $phone=preg_replace('/[^0-9+]/','',(string)($_POST['phone']??''));$identity=preg_replace('/\D/','',(string)($_POST['identity_number']??''));
        $address=trim((string)($_POST['address']??''));$city=trim((string)($_POST['city']??''));$zip=trim((string)($_POST['zip']??''));
        if(strlen($identity)!==11||$phone===''||$address===''||$city===''||$zip==='')throw new \RuntimeException('iyzico için telefon, 11 haneli T.C. kimlik numarası ve adres bilgileri gereklidir.');
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
        if(($result['status']??'')!=='success'||empty($result['token']))throw new \RuntimeException((string)($result['errorMessage']??'iyzico ödeme formu oluşturulamadı.'));
        $token=(string)$result['token'];$this->updateTransaction($txId,['request_reference'=>$token]);
        if(!empty($result['paymentPageUrl'])){$this->redirectExternal((string)$result['paymentPageUrl']);}
        $html=base64_decode((string)($result['checkoutFormContent']??''),true);
        if($html===false||$html==='')throw new \RuntimeException('iyzico ödeme formu içeriği alınamadı.');
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
        if($status==='success'&&$paid===$expectedAmount){
            $this->settle($tx,'success',$oid,'PayTR callback doğrulandı');
        }else{$this->updateTransaction((int)$tx['id'],['status'=>'failed','response_code'=>$status,'safe_message'=>'PayTR ödeme başarısız veya tutar eşleşmedi']);}
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
            $paid=(float)($result['paidPrice']??0);$expected=(float)$tx['amount'];$currency=(string)($result['currency']??'');
            $sigData=implode(':',[(string)($result['paymentStatus']??''),(string)($result['paymentId']??''),$currency,(string)($result['basketId']??''),(string)($result['conversationId']??''),$this->normalizeMoney($result['paidPrice']??''),$this->normalizeMoney($result['price']??''),$token]);
            $signature=(string)($result['signature']??'');$signatureOk=$signature!==''&&hash_equals(hash_hmac('sha256',$sigData,$secret),$signature);
            $ok=($result['status']??'')==='success'&&($result['paymentStatus']??'')==='SUCCESS'&&(int)($result['fraudStatus']??-1)===1&&$currency===(string)$tx['currency']&&abs($paid-$expected)<0.005&&abs((float)($result['price']??0)-$expected)<0.005&&(string)($result['basketId']??'')===(string)$tx['invoice_number']&&(string)($result['conversationId']??'')===(string)$tx['idempotency_key']&&$signatureOk;
            if($ok)$this->settle($tx,'success',(string)($result['paymentId']??$token),'iyzico retrieve and signature verified');
            else $this->updateTransaction((int)$tx['id'],['status'=>'failed','gateway_transaction_id'=>(string)($result['paymentId']??''),'response_code'=>(string)($result['errorCode']??$result['paymentStatus']??'not_approved'),'safe_message'=>'iyzico sonucu doğrulanamadı veya ödeme onaylanmadı']);
            $this->redirect('/client/invoices');
        }catch(Throwable $e){error_log('[HostPilot] iyzico callback: '.$e->getMessage());http_response_code(502);echo 'Ödeme sonucu sağlayıcıdan doğrulanamadı. Lütfen daha sonra faturalarınızı kontrol edin.';}
    }

    public function returnPage():void
    {$this->redirect('/client/invoices');}

    private function settle(array $tx,string $status,string $gatewayId,string $message):void
    {
        $this->db->beginTransaction();
        try{
            $lock=$this->db->prepare('SELECT * FROM payment_transactions WHERE id=:id FOR UPDATE');$lock->execute([':id'=>$tx['id']]);$current=$lock->fetch();
            if(!$current||$current['status']==='success'){$this->db->commit();return;}
            $inv=$this->db->prepare('SELECT * FROM invoices WHERE id=:id FOR UPDATE');$inv->execute([':id'=>$tx['invoice_id']]);$invoice=$inv->fetch();
            if(!$invoice||(float)$tx['amount']>((float)$invoice['total']-(float)$invoice['amount_paid']+0.005))throw new \RuntimeException('Tahsilat tutarı fatura bakiyesini aşıyor.');
            $newPaid=round((float)$invoice['amount_paid']+(float)$tx['amount'],2);$newStatus=$newPaid+0.005>=(float)$invoice['total']?'paid':'partially_paid';$now=gmdate('Y-m-d H:i:s');
            $u=$this->db->prepare('UPDATE invoices SET amount_paid=:paid,status=:status,paid_at=:paid_at,updated_at=:updated WHERE id=:id');
            $u->execute([':paid'=>$newPaid,':status'=>$newStatus,':paid_at'=>$newStatus==='paid'?$now:null,':updated'=>$now,':id'=>$invoice['id']]);
            $p=$this->db->prepare("UPDATE payment_transactions SET status='success',gateway_transaction_id=:gateway_id,response_code='success',safe_message=:message,updated_at=:updated WHERE id=:id");
            $p->execute([':gateway_id'=>$gatewayId,':message'=>$message,':updated'=>$now,':id'=>$tx['id']]);
            if($newStatus==='paid'){
                $this->db->prepare("UPDATE orders SET status='paid',updated_at=:updated WHERE id=:id")->execute([':updated'=>$now,':id'=>$invoice['order_id']]);
                $exists=$this->db->prepare('SELECT COUNT(*) FROM services WHERE order_id=:id');$exists->execute([':id'=>$invoice['order_id']]);
                if((int)$exists->fetchColumn()===0){
                    $items=$this->db->prepare('SELECT o.user_id,oi.product_id,oi.billing_cycle,oi.line_total FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE o.id=:order_id');
                    $items->execute([':order_id'=>$invoice['order_id']]);
                    $service=$this->db->prepare("INSERT INTO services(user_id,product_id,order_id,status,billing_cycle,amount,next_due_date,created_at,updated_at) VALUES(:user,:product,:order_id,'pending',:cycle,:amount,:due,:created,:updated)");
                    foreach($items->fetchAll() as $item)$service->execute([':user'=>$item['user_id'],':product'=>$item['product_id'],':order_id'=>$invoice['order_id'],':cycle'=>$item['billing_cycle'],':amount'=>$item['line_total'],':due'=>$this->nextDueDate((string)$item['billing_cycle']),':created'=>$now,':updated'=>$now]);
                }
            }
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function nextDueDate(string $cycle):?string
    {
        $modify=['monthly'=>'+1 month','quarterly'=>'+3 months','semiannually'=>'+6 months','annually'=>'+1 year','biennially'=>'+2 years','triennially'=>'+3 years'];
        if(!isset($modify[$cycle]))return null;
        return (new \DateTimeImmutable('today',new \DateTimeZone('UTC')))->modify($modify[$cycle])->format('Y-m-d');
    }

    private function updateTransaction(int $id,array $fields):void
    {
        $allowed=['status','gateway_transaction_id','request_reference','response_code','safe_message'];
        $sets=[];$params=[':id'=>$id];foreach($fields as $k=>$v)if(in_array($k,$allowed,true)){$sets[]=$k.'=:'.$k;$params[':'.$k]=$v;}
        if(!$sets)return;$sets[]='updated_at=:updated';$params[':updated']=gmdate('Y-m-d H:i:s');
        $where=' WHERE id=:id';if(isset($fields['status'])&&$fields['status']!=='success')$where.=" AND status<>'success'";$q=$this->db->prepare('UPDATE payment_transactions SET '.implode(',',$sets).$where);$q->execute($params);
    }

    private function settings():array
    {
        $defaults=['paytr_enabled'=>'0','paytr_test_mode'=>'1','paytr_merchant_id'=>'','paytr_merchant_key'=>'','paytr_merchant_salt'=>'','iyzico_enabled'=>'0','iyzico_test_mode'=>'1','iyzico_api_key'=>'','iyzico_secret_key'=>''];
        $q=$this->db->query("SELECT setting_key,setting_value FROM settings WHERE setting_group='payment'");
        foreach($q->fetchAll() as $row)if(array_key_exists($row['setting_key'],$defaults))$defaults[$row['setting_key']]=(string)$row['setting_value'];
        return $defaults;
    }
    private function isStaff():bool{return in_array((Auth::user()['role']??''),['super_admin','admin'],true);}
    private function clientIp():string
    {$ip=(string)($_SERVER['REMOTE_ADDR']??'');return filter_var($ip,FILTER_VALIDATE_IP)?$ip:'127.0.0.1';}
    private function asciiEmail(string $email):string
    {return substr(preg_replace('/[^\\x20-\\x7E]/','',$email)??$email,0,100);}
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
    private function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
