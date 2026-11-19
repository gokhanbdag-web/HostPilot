<?php
declare(strict_types=1);
namespace HostPilot\Http;
use HostPilot\Core\Auth;
use HostPilot\Core\SecretVault;
use HostPilot\Core\TaxCalculator;
use PDO;
use Throwable;

final class CatalogController
{
    public function __construct(private readonly PDO $db, private readonly string $basePath='',private readonly string $applicationKey='') {}

    public function storefront():void
    {
        $demoCheck=$this->db->prepare("SELECT setting_value FROM settings WHERE setting_group='demo' AND setting_key='seed_version' LIMIT 1");$demoCheck->execute();$demoActive=(string)$demoCheck->fetchColumn()==='1';
        $q=$this->db->query("SELECT p.id,p.name,p.slug,p.description,p.product_type,MIN(pp.price) AS from_price,MIN(pp.currency) AS currency FROM products p LEFT JOIN product_pricing pp ON pp.product_id=p.id AND pp.is_active=1 WHERE p.status='active' GROUP BY p.id ORDER BY p.featured DESC,p.sort_order,p.name");
        $rows=$q->fetchAll();$this->start('Hosting ve domain paketleri');
        echo '<main class="store"><header><a class="brand" href="'.$this->url('/').'">HOST<span>PILOT</span></a><nav>'.$this->publicPageLinks().'<a href="'.$this->url('/cart').'">Sepet ('.count($this->cartSession()).')</a><a href="'.$this->url('/knowledgebase').'">Yardım merkezi</a><a href="'.$this->url('/client').'">Müşteri paneli</a></nav></header><section class="storehero"><small>HOSTING • DOMAIN • ALTYAPI</small><h1>Serdar abi, ben ChatGPT.</h1><h1>Evet, otomatik güncelliyorum.</h1><p>HostPilot üzerinde geliştirmelerimi sürdürüyor, güncellemeleri otomatik dağıtım altyapısıyla hazırlıyorum.</p></section>';
        if($demoActive)echo '<div class="demo-banner" role="note" style="margin:18px 0;padding:13px 16px;border:1px solid #8b6b2c;background:#302716;color:#f6d78b;border-radius:10px"><strong>DEMO KATALOĞU</strong> · Örnek paketler ve fiyatlardır. Ödeme ve sunucu bağlantıları yapılandırılmadan gerçek sipariş/aktivasyon yapılmaz.</div>';
        echo '<section class="products">';
        if(!$rows)echo '<div class="empty">Satışa açık ürün bulunmuyor. Paketler yakında burada yayınlanacak.</div>';
        foreach($rows as $p){$price=$p['from_price']!==null?number_format((float)$p['from_price'],2,',','.').' '.($p['currency']?:'TRY'):'Fiyat için iletişime geçin';echo '<article class="product"><span class="type">'.$this->e(strtoupper($p['product_type'])).'</span><h2>'.$this->e($p['name']).'</h2><p>'.$this->e(substr(strip_tags((string)$p['description']),0,220)).'</p><strong>'.$this->e($price).'</strong><a class="button" href="'.$this->url('/product/'.$this->e($p['slug'])).'">Paketi incele</a></article>';}
        echo '</section><footer>HostPilot · Hosting ve domain hizmetleri<nav>'.$this->footerMenuLinks().'</nav></footer></main>';$this->end();
    }

    public function product(string $slug):void
    {
        $q=$this->db->prepare("SELECT p.*,g.name AS group_name FROM products p LEFT JOIN product_groups g ON g.id=p.group_id WHERE p.slug=:slug AND p.status='active' LIMIT 1");$q->execute([':slug'=>$slug]);$p=$q->fetch();
        if(!$p){http_response_code(404);echo 'Ürün bulunamadı.';return;}
        $prices=$this->db->prepare("SELECT billing_cycle,price,currency,setup_fee FROM product_pricing WHERE product_id=:id AND is_active=1 ORDER BY FIELD(billing_cycle,'monthly','quarterly','semiannually','annually','biennially','triennially')");$prices->execute([':id'=>$p['id']]);$rows=$prices->fetchAll();
        $cartCount=count($this->cartSession());
        $this->start($p['name']);
        echo '<main class="detail"><a class="brand" href="'.$this->url('/').'">HOST<span>PILOT</span></a><nav>'.$this->publicPageLinks().'<a href="'.$this->url('/cart').'">Sepet ('.$cartCount.')</a><a href="'.$this->url('/knowledgebase').'">Yardım merkezi</a></nav><a class="back" href="'.$this->url('/').'">← Tüm paketler</a><section class="productdetail"><small>'.$this->e($p['group_name']??'HİZMET PAKETİ').'</small><h1>'.$this->e($p['name']).'</h1><div class="description">'.nl2br($this->e((string)$p['description'])).'</div><h2>Faturalandırma dönemi seçin</h2>';
        if(!$rows)echo '<p>Bu ürün için henüz aktif fiyat tanımlanmamış.</p>';
        else{
            echo '<form method="post" action="'.$this->url('/cart/add').'" class="price"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="product_id" value="'.(int)$p['id'].'">';
            foreach($rows as $index=>$r){
                $baseCents=$this->moneyCents($r['price']);$setupCents=$this->moneyCents($r['setup_fee']);$lineCents=$baseCents+$setupCents;
                echo '<label class="billing-option"><input type="radio" name="billing_cycle" value="'.$this->e((string)$r['billing_cycle']).'" '.($index===0?'checked':'').' required> '.$this->e($this->cycle((string)$r['billing_cycle'])).' — <b>'.number_format($lineCents/100,2,',','.').' '.$this->e((string)$r['currency']).'</b>';
                if($setupCents>0)echo '<small>Kurulum ücreti dahil: '.number_format($setupCents/100,2,',','.').' '.$this->e((string)$r['currency']).'</small>';
                echo '</label>';
            }
            if(($p['product_type']??'')==='domain'){
                echo '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;width:100%"><label>Alan adı<input name="domain_name" maxlength="253" placeholder="ornek.com" required></label><label>Adres<input name="addressLine1" maxlength="190" required></label><label>Şehir<input name="city" maxlength="80" required></label><label>Ülke kodu (ISO)<input name="country" value="TR" minlength="2" maxlength="2" required></label><label>Posta kodu<input name="zipcode" maxlength="20" required></label><label>Telefon ülke kodu<input name="phoneCountryCode" value="90" maxlength="5" required></label><label>Telefon<input name="phone" maxlength="25" required></label></div>';
            }
            echo '<button class="button">Sepete ekle</button> <a class="back" href="'.$this->url('/cart').'">Sepeti görüntüle</a></form>';
        }
        echo '</section></main>';$this->end();
    }

