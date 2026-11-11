<?php
declare(strict_types=1);
namespace HostPilot\Http;
use HostPilot\Core\Auth;
use PDO;
use Throwable;

final class CatalogController
{
    public function __construct(private readonly PDO $db, private readonly string $basePath='') {}

    public function storefront():void
    {
        $q=$this->db->query("SELECT p.id,p.name,p.slug,p.description,p.product_type,MIN(pp.price) AS from_price,MIN(pp.currency) AS currency FROM products p LEFT JOIN product_pricing pp ON pp.product_id=p.id AND pp.is_active=1 WHERE p.status='active' GROUP BY p.id ORDER BY p.featured DESC,p.sort_order,p.name");
        $rows=$q->fetchAll();$this->start('Hosting ve domain paketleri');
        echo '<main class="store"><header><a class="brand" href="'.$this->url('/').'">HOST<span>PILOT</span></a><nav><a href="'.$this->url('/knowledgebase').'">Yardım merkezi</a><a href="'.$this->url('/client').'">Müşteri paneli</a></nav></header><section class="storehero"><small>HOSTING • DOMAIN • ALTYAPI</small><h1>İşletmeniz için doğru çözüm.</h1><p>İhtiyacınıza uygun hizmeti seçin, siparişinizi güvenle oluşturun.</p></section><section class="products">';
        if(!$rows)echo '<div class="empty">Satışa açık ürün bulunmuyor. Paketler yakında burada yayınlanacak.</div>';
        foreach($rows as $p){$price=$p['from_price']!==null?number_format((float)$p['from_price'],2,',','.').' '.($p['currency']?:'TRY'):'Fiyat için iletişime geçin';echo '<article class="product"><span class="type">'.$this->e(strtoupper($p['product_type'])).'</span><h2>'.$this->e($p['name']).'</h2><p>'.$this->e(substr(strip_tags((string)$p['description']),0,220)).'</p><strong>'.$this->e($price).'</strong><a class="button" href="'.$this->url('/product/'.$this->e($p['slug'])).'">Paketi incele</a></article>';}
        echo '</section><footer>HostPilot · Hosting ve domain hizmetleri</footer></main>';$this->end();
    }

    public function product(string $slug):void
    {
        $q=$this->db->prepare("SELECT p.*,g.name AS group_name FROM products p LEFT JOIN product_groups g ON g.id=p.group_id WHERE p.slug=:slug AND p.status='active' LIMIT 1");$q->execute([':slug'=>$slug]);$p=$q->fetch();
        if(!$p){http_response_code(404);echo 'Ürün bulunamadı.';return;}
        $prices=$this->db->prepare('SELECT billing_cycle,price,currency,setup_fee FROM product_pricing WHERE product_id=:id AND is_active=1 ORDER BY FIELD(billing_cycle,\'monthly\',\'quarterly\',\'semiannually\',\'annually\',\'biennially\',\'triennially\')');$prices->execute([':id'=>$p['id']]);$rows=$prices->fetchAll();
        $this->start($p['name']);echo '<main class="detail"><a class="brand" href="'.$this->url('/').'">HOST<span>PILOT</span></a><a class="back" href="'.$this->url('/').'">← Tüm paketler</a><section class="productdetail"><small>'.$this->e($p['group_name']??'HİZMET PAKETİ').'</small><h1>'.$this->e($p['name']).'</h1><div class="description">'.nl2br($this->e((string)$p['description'])).'</div><h2>Faturalandırma dönemi seçin</h2>';
        if(!$rows)echo '<p>Bu ürün için henüz aktif fiyat tanımlanmamış.</p>';
        foreach($rows as $r)echo '<form method="post" action="'.$this->url('/order/create').'" class="price"><input type="hidden" name="_token" value="'.(class_exists(Auth::class)?$this->e(Auth::csrfToken()):'').'"><input type="hidden" name="product_id" value="'.(int)$p['id'].'"><label><input type="radio" name="billing_cycle" value="'.$this->e($r['billing_cycle']).'" '.($r===$rows[0]?'checked':'').' required> '.$this->e($this->cycle($r['billing_cycle'])).' — <b>'.number_format((float)$r['price'],2,',','.').' '.$this->e($r['currency']).'</b></label><button class="button">Siparişe devam et</button></form>';
        echo '</section></main>';$this->end();
    }

