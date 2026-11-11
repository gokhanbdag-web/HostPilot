<?php
declare(strict_types=1);
namespace HostPilot\Http;
use HostPilot\Core\Auth;
use PDO;
use Throwable;

final class PackageChangeController
{
    public function __construct(private readonly PDO $db,private readonly string $basePath=''){}
    public function index():void
    {
        $u=$this->customer();if(!$u)return;$id=(int)($_GET['service_id']??0);
        $q=$this->db->prepare("SELECT s.*,p.name AS product_name,sv.panel_type,sv.enabled AS server_enabled,o.currency FROM services s JOIN products p ON p.id=s.product_id LEFT JOIN servers sv ON sv.id=s.server_id LEFT JOIN orders o ON o.id=s.order_id WHERE s.id=:id AND s.user_id=:user LIMIT 1");
        $q->execute([':id'=>$id,':user'=>$u['id']]);$s=$q->fetch();if(!$s){http_response_code(404);echo 'Hizmet bulunamadı.';return;}
        $currency=(string)($s['currency']?:'TRY');
        $p=$this->db->prepare("SELECT p.id,p.name,pp.price FROM products p JOIN product_pricing pp ON pp.product_id=p.id AND pp.currency=:currency AND pp.billing_cycle=:cycle AND pp.is_active=1 JOIN server_product_map m ON m.product_id=p.id AND m.server_id=:server WHERE p.status='active' AND p.product_type='hosting' AND p.id<>:current AND pp.price>:amount ORDER BY pp.price,p.name");
        $p->execute([':currency'=>$currency,':cycle'=>$s['billing_cycle'],':server'=>$s['server_id'],':current'=>$s['product_id'],':amount'=>$s['amount']]);$plans=$p->fetchAll();
        $this->start();
        echo '<main><a href="'.$this->url('/client/services').'">← Hizmetlerime dön</a><section><h1>Paket yükseltme</h1><p>'.$this->e($s['product_name']).' · '.$this->e((string)$s['remote_domain']).'</p>';
        if($s['status']!=='active'||$s['provisioning_status']!=='completed'||empty($s['remote_username'])||empty($s['server_id'])||empty($s['next_due_date'])||$s['next_due_date']<=gmdate('Y-m-d')||!(int)$s['server_enabled']||!in_array($s['panel_type'],['cpanel','plesk'],true))echo '<p>Bu hizmet otomatik yükseltmeye uygun değil. Yalnızca aktif cPanel ve Plesk hizmetleri desteklenir. DirectAdmin desteği API doğrulaması bekliyor.</p>';
        elseif(!$plans)echo '<p>Aynı sunucuya eşleştirilmiş, mevcut dönemle uyumlu daha yüksek bir paket bulunamadı.</p>';
        else{echo '<p>Fark tutarı kalan dönem günleri üzerinden hesaplanır. Ödeme doğrulanmadan sunucudaki paket değiştirilmez.</p><form method="post" action="'.$this->url('/client/services/change').'"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="service_id" value="'.$id.'"><select name="product_id" required><option value="">Yeni paketi seçin</option>';foreach($plans as $x)echo '<option value="'.(int)$x['id'].'">'.$this->e($x['name']).' · '.number_format((float)$x['price'],2,',','.').' '.$this->e($currency).'</option>';echo '</select><button>Yükseltme farkı faturasını oluştur</button></form>';}
        echo '</section></main>';$this->end();
    }
    public function create():void
    {
        $u=$this->customer();if(!$u)return;if(!Auth::verifyCsrf($_POST['_token']??null))$this->go('/client/services');
        $sid=(int)($_POST['service_id']??0);$pid=(int)($_POST['product_id']??0);
        try{
            $this->db->beginTransaction();
            $q=$this->db->prepare("SELECT s.*,p.name AS old_name,o.currency,sv.panel_type,sv.enabled AS server_enabled FROM services s JOIN products p ON p.id=s.product_id LEFT JOIN orders o ON o.id=s.order_id LEFT JOIN servers sv ON sv.id=s.server_id WHERE s.id=:id AND s.user_id=:user FOR UPDATE");
            $q->execute([':id'=>$sid,':user'=>$u['id']]);$s=$q->fetch();
            if(!$s||$s['status']!=='active'||$s['provisioning_status']!=='completed'||empty($s['remote_username'])||empty($s['remote_domain'])||empty($s['server_id'])||empty($s['next_due_date'])||$s['next_due_date']<=gmdate('Y-m-d')||!(int)$s['server_enabled'])throw new \RuntimeException('Hizmet paket yükseltmeye uygun değil.');
            if(!in_array($s['panel_type'],['cpanel','plesk'],true))throw new \RuntimeException('Bu panelde otomatik paket değişimi henüz desteklenmiyor.');
            $busy=$this->db->prepare("SELECT id FROM service_package_changes WHERE service_id=:id AND status IN ('pending_payment','queued','processing','needs_review') LIMIT 1 FOR UPDATE");$busy->execute([':id'=>$sid]);if($busy->fetchColumn())throw new \RuntimeException('Bu hizmet için sonuçlanmamış paket değişikliği var.');
            $currency=(string)($s['currency']?:'TRY');
            $p=$this->db->prepare("SELECT p.name,pp.price,m.remote_package,m.remote_package_guid,m.remote_package_external_id FROM products p JOIN product_pricing pp ON pp.product_id=p.id AND pp.currency=:currency AND pp.billing_cycle=:cycle AND pp.is_active=1 JOIN server_product_map m ON m.product_id=p.id AND m.server_id=:server WHERE p.id=:pid AND p.status='active' AND p.product_type='hosting' LIMIT 1");
            $p->execute([':currency'=>$currency,':cycle'=>$s['billing_cycle'],':server'=>$s['server_id'],':pid'=>$pid]);$plan=$p->fetch();
            if(!$plan||(float)$plan['price']<=(float)$s['amount'])throw new \RuntimeException('Seçilen ürün geçerli bir üst paket değil veya sunucuyla eşleştirilmemiş.');
            if($s['panel_type']==='plesk'&&(!$plan['remote_package_guid']||!$plan['remote_package_external_id']))throw new \RuntimeException('Plesk paketi için GUID ve harici plan kimliği eksik.');
            $days=max(0,(new \\DateTimeImmutable(gmdate('Y-m-d'),new \\DateTimeZone('UTC')))->diff(new \\DateTimeImmutable((string)$s['next_due_date'],new \\DateTimeZone('UTC')))->days);
            $cycleDays=['monthly'=>30,'quarterly'=>90,'semiannually'=>182,'annually'=>365,'biennially'=>730,'triennially'=>1095][$s['billing_cycle']]??0;
            if($days<1||$cycleDays<1)throw new \\RuntimeException('Kalan fatura dönemi hesaplanamadı.');
            $amount=round(((float)$plan['price']-(float)$s['amount'])*min(1,$days/$cycleDays),2);if($amount<=0)throw new \RuntimeException('Yükseltme farkı tahsilata uygun değil.');
            $now=gmdate('Y-m-d H:i:s');$due=gmdate('Y-m-d H:i:s',time()+604800);
            $c=$this->db->prepare("INSERT INTO service_package_changes(service_id,from_product_id,to_product_id,target_package,target_package_guid,target_package_external_id,target_amount,charge_amount,currency,status,effective_at,created_at,updated_at) VALUES(:sid,:from,:to,:package,:guid,:external,:target,:charge,:currency,'pending_payment',:effective,:created,:updated)");
            $c->execute([':sid'=>$sid,':from'=>$s['product_id'],':to'=>$pid,':package'=>$plan['remote_package'],':guid'=>$plan['remote_package_guid']?:null,':external'=>$plan['remote_package_external_id']?:null,':target'=>$plan['price'],':charge'=>$amount,':currency'=>$currency,':effective'=>$now,':created'=>$now,':updated'=>$now]);$changeId=(int)$this->db->lastInsertId();
            $orderNo='HP'.gmdate('ymd').strtoupper(bin2hex(random_bytes(4)));
            $o=$this->db->prepare("INSERT INTO orders(order_number,user_id,status,currency,subtotal,tax_total,total,notes,created_at,updated_at) VALUES(:no,:uid,'pending',:currency,:total,0,:total,:notes,:now,:now)");
            $o->execute([':no'=>$orderNo,':uid'=>$u['id'],':currency'=>$currency,':total'=>$amount,':notes'=>'Paket yükseltme #'.$changeId,':now'=>$now]);$oid=(int)$this->db->lastInsertId();
            $oi=$this->db->prepare("INSERT INTO order_items(order_id,product_id,product_name,billing_cycle,quantity,unit_price,line_total,created_at) VALUES(:oid,:pid,:name,:cycle,1,:price,:price,:now)");
            $oi->execute([':oid'=>$oid,':pid'=>$pid,':name'=>$plan['name'].' · Paket yükseltme farkı',':cycle'=>$s['billing_cycle'],':price'=>$amount,':now'=>$now]);
            $invoiceNo='INV'.gmdate('ymd').strtoupper(bin2hex(random_bytes(4)));
            $iv=$this->db->prepare("INSERT INTO invoices(invoice_number,order_id,user_id,status,currency,subtotal,tax_total,total,amount_paid,issued_at,due_at,created_at,updated_at) VALUES(:no,:oid,:uid,'unpaid',:currency,:total,0,:total,0,:now,:due,:now,:now)");
            $iv->execute([':no'=>$invoiceNo,':oid'=>$oid,':uid'=>$u['id'],':currency'=>$currency,':total'=>$amount,':now'=>$now,':due'=>$due]);$invoiceId=(int)$this->db->lastInsertId();
            $line=$this->db->prepare("INSERT INTO invoice_items(invoice_id,description,quantity,unit_price,tax_rate,tax_amount,line_total,created_at) VALUES(:invoice,:description,1,:price,0,0,:price,:now)");
            $line->execute([':invoice'=>$invoiceId,':description'=>'Paket yükseltme: '.$s['old_name'].' → '.$plan['name'].' ('.$days.' gün)',':price'=>$amount,':now'=>$now]);
            $this->db->prepare("UPDATE service_package_changes SET invoice_id=:invoice WHERE id=:id")->execute([':invoice'=>$invoiceId,':id'=>$changeId]);
            $this->db->commit();$this->go('/client/invoices/'.$invoiceId.'/pay');
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();$this->go('/client/services','error='.$e->getMessage());}
    }
    private function customer():?array{$u=Auth::user();if(!$u){$this->go('/client/login');return null;}if(($u['role']??'')!=='customer'){http_response_code(403);echo 'Müşteri hesabı gerekli.';return null;}return $u;}
    private function start():void{echo '<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Paket yükseltme · HostPilot</title><style>body{margin:0;background:#0a1020;color:#e8edf7;font:15px system-ui}*{box-sizing:border-box}main{max-width:850px;margin:35px auto;padding:20px}section{background:#111c2f;border:1px solid #2b3c56;border-radius:14px;padding:25px;margin-top:18px}p{color:#aabbd2;line-height:1.6}a{color:#a9c0ff}select{display:block;width:100%;max-width:520px;padding:12px;margin:14px 0;background:#091321;color:#fff;border:1px solid #344760;border-radius:8px}button{padding:12px 18px;border:0;border-radius:8px;background:#5579ef;color:white;font-weight:700}</style></head><body>';}
    private function end():void{echo '</body></html>';}
    private function url(string $p):string{return htmlspecialchars(rtrim($this->basePath,'/').$p,ENT_QUOTES,'UTF-8');}
    private function e(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private function go(string $p,string $query=''):never{$location=rtrim($this->basePath,'/').$p;if($query!==''){parse_str($query,$params);if($params)$location.=(str_contains($location,'?')?'&':'?').http_build_query($params);}header('Location: '.$location);exit;}
}