    public function cart():void
    {
        $cart=$this->cartSession();$rows=[];$currency='';$currencyConflict=false;
        foreach($cart as $key=>$entry){
            if(!is_array($entry)){unset($cart[$key]);continue;}
            $q=$this->db->prepare("SELECT p.id,p.name,p.slug,p.product_type,pp.price,pp.setup_fee,pp.currency,pp.billing_cycle FROM products p JOIN product_pricing pp ON pp.product_id=p.id WHERE p.id=:id AND p.status='active' AND pp.billing_cycle=:cycle AND pp.is_active=1 LIMIT 1");
            $q->execute([':id'=>(int)($entry['product_id']??0),':cycle'=>(string)($entry['billing_cycle']??'')]);$p=$q->fetch(PDO::FETCH_ASSOC);
            if(!$p){unset($cart[$key]);continue;}
            if($currency!==''&&$currency!==(string)$p['currency'])$currencyConflict=true;
            $currency=(string)$p['currency'];$p['cart_key']=(string)$key;
            $p['base_cents']=$this->moneyCents($p['price']);$p['setup_cents']=$this->moneyCents($p['setup_fee']);$p['line_cents']=$p['base_cents']+$p['setup_cents'];
            $p['domain_name']=(string)($entry['domain']['domain_name']??'');$rows[]=$p;
        }
        $_SESSION['hostpilot_cart']=$cart;
        $taxSettings=TaxCalculator::settings($this->db);$cartAmounts=[];
        foreach($rows as $row){$cartAmounts[]=$row['base_cents'];if($row['setup_cents']>0)$cartAmounts[]=$row['setup_cents'];}
        $cartTotals=TaxCalculator::totals($cartAmounts,$taxSettings);
        $this->start('Alışveriş sepeti');
        echo '<main class="detail"><a class="brand" href="'.$this->url('/').'">HOST<span>PILOT</span></a><section class="productdetail"><small>SİPARİŞ</small><h1>Alışveriş sepetiniz</h1>';
        if(!$rows)echo '<p>Sepetiniz şu anda boş. <a href="'.$this->url('/').'">Paketleri inceleyin.</a></p>';
        else{
            foreach($rows as $p){
                echo '<article class="order"><b>'.$this->e((string)$p['name']).'</b><span>'.number_format($p['line_cents']/100,2,',','.').' '.$this->e((string)$p['currency']).'</span><p>'.$this->e($this->cycle((string)$p['billing_cycle'])).' · '.$this->e((string)$p['product_type']).'</p>';
                if($p['setup_cents']>0)echo '<small>Kurulum: '.number_format($p['setup_cents']/100,2,',','.').' '.$this->e((string)$p['currency']).'</small>';
                if($p['product_type']==='domain')echo '<small>Alan adı: '.$this->e((string)$p['domain_name']).'</small>';
                echo '<form method="post" action="'.$this->url('/cart/remove').'"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="cart_key" value="'.$this->e((string)$p['cart_key']).'"><button class="button" style="background:#6b3444">Sepetten çıkar</button></form></article>';
            }
            if(!$currencyConflict)echo '<div class="order"><p>Ara toplam: <b>'.number_format($cartTotals['subtotal_cents']/100,2,',','.').' '.$this->e($currency).'</b></p><p>'.$this->e($taxSettings['label']).' ('.number_format($cartTotals['tax_cents']/100,2,',','.').' '.$this->e($currency).')</p><h2>Genel toplam: '.number_format($cartTotals['total_cents']/100,2,',','.').' '.$this->e($currency).'</h2><small>Vergi ayarı sipariş oluşturulurken yeniden doğrulanır.</small></div>';
            if($currencyConflict)echo '<p class="error">Sepette farklı para birimleri var. Sipariş vermek için ürünleri aynı para birimine getirin.</p>';
            elseif(Auth::check()&&(Auth::user()['role']??'')==='customer')echo '<form method="post" action="'.$this->url('/order/create').'"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><button class="button">Siparişi oluştur ve faturaya geç</button></form>';
            else echo '<p><a class="button" href="'.$this->url('/client/login').'">Giriş yaparak devam et</a></p>';
        }
        echo '<p><a class="back" href="'.$this->url('/').'">← Paketlere dön</a></p></section></main>';$this->end();
    }

