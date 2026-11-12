<?php
declare(strict_types=1);
namespace HostPilot\Http;
use HostPilot\Core\Auth;
use PDO;
use Throwable;

final class PackageChangeController
{
    public function __construct(private readonly PDO $db,private readonly string $basePath='',private readonly string $applicationKey=''){}
    public function index():void
    {
        $u=$this->customer();if(!$u)return;$id=(int)($_GET['service_id']??0);
        $q=$this->db->prepare("SELECT s.*,p.name AS product_name,sv.panel_type,sv.enabled AS server_enabled,o.currency FROM services s JOIN products p ON p.id=s.product_id LEFT JOIN servers sv ON sv.id=s.server_id LEFT JOIN orders o ON o.id=s.order_id WHERE s.id=:id AND s.user_id=:user LIMIT 1");
        $q->execute([':id'=>$id,':user'=>$u['id']]);$s=$q->fetch();if(!$s){http_response_code(404);echo 'Hizmet bulunamadı.';return;}
        $currency=(string)($s['currency']?:'TRY');
        $p=$this->db->prepare("SELECT p.id,p.name,pp.price,m.remote_package,m.remote_package_guid,m.remote_package_external_id FROM products p JOIN product_pricing pp ON pp.product_id=p.id AND pp.currency=:currency AND pp.billing_cycle=:cycle AND pp.is_active=1 JOIN server_product_map m ON m.product_id=p.id AND m.server_id=:server WHERE p.status='active' AND p.product_type='hosting' AND p.id<>:current ORDER BY pp.price,p.name");
        $p->execute([':currency'=>$currency,':cycle'=>$s['billing_cycle'],':server'=>$s['server_id'],':current'=>$s['product_id']]);$allPlans=$p->fetchAll();
        $higher=array_values(array_filter($allPlans,static fn(array $x):bool=>(float)$x['price']>(float)$s['amount']));
        $lower=array_values(array_filter($allPlans,static fn(array $x):bool=>(float)$x['price']<(float)$s['amount']));
        if($s['panel_type']==='plesk'){$hasPleskIds=static fn(array $x):bool=>!empty($x['remote_package_guid'])&&!empty($x['remote_package_external_id']);$higher=array_values(array_filter($higher,$hasPleskIds));$lower=array_values(array_filter($lower,$hasPleskIds));}
        $busy=$this->db->prepare("SELECT id FROM service_package_changes WHERE service_id=:id AND status IN ('pending_payment','scheduled','queued','processing','needs_review','failed') LIMIT 1");
        $busy->execute([':id'=>$id]);$hasPending=(bool)$busy->fetchColumn();
        $outstanding=$this->db->prepare("SELECT COUNT(*) FROM invoices WHERE service_id=:service AND status IN ('unpaid','partially_paid','overdue') AND amount_paid+0.005<total");
        $outstanding->execute([':service'=>$id]);$hasDebt=(int)$outstanding->fetchColumn()>0;
        $eligible=$s['status']==='active'&&$s['provisioning_status']==='completed'&&!empty($s['remote_username'])&&!empty($s['remote_domain'])&&!empty($s['server_id'])&&!empty($s['next_due_date'])&&$s['next_due_date']>gmdate('Y-m-d')&&(int)$s['server_enabled']===1&&in_array($s['panel_type'],['cpanel','plesk'],true);
        $this->start();
        echo '<main><a href="'.$this->url('/client/services').'">← Hizmetlerime dön</a><section><h1>Paket değişikliği</h1><p>'.$this->e($s['product_name']).' · '.$this->e((string)$s['remote_domain']).'</p>';
        if(!$eligible)echo '<p>Bu hizmet otomatik paket değişikliğine uygun değil. Yalnızca aktif, kurulumu tamamlanmış cPanel ve Plesk hizmetleri desteklenir. DirectAdmin desteği API doğrulaması bekliyor.</p>';
        elseif($hasPending)echo '<p>Bu hizmet için sonuçlanmamış bir paket değişikliği var. Yeni istek oluşturulmadan önce mevcut işlem tamamlanmalı veya yönetici tarafından uzlaştırılmalıdır.</p>';
        elseif($hasDebt)echo '<p>Ödenmemiş hizmet faturası varken paket değişikliği yapılamaz. Önce bekleyen faturalarınızı tamamlayın.</p>';
        else{
            if($higher){
                echo '<h2>Paket yükseltme</h2><p>Yükseltme farkı kalan dönem günleri üzerinden hesaplanır. Sunucudaki paket yalnızca ödeme sağlayıcısı tahsilatı doğruladıktan sonra değiştirilir.</p><form method="post" action="'.$this->url('/client/services/change').'"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="service_id" value="'.$id.'"><select name="product_id" required><option value="">Daha yüksek paket seçin</option>';
                foreach($higher as $x){if($s['panel_type']==='plesk'&&(!$x['remote_package_guid']||!$x['remote_package_external_id']))continue;echo '<option value="'.(int)$x['id'].'">'.$this->e($x['name']).' · '.number_format((float)$x['price'],2,',','.').' '.$this->e($currency).'</option>';}
                echo '</select><button>Yükseltme farkı faturasını oluştur</button></form>';
            }else echo '<p>Aynı sunucuya eşleştirilmiş daha yüksek bir paket bulunamadı.</p>';
            if($lower){
                echo '<h2>Bir sonraki yenilemede paket düşürme</h2><p>Şimdi ücret alınmaz ve mevcut dönem boyunca mevcut paketiniz korunur. Seçtiğiniz düşük paket, yenileme tarihinizde kontrol paneline uygulanır; o tarihte oluşturulacak yenileme faturası yeni paket ücretinden hesaplanır.</p><form method="post" action="'.$this->url('/client/services/change').'" onsubmit="return confirm(\'Paket düşürme işlemi bir sonraki yenileme tarihinizde uygulanacak. Devam etmek istiyor musunuz?\')"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="service_id" value="'.$id.'"><select name="product_id" required><option value="">Daha düşük paket seçin</option>';
                foreach($lower as $x){if($s['panel_type']==='plesk'&&(!$x['remote_package_guid']||!$x['remote_package_external_id']))continue;echo '<option value="'.(int)$x['id'].'">'.$this->e($x['name']).' · '.number_format((float)$x['price'],2,',','.').' '.$this->e($currency).'</option>';}
                echo '</select><button>Yenileme tarihinde paketimi düşür</button></form>';
            }else echo '<p>Aynı sunucuya eşleştirilmiş daha düşük bir paket bulunamadı.</p>';
        }
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
            if(!$s||$s['status']!=='active'||$s['provisioning_status']!=='completed'||empty($s['remote_username'])||empty($s['remote_domain'])||empty($s['server_id'])||empty($s['next_due_date'])||$s['next_due_date']<=gmdate('Y-m-d')||(int)$s['server_enabled']!==1)throw new \RuntimeException('Hizmet paket değişikliğine uygun değil.');
            if(!in_array($s['panel_type'],['cpanel','plesk'],true))throw new \RuntimeException('Bu panelde otomatik paket değişimi henüz desteklenmiyor.');
            $busy=$this->db->prepare("SELECT id FROM service_package_changes WHERE service_id=:id AND status IN ('pending_payment','scheduled','queued','processing','needs_review','failed') LIMIT 1 FOR UPDATE");$busy->execute([':id'=>$sid]);if($busy->fetchColumn())throw new \RuntimeException('Bu hizmet için sonuçlanmamış paket değişikliği var.');
            $currency=(string)($s['currency']?:'TRY');
            $p=$this->db->prepare("SELECT p.name,pp.price,m.remote_package,m.remote_package_guid,m.remote_package_external_id FROM products p JOIN product_pricing pp ON pp.product_id=p.id AND pp.currency=:currency AND pp.billing_cycle=:cycle AND pp.is_active=1 JOIN server_product_map m ON m.product_id=p.id AND m.server_id=:server WHERE p.id=:pid AND p.status='active' AND p.product_type='hosting' LIMIT 1");
            $p->execute([':currency'=>$currency,':cycle'=>$s['billing_cycle'],':server'=>$s['server_id'],':pid'=>$pid]);$plan=$p->fetch();
            if(!$plan||(float)$plan['price']===(float)$s['amount'])throw new \RuntimeException('Seçilen ürün geçerli bir farklı paket değil veya sunucuyla eşleştirilmemiş.');
            if($s['panel_type']==='plesk'&&(!$plan['remote_package_guid']||!$plan['remote_package_external_id']))throw new \RuntimeException('Plesk paketi için GUID ve harici plan kimliği eksik.');
            $outstanding=$this->db->prepare("SELECT COUNT(*) FROM invoices WHERE service_id=:service AND status IN ('unpaid','partially_paid','overdue') AND amount_paid+0.005<total");
            $outstanding->execute([':service'=>$sid]);if((int)$outstanding->fetchColumn()>0)throw new \RuntimeException('Ödenmemiş hizmet faturası varken paket değiştirilemez.');
            $now=gmdate('Y-m-d H:i:s');
            if((float)$plan['price']<(float)$s['amount']){
                $effective=(string)$s['next_due_date'].' 00:00:00';
                $c=$this->db->prepare("INSERT INTO service_package_changes(service_id,from_product_id,to_product_id,target_package,target_package_guid,target_package_external_id,target_amount,charge_amount,currency,change_type,status,effective_at,created_at,updated_at) VALUES(:sid,:from,:to,:package,:guid,:external,:target,0,:currency,'downgrade','scheduled',:effective,:created,:updated)");
                $c->execute([':sid'=>$sid,':from'=>$s['product_id'],':to'=>$pid,':package'=>$plan['remote_package'],':guid'=>$plan['remote_package_guid']?:null,':external'=>$plan['remote_package_external_id']?:null,':target'=>$plan['price'],':currency'=>$currency,':effective'=>$effective,':created'=>$now,':updated'=>$now]);
                $this->db->commit();
                (new EmailService($this->db,$this->applicationKey))->sendPackageChange((string)$u['email'],(string)$u['name'],(string)$s['remote_domain'],(string)$s['old_name'],(string)$plan['name'],'scheduled',(string)$s['next_due_date']);
                $this->go('/client/services','notice='.rawurlencode('Paket düşürme isteğiniz kaydedildi. Yeni paket '.$s['next_due_date'].' tarihindeki yenilemede uygulanacak; mevcut döneminiz değişmeyecek.'));
            }
            $periodQuery=$this->db->prepare('SELECT service_period_start FROM invoices WHERE service_id=:service AND service_period_start IS NOT NULL ORDER BY service_period_start DESC LIMIT 1');
            $periodQuery->execute([':service'=>$sid]);$periodStartValue=$periodQuery->fetchColumn();
            $periodStart=new \DateTimeImmutable($periodStartValue?(string)$periodStartValue:substr((string)$s['created_at'],0,10),new \DateTimeZone('UTC'));
            $dueDate=new \DateTimeImmutable((string)$s['next_due_date'],new \DateTimeZone('UTC'));
            $today=new \DateTimeImmutable(gmdate('Y-m-d'),new \DateTimeZone('UTC'));
            $days=max(0,(int)$today->diff($dueDate)->format('%r%a'));
            $cycleDays=(int)$periodStart->diff($dueDate)->days;
            if($days<1||$cycleDays<1||$periodStart>$today)throw new \RuntimeException('Kalan fatura dönemi hesaplanamadı.');
            $amount=round(((float)$plan['price']-(float)$s['amount'])*min(1,$days/$cycleDays),2);if($amount<=0)throw new \RuntimeException('Yükseltme farkı tahsilata uygun değil.');
            $due=gmdate('Y-m-d H:i:s',time()+604800);
            $c=$this->db->prepare("INSERT INTO service_package_changes(service_id,from_product_id,to_product_id,target_package,target_package_guid,target_package_external_id,target_amount,charge_amount,currency,change_type,status,effective_at,created_at,updated_at) VALUES(:sid,:from,:to,:package,:guid,:external,:target,:charge,:currency,'upgrade','pending_payment',:effective,:created,:updated)");
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
            $line->execute([':invoice'=>$invoiceId,':description'=>'Paket yükseltme: '.$s['old_name'].' → '.$plan['name'],':price'=>$amount,':now'=>$now]);
            $this->db->prepare("UPDATE service_package_changes SET invoice_id=:invoice WHERE id=:id")->execute([':invoice'=>$invoiceId,':id'=>$changeId]);
            $this->db->commit();(new EmailService($this->db,$this->applicationKey))->sendPackageChange((string)$u['email'],(string)$u['name'],(string)$s['remote_domain'],(string)$s['old_name'],(string)$plan['name'],'requested');$this->go('/client/invoices/'.$invoiceId.'/pay');
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();$this->go('/client/services','error='.$e->getMessage());}
    }
    public function cancelScheduled():void
    {
        $u=$this->customer();if(!$u)return;
        if(!Auth::verifyCsrf($_POST['_token']??null))$this->go('/client/services','error=Güvenlik doğrulaması başarısız.');
        $changeId=(int)($_POST['change_id']??0);
        try{
            $this->db->beginTransaction();
            $q=$this->db->prepare("SELECT c.*,s.user_id,s.status AS service_status,s.product_id,s.next_due_date,s.remote_domain,fp.name AS from_name,tp.name AS to_name,u.name AS customer_name,u.email AS customer_email FROM service_package_changes c JOIN services s ON s.id=c.service_id JOIN products fp ON fp.id=c.from_product_id JOIN products tp ON tp.id=c.to_product_id JOIN users u ON u.id=s.user_id WHERE c.id=:id AND s.user_id=:user FOR UPDATE");
            $q->execute([':id'=>$changeId,':user'=>$u['id']]);$change=$q->fetch();
            if(!$change||$change['change_type']!=='downgrade'||$change['status']!=='scheduled'||$change['service_status']!=='active'||(int)$change['product_id']!==(int)$change['from_product_id']||$change['effective_at']<=gmdate('Y-m-d H:i:s')||$change['next_due_date']<=gmdate('Y-m-d'))throw new \\RuntimeException('Bu paket düşürme artık iptal edilemiyor; işlem yenileme kuyruğuna alınmış veya tarihi geçmiş olabilir.');
            $u1=$this->db->prepare("UPDATE service_package_changes SET status='cancelled',updated_at=:updated WHERE id=:id AND status='scheduled'");
            $u1->execute([':updated'=>gmdate('Y-m-d H:i:s'),':id'=>$changeId]);
            if($u1->rowCount()!==1)throw new \\RuntimeException('Paket düşürme talebi eşzamanlı olarak değişti; sayfayı yenileyin.');
            $this->db->commit();
            (new EmailService($this->db,$this->applicationKey))->sendPackageChange((string)$change['customer_email'],(string)$change['customer_name'],(string)($change['remote_domain']??''),(string)$change['from_name'],(string)$change['to_name'],'cancelled');
            $this->go('/client/services','notice=Planlanmış paket düşürme talebiniz iptal edildi.');
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();$this->go('/client/services','error='.$e->getMessage());}
    }

    private function customer():?array{$u=Auth::user();if(!$u){$this->go('/client/login');return null;}if(($u['role']??'')!=='customer'){http_response_code(403);echo 'Müşteri hesabı gerekli.';return null;}return $u;}
    private function start():void{echo '<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Paket yükseltme · HostPilot</title><style>body{margin:0;background:#0a1020;color:#e8edf7;font:15px system-ui}*{box-sizing:border-box}main{max-width:850px;margin:35px auto;padding:20px}section{background:#111c2f;border:1px solid #2b3c56;border-radius:14px;padding:25px;margin-top:18px}p{color:#aabbd2;line-height:1.6}a{color:#a9c0ff}select{display:block;width:100%;max-width:520px;padding:12px;margin:14px 0;background:#091321;color:#fff;border:1px solid #344760;border-radius:8px}button{padding:12px 18px;border:0;border-radius:8px;background:#5579ef;color:white;font-weight:700}</style></head><body>';}
    private function end():void{echo '</body></html>';}
    private function url(string $p):string{return htmlspecialchars(rtrim($this->basePath,'/').$p,ENT_QUOTES,'UTF-8');}
    private function e(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private function go(string $p,string $query=''):never{$location=rtrim($this->basePath,'/').$p;if($query!==''){parse_str($query,$params);if($params)$location.=(str_contains($location,'?')?'&':'?').http_build_query($params);}header('Location: '.$location);exit;}
}
