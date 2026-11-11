<?php
declare(strict_types=1);
namespace HostPilot\Http;

use HostPilot\Core\Auth;
use HostPilot\Core\SecretVault;
use HostPilot\Hosting\CpanelWhmAdapter;
use HostPilot\Hosting\DirectAdminAdapter;
use HostPilot\Hosting\PleskXmlAdapter;
use PDO;
use Throwable;

final class CronController
{
    private SecretVault $vault;
    public function __construct(
        private readonly PDO $db,
        private readonly string $basePath,
        private readonly string $applicationKey,
        private readonly string $baseUrl
    ) {
        $this->vault=new SecretVault($applicationKey);
    }

    public function adminAutomation():void
    {
        $user=Auth::user();
        if(!$user){$this->go('/admin/login');return;}
        if(!in_array($user['role']??'', ['super_admin','admin'],true)){http_response_code(403);echo 'Yetkisiz erişim.';return;}
        if($this->applicationKey===''){http_response_code(500);echo 'Kurulum anahtarı bulunamadı.';return;}
        $notice='';$error='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!Auth::verifyCsrf($_POST['_token']??null))$error='Güvenlik doğrulaması başarısız.';
            else{
                $grace=(int)($_POST['billing_grace_days']??7);
                if($grace<1||$grace>30)$error='Ödeme tolerans süresi 1–30 gün arasında olmalıdır.';
                else{
                    $q=$this->db->prepare("INSERT INTO settings(setting_group,setting_key,setting_value,is_secret,updated_at) VALUES('billing','grace_days',:value,0,:updated) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_secret=0,updated_at=VALUES(updated_at)");
                    $q->execute([':value'=>(string)$grace,':updated'=>gmdate('Y-m-d H:i:s')]);
                    $notice='Fatura tolerans süresi kaydedildi.';
                }
            }
        }
        $q=$this->db->prepare("SELECT setting_value FROM settings WHERE setting_group='billing' AND setting_key='grace_days' LIMIT 1");$q->execute();
        $graceDays=max(1,min(30,(int)($q->fetchColumn()?:7)));
        $token=hash_hmac('sha256','HostPilot provisioning cron v1',$this->applicationKey);
        $url=rtrim($this->baseUrl,'/').'/cron/provision';
        $command="curl -fsS -H 'Authorization: Bearer ".$token."' '".$url."' >/dev/null 2>&1";
        echo '<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Otomasyon · HostPilot</title><style>body{margin:0;background:#0b1220;color:#e8eef9;font:15px system-ui}.wrap{max-width:900px;margin:50px auto;padding:24px}.box{background:#121e31;border:1px solid #2b3c56;border-radius:14px;padding:24px;margin:16px 0}code,pre{display:block;white-space:pre-wrap;overflow-wrap:anywhere;background:#091321;color:#b9ceff;padding:14px;border-radius:8px}a{color:#a9c0ff}input,button{padding:11px;border-radius:8px;border:1px solid #344760;background:#091321;color:#fff}button{background:#5579ef;border:0;font-weight:700}.notice{color:#8ce0bb}.error{color:#ff9da8}</style><div class="wrap"><a href="'.$this->e($this->url('/admin')).'">← Yönetim paneli</a><h1>Otomatik hosting ve faturalandırma</h1>';
        if($notice!=='')echo '<p class="notice">'.$this->e($notice).'</p>';
        if($error!=='')echo '<p class="error">'.$this->e($error).'</p>';
        echo '<div class="box"><h2>Ödeme tolerans süresi</h2><p>Yenileme faturası, hizmetin sonraki vade tarihinde oluşturulur. Ödeme bu süre içinde tamamlanmazsa hizmet askıya alma kuyruğuna alınır.</p><form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>Vade sonrası tolerans (gün) <input type="number" name="billing_grace_days" min="1" max="30" value="'.$graceDays.'" required></label> <button type="submit">Ayarı kaydet</button></form></div>';
        echo '<div class="box"><h2>cPanel Cron Jobs</h2><p>Otomasyon; yenileme faturalarını oluşturur, vadesi geçmiş ve ödenmemiş faturalar için askıya alma işi açar, ödeme sonrası yeniden etkinleştirmeyi işler. Her çağrı en fazla bir kontrol paneli hesabı işlemi yapar.</p><p>Önerilen çalışma aralığı: dakikada bir.</p><pre>'.$this->e($command).'</pre><p>Komut kurulum anahtarından türetilen gizli erişim bilgisi içerir. Paylaşmayın. Uygulama anahtarı değişirse cron komutunu yeniden alın.</p></div><div class="box"><h2>Güvenlik</h2><p>Manuel yönetici askıya alması ile fatura nedeniyle askıya alma ayrı tutulur. Sistem yalnızca faturalandırma nedeniyle askıya aldığı hizmeti otomatik açabilir. Belirsiz uzak sunucu yanıtları yönetici incelemesine alınır.</p><p>Alan adı girilmeden hosting hesabı oluşturulmaz. Uygun sunucu ve ürün-paket eşleştirmesi gerekir.</p></div></div></html>';
    }

    public function runProvisioning():void
    {
        $provided='';
        if(preg_match('/Bearer\s+(.+)/i',(string)($_SERVER['HTTP_AUTHORIZATION']??''),$m))$provided=trim($m[1]);
        if($provided===''&&isset($_GET['token']))$provided=(string)$_GET['token'];
        $expected=hash_hmac('sha256','HostPilot provisioning cron v1',$this->applicationKey);
        if($this->applicationKey===''||$provided===''||!hash_equals($expected,$provided)){http_response_code(403);echo 'Forbidden';return;}
        header('Content-Type: application/json; charset=utf-8');
        $jobId=0;$job=null;$service=null;$server=null;$remoteCallStarted=false;
        $this->runBillingAutomation();
        try{
            $now=gmdate('Y-m-d H:i:s');
            $this->db->beginTransaction();
            $stale=$this->db->prepare("SELECT id,service_id FROM provisioning_jobs WHERE status='processing' AND locked_at IS NOT NULL AND locked_at<:cutoff LIMIT 10 FOR UPDATE");
            $stale->execute([':cutoff'=>gmdate('Y-m-d H:i:s',time()-1200)]);
            foreach($stale->fetchAll() as $old){
                $this->db->prepare("UPDATE provisioning_jobs SET status='needs_review',last_error='Worker yanıtı zaman aşımına uğradı; uzak hesap durumu doğrulanmalı.',updated_at=:now WHERE id=:id")->execute([':now'=>$now,':id'=>$old['id']]);
                $this->db->prepare("UPDATE services SET provisioning_status='needs_review',provisioning_error='Otomatik işlem sonucu belirsiz; yönetici kontrolü gerekli.',updated_at=:now WHERE id=:id")->execute([':now'=>$now,':id'=>$old['service_id']]);
            }
            $pick=$this->db->prepare("SELECT id FROM provisioning_jobs WHERE status='queued' AND available_at<=:now ORDER BY id ASC LIMIT 1 FOR UPDATE");
            $pick->execute([':now'=>$now]);$jobId=(int)$pick->fetchColumn();
            if(!$jobId){$this->db->commit();echo json_encode(['ok'=>true,'processed'=>0,'message'=>'Bekleyen iş yok.']);return;}
            $jobQuery=$this->db->prepare("SELECT * FROM provisioning_jobs WHERE id=:id FOR UPDATE");
            $jobQuery->execute([':id'=>$jobId]);$job=$jobQuery->fetch();
            if(!$job||($job['job_type']!=='create'&&!preg_match('/^(suspend|unsuspend)_[0-9]+$/',(string)$job['job_type'])))throw new \RuntimeException('Desteklenmeyen kuyruk işlemi.');
            $this->db->prepare("UPDATE provisioning_jobs SET status='processing',attempts=attempts+1,locked_at=:now,updated_at=:now2 WHERE id=:id")
                ->execute([':now'=>$now,':now2'=>$now,':id'=>$jobId]);
            $this->db->commit();

            $serviceQuery=$this->db->prepare("SELECT s.*,u.email FROM services s JOIN users u ON u.id=s.user_id WHERE s.id=:id LIMIT 1");
            $serviceQuery->execute([':id'=>$job['service_id']]);$service=$serviceQuery->fetch();
            if(!$service)throw new \RuntimeException('Hizmet kaydı bulunamadı.');
            if($job['job_type']!=='create'){
                $this->processLifecycleJob($job,$service,$remoteCallStarted);
                return;
            }
            if($service['status']!=='pending'||empty($service['domain'])||!filter_var($service['domain'],FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME))
                throw new \RuntimeException('Hizmet veya alan adı doğrulaması başarısız.');

            $serverQuery=$this->db->prepare("SELECT sv.*,m.remote_package FROM servers sv JOIN server_product_map m ON m.server_id=sv.id WHERE sv.enabled=1 AND m.product_id=:product ORDER BY (SELECT COUNT(*) FROM services s2 WHERE s2.server_id=sv.id AND s2.status IN ('active','pending')) ASC,sv.id ASC LIMIT 1");
            $serverQuery->execute([':product'=>$service['product_id']]);$server=$serverQuery->fetch();
            if(!$server)throw new \RuntimeException('Bu ürün için etkin sunucu ve uzak paket eşleştirmesi bulunamadı.');

            $server['secret']=$this->vault->decrypt((string)$server['secret_enc']);
            $adapterConfig=['base_url'=>$server['base_url'],'username'=>$server['username'],'secret'=>$server['secret'],'auth_mode'=>$server['auth_mode'],'shared_ip'=>$server['shared_ip']];
            $adapter=match($server['panel_type']){
                'cpanel'=>new CpanelWhmAdapter($adapterConfig),
                'directadmin'=>new DirectAdminAdapter($adapterConfig),
                'plesk'=>new PleskXmlAdapter($adapterConfig),
                default=>throw new \RuntimeException('Desteklenmeyen kontrol paneli.')
            };
            $username='hp'.substr(bin2hex(random_bytes(6)),0,6);
            $password=rtrim(strtr(base64_encode(random_bytes(24)),'+/','-_'),'=');
            $this->db->prepare("UPDATE services SET server_id=:server,remote_domain=domain,provisioning_status='in_progress',provisioning_error=NULL,updated_at=:now WHERE id=:id")
                ->execute([':server'=>$server['id'],':now'=>$now,':id'=>$service['id']]);
            $remoteCallStarted=true;
            $result=$adapter->createAccount(['username'=>$username,'password'=>$password,'domain'=>$service['domain'],'email'=>$service['email'],'package'=>$server['remote_package']]);

            $finished=gmdate('Y-m-d H:i:s');
            $this->db->beginTransaction();
            $this->db->prepare("UPDATE services SET status='active',provisioning_status='completed',remote_identifier=:remote,remote_username=:username,remote_password_enc=:password,provisioned_at=:at,provisioning_error=NULL,updated_at=:updated WHERE id=:id")
                ->execute([':remote'=>$result['identifier'],':username'=>$result['username'],':password'=>$this->vault->encrypt($password),':at'=>$finished,':updated'=>$finished,':id'=>$service['id']]);
            $this->db->prepare("UPDATE provisioning_jobs SET status='completed',locked_at=NULL,last_error=NULL,updated_at=:updated WHERE id=:id")
                ->execute([':updated'=>$finished,':id'=>$jobId]);
            $this->audit((int)$service['id'],(int)$server['id'],'create','success','Hosting hesabı oluşturuldu: '.(string)$result['identifier']);
            $this->db->commit();
            echo json_encode(['ok'=>true,'processed'=>1,'job_id'=>$jobId,'service_id'=>(int)$service['id'],'status'=>'completed'],JSON_UNESCAPED_SLASHES);
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            $finished=gmdate('Y-m-d H:i:s');
            $ambiguous=$remoteCallStarted;
            $jobStatus=$ambiguous?'needs_review':'failed';
            $safe=substr($e->getMessage(),0,900);
            if($jobId>0){
                $this->db->prepare("UPDATE provisioning_jobs SET status=:status,locked_at=NULL,last_error=:error,updated_at=:updated WHERE id=:id")
                    ->execute([':status'=>$jobStatus,':error'=>$safe,':updated'=>$finished,':id'=>$jobId]);
                if(is_array($service)&&isset($service['id'])){
                    if(($job['job_type']??'create')==='create'){
                        $this->db->prepare("UPDATE services SET provisioning_status=:status,provisioning_error=:error,updated_at=:updated WHERE id=:id")
                            ->execute([':status'=>$jobStatus,':error'=>$ambiguous?'Uzak sunucu yanıtı belirsiz; yinelenen hesap açılmasını önlemek için yönetici kontrolü gerekiyor.':$safe,':updated'=>$finished,':id'=>$service['id']]);
                    }
                    $this->audit((int)$service['id'],is_array($server)?(int)$server['id']:0,(string)($job['job_type']??'create'),$jobStatus,$safe);
                }
            }
            http_response_code(200);
            echo json_encode(['ok'=>false,'processed'=>($jobId>0?1:0),'status'=>$jobStatus,'message'=>$ambiguous?'Uzak işlem sonucu belirsiz; yönetici incelemesi gerekli.':$safe],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
    }

    private function runBillingAutomation():void
    {
        try{
            $setting=$this->db->prepare("SELECT setting_value FROM settings WHERE setting_group='billing' AND setting_key='grace_days' LIMIT 1");
            $setting->execute();$grace=max(1,min(30,(int)($setting->fetchColumn()?:7)));
            $today=gmdate('Y-m-d');$now=gmdate('Y-m-d H:i:s');

            // Create at most one renewal invoice per cron request; the service-period unique key prevents duplicates.
            $this->db->beginTransaction();
            $due=$this->db->prepare("SELECT s.*,p.name AS product_name FROM services s JOIN products p ON p.id=s.product_id WHERE s.status='active' AND s.provisioning_status='completed' AND s.suspension_source='none' AND s.next_due_date IS NOT NULL AND s.next_due_date<=:today ORDER BY s.next_due_date,s.id LIMIT 1 FOR UPDATE");
            $due->execute([':today'=>$today]);$service=$due->fetch();
            if($service){
                if(empty($service['order_id']))throw new \RuntimeException('Yenileme faturası için hizmetin sipariş bağlantısı bulunamadı.');
                $period=(string)$service['next_due_date'];
                $next=$this->advanceBillingDate($period,(string)$service['billing_cycle']);
                if($next===null)throw new \RuntimeException('Desteklenmeyen hizmet faturalandırma dönemi.');
                $number='HPF'.gmdate('ymd').strtoupper(bin2hex(random_bytes(4)));
                $dueAt=(new \DateTimeImmutable($now,new \DateTimeZone('UTC')))->modify('+'.$grace.' days')->format('Y-m-d H:i:s');
                $invoice=$this->db->prepare("INSERT INTO invoices(invoice_number,order_id,user_id,service_id,service_period_start,status,currency,subtotal,tax_total,total,amount_paid,issued_at,due_at,created_at,updated_at) SELECT :number,:order_id,s.user_id,:service,:period,'unpaid',o.currency,:amount,0,:total,0,:issued,:due,:created,:updated FROM services s JOIN orders o ON o.id=s.order_id WHERE s.id=:id");
                $invoice->execute([':number'=>$number,':order_id'=>$service['order_id'],':service'=>$service['id'],':period'=>$period,':amount'=>$service['amount'],':total'=>$service['amount'],':issued'=>$now,':due'=>$dueAt,':created'=>$now,':updated'=>$now,':id'=>$service['id']]);
                $invoiceId=(int)$this->db->lastInsertId();
                $line=$this->db->prepare("INSERT INTO invoice_items(invoice_id,description,quantity,unit_price,tax_rate,tax_amount,line_total,created_at) VALUES(:invoice,:description,1,:price,0,0,:total,:created)");
                $line->execute([':invoice'=>$invoiceId,':description'=>$service['product_name'].' · Hizmet yenileme '.$period,':price'=>$service['amount'],':total'=>$service['amount'],':created'=>$now]);
                $this->db->prepare("UPDATE services SET next_due_date=:next,updated_at=:updated WHERE id=:id")->execute([':next'=>$next,':updated'=>$now,':id'=>$service['id']]);
            }
            $this->db->commit();

            // Queue suspension only for a provisioned service with a genuinely outstanding, expired invoice balance.
            $overdue=$this->db->prepare("SELECT i.id AS invoice_id,s.id AS service_id FROM invoices i JOIN services s ON s.id=i.service_id WHERE i.status IN ('unpaid','partially_paid') AND i.amount_paid+0.005<i.total AND i.due_at IS NOT NULL AND i.due_at<=:now AND s.status='active' AND s.suspension_source='none' AND s.provisioned_at IS NOT NULL ORDER BY i.due_at,i.id LIMIT 1");
            $overdue->execute([':now'=>$now]);$item=$overdue->fetch();
            if($item){
                $type='suspend_'.(int)$item['invoice_id'];
                $insert=$this->db->prepare("INSERT IGNORE INTO provisioning_jobs(service_id,job_type,status,attempts,available_at,created_at,updated_at) VALUES(:service,:type,'queued',0,:available,:created,:updated)");
                $insert->execute([':service'=>$item['service_id'],':type'=>$type,':available'=>$now,':created'=>$now,':updated'=>$now]);
            }
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            error_log('[HostPilot] Billing automation: '.$e->getMessage());
        }
    }

    private function advanceBillingDate(string $period,string $cycle):?string
    {
        $modify=['monthly'=>'+1 month','quarterly'=>'+3 months','semiannually'=>'+6 months','annually'=>'+1 year','biennially'=>'+2 years','triennially'=>'+3 years'];
        if(!isset($modify[$cycle]))return null;
        return (new \DateTimeImmutable($period,new \DateTimeZone('UTC')))->modify($modify[$cycle])->format('Y-m-d');
    }

    private function processLifecycleJob(array $job,array $service,bool &$remoteCallStarted):void
    {
        if(!preg_match('/^(suspend|unsuspend)_([0-9]+)$/',(string)$job['job_type'],$match))throw new \RuntimeException('Geçersiz hizmet yaşam döngüsü işi.');
        $action=$match[1];$invoiceId=(int)$match[2];$now=gmdate('Y-m-d H:i:s');
        $q=$this->db->prepare('SELECT * FROM invoices WHERE id=:id AND service_id=:service LIMIT 1');
        $q->execute([':id'=>$invoiceId,':service'=>$service['id']]);$invoice=$q->fetch();
        if(!$invoice)throw new \RuntimeException('Hizmetle ilişkili fatura bulunamadı.');

        if($action==='suspend'){
            $eligible=in_array($invoice['status'],['unpaid','partially_paid'],true)
                &&(float)$invoice['amount_paid']+0.005<(float)$invoice['total']
                &&!empty($invoice['due_at'])&&$invoice['due_at']<=$now
                &&$service['status']==='active'&&($service['suspension_source']??'none')==='none'
                &&!empty($service['provisioned_at']);
        }else{
            $eligible=$invoice['status']==='paid'&&$service['status']==='suspended'
                &&($service['suspension_source']??'none')==='billing'
                &&(int)($service['suspension_invoice_id']??0)===$invoiceId;
        }
        if(!$eligible){
            $this->db->prepare("UPDATE provisioning_jobs SET status='completed',locked_at=NULL,last_error='İşlem koşulları artık geçerli değil; güvenlik nedeniyle atlandı.',updated_at=:updated WHERE id=:id")
                ->execute([':updated'=>$now,':id'=>$job['id']]);
            echo json_encode(['ok'=>true,'processed'=>1,'job_id'=>(int)$job['id'],'status'=>'skipped'],JSON_UNESCAPED_SLASHES);
            return;
        }
        if(empty($service['server_id'])||empty($service['remote_username'])||empty($service['remote_domain']))throw new \RuntimeException('Uzak hosting hesabı bilgileri eksik.');
        $serverQuery=$this->db->prepare('SELECT * FROM servers WHERE id=:id LIMIT 1');$serverQuery->execute([':id'=>$service['server_id']]);$server=$serverQuery->fetch();
        if(!$server||!(int)$server['enabled'])throw new \RuntimeException('Hizmetin sunucusu bulunamadı veya devre dışı.');
        $server['secret']=$this->vault->decrypt((string)$server['secret_enc']);
        $adapterConfig=['base_url'=>$server['base_url'],'username'=>$server['username'],'secret'=>$server['secret'],'auth_mode'=>$server['auth_mode'],'shared_ip'=>$server['shared_ip']];
        $adapter=match($server['panel_type']){
            'cpanel'=>new CpanelWhmAdapter($adapterConfig),
            'directadmin'=>new DirectAdminAdapter($adapterConfig),
            'plesk'=>new PleskXmlAdapter($adapterConfig),
            default=>throw new \RuntimeException('Desteklenmeyen kontrol paneli.')
        };
        $remoteCallStarted=true;
        if($action==='suspend'){
            $adapter->suspendAccount((string)$service['remote_username'],(string)$service['remote_domain'],'HostPilot overdue invoice #'.$invoice['invoice_number']);
            // Recheck after the remote call: a payment may have arrived while the panel request was running.
            $check=$this->db->prepare('SELECT status,amount_paid,total FROM invoices WHERE id=:id');$check->execute([':id'=>$invoiceId]);$fresh=$check->fetch();
            if($fresh&&$fresh['status']==='paid'&&(float)$fresh['amount_paid']+0.005>=(float)$fresh['total']){
                $adapter->unsuspendAccount((string)$service['remote_username'],(string)$service['remote_domain']);
                $this->db->prepare("UPDATE services SET status='active',suspension_source='none',suspension_invoice_id=NULL,suspended_at=NULL,updated_at=:updated WHERE id=:id AND status='active' AND suspension_source='none'")->execute([':updated'=>$now,':id'=>$service['id']]);
                $this->db->prepare("UPDATE provisioning_jobs SET status='completed',locked_at=NULL,last_error='Ödeme işlem sırasında tamamlandı; askıya alma geri alındı.',updated_at=:updated WHERE id=:id")->execute([':updated'=>$now,':id'=>$job['id']]);
                $this->audit((int)$service['id'],(int)$server['id'],$job['job_type'],'success','Ödeme kontrol edildi; askıya alma geri alındı.');
                echo json_encode(['ok'=>true,'processed'=>1,'status'=>'payment_race_recovered'],JSON_UNESCAPED_SLASHES);
                return;
            }
            $update=$this->db->prepare("UPDATE services SET status='suspended',suspension_source='billing',suspension_invoice_id=:invoice,suspended_at=:suspended,updated_at=:updated WHERE id=:id AND status='active' AND suspension_source='none'");
            $update->execute([':invoice'=>$invoiceId,':suspended'=>$now,':updated'=>$now,':id'=>$service['id']]);
        }else{
            $adapter->unsuspendAccount((string)$service['remote_username'],(string)$service['remote_domain']);
            $update=$this->db->prepare("UPDATE services SET status='active',suspension_source='none',suspension_invoice_id=NULL,suspended_at=NULL,updated_at=:updated WHERE id=:id AND status='suspended' AND suspension_source='billing' AND suspension_invoice_id=:invoice");
            $update->execute([':updated'=>$now,':id'=>$service['id'],':invoice'=>$invoiceId]);
        }
        if($update->rowCount()!==1)throw new \RuntimeException('Uzak işlem tamamlandı ancak hizmet kaydı eşzamanlı değişti; yönetici kontrolü gerekli.');
        $this->db->prepare("UPDATE provisioning_jobs SET status='completed',locked_at=NULL,last_error=NULL,updated_at=:updated WHERE id=:id")
            ->execute([':updated'=>$now,':id'=>$job['id']]);
        $this->audit((int)$service['id'],(int)$server['id'],$job['job_type'],'success',$action==='suspend'?'Vadesi geçmiş fatura nedeniyle hizmet askıya alındı.':'Ödeme alındı; hizmet yeniden etkinleştirildi.');
        echo json_encode(['ok'=>true,'processed'=>1,'job_id'=>(int)$job['id'],'status'=>'completed'],JSON_UNESCAPED_SLASHES);
    }

    private function audit(int $service,int $server,string $action,string $status,string $message):void
    {
        try{
            $q=$this->db->prepare("INSERT INTO provisioning_logs(service_id,server_id,action,status,message,created_at) VALUES(:service,:server,:action,:status,:message,:created)");
            $q->execute([':service'=>$service,':server'=>$server?:null,':action'=>$action,':status'=>$status,':message'=>substr($message,0,1000),':created'=>gmdate('Y-m-d H:i:s')]);
        }catch(Throwable $e){error_log('[HostPilot] cron audit: '.$e->getMessage());}
    }
    private function url(string $path):string{return htmlspecialchars(rtrim($this->basePath,'/').$path,ENT_QUOTES,'UTF-8');}
    private function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private function go(string $path):never{header('Location: '.$this->url($path));exit;}
}