    public function addToCart():void
    {
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||!Auth::verifyCsrf($_POST['_token']??null)){http_response_code(400);echo 'Güvenlik doğrulaması başarısız.';return;}
        $productId=(int)($_POST['product_id']??0);$cycle=(string)($_POST['billing_cycle']??'');
        if($productId<1||!in_array($cycle,['monthly','quarterly','semiannually','annually','biennially','triennially','onetime'],true)){http_response_code(422);echo 'Ürün veya faturalandırma dönemi geçersiz.';return;}
        $q=$this->db->prepare("SELECT p.id,p.name,p.product_type,pp.currency,pp.billing_cycle FROM products p JOIN product_pricing pp ON pp.product_id=p.id WHERE p.id=:id AND p.status='active' AND pp.billing_cycle=:cycle AND pp.is_active=1 LIMIT 1");
        $q->execute([':id'=>$productId,':cycle'=>$cycle]);$product=$q->fetch(PDO::FETCH_ASSOC);
        if(!$product){http_response_code(422);echo 'Seçilen paket veya fiyat artık satışta değil.';return;}
        $cart=$this->cartSession();$key=$productId.':'.$cycle;
        if(isset($cart[$key])){http_response_code(409);echo 'Bu paket ve dönem zaten sepetinizde.';return;}
        if(count($cart)>=10){http_response_code(409);echo 'Sepetinizde en fazla 10 ürün bulunabilir.';return;}
        foreach($cart as $entry){
            if(!is_array($entry))continue;
            if((string)($entry['currency']??'')!==(string)$product['currency']){http_response_code(409);echo 'Tek siparişte aynı para birimindeki ürünler bulunmalıdır.';return;}
            if(($entry['product_type']??'')==='domain'&&$product['product_type']==='domain'){http_response_code(409);echo 'Tek siparişte şimdilik yalnızca bir domain bulunabilir.';return;}
        }
        $domain=[];
        if($product['product_type']==='domain'){
            $domain=$this->normalizeDomainDetails($_POST);
            if($domain===null||$this->domainYears($cycle)<1){http_response_code(422);echo 'Domain ve kayıt sahibi bilgilerini eksiksiz girin; domain için yıllık dönem seçin.';return;}
        }
        $cart[$key]=['product_id'=>$productId,'billing_cycle'=>$cycle,'product_type'=>(string)$product['product_type'],'currency'=>(string)$product['currency'],'domain'=>$domain];
        $_SESSION['hostpilot_cart']=$cart;$this->redirect('/cart');
    }

    public function removeFromCart():void
    {
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||!Auth::verifyCsrf($_POST['_token']??null)){http_response_code(400);echo 'Güvenlik doğrulaması başarısız.';return;}
        $key=(string)($_POST['cart_key']??'');$cart=$this->cartSession();
        if(isset($cart[$key]))unset($cart[$key]);
        $_SESSION['hostpilot_cart']=$cart;$this->redirect('/cart');
    }

    public function createOrder():void
    {
        if(!Auth::check())$this->redirect('/client/login');
        $user=Auth::user();if(($user['role']??'')!=='customer'){$this->redirect('/client/login?reason=customer');}
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||!Auth::verifyCsrf($_POST['_token']??null)){http_response_code(400);echo 'Geçersiz veya süresi dolmuş istek.';return;}
        $cart=$this->cartSession();
        if(!$cart||count($cart)>10){http_response_code(422);echo 'Sepetiniz boş veya geçersiz.';return;}
        $items=[];$currency='';$totalCents=0;$domainData=null;$domainName='';$domainYears=0;
        foreach($cart as $entry){
            if(!is_array($entry)){http_response_code(422);echo 'Sepetinizde geçersiz bir ürün var.';return;}
            $productId=(int)($entry['product_id']??0);$cycle=(string)($entry['billing_cycle']??'');
            $q=$this->db->prepare("SELECT p.id,p.name,p.product_type,pp.price,pp.setup_fee,pp.currency,pp.billing_cycle FROM products p JOIN product_pricing pp ON pp.product_id=p.id WHERE p.id=:id AND p.status='active' AND pp.billing_cycle=:cycle AND pp.is_active=1 LIMIT 1");
            $q->execute([':id'=>$productId,':cycle'=>$cycle]);$p=$q->fetch(PDO::FETCH_ASSOC);
            if(!$p){http_response_code(422);echo 'Sepetinizdeki bir ürünün fiyatı veya satış durumu değişmiş. Lütfen sepetinizi güncelleyin.';return;}
            if($currency!==''&&$currency!==(string)$p['currency']){http_response_code(422);echo 'Tek siparişte farklı para birimleri kullanılamaz.';return;}
            $currency=(string)$p['currency'];$baseCents=$this->moneyCents($p['price']);$setupCents=$this->moneyCents($p['setup_fee']);$lineCents=$baseCents+$setupCents;
            if($p['product_type']==='domain'){
                if($domainData!==null){http_response_code(422);echo 'Tek siparişte yalnızca bir domain bulunabilir.';return;}
                $domainData=$this->normalizeDomainDetails(is_array($entry['domain']??null)?$entry['domain']:[]);
                $domainYears=$this->domainYears((string)$p['billing_cycle']);
                if($domainData===null||$domainYears<1){http_response_code(422);echo 'Domain kayıt bilgileri geçersiz. Lütfen ürünü sepetten çıkarıp yeniden ekleyin.';return;}
                $domainName=(string)$domainData['domain_name'];
            }
            $items[]=['id'=>(int)$p['id'],'name'=>(string)$p['name'],'product_type'=>(string)$p['product_type'],'billing_cycle'=>(string)$p['billing_cycle'],'base_cents'=>$baseCents,'setup_cents'=>$setupCents,'line_cents'=>$lineCents];
            $totalCents+=$lineCents;
        }
        if($totalCents<=0||$currency===''){http_response_code(422);echo 'Sipariş toplamı geçersiz.';return;}
        $registrarPayload=null;
        if($domainData!==null){
            if($this->applicationKey===''){http_response_code(503);echo 'Güvenli domain siparişi şu anda yapılandırılamıyor.';return;}
            $customerName=trim((string)$user['name']);$username='hp'.strtolower(bin2hex(random_bytes(6)));$password='Hp!'.bin2hex(random_bytes(12)).'9a';
            $customer=['username'=>$username,'password'=>$password,'name'=>$customerName,'addressLine1'=>$domainData['addressLine1'],'city'=>$domainData['city'],'country'=>$domainData['country'],'zipcode'=>$domainData['zipcode'],'phoneCountryCode'=>$domainData['phoneCountryCode'],'phone'=>$domainData['phone']];
            $contact=['name'=>$customerName,'email'=>(string)$user['email'],'addressLine1'=>$domainData['addressLine1'],'city'=>$domainData['city'],'country'=>$domainData['country'],'zipcode'=>$domainData['zipcode'],'phoneCountryCode'=>$domainData['phoneCountryCode'],'phone'=>$domainData['phone']];
            $registrarPayload=['customer'=>$customer,'contact'=>$contact,'nameservers'=>[],'invoiceOption'=>'OnlyAdd','autoRenew'=>false];
        }
        $taxSettings=TaxCalculator::settings($this->db);$invoiceRows=[];
        foreach($items as $item){$invoiceRows[]=['description'=>$item['name'].' · '.$this->cycle($item['billing_cycle']),'amount_cents'=>$item['base_cents']];if($item['setup_cents']>0)$invoiceRows[]=['description'=>$item['name'].' · Kurulum ücreti','amount_cents'=>$item['setup_cents']];}
        $taxTotals=TaxCalculator::totals(array_column($invoiceRows,'amount_cents'),$taxSettings);
        $subtotal=TaxCalculator::decimal($taxTotals['subtotal_cents']);$taxTotal=TaxCalculator::decimal($taxTotals['tax_cents']);$grandTotal=TaxCalculator::decimal($taxTotals['total_cents']);
        $now=gmdate('Y-m-d H:i:s');$number='HP'.gmdate('ymd').strtoupper(bin2hex(random_bytes(4)));
        try{
            $this->db->beginTransaction();
            $o=$this->db->prepare("INSERT INTO orders(order_number,user_id,status,currency,subtotal,tax_total,total,created_at,updated_at) VALUES(:number,:uid,'pending',:currency,:subtotal,:tax,:total,:created,:updated)");
            $o->execute([':number'=>$number,':uid'=>$user['id'],':currency'=>$currency,':subtotal'=>$subtotal,':tax'=>$taxTotal,':total'=>$grandTotal,':created'=>$now,':updated'=>$now]);$orderId=(int)$this->db->lastInsertId();
            $orderLine=$this->db->prepare('INSERT INTO order_items(order_id,product_id,product_name,billing_cycle,quantity,unit_price,line_total,created_at) VALUES(:oid,:pid,:name,:cycle,1,:price,:line,:created)');
            $invoiceNumber='HPF'.gmdate('ymd').strtoupper(bin2hex(random_bytes(4)));
            $invoice=$this->db->prepare("INSERT INTO invoices(invoice_number,order_id,user_id,status,currency,subtotal,tax_total,total,amount_paid,issued_at,due_at,created_at,updated_at) VALUES(:number,:order_id,:user_id,'unpaid',:currency,:subtotal,:tax,:total,0,:issued,:due,:created,:updated)");
            $due=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->modify('+7 days')->format('Y-m-d H:i:s');
            $invoice->execute([':number'=>$invoiceNumber,':order_id'=>$orderId,':user_id'=>$user['id'],':currency'=>$currency,':subtotal'=>$subtotal,':tax'=>$taxTotal,':total'=>$grandTotal,':issued'=>$now,':due'=>$due,':created'=>$now,':updated'=>$now]);$invoiceId=(int)$this->db->lastInsertId();
            $invoiceLine=$this->db->prepare('INSERT INTO invoice_items(invoice_id,description,quantity,unit_price,tax_rate,tax_amount,line_total,created_at) VALUES(:invoice,:description,1,:price,:rate,:tax,:total,:created)');
            $taxIndex=0;
            foreach($items as $item){
                $base=$this->moneyDecimal($item['base_cents']);$line=$this->moneyDecimal($item['line_cents']);
                $orderLine->execute([':oid'=>$orderId,':pid'=>$item['id'],':name'=>$item['name'],':cycle'=>$item['billing_cycle'],':price'=>$base,':line'=>$base,':created'=>$now]);
                $baseTax=$taxTotals['lines'][$taxIndex++];
                $invoiceLine->execute([':invoice'=>$invoiceId,':description'=>$item['name'].' · '.$this->cycle($item['billing_cycle']),':price'=>TaxCalculator::decimal($baseTax['subtotal_cents']),':rate'=>$baseTax['tax_rate'],':tax'=>TaxCalculator::decimal($baseTax['tax_cents']),':total'=>TaxCalculator::decimal($baseTax['total_cents']),':created'=>$now]);
                if($item['setup_cents']>0){
                    $setupTax=$taxTotals['lines'][$taxIndex++];
                    $invoiceLine->execute([':invoice'=>$invoiceId,':description'=>$item['name'].' · Kurulum ücreti',':price'=>TaxCalculator::decimal($setupTax['subtotal_cents']),':rate'=>$setupTax['tax_rate'],':tax'=>TaxCalculator::decimal($setupTax['tax_cents']),':total'=>TaxCalculator::decimal($setupTax['total_cents']),':created'=>$now]);
                }
            }
            if($registrarPayload!==null){
                $vault=new SecretVault($this->applicationKey);$encrypted=$vault->encrypt(json_encode($registrarPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
                $reg=$this->db->prepare("INSERT INTO domain_registrations(user_id,order_id,invoice_id,domain_name,years,registrar_payload_enc,idempotency_key,status,available_at,created_at,updated_at) VALUES(:user,:order_id,:invoice,:domain,:years,:payload,:key,'awaiting_payment',:available,:created,:updated)");
                $reg->execute([':user'=>$user['id'],':order_id'=>$orderId,':invoice'=>$invoiceId,':domain'=>$domainName,':years'=>$domainYears,':payload'=>$encrypted,':key'=>hash('sha256','domain:'.$orderId.':'.$domainName),':available'=>$now,':created'=>$now,':updated'=>$now]);
            }
            $this->db->commit();unset($_SESSION['hostpilot_cart']);$this->redirect('/client/invoices');
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            error_log('[HostPilot] Cart order creation: '.$e->getMessage());
            http_response_code(500);echo 'Sipariş oluşturulamadı. Sepetiniz korunmuştur; lütfen tekrar deneyin.';
        }
    }

    public function clientOrders():void
    {
        if(!Auth::check()){$this->redirect('/client/login');}
        $u=Auth::user();if(($u['role']??'')!=='customer'){http_response_code(403);echo 'Müşteri hesabı gerekli.';return;}
        $q=$this->db->prepare('SELECT o.*,COUNT(oi.id) AS item_count FROM orders o LEFT JOIN order_items oi ON oi.order_id=o.id WHERE o.user_id=:uid GROUP BY o.id ORDER BY o.created_at DESC');$q->execute([':uid'=>$u['id']]);$rows=$q->fetchAll();
        $this->start('Siparişlerim');echo '<main class="detail"><a class="brand" href="'.$this->url('/').'">HOST<span>PILOT</span></a><a class="back" href="'.$this->url('/client').'">← Müşteri paneli</a><section class="productdetail"><h1>Siparişlerim</h1>';
        if(!$rows)echo '<p>Henüz siparişiniz bulunmuyor. <a href="'.$this->url('/').'">Paketleri inceleyin.</a></p>';
        foreach($rows as $o){$items=$this->db->prepare('SELECT product_name,billing_cycle,quantity,line_total FROM order_items WHERE order_id=:id');$items->execute([':id'=>$o['id']]);echo '<article class="order"><b>#'.$this->e($o['order_number']).'</b><span>'.$this->e($o['status']).'</span><p>'.number_format((float)$o['total'],2,',','.').' '.$this->e($o['currency']).' · '.date('d.m.Y H:i',strtotime($o['created_at'])).'</p>';foreach($items->fetchAll() as $i)echo '<small>'.$this->e($i['product_name']).' · '.$this->e($this->cycle($i['billing_cycle'])).' · '.number_format((float)$i['line_total'],2,',','.').'</small>';echo '</article>';}
        echo '</section></main>';$this->end();
    }

    public function clientInvoices():void
    {
        if(!Auth::check()){$this->redirect('/client/login');}
        $u=Auth::user();if(($u['role']??'')!=='customer'){http_response_code(403);echo 'Müşteri hesabı gerekli.';return;}
        $q=$this->db->prepare("SELECT i.id,i.invoice_number,i.status,i.currency,i.total,i.amount_paid,i.issued_at,i.due_at,ap.status AS active_payment_status,ap.gateway AS active_payment_gateway,ap.created_at AS active_payment_created_at
            FROM invoices i LEFT JOIN payment_transactions ap ON ap.id=(SELECT MAX(pt.id) FROM payment_transactions pt WHERE pt.invoice_id=i.id AND pt.user_id=i.user_id AND pt.status IN ('pending','review'))
            WHERE i.user_id=:uid ORDER BY i.issued_at DESC");$q->execute([':uid'=>$u['id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $this->start('Faturalarım');echo '<main class="detail"><a class="brand" href="'.$this->url('/').'">HOST<span>PILOT</span></a><a class="back" href="'.$this->url('/client').'">← Müşteri paneli</a><section class="productdetail"><h1>Faturalarım</h1>';
        if(!$rows)echo '<p>Henüz faturanız bulunmuyor.</p>';
        foreach($rows as $v){
            $activePayment=in_array((string)($v['active_payment_status']??''),['pending','review'],true);
            echo '<article class="order"><b>#'.$this->e($v['invoice_number']).'</b><span>'.$this->e($this->invoiceStatus($v['status'])).'</span><p>'.number_format((float)$v['total'],2,',','.').' '.$this->e($v['currency']).' · Düzenlenme: '.date('d.m.Y',strtotime($v['issued_at'])).'</p><small>Son ödeme: '.($v['due_at']?date('d.m.Y',strtotime($v['due_at'])):'—').' · Ödenen: '.number_format((float)$v['amount_paid'],2,',','.').' '.$this->e($v['currency']).'</small>';
            if($activePayment){
                $activeLabel=$v['active_payment_status']==='review'?'İncelemede':'Ödeme bekliyor';
                $activeGateway=['paytr'=>'PayTR','iyzico'=>'iyzico','manual'=>'Manuel ödeme'][$v['active_payment_gateway']]??'Ödeme sağlayıcısı';
                echo '<p class="notice"><b>'.$this->e($activeLabel).'</b> · '.$this->e($activeGateway).' işlemi sonuçlanmayı bekliyor.</p>';
            }
            echo '<p><a class="button" href="'.$this->url('/client/invoices/'.$v['id']).'">Fatura detayını görüntüle</a></p>';
            if(in_array($v['status'],['unpaid','partially_paid','overdue'],true)&&!$activePayment)echo '<p><a class="button" href="'.$this->url('/client/invoices/'.$v['id'].'/pay').'">Faturayı öde</a></p>';
            echo '</article>';
        }
        echo '</section></main>';$this->end();
    }

    public function clientInvoiceDetail(int $invoiceId):void
    {
        if(!Auth::check()){$this->redirect('/client/login');}
        $u=Auth::user();
        if(($u['role']??'')!=='customer'){http_response_code(403);echo 'Müşteri hesabı gerekli.';return;}
        $q=$this->db->prepare('SELECT i.*,o.order_number FROM invoices i JOIN orders o ON o.id=i.order_id WHERE i.id=:id AND i.user_id=:uid LIMIT 1');
        $q->execute([':id'=>$invoiceId,':uid'=>$u['id']]);$invoice=$q->fetch(PDO::FETCH_ASSOC);
        if(!$invoice){http_response_code(404);echo 'Fatura bulunamadı.';return;}
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
        $activePaymentQuery=$this->db->prepare("SELECT gateway,status,created_at FROM payment_transactions WHERE invoice_id=:id AND user_id=:uid AND status IN ('pending','review') ORDER BY id DESC LIMIT 1");
        $activePaymentQuery->execute([':id'=>$invoiceId,':uid'=>$u['id']]);$activePayment=$activePaymentQuery->fetch(PDO::FETCH_ASSOC);
        $itemsQuery=$this->db->prepare('SELECT description,quantity,unit_price,tax_rate,tax_amount,line_total FROM invoice_items WHERE invoice_id=:id ORDER BY id');
        $itemsQuery->execute([':id'=>$invoiceId]);$items=$itemsQuery->fetchAll(PDO::FETCH_ASSOC);
        $paymentsQuery=$this->db->prepare('SELECT gateway,status,currency,amount,created_at FROM payment_transactions WHERE invoice_id=:id AND user_id=:uid ORDER BY id');
        $paymentsQuery->execute([':id'=>$invoiceId,':uid'=>$u['id']]);$payments=$paymentsQuery->fetchAll(PDO::FETCH_ASSOC);
        $refundsQuery=$this->db->prepare('SELECT pr.amount,pr.currency,pr.created_at FROM payment_refunds pr JOIN payment_transactions pt ON pt.id=pr.payment_transaction_id WHERE pt.invoice_id=:id AND pt.user_id=:uid ORDER BY pr.id');
        $refundsQuery->execute([':id'=>$invoiceId,':uid'=>$u['id']]);$refunds=$refundsQuery->fetchAll(PDO::FETCH_ASSOC);
        $total=(float)$invoice['total'];$paid=(float)$invoice['amount_paid'];$remaining=max(0.0,round($total-$paid,2));
        $gatewayLabels=['paytr'=>'PayTR','iyzico'=>'iyzico','manual'=>'Manuel ödeme'];
        $paymentStatuses=['success'=>'Başarılı','pending'=>'Bekliyor','review'=>'İncelemede','failed'=>'Başarısız','cancelled'=>'İptal','refunded'=>'İade edildi','partially_refunded'=>'Kısmi iade'];
        $this->start('Fatura detayları');
        echo '<style>@media print{body{background:#fff!important;color:#111!important}.invoice-detail .brand,.invoice-detail .back,.invoice-actions{display:none!important}.invoice-detail .productdetail{box-shadow:none!important;border:0!important;max-width:none!important}a{color:#111!important;text-decoration:none!important}}</style>';
        echo '<main class="detail invoice-detail"><a class="brand" href="'.$this->url('/').'">HOST<span>PILOT</span></a><a class="back" href="'.$this->url('/client/invoices').'">← Faturalarıma dön</a><section class="productdetail">';
        echo '<div class="invoice-actions" style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end"><button type="button" class="button" onclick="window.print()">Yazdır / PDF kaydet</button>';
        if(in_array($invoice['status'],['unpaid','partially_paid','overdue'],true)&&$remaining>0&&!is_array($activePayment))echo '<a class="button" href="'.$this->url('/client/invoices/'.$invoiceId.'/pay').'">Faturayı öde</a>';
        echo '</div><small>FATURA DETAYI</small><h1>#'.$this->e((string)$invoice['invoice_number']).'</h1><p><b>'.$this->e($this->invoiceStatus((string)$invoice['status'])).'</b> · Sipariş #'.$this->e((string)$invoice['order_number']).'</p>';
        if(is_array($activePayment)){
            $activeLabel=$activePayment['status']==='review'?'İncelemede':'Bekliyor';
            $activeGateway=['paytr'=>'PayTR','iyzico'=>'iyzico','manual'=>'Manuel ödeme'][$activePayment['gateway']]??'Ödeme sağlayıcısı';
            $activeCopy=$activePayment['status']==='review'?'Ödeme sağlayıcısının sonucu doğrulanana kadar yeni ödeme başlatılamaz. İşlem destek ekibi tarafından kontrol ediliyor.':'Ödeme sağlayıcısından sonuç bekleniyor. Aynı fatura için ikinci bir ödeme başlatılmaz.';
            echo '<section class="notice invoice-payment-status"><b>Ödeme işlemi: '.$this->e($activeLabel).' · '.$this->e($activeGateway).'</b><p>'.$this->e($activeCopy).'</p><a class="button payment-refresh" href="'.$this->url('/client/invoices/'.$invoiceId).'">Durumu yenile</a></section>';
        }
        echo '<div class="stats"><article><small>Fatura tarihi</small><strong>'.($invoice['issued_at']?date('d.m.Y',strtotime($invoice['issued_at'])):'—').'</strong></article><article><small>Son ödeme tarihi</small><strong>'.($invoice['due_at']?date('d.m.Y',strtotime($invoice['due_at'])):'—').'</strong></article><article><small>Para birimi</small><strong>'.$this->e((string)$invoice['currency']).'</strong></article></div>';
        echo '<h2>Fatura kalemleri</h2><div class="tablewrap"><table><thead><tr><th>Açıklama</th><th>Adet</th><th>Birim fiyat</th><th>KDV</th><th>Toplam</th></tr></thead><tbody>';
        if(!$items)echo '<tr><td colspan="5">Fatura kalemi bulunmuyor.</td></tr>';
        foreach($items as $item)echo '<tr><td>'.$this->e((string)$item['description']).'</td><td>'.(int)$item['quantity'].'</td><td>'.number_format((float)$item['unit_price'],2,',','.').' '.$this->e((string)$invoice['currency']).'</td><td>'.number_format((float)$item['tax_amount'],2,',','.').' '.$this->e((string)$invoice['currency']).'</td><td>'.number_format((float)$item['line_total'],2,',','.').' '.$this->e((string)$invoice['currency']).'</td></tr>';
        echo '</tbody></table></div><div class="invoice-total" style="max-width:380px;margin:18px 0 18px auto"><p>Ara toplam: <b>'.number_format((float)$invoice['subtotal'],2,',','.').' '.$this->e((string)$invoice['currency']).'</b></p><p>Vergi: <b>'.number_format((float)$invoice['tax_total'],2,',','.').' '.$this->e((string)$invoice['currency']).'</b></p><p>Fatura toplamı: <b>'.number_format($total,2,',','.').' '.$this->e((string)$invoice['currency']).'</b></p><p>Ödenen: <b>'.number_format($paid,2,',','.').' '.$this->e((string)$invoice['currency']).'</b></p><p>Kalan bakiye: <b>'.number_format($remaining,2,',','.').' '.$this->e((string)$invoice['currency']).'</b></p></div>';
        echo '<h2>Ödeme geçmişi</h2>';
        if(!$payments)echo '<p>Bu fatura için henüz ödeme işlemi bulunmuyor.</p>';
        foreach($payments as $payment){$gateway=$gatewayLabels[$payment['gateway']]??'Ödeme sağlayıcısı';$status=$paymentStatuses[$payment['status']]??'Durum güncelleniyor';echo '<article class="order"><b>'.$this->e($gateway).' · '.$this->e($status).'</b><p>'.number_format((float)$payment['amount'],2,',','.').' '.$this->e((string)$payment['currency']).' · '.date('d.m.Y H:i',strtotime($payment['created_at'])).'</p></article>';}
        if($refunds){
            echo '<h2>İade geçmişi</h2>';
            foreach($refunds as $refund)echo '<article class="order"><b>İade edildi</b><p>'.number_format((float)$refund['amount'],2,',','.').' '.$this->e((string)$refund['currency']).' · '.date('d.m.Y H:i',strtotime($refund['created_at'])).'</p></article>';
        }
        echo '</section></main>';$this->end();
    }

    public function adminInvoices():void
    {
        $staff=$this->staff();if(!$staff)return;
        $rows=$this->db->query('SELECT i.id,i.invoice_number,i.status,i.currency,i.total,i.amount_paid,i.issued_at,u.name,u.email FROM invoices i JOIN users u ON u.id=i.user_id ORDER BY i.issued_at DESC LIMIT 300')->fetchAll();
        $this->start('Faturalar');echo '<div class="admin"><aside><a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a><a href="'.$this->url('/admin').'">← Kontrol merkezi</a><a href="'.$this->url('/admin/orders').'">Siparişler</a><a class="active" href="'.$this->url('/admin/invoices').'">Faturalar</a></aside><main><header><small>FİNANS / FATURALAR</small><h1>Faturalar</h1><p>Oluşturulan müşteri faturaları ve tahsilat durumları.</p></header><section class="box"><div class="tablewrap"><table><thead><tr><th>Fatura</th><th>Müşteri</th><th>Toplam</th><th>Ödenen</th><th>Durum</th><th>Tarih</th></tr></thead><tbody>';
        if(!$rows)echo '<tr><td colspan="6">Henüz fatura bulunmuyor.</td></tr>';
        foreach($rows as $v)echo '<tr><td><a href="'.$this->url('/admin/invoices/'.(int)$v['id']).'">#'.$this->e($v['invoice_number']).'</a></td><td>'.$this->e($v['name']).'<small>'.$this->e($v['email']).'</small></td><td>'.number_format((float)$v['total'],2,',','.').' '.$this->e($v['currency']).'</td><td>'.number_format((float)$v['amount_paid'],2,',','.').' '.$this->e($v['currency']).'</td><td>'.$this->e($this->invoiceStatus($v['status'])).'</td><td>'.date('d.m.Y',strtotime($v['issued_at'])).'</td></tr>';
        echo '</tbody></table></div></section></main></div>';$this->end();
    }

    private function cartSession():array
    {
        $cart=$_SESSION['hostpilot_cart']??[];
        return is_array($cart)?$cart:[];
    }

    private function normalizeDomainDetails(array $input):?array
    {
        $domain=strtolower(trim((string)($input['domain_name']??'')));
        $address=trim((string)($input['addressLine1']??''));$city=trim((string)($input['city']??''));$country=strtoupper(trim((string)($input['country']??'')));
        $zip=trim((string)($input['zipcode']??''));$phoneCode=preg_replace('/\\D/','',(string)($input['phoneCountryCode']??''));$phone=preg_replace('/\\D/','',(string)($input['phone']??''));
        if(strlen($domain)>253||!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/D',$domain)
            ||$address===''||strlen($address)>190||$city===''||strlen($city)>80||!preg_match('/^[A-Z]{2}$/D',$country)
            ||$zip===''||strlen($zip)>20||$phoneCode===''||strlen($phoneCode)>5||$phone===''||strlen($phone)<5||strlen($phone)>25){
            return null;
        }
        return ['domain_name'=>$domain,'addressLine1'=>$address,'city'=>$city,'country'=>$country,'zipcode'=>$zip,'phoneCountryCode'=>$phoneCode,'phone'=>$phone];
    }

    private function domainYears(string $cycle):int
    {
        return ['annually'=>1,'biennially'=>2,'triennially'=>3,'onetime'=>1][$cycle]??0;
    }

    private function moneyCents(mixed $amount):int
    {
        $raw=trim((string)$amount);
        if(!preg_match('/^(?:0|[1-9][0-9]*)(?:\\.[0-9]{1,2})?$/D',$raw))throw new \RuntimeException('Fiyat değeri geçersiz.');
        [$whole,$fraction]=array_pad(explode('.',$raw,2),2,'');
        return ((int)$whole*100)+(int)str_pad($fraction,2,'0');
    }

    private function moneyDecimal(int $cents):string
    {
        if($cents<0)throw new \RuntimeException('Tutar negatif olamaz.');
        return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
    }

    private function invoiceStatus(string $s):string{return ['unpaid'=>'Ödenmedi','paid'=>'Ödendi','partially_paid'=>'Kısmi ödendi','overdue'=>'Vadesi geçti','cancelled'=>'İptal','refunded'=>'İade edildi'][$s]??$s;}

    public function adminProducts():void
    {
        $staff=$this->staff();if(!$staff)return;$error='';$notice='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!Auth::verifyCsrf($_POST['_token']??null))$error='Güvenlik doğrulaması başarısız.';
            else{$action=(string)($_POST['action']??'product');$now=gmdate('Y-m-d H:i:s');
                try{
                    if($action==='group'){$name=trim((string)($_POST['group_name']??''));if($name==='')throw new \RuntimeException('Grup adı zorunludur.');$slug=$this->slug($name);$s=$this->db->prepare("INSERT INTO product_groups(name,slug,status,created_at,updated_at) VALUES(:n,:s,'active',:c,:u)");$s->execute([':n'=>$name,':s'=>$slug,':c'=>$now,':u'=>$now]);$notice='Ürün grubu oluşturuldu.';}
                    elseif($action==='product'){$name=trim((string)($_POST['name']??''));$desc=trim((string)($_POST['description']??''));$type=(string)($_POST['product_type']??'hosting');$group=(int)($_POST['group_id']??0);$status=(string)($_POST['status']??'draft');$cycle=(string)($_POST['billing_cycle']??'monthly');$price=trim((string)($_POST['price']??''));$currency=strtoupper(trim((string)($_POST['currency']??'TRY')));
                        if($name===''||strlen($name)>190||!in_array($type,['hosting','reseller','vps','dedicated','domain','ssl','other'],true)||!in_array($status,['draft','active','hidden'],true)||!in_array($cycle,['monthly','quarterly','semiannually','annually','biennially','triennially','onetime'],true)||!preg_match('/^[A-Z]{3}$/',$currency)||!is_numeric($price)||(float)$price<0||(float)$price>9999999999)throw new \RuntimeException('Ürün bilgileri veya fiyat alanları geçersiz.');
                        $slug=$this->slug($name).'-'.strtolower(bin2hex(random_bytes(2)));$this->db->beginTransaction();$s=$this->db->prepare('INSERT INTO products(group_id,name,slug,description,product_type,status,created_at,updated_at) VALUES(:g,:n,:slug,:d,:t,:s,:c,:u)');$s->execute([':g'=>$group?:null,':n'=>$name,':slug'=>$slug,':d'=>$desc,':t'=>$type,':s'=>$status,':c'=>$now,':u'=>$now]);$pid=(int)$this->db->lastInsertId();$p=$this->db->prepare('INSERT INTO product_pricing(product_id,currency,billing_cycle,price,created_at,updated_at) VALUES(:id,:currency,:cycle,:price,:c,:u)');$p->execute([':id'=>$pid,':currency'=>$currency,':cycle'=>$cycle,':price'=>$price,':c'=>$now,':u'=>$now]);$this->db->commit();$notice='Ürün ve ilk fiyat kaydedildi.';
                    }else throw new \RuntimeException('Bilinmeyen işlem.');
                }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();$error=$e instanceof \RuntimeException?$e->getMessage():'Kayıt yapılamadı. Grup adı veya ürün bağlantısı zaten kullanılıyor olabilir.';}
            }
        }
        $groups=$this->db->query("SELECT id,name FROM product_groups WHERE status='active' ORDER BY sort_order,name")->fetchAll();$products=$this->db->query('SELECT p.*,g.name AS group_name,(SELECT COUNT(*) FROM product_pricing pp WHERE pp.product_id=p.id AND pp.is_active=1) AS price_count FROM products p LEFT JOIN product_groups g ON g.id=p.group_id ORDER BY p.created_at DESC')->fetchAll();
        $this->start('Ürün ve paket yönetimi');echo '<div class="admin"><aside><a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a><a href="'.$this->url('/admin').'">← Kontrol merkezi</a><a class="active" href="'.$this->url('/admin/products').'">Ürün & paketler</a><a href="'.$this->url('/admin/orders').'">Siparişler</a></aside><main><header><small>KATALOG YÖNETİMİ</small><h1>Ürün ve paketler</h1><p>Satışa sunacağınız hizmetleri ve ilk fiyatlarını tanımlayın.</p></header>';
        if($error!=='')echo '<div class="error">'.$this->e($error).'</div>';if($notice!=='')echo '<div class="notice">'.$this->e($notice).'</div>';
        echo '<section class="box"><h2>Ürün grubu ekle</h2><form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="action" value="group"><label>Grup adı<input name="group_name" maxlength="120" required></label><button class="button">Grup oluştur</button></form></section><section class="box"><h2>Yeni ürün / paket</h2><form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="action" value="product"><div class="grid"><label>Ürün adı<input name="name" maxlength="190" required></label><label>Ürün grubu<select name="group_id"><option value="0">Grupsuz</option>';foreach($groups as $g)echo '<option value="'.(int)$g['id'].'">'.$this->e($g['name']).'</option>';echo '</select></label><label>Hizmet türü<select name="product_type">';foreach(['hosting'=>'Web hosting','reseller'=>'Reseller hosting','vps'=>'VPS','dedicated'=>'Dedicated server','domain'=>'Domain','ssl'=>'SSL sertifikası','other'=>'Diğer'] as $v=>$label)echo '<option value="'.$v.'">'.$label.'</option>';echo '</select></label><label>Yayın durumu<select name="status"><option value="draft">Taslak</option><option value="active">Yayında</option><option value="hidden">Gizli</option></select></label><label>İlk fiyat dönemi<select name="billing_cycle">';foreach(['monthly'=>'Aylık','quarterly'=>'3 aylık','semiannually'=>'6 aylık','annually'=>'Yıllık','biennially'=>'2 yıllık','triennially'=>'3 yıllık','onetime'=>'Tek seferlik'] as $v=>$label)echo '<option value="'.$v.'">'.$label.'</option>';echo '</select></label><label>Fiyat<input name="price" type="number" min="0" step="0.01" required></label><label>Para birimi<input name="currency" value="TRY" minlength="3" maxlength="3" required></label></div><label>Açıklama<textarea name="description" rows="5"></textarea></label><button class="button">Ürünü kaydet</button></form></section><section class="box"><h2>Kayıtlı ürünler</h2>';
        if(!$products)echo '<p>Henüz ürün yok.</p>';foreach($products as $p)echo '<div class="productrow"><span><b>'.$this->e($p['name']).'</b><small>'.$this->e($p['group_name']??'Grupsuz').' · '.$this->e($p['product_type']).' · '.$this->e($p['status']).' · '.$p['price_count'].' aktif fiyat</small></span><a href="'.$this->url('/product/'.$this->e($p['slug'])).'">Önizleme →</a></div>';
        echo '</section></main></div>';$this->end();
    }

    public function adminOrders():void
    {
        $staff=$this->staff();if(!$staff)return;$rows=$this->db->query('SELECT o.id,o.order_number,o.status,o.currency,o.total,o.created_at,u.name,u.email FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.created_at DESC LIMIT 300')->fetchAll();$this->start('Siparişler');
        echo '<div class="admin"><aside><a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a><a href="'.$this->url('/admin').'">← Kontrol merkezi</a><a href="'.$this->url('/admin/products').'">Ürün & paketler</a><a class="active" href="'.$this->url('/admin/orders').'">Siparişler</a></aside><main><header><small>SATIŞ / SİPARİŞ</small><h1>Siparişler</h1><p>Müşterilerin oluşturduğu siparişleri inceleyin.</p></header><section class="box"><div class="tablewrap"><table><thead><tr><th>Sipariş</th><th>Müşteri</th><th>Tutar</th><th>Durum</th><th>Tarih</th></tr></thead><tbody>';
        if(!$rows)echo '<tr><td colspan="5">Henüz sipariş bulunmuyor.</td></tr>';foreach($rows as $o)echo '<tr><td><a href="'.$this->url('/admin/orders/'.(int)$o['id']).'">#'.$this->e($o['order_number']).'</a></td><td>'.$this->e($o['name']).'<small>'.$this->e($o['email']).'</small></td><td>'.number_format((float)$o['total'],2,',','.').' '.$this->e($o['currency']).'</td><td>'.$this->e($o['status']).'</td><td>'.date('d.m.Y H:i',strtotime($o['created_at'])).'</td></tr>';
        echo '</tbody></table></div></section></main></div>';$this->end();
    }

    public function adminOrderDetail(int $id):void
    {
        if(!$this->staff())return;
        $q=$this->db->prepare('SELECT o.*,u.name AS customer_name,u.email AS customer_email FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=:id LIMIT 1');
        $q->execute([':id'=>$id]);$order=$q->fetch(PDO::FETCH_ASSOC);
        if(!$order){http_response_code(404);echo 'Sipariş bulunamadı.';return;}
        $items=$this->db->prepare('SELECT product_name,quantity,unit_price,line_total FROM order_items WHERE order_id=:id ORDER BY id');
        $items->execute([':id'=>$id]);
        $invoices=$this->db->prepare('SELECT id,invoice_number,status,total,amount_paid,currency FROM invoices WHERE order_id=:id ORDER BY id DESC');
        $invoices->execute([':id'=>$id]);
        $this->start('Sipariş detayı');
        echo '<div class="admin"><aside><a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a><a href="'.$this->url('/admin/orders').'">← Siparişlere dön</a><a href="'.$this->url('/admin/invoices').'">Faturalar</a></aside><main><header><small>SATIŞ / SİPARİŞ DETAYI</small><h1>#'.$this->e((string)$order['order_number']).'</h1><p>'.$this->e((string)$order['customer_name']).' · '.$this->e((string)$order['customer_email']).'</p></header><section class="box"><h2>Sipariş bilgileri</h2><p>Durum: '.$this->e((string)$order['status']).' · Tarih: '.$this->e((string)$order['created_at']).'</p><p>Toplam: '.number_format((float)$order['total'],2,',','.').' '.$this->e((string)$order['currency']).'</p><a class="button" href="'.$this->url('/admin/customers/'.(int)$order['user_id']).'">Müşteri detayları</a></section><section class="box"><h2>Sipariş kalemleri</h2><div class="tablewrap"><table><thead><tr><th>Ürün</th><th>Adet</th><th>Birim</th><th>Toplam</th></tr></thead><tbody>';
        $rows=$items->fetchAll(PDO::FETCH_ASSOC);if(!$rows)echo '<tr><td colspan="4">Sipariş kalemi yok.</td></tr>';
        foreach($rows as $row)echo '<tr><td>'.$this->e((string)$row['product_name']).'</td><td>'.number_format((float)$row['quantity'],2,',','.').'</td><td>'.number_format((float)$row['unit_price'],2,',','.').'</td><td>'.number_format((float)$row['line_total'],2,',','.').'</td></tr>';
        echo '</tbody></table></div></section><section class="box"><h2>Bağlı faturalar</h2>';
        $rows=$invoices->fetchAll(PDO::FETCH_ASSOC);if(!$rows)echo '<p>Bağlı fatura yok.</p>';
        foreach($rows as $row)echo '<p><a href="'.$this->url('/admin/invoices/'.(int)$row['id']).'">#'.$this->e((string)$row['invoice_number']).'</a> · '.$this->e((string)$row['status']).' · '.number_format((float)$row['amount_paid'],2,',','.').' / '.number_format((float)$row['total'],2,',','.').' '.$this->e((string)$row['currency']).'</p>';
        echo '</section><section class="box"><h2>İç operasyon notu</h2><p>Bu not müşteri tarafından görüntülenmez; ödeme ve hizmet durumunu değiştirmez.</p>';
        if(in_array((string)(Auth::user()['role']??''),['super_admin','admin'],true)){
            echo '<form method="post" action="'.$this->url('/admin/orders/'.$id.'/notes').'"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>Yönetici notu<textarea name="notes" maxlength="4000" rows="5">'.$this->e((string)($order['notes']??'')).'</textarea></label><button class="button">Notu kaydet</button></form>';
        }else echo '<p>'.$this->e((string)($order['notes']??'Not bulunmuyor.')).'</p>';
        echo '</section></main></div>';$this->end();
    }

    public function adminSaveOrderNotes(int $id):void
    {
        $staff=$this->staff();if(!$staff)return;
        if(!in_array((string)$staff['role'],['super_admin','admin'],true)){http_response_code(403);echo 'Yetkisiz işlem.';return;}
        if(!Auth::verifyCsrf($_POST['_token']??null)){http_response_code(403);echo 'Güvenlik doğrulaması başarısız.';return;}
        $notes=trim((string)($_POST['notes']??''));
        if(mb_strlen($notes,'UTF-8')>4000){http_response_code(422);echo 'Not en fazla 4000 karakter olabilir.';return;}
        $q=$this->db->prepare('UPDATE orders SET notes=:notes,updated_at=:now WHERE id=:id');
        $q->execute([':notes'=>$notes,':now'=>gmdate('Y-m-d H:i:s'),':id'=>$id]);
        if($q->rowCount()===0){
            $exists=$this->db->prepare('SELECT id FROM orders WHERE id=:id');$exists->execute([':id'=>$id]);
            if(!$exists->fetchColumn()){http_response_code(404);echo 'Sipariş bulunamadı.';return;}
        }
        \HostPilot\Core\SecurityAudit::record($this->db,'order.notes_updated',(int)$staff['id'],['resource_id'=>$id,'action'=>'updated']);
        $this->redirect('/admin/orders/'.$id);
    }

    public function adminInvoiceDetail(int $id):void
    {
        if(!$this->staff())return;
        $q=$this->db->prepare('SELECT i.*,u.name AS customer_name,u.email AS customer_email FROM invoices i JOIN users u ON u.id=i.user_id WHERE i.id=:id LIMIT 1');
        $q->execute([':id'=>$id]);$invoice=$q->fetch(PDO::FETCH_ASSOC);
        if(!$invoice){http_response_code(404);echo 'Fatura bulunamadı.';return;}
        $items=$this->db->prepare('SELECT description,quantity,unit_price,line_total FROM invoice_items WHERE invoice_id=:id ORDER BY id');
        $items->execute([':id'=>$id]);
        $this->start('Fatura detayı');
        echo '<div class="admin"><aside><a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a><a href="'.$this->url('/admin/invoices').'">← Faturalara dön</a><a href="'.$this->url('/admin/orders').'">Siparişler</a></aside><main><header><small>FİNANS / FATURA DETAYI</small><h1>#'.$this->e((string)$invoice['invoice_number']).'</h1><p>'.$this->e((string)$invoice['customer_name']).' · '.$this->e((string)$invoice['customer_email']).'</p></header><section class="box"><h2>Fatura özeti</h2><p>Durum: '.$this->e($this->invoiceStatus((string)$invoice['status'])).' · Düzenlenme: '.$this->e((string)$invoice['issued_at']).' · Vade: '.$this->e((string)($invoice['due_at']??'—')).'</p><p>Toplam: '.number_format((float)$invoice['total'],2,',','.').' '.$this->e((string)$invoice['currency']).'</p><p>Tahsil edilen: '.number_format((float)$invoice['amount_paid'],2,',','.').' '.$this->e((string)$invoice['currency']).'</p><a class="button" href="'.$this->url('/admin/customers/'.(int)$invoice['user_id']).'">Müşteri detayları</a>';
        if(!empty($invoice['order_id']))echo ' <a class="button" href="'.$this->url('/admin/orders/'.(int)$invoice['order_id']).'">Bağlı sipariş</a>';
        echo '</section><section class="box"><h2>Fatura kalemleri</h2><div class="tablewrap"><table><thead><tr><th>Açıklama</th><th>Adet</th><th>Birim</th><th>Toplam</th></tr></thead><tbody>';
        $rows=$items->fetchAll(PDO::FETCH_ASSOC);if(!$rows)echo '<tr><td colspan="4">Fatura kalemi yok.</td></tr>';
        foreach($rows as $row)echo '<tr><td>'.$this->e((string)$row['description']).'</td><td>'.(int)$row['quantity'].'</td><td>'.number_format((float)$row['unit_price'],2,',','.').'</td><td>'.number_format((float)$row['line_total'],2,',','.').'</td></tr>';
        echo '</tbody></table></div></section></main></div>';$this->end();
    }

    private function staff():?array{if(!Auth::check()){$this->redirect('/admin/login');return null;}$u=Auth::user();if(!in_array($u['role']??'', ['super_admin','admin','staff'],true)){http_response_code(403);echo 'Yetkisiz erişim.';return null;}return $u;}
    private function cycle(string $c):string{return ['monthly'=>'Aylık','quarterly'=>'3 aylık','semiannually'=>'6 aylık','annually'=>'Yıllık','biennially'=>'2 yıllık','triennially'=>'3 yıllık','onetime'=>'Tek seferlik'][$c]??$c;}
    private function slug(string $s):string{$s=strtolower($s);$s=strtr($s,['ı'=>'i','ğ'=>'g','ü'=>'u','ş'=>'s','ö'=>'o','ç'=>'c']);$s=preg_replace('/[^a-z0-9]+/','-',$s)??'';return trim($s,'-')?:'product';}
    private function url(string $p):string{return htmlspecialchars(rtrim($this->basePath,'/').$p,ENT_QUOTES,'UTF-8');}
    private function redirect(string $p):never{header('Location: '.$this->url($p));exit;}
    private function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private function publicPageLinks():string{return CmsMenuRenderer::links($this->db,$this->basePath,'header');}
    private function footerMenuLinks():string{return CmsMenuRenderer::links($this->db,$this->basePath,'footer');}

    private function start(string $title):void{echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->e($title).' · HostPilot</title><style>'.$this->css().'</style></head><body>';}
    private function end():void{echo '</body></html>';}
    private function css():string{return ':root{font-family:Inter,system-ui,sans-serif;color:#e9eef8;background:#091120}*{box-sizing:border-box}body{margin:0}.store,.detail{max-width:1240px;margin:auto;padding:26px}.store>header{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #26354c;padding-bottom:18px}.brand{font-size:19px;font-weight:900;letter-spacing:.12em;color:#fff;text-decoration:none}.brand span{color:#7898ff}.store nav{display:flex;gap:22px}.store nav a,.back{color:#a9bad4;text-decoration:none;font-size:13px}.storehero{padding:65px 0 36px}.storehero small,.detail small,header small{color:#8ea9f3;letter-spacing:.15em;font-weight:800;font-size:10px}.storehero h1{font-size:clamp(30px,5vw,52px);margin:12px 0}.storehero p,.product p,.detail .description{color:#a4b4ca;line-height:1.7}.products{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.product,.productdetail,.box{background:#111d31;border:1px solid #2a3a55;border-radius:14px;padding:22px}.product h2{font-size:19px}.product p{min-height:58px;font-size:13px}.product strong{display:block;font-size:20px;margin:18px 0}.type{color:#90a8ed;font-size:10px;letter-spacing:.13em}.button{display:inline-block;border:0;border-radius:8px;padding:11px 16px;background:#5278ed;color:#fff;text-decoration:none;font-weight:750;cursor:pointer}.store footer{padding:40px 0;color:#74859f;font-size:12px}.detail{max-width:850px}.detail .brand{display:block;margin:12px 0 50px}.productdetail{margin-top:20px}.order{padding:16px 0;border-top:1px solid #2a3a55}.order>span{float:right;color:#a7bbf5;font-size:12px}.order p,.order small{display:block;color:#9aabc2;font-size:12px;margin:8px 0}.productdetail h1{font-size:32px}.productdetail h2{font-size:17px;margin-top:35px}.price{display:flex;justify-content:space-between;align-items:center;gap:15px;border-top:1px solid #2a3a55;padding:17px 0}.price label{font-size:13px}.price input{accent-color:#7795ff}.admin{display:flex;min-height:100vh}.admin aside{width:235px;flex:0 0 235px;background:#0e192b;padding:24px;display:flex;flex-direction:column;gap:17px;border-right:1px solid #293a54}.admin aside>a{color:#aabbd2;text-decoration:none;font-size:13px}.admin aside .brand{margin-bottom:18px;color:#fff;font-size:18px}.admin aside .active{color:#fff;background:#203253;padding:10px;border-radius:7px}.admin main{padding:32px;min-width:0;flex:1;max-width:1400px;margin:auto}.admin main header h1{margin:8px 0}.admin main header p{color:#92a4be}.box{margin:18px 0}.box h2{font-size:17px}.box form label{display:block;color:#b9c8dc;font-size:12px;margin:12px 0}.box input,.box select,.box textarea{display:block;width:100%;margin-top:7px;padding:11px;background:#0a1425;color:#fff;border:1px solid #354661;border-radius:7px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.productrow{display:flex;justify-content:space-between;gap:15px;padding:15px 0;border-top:1px solid #2a3a55;font-size:13px}.productrow small{display:block;color:#91a3bd;margin-top:6px}.productrow a{color:#9eb7fa;text-decoration:none}.notice{background:#143a31;color:#b8f4d9;padding:12px;border-radius:8px;margin:12px 0}.error{background:#4b202b;color:#ffc3ca;padding:12px;border-radius:8px;margin:12px 0}.empty{grid-column:1/-1;color:#91a3bd;padding:25px}.tablewrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:12px;text-align:left}td,th{padding:12px;border-bottom:1px solid #2a3a55;white-space:nowrap}td small{display:block;color:#91a3bd;margin-top:5px}@media(max-width:800px){.products{grid-template-columns:1fr}.store,.detail{padding:17px}.storehero{padding:42px 0 24px}.admin aside{width:65px;flex-basis:65px;padding:16px 7px}.admin aside>a:not(.brand){font-size:0}.admin aside .brand{font-size:0}.admin aside .brand span{font-size:12px}.admin main{padding:20px 12px}.grid{grid-template-columns:1fr}.price{align-items:flex-start;flex-direction:column}}';}
}