    public function createOrder():void
    {
        if(!Auth::check()){$this->redirect('/client/login');}
        $user=Auth::user();if(($user['role']??'')!=='customer'){http_response_code(403);echo 'Sipariş oluşturmak için müşteri hesabı gerekir.';return;}
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||!Auth::verifyCsrf($_POST['_token']??null)){http_response_code(400);echo 'Geçersiz veya süresi dolmuş istek.';return;}
        $productId=(int)($_POST['product_id']??0);$cycle=(string)($_POST['billing_cycle']??'');
        $q=$this->db->prepare("SELECT p.id,p.name,pp.price,pp.currency,pp.billing_cycle FROM products p JOIN product_pricing pp ON pp.product_id=p.id WHERE p.id=:id AND p.status='active' AND pp.billing_cycle=:cycle AND pp.is_active=1 LIMIT 1");$q->execute([':id'=>$productId,':cycle'=>$cycle]);$p=$q->fetch();
        if(!$p){http_response_code(422);echo 'Seçilen paket veya fiyat artık satışta değil. Lütfen yeniden deneyin.';return;}
        $now=gmdate('Y-m-d H:i:s');$total=(string)$p['price'];$number='HP'.gmdate('ymd').strtoupper(bin2hex(random_bytes(4)));
        try{$this->db->beginTransaction();$o=$this->db->prepare("INSERT INTO orders(order_number,user_id,status,currency,subtotal,tax_total,total,created_at,updated_at) VALUES(:number,:uid,'pending',:currency,:subtotal,0,:total,:created,:updated)");$o->execute([':number'=>$number,':uid'=>$user['id'],':currency'=>$p['currency'],':subtotal'=>$total,':total'=>$total,':created'=>$now,':updated'=>$now]);$orderId=(int)$this->db->lastInsertId();$i=$this->db->prepare('INSERT INTO order_items(order_id,product_id,product_name,billing_cycle,quantity,unit_price,line_total,created_at) VALUES(:oid,:pid,:name,:cycle,1,:price,:line,:created)');$i->execute([':oid'=>$orderId,':pid'=>$p['id'],':name'=>$p['name'],':cycle'=>$p['billing_cycle'],':price'=>$total,':line'=>$total,':created'=>$now]);
            $invoiceNumber='HPF'.gmdate('ymd').strtoupper(bin2hex(random_bytes(4)));
            $invoice=$this->db->prepare("INSERT INTO invoices(invoice_number,order_id,user_id,status,currency,subtotal,tax_total,total,amount_paid,issued_at,due_at,created_at,updated_at) VALUES(:number,:order_id,:user_id,'unpaid',:currency,:subtotal,0,:total,0,:issued,:due,:created,:updated)");
            $due=(new \\DateTimeImmutable('now',new \\DateTimeZone('UTC')))->modify('+7 days')->format('Y-m-d H:i:s');
            $invoice->execute([':number'=>$invoiceNumber,':order_id'=>$orderId,':user_id'=>$user['id'],':currency'=>$p['currency'],':subtotal'=>$total,':total'=>$total,':issued'=>$now,':due'=>$due,':created'=>$now,':updated'=>$now]);
            $invoiceId=(int)$this->db->lastInsertId();
            $line=$this->db->prepare('INSERT INTO invoice_items(invoice_id,description,quantity,unit_price,tax_rate,tax_amount,line_total,created_at) VALUES(:invoice,:description,1,:price,0,0,:total,:created)');
            $line->execute([':invoice'=>$invoiceId,':description'=>$p['name'].' · '.$this->cycle($p['billing_cycle']),':price'=>$total,':total'=>$total,':created'=>$now]);
            $this->db->commit();$this->redirect('/client/invoices');}catch(Throwable){if($this->db->inTransaction())$this->db->rollBack();http_response_code(500);echo 'Sipariş oluşturulamadı. Lütfen tekrar deneyin.';}
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
        $q=$this->db->prepare('SELECT id,invoice_number,status,currency,total,amount_paid,issued_at,due_at FROM invoices WHERE user_id=:uid ORDER BY issued_at DESC');$q->execute([':uid'=>$u['id']]);$rows=$q->fetchAll();
        $this->start('Faturalarım');echo '<main class="detail"><a class="brand" href="'.$this->url('/').'">HOST<span>PILOT</span></a><a class="back" href="'.$this->url('/client').'">← Müşteri paneli</a><section class="productdetail"><h1>Faturalarım</h1>';
        if(!$rows)echo '<p>Henüz faturanız bulunmuyor.</p>';
        foreach($rows as $v)echo '<article class="order"><b>#'.$this->e($v['invoice_number']).'</b><span>'.$this->e($this->invoiceStatus($v['status'])).'</span><p>'.number_format((float)$v['total'],2,',','.').' '.$this->e($v['currency']).' · Düzenlenme: '.date('d.m.Y',strtotime($v['issued_at'])).'</p><small>Son ödeme: '.($v['due_at']?date('d.m.Y',strtotime($v['due_at'])):'—').' · Ödenen: '.number_format((float)$v['amount_paid'],2,',','.').' '.$this->e($v['currency']).'</small></article>';
        echo '</section></main>';$this->end();
    }

    public function adminInvoices():void
    {
        $staff=$this->staff();if(!$staff)return;
        $rows=$this->db->query('SELECT i.invoice_number,i.status,i.currency,i.total,i.amount_paid,i.issued_at,u.name,u.email FROM invoices i JOIN users u ON u.id=i.user_id ORDER BY i.issued_at DESC LIMIT 300')->fetchAll();
        $this->start('Faturalar');echo '<div class="admin"><aside><a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a><a href="'.$this->url('/admin').'">← Kontrol merkezi</a><a href="'.$this->url('/admin/orders').'">Siparişler</a><a class="active" href="'.$this->url('/admin/invoices').'">Faturalar</a></aside><main><header><small>FİNANS / FATURALAR</small><h1>Faturalar</h1><p>Oluşturulan müşteri faturaları ve tahsilat durumları.</p></header><section class="box"><div class="tablewrap"><table><thead><tr><th>Fatura</th><th>Müşteri</th><th>Toplam</th><th>Ödenen</th><th>Durum</th><th>Tarih</th></tr></thead><tbody>';
        if(!$rows)echo '<tr><td colspan="6">Henüz fatura bulunmuyor.</td></tr>';
        foreach($rows as $v)echo '<tr><td>#'.$this->e($v['invoice_number']).'</td><td>'.$this->e($v['name']).'<small>'.$this->e($v['email']).'</small></td><td>'.number_format((float)$v['total'],2,',','.').' '.$this->e($v['currency']).'</td><td>'.number_format((float)$v['amount_paid'],2,',','.').' '.$this->e($v['currency']).'</td><td>'.$this->e($this->invoiceStatus($v['status'])).'</td><td>'.date('d.m.Y',strtotime($v['issued_at'])).'</td></tr>';
        echo '</tbody></table></div></section></main></div>';$this->end();
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
        if(!$rows)echo '<tr><td colspan="5">Henüz sipariş bulunmuyor.</td></tr>';foreach($rows as $o)echo '<tr><td>#'.$this->e($o['order_number']).'</td><td>'.$this->e($o['name']).'<small>'.$this->e($o['email']).'</small></td><td>'.number_format((float)$o['total'],2,',','.').' '.$this->e($o['currency']).'</td><td>'.$this->e($o['status']).'</td><td>'.date('d.m.Y H:i',strtotime($o['created_at'])).'</td></tr>';
        echo '</tbody></table></div></section></main></div>';$this->end();
    }

    private function staff():?array{if(!Auth::check()){$this->redirect('/admin/login');return null;}$u=Auth::user();if(!in_array($u['role']??'', ['super_admin','admin','staff'],true)){http_response_code(403);echo 'Yetkisiz erişim.';return null;}return $u;}
    private function cycle(string $c):string{return ['monthly'=>'Aylık','quarterly'=>'3 aylık','semiannually'=>'6 aylık','annually'=>'Yıllık','biennially'=>'2 yıllık','triennially'=>'3 yıllık','onetime'=>'Tek seferlik'][$c]??$c;}
    private function slug(string $s):string{$s=strtolower($s);$s=strtr($s,['ı'=>'i','ğ'=>'g','ü'=>'u','ş'=>'s','ö'=>'o','ç'=>'c']);$s=preg_replace('/[^a-z0-9]+/','-',$s)??'';return trim($s,'-')?:'product';}
    private function url(string $p):string{return htmlspecialchars(rtrim($this->basePath,'/').$p,ENT_QUOTES,'UTF-8');}
    private function redirect(string $p):never{header('Location: '.$this->url($p));exit;}
    private function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private function start(string $title):void{echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->e($title).' · HostPilot</title><style>'.$this->css().'</style></head><body>';}
    private function end():void{echo '</body></html>';}
    private function css():string{return ':root{font-family:Inter,system-ui,sans-serif;color:#e9eef8;background:#091120}*{box-sizing:border-box}body{margin:0}.store,.detail{max-width:1240px;margin:auto;padding:26px}.store>header{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #26354c;padding-bottom:18px}.brand{font-size:19px;font-weight:900;letter-spacing:.12em;color:#fff;text-decoration:none}.brand span{color:#7898ff}.store nav{display:flex;gap:22px}.store nav a,.back{color:#a9bad4;text-decoration:none;font-size:13px}.storehero{padding:65px 0 36px}.storehero small,.detail small,header small{color:#8ea9f3;letter-spacing:.15em;font-weight:800;font-size:10px}.storehero h1{font-size:clamp(30px,5vw,52px);margin:12px 0}.storehero p,.product p,.detail .description{color:#a4b4ca;line-height:1.7}.products{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.product,.productdetail,.box{background:#111d31;border:1px solid #2a3a55;border-radius:14px;padding:22px}.product h2{font-size:19px}.product p{min-height:58px;font-size:13px}.product strong{display:block;font-size:20px;margin:18px 0}.type{color:#90a8ed;font-size:10px;letter-spacing:.13em}.button{display:inline-block;border:0;border-radius:8px;padding:11px 16px;background:#5278ed;color:#fff;text-decoration:none;font-weight:750;cursor:pointer}.store footer{padding:40px 0;color:#74859f;font-size:12px}.detail{max-width:850px}.detail .brand{display:block;margin:12px 0 50px}.productdetail{margin-top:20px}.order{padding:16px 0;border-top:1px solid #2a3a55}.order>span{float:right;color:#a7bbf5;font-size:12px}.order p,.order small{display:block;color:#9aabc2;font-size:12px;margin:8px 0}.productdetail h1{font-size:32px}.productdetail h2{font-size:17px;margin-top:35px}.price{display:flex;justify-content:space-between;align-items:center;gap:15px;border-top:1px solid #2a3a55;padding:17px 0}.price label{font-size:13px}.price input{accent-color:#7795ff}.admin{display:flex;min-height:100vh}.admin aside{width:235px;flex:0 0 235px;background:#0e192b;padding:24px;display:flex;flex-direction:column;gap:17px;border-right:1px solid #293a54}.admin aside>a{color:#aabbd2;text-decoration:none;font-size:13px}.admin aside .brand{margin-bottom:18px;color:#fff;font-size:18px}.admin aside .active{color:#fff;background:#203253;padding:10px;border-radius:7px}.admin main{padding:32px;min-width:0;flex:1;max-width:1400px;margin:auto}.admin main header h1{margin:8px 0}.admin main header p{color:#92a4be}.box{margin:18px 0}.box h2{font-size:17px}.box form label{display:block;color:#b9c8dc;font-size:12px;margin:12px 0}.box input,.box select,.box textarea{display:block;width:100%;margin-top:7px;padding:11px;background:#0a1425;color:#fff;border:1px solid #354661;border-radius:7px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.productrow{display:flex;justify-content:space-between;gap:15px;padding:15px 0;border-top:1px solid #2a3a55;font-size:13px}.productrow small{display:block;color:#91a3bd;margin-top:6px}.productrow a{color:#9eb7fa;text-decoration:none}.notice{background:#143a31;color:#b8f4d9;padding:12px;border-radius:8px;margin:12px 0}.error{background:#4b202b;color:#ffc3ca;padding:12px;border-radius:8px;margin:12px 0}.empty{grid-column:1/-1;color:#91a3bd;padding:25px}.tablewrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:12px;text-align:left}td,th{padding:12px;border-bottom:1px solid #2a3a55;white-space:nowrap}td small{display:block;color:#91a3bd;margin-top:5px}@media(max-width:800px){.products{grid-template-columns:1fr}.store,.detail{padding:17px}.storehero{padding:42px 0 24px}.admin aside{width:65px;flex-basis:65px;padding:16px 7px}.admin aside>a:not(.brand){font-size:0}.admin aside .brand{font-size:0}.admin aside .brand span{font-size:12px}.admin main{padding:20px 12px}.grid{grid-template-columns:1fr}.price{align-items:flex-start;flex-direction:column}}';}
}
