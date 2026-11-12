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
        $notice=(string)($_GET['notice']??'');$error=(string)($_GET['error']??'');
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
        echo '<div class="box"><h2>cPanel Cron Jobs</h2><p>Otomasyon; yenileme faturalarını oluşturur, vadesi geçmiş ve ödenmemiş faturalar için askıya alma işi açar, ödeme sonrası yeniden etkinleştirmeyi işler. Her çağrı en fazla bir kontrol paneli hesabı işlemi yapar.</p><p>Önerilen çalışma aralığı: dakikada bir.</p><pre>'.$this->e($command).'</pre><p>Komut kurulum anahtarından türetilen gizli erişim bilgisi içerir. Paylaşmayın. Uygulama anahtarı değişirse cron komutunu yeniden alın.</p></div><div class="box"><h2>Güvenlik</h2><p>Manuel yönetici askıya alması ile fatura nedeniyle askıya alma ayrı tutulur. Sistem yalnızca faturalandırma nedeniyle askıya aldığı hizmeti otomatik açabilir. Belirsiz uzak sunucu yanıtları yönetici incelemesine alınır.</p><p>Alan adı girilmeden hosting hesabı oluşturulmaz. Uygun sunucu ve ürün-paket eşleştirmesi gerekir.</p></div>';
        $scheduledChanges=$this->db->query("SELECT c.id,c.effective_at,c.target_amount,c.currency,s.domain,fp.name AS from_name,tp.name AS to_name,u.email FROM service_package_changes c JOIN services s ON s.id=c.service_id JOIN products fp ON fp.id=c.from_product_id JOIN products tp ON tp.id=c.to_product_id JOIN users u ON u.id=s.user_id WHERE c.change_type='downgrade' AND c.status='scheduled' AND c.invoice_id IS NULL ORDER BY c.effective_at,c.id LIMIT 100")->fetchAll();
        echo '<div class="box"><h2>Planlanmış paket düşürmeleri</h2><p>Bu değişiklikler yenileme tarihinde otomatik kuyruğa alınır. Uygulama tamamlanana kadar eski paket üzerinden yenileme faturası oluşturulmaz.</p>';
        if(!$scheduledChanges)echo '<p>Planlanmış paket düşürme bulunmuyor.</p>';
        foreach($scheduledChanges as $change)echo '<article style="border-top:1px solid #2b3c56;padding:12px 0"><b>'.$this->e($change['from_name']).' → '.$this->e($change['to_name']).'</b><p>'.$this->e($change['email']).' · '.$this->e((string)($change['domain']??'Domain yok')).' · Uygulama tarihi: '.$this->e((string)$change['effective_at']).' · Yeni ücret: '.$this->e(number_format((float)$change['target_amount'],2,',','.')).' '.$this->e($change['currency']).'</p></article>';
        echo '</div>';
        $jobs=$this->db->query("SELECT j.*,s.domain,s.provisioning_username,s.provisioning_status,s.status AS service_status,p.name AS product_name,u.email,sv.name AS server_name FROM provisioning_jobs j JOIN services s ON s.id=j.service_id JOIN products p ON p.id=s.product_id JOIN users u ON u.id=s.user_id LEFT JOIN servers sv ON sv.id=s.server_id ORDER BY CASE WHEN j.status='needs_review' THEN 0 WHEN j.status='failed' THEN 1 WHEN j.status='queued' THEN 2 ELSE 3 END,j.updated_at DESC LIMIT 100")->fetchAll();
        echo '<div class="box"><h2>İş kuyruğu ve yönetici incelemesi</h2><p>Belirsiz uzak işlemleri panelden doğrulamadan tekrar çalıştırmayın. Hesap oluşturma incelemesinde kullanıcı adıyla kontrol panelinde arama yapın.</p>';
        if(!$jobs)echo '<p>Henüz otomasyon işi yok.</p>';
        foreach($jobs as $job){
            $type=(string)$job['job_type'];$label=$type==='create'?'Hesap oluşturma':(str_starts_with($type,'package_')?'Paket değişikliği':(str_starts_with($type,'suspend_')?'Fatura nedeniyle askıya alma':(str_starts_with($type,'unsuspend_')?'Ödeme sonrası yeniden açma':$type)));if(preg_match('/^package_([0-9]+)$/',$type,$labelMatch)){$labelQuery=$this->db->prepare('SELECT target_amount,invoice_id,change_type,effective_at,tp.name AS target_name FROM service_package_changes c JOIN products tp ON tp.id=c.to_product_id WHERE c.id=:id');$labelQuery->execute([':id'=>(int)$labelMatch[1]]);$labelData=$labelQuery->fetch();if($labelData){$label=$labelData['change_type']==='downgrade'?'Planlı paket düşürme':'Paket yükseltme';$job['package_target']=$labelData['target_name'];$job['package_effective']=$labelData['effective_at'];}}
            echo '<article style="border-top:1px solid #2b3c56;padding:15px 0"><b>'.$this->e($job['product_name']).' · '.$this->e($label).'</b><p>'.$this->e($job['email']).' · '.$this->e((string)($job['domain']??'Domain yok')).' · Sunucu: '.$this->e((string)($job['server_name']??'Atanmadı')).' · Durum: <b>'.$this->e($job['status']).'</b></p>';
            if(!empty($job['provisioning_username']))echo '<p>Kontrol panelinde aranacak kullanıcı adı: <code>'.$this->e($job['provisioning_username']).'</code></p>';
            if(!empty($job['package_target']))echo '<p>Hedef paket: '.$this->e($job['package_target']).' · Yürürlük: '.$this->e((string)$job['package_effective']).'</p>';
            if(!empty($job['last_error']))echo '<p class="error">'.$this->e($job['last_error']).'</p>';
            if($job['status']==='failed'){
                echo '<form method="post" action="'.$this->e($this->url('/admin/automation/jobs/retry')).'"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="job_id" value="'.(int)$job['id'].'"><button type="submit">Güvenli şekilde yeniden kuyruğa al</button></form>';
            }elseif($job['status']==='needs_review'&&$type==='create'&&!empty($job['provisioning_username'])){
                echo '<p>Önce kontrol panelinde kullanıcı adının varlığını ve alan adını doğrulayın.</p><form method="post" action="'.$this->e($this->url('/admin/automation/jobs/resolve')).'" style="display:inline-block;margin:4px 6px 4px 0"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="job_id" value="'.(int)$job['id'].'"><input type="hidden" name="resolution" value="remote_created"><button type="submit">Hesap mevcut — etkinleştir</button></form><form method="post" action="'.$this->e($this->url('/admin/automation/jobs/resolve')).'" style="display:inline-block;margin:4px 0"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="job_id" value="'.(int)$job['id'].'"><input type="hidden" name="resolution" value="remote_absent"><button type="submit">Hesap yok — yeniden dene</button></form>';
            }elseif($job['status']==='needs_review'&&preg_match('/^terminate_[0-9]{14}_[a-f0-9]{6}$/',$type)){
                echo '<p>Kontrol panelinde bu domain ve kullanıcı adının mevcut olup olmadığını doğrulayın. Silinmişse HostPilot kaydını sonlandırılmış olarak eşitleyin; hâlâ mevcutsa yeniden denemeyi seçin.</p>';
                echo '<form method="post" action="'.$this->e($this->url('/admin/automation/jobs/resolve')).'" style="display:inline-block;margin:4px 6px 4px 0"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="job_id" value="'.(int)$job['id'].'"><input type="hidden" name="resolution" value="remote_deleted"><button type="submit">Uzak hesap silinmiş — eşitle</button></form>';
                echo '<form method="post" action="'.$this->e($this->url('/admin/automation/jobs/resolve')).'" style="display:inline-block;margin:4px 0"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="job_id" value="'.(int)$job['id'].'"><input type="hidden" name="resolution" value="remote_present"><button type="submit">Uzak hesap mevcut — yeniden dene</button></form>';
            }elseif($job['status']==='needs_review'&&preg_match('/^package_([0-9]+)$/',$type,$packageReview)){
                echo '<p>Kontrol panelinde hizmetin yeni pakete geçip geçmediğini doğrulayın. Uzak paket değiştiyse HostPilot ürününü eşitleyin; değişmediyse yeniden deneyin.</p>';
                echo '<form method="post" action="'.$this->e($this->url('/admin/automation/jobs/resolve')).'" style="display:inline-block;margin:4px 6px 4px 0"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="job_id" value="'.(int)$job['id'].'"><input type="hidden" name="resolution" value="remote_applied"><button type="submit">Yeni paket uygulanmış — eşitle</button></form>';
                echo '<form method="post" action="'.$this->e($this->url('/admin/automation/jobs/resolve')).'" style="display:inline-block;margin:4px 0"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="job_id" value="'.(int)$job['id'].'"><input type="hidden" name="resolution" value="remote_not_applied"><button type="submit">Eski paket duruyor — yeniden dene</button></form>';
            }elseif($job['status']==='needs_review'&&preg_match('/^(suspend|unsuspend)_([0-9]+)$/',$type,$lifeMatch)){
                $lifeAction=$lifeMatch[1];
                echo '<p>Kontrol panelinde hesabın durumunu doğrulayın. Uzak işlem uygulanmışsa durumu eşitleyin; uygulanmamışsa güvenli yeniden deneme kuyruğuna alın.</p>';
                echo '<form method="post" action="'.$this->e($this->url('/admin/automation/jobs/resolve')).'" style="display:inline-block;margin:4px 6px 4px 0"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="job_id" value="'.(int)$job['id'].'"><input type="hidden" name="resolution" value="remote_applied"><button type="submit">'.($lifeAction==='suspend'?'Hesap askıda — HostPilot ile eşitle':'Hesap aktif — HostPilot ile eşitle').'</button></form>';
                echo '<form method="post" action="'.$this->e($this->url('/admin/automation/jobs/resolve')).'" style="display:inline-block;margin:4px 0"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="job_id" value="'.(int)$job['id'].'"><input type="hidden" name="resolution" value="remote_not_applied"><button type="submit">Uzak işlem uygulanmadı — yeniden kuyruğa al</button></form>';
            }elseif($job['status']==='needs_review'){
                echo '<p>Uzak hizmet durumu belirsiz. Kontrol panelinde doğrulayıp gerekli manuel durum eşitlemesini yapın; otomatik tekrar kapalıdır.</p>';
            }
            echo '</article>';
        }
        echo '</div></div></html>';
    }

    public function retryProvisioningJob():void
    {
        $user=Auth::user();
        if(!$user||!in_array($user['role']??'', ['super_admin','admin'],true)){http_response_code(403);echo 'Yetkisiz erişim.';return;}
        if(!Auth::verifyCsrf($_POST['_token']??null))$this->go('/admin/automation');
        $id=(int)($_POST['job_id']??0);$now=gmdate('Y-m-d H:i:s');
        try{
            $this->db->beginTransaction();
            $q=$this->db->prepare('SELECT * FROM provisioning_jobs WHERE id=:id FOR UPDATE');$q->execute([':id'=>$id]);$job=$q->fetch();
            if(!$job||$job['status']!=='failed')throw new \RuntimeException('Yalnızca güvenli biçimde başarısız olmuş işler yeniden kuyruğa alınabilir.');
            $serviceQuery=$this->db->prepare('SELECT * FROM services WHERE id=:id FOR UPDATE');$serviceQuery->execute([':id'=>$job['service_id']]);$service=$serviceQuery->fetch();
            if(!$service)throw new \RuntimeException('Hizmet kaydı bulunamadı.');
            if($job['job_type']==='create'){
                if($service['status']!=='pending'||empty($service['domain']))throw new \RuntimeException('Hizmet veya alan adı yeniden denemeye uygun değil.');
                $this->db->prepare("UPDATE services SET provisioning_status='queued',provisioning_error=NULL,updated_at=:updated WHERE id=:id")->execute([':updated'=>$now,':id'=>$service['id']]);
            }elseif(preg_match('/^package_([0-9]+)$/',(string)$job['job_type'],$packageRetry)){
                $change=$this->db->prepare("SELECT id,status,invoice_id,target_amount,effective_at,from_product_id,change_type FROM service_package_changes WHERE id=:id AND service_id=:service FOR UPDATE");$change->execute([':id'=>(int)$packageRetry[1],':service'=>$service['id']]);$request=$change->fetch();
                if(!$request||$request['status']!=='failed')throw new \RuntimeException('Paket değişikliği işi güvenli yeniden denemeye uygun değil.');
                if($request['change_type']==='downgrade'){
                    if($request['invoice_id']!==null||(float)$request['target_amount']>=(float)$service['amount']||$request['effective_at']>=$now||$service['status']!=='active'||(int)$service['product_id']!==(int)$request['from_product_id'])throw new \RuntimeException('Planlanmış paket düşürme henüz güvenli yeniden deneme koşullarını karşılamıyor.');
                }else{
                    if($request['invoice_id']===null)throw new \RuntimeException('Yükseltme faturası bağlantısı bulunamadı.');
                    $paid=$this->db->prepare("SELECT status,total,amount_paid FROM invoices WHERE id=:id");$paid->execute([':id'=>$request['invoice_id']]);$bill=$paid->fetch();
                    if(!$bill||$bill['status']!=='paid'||(float)$bill['amount_paid']+0.005<(float)$bill['total'])throw new \RuntimeException('Paket yükseltme faturası tamamen ödenmiş değil.');
                }
                $this->db->prepare("UPDATE service_package_changes SET status='queued',updated_at=:updated WHERE id=:id")->execute([':updated'=>$now,':id'=>$request['id']]);
            }elseif(!preg_match('/^(suspend|unsuspend)_[0-9]+$/',(string)$job['job_type'])){
                throw new \RuntimeException('Desteklenmeyen kuyruk işi.');
            }
            $this->db->prepare("UPDATE provisioning_jobs SET status='queued',attempts=0,available_at=:available,locked_at=NULL,last_error=NULL,updated_at=:updated WHERE id=:id")
                ->execute([':available'=>$now,':updated'=>$now,':id'=>$id]);
            $this->db->commit();
            $this->go('/admin/automation','notice=İş yeniden kuyruğa alındı.');
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            $this->go('/admin/automation','error='.$e->getMessage());
        }
    }

    public function resolveProvisioningJob():void
    {
        $user=Auth::user();
        if(!$user||!in_array($user['role']??'', ['super_admin','admin'],true)){http_response_code(403);echo 'Yetkisiz erişim.';return;}
        if(!Auth::verifyCsrf($_POST['_token']??null))$this->go('/admin/automation');
        $id=(int)($_POST['job_id']??0);$resolution=(string)($_POST['resolution']??'');$now=gmdate('Y-m-d H:i:s');
        try{
            $this->db->beginTransaction();
            $q=$this->db->prepare('SELECT * FROM provisioning_jobs WHERE id=:id FOR UPDATE');$q->execute([':id'=>$id]);$job=$q->fetch();
            if(!$job||$job['status']!=='needs_review')throw new \RuntimeException('Bu işlem yönetici incelemesinde değil.');
            $serviceQuery=$this->db->prepare('SELECT * FROM services WHERE id=:id FOR UPDATE');$serviceQuery->execute([':id'=>$job['service_id']]);$service=$serviceQuery->fetch();
            if(!$service)throw new \RuntimeException('Hizmet kaydı bulunamadı.');
            if(preg_match('/^terminate_[0-9]{14}_[a-f0-9]{6}$/',(string)$job['job_type'])){
                if(!in_array($resolution,['remote_deleted','remote_present'],true))throw new \RuntimeException('Geçersiz sonlandırma uzlaştırma kararı.');
                if($resolution==='remote_deleted'){
                    $this->db->prepare("UPDATE services SET status='terminated',provisioning_status='terminated',provisioning_error=NULL,remote_password_enc=NULL,suspension_source='none',suspension_invoice_id=NULL,suspended_at=NULL,updated_at=:updated WHERE id=:id AND status IN ('active','suspended')")->execute([':updated'=>$now,':id'=>$service['id']]);
                    $jobStatus='completed';$message='Yönetici uzak hesabın silindiğini doğruladı; HostPilot hizmeti sonlandırıldı.';
                }else{
                    if(!in_array($service['status'],['active','suspended'],true))throw new \RuntimeException('Hizmet yeniden denemeye uygun değil.');
                    $this->db->prepare("UPDATE services SET provisioning_status='completed',provisioning_error=NULL,updated_at=:updated WHERE id=:id")->execute([':updated'=>$now,':id'=>$service['id']]);
                    $jobStatus='queued';$message='Yönetici uzak hesabın hâlâ mevcut olduğunu doğruladı; sonlandırma yeniden kuyruğa alındı.';
                }
                $this->db->prepare("UPDATE provisioning_jobs SET status=:status,attempts=0,available_at=:available,locked_at=NULL,last_error=NULL,updated_at=:updated WHERE id=:id")->execute([':status'=>$jobStatus,':available'=>$now,':updated'=>$now,':id'=>$id]);
                $this->audit((int)$service['id'],(int)$service['server_id'],'terminate','admin_reconciled',$message);
                $this->db->commit();
                $this->go('/admin/automation','notice='.$message);
            }elseif(preg_match('/^package_([0-9]+)$/',(string)$job['job_type'],$packageMatch)){
                if(!in_array($resolution,['remote_applied','remote_not_applied'],true))throw new \RuntimeException('Geçersiz paket değişikliği uzlaştırma kararı.');
                $changeQuery=$this->db->prepare('SELECT * FROM service_package_changes WHERE id=:id AND service_id=:service FOR UPDATE');
                $changeQuery->execute([':id'=>(int)$packageMatch[1],':service'=>$service['id']]);$change=$changeQuery->fetch();
                if(!$change||$change['status']!=='needs_review')throw new \RuntimeException('Paket değişikliği inceleme kaydı bulunamadı.');
                $scheduled=$change['change_type']==='downgrade';
                if($scheduled){
                    if($change['invoice_id']!==null||(float)$change['target_amount']>=(float)$service['amount']||$change['effective_at']>$now||empty($service['next_due_date'])||$service['next_due_date']>gmdate('Y-m-d'))throw new \RuntimeException('Planlanmış paket düşürme henüz yürürlük tarihine ulaşmadı veya tutarsız.');
                }else{
                    if($change['invoice_id']===null)throw new \RuntimeException('Yükseltme faturası bağlantısı bulunamadı.');
                    $invoiceQuery=$this->db->prepare('SELECT status,total,amount_paid FROM invoices WHERE id=:id');$invoiceQuery->execute([':id'=>$change['invoice_id']]);$bill=$invoiceQuery->fetch();
                    if(!$bill||$bill['status']!=='paid'||(float)$bill['amount_paid']+0.005<(float)$bill['total'])throw new \RuntimeException('Paket yükseltme faturası tamamen ödenmiş değil.');
                }
                if($resolution==='remote_applied'){
                    if((int)$service['product_id']!==(int)$change['from_product_id']||$service['status']!=='active')throw new \RuntimeException('Hizmetin mevcut durumu paket değişikliğiyle çelişiyor.');
                    $this->db->prepare('UPDATE services SET product_id=:product,amount=:amount,updated_at=:updated WHERE id=:id')->execute([':product'=>$change['to_product_id'],':amount'=>$change['target_amount'],':updated'=>$now,':id'=>$service['id']]);
                    $this->db->prepare("UPDATE service_package_changes SET status='completed',updated_at=:updated WHERE id=:id")->execute([':updated'=>$now,':id'=>$change['id']]);
                    $jobStatus='completed';$message=$scheduled?'Yönetici uzak paketin planlanan tarihte düşürüldüğünü doğruladı; HostPilot hizmeti yeni paketle eşitlendi.':'Yönetici uzak paketin değiştirildiğini doğruladı; HostPilot hizmeti yeni paketle eşitlendi.';
                }else{
                    if((int)$service['product_id']!==(int)$change['from_product_id']||$service['status']!=='active')throw new \RuntimeException('Hizmet yeniden denemeye uygun değil.');
                    $this->db->prepare("UPDATE service_package_changes SET status='queued',updated_at=:updated WHERE id=:id")->execute([':updated'=>$now,':id'=>$change['id']]);
                    $jobStatus='queued';$message='Yönetici uzak paketin henüz değiştirilmediğini doğruladı; işlem yeniden kuyruğa alındı.';
                }
                $this->db->prepare("UPDATE provisioning_jobs SET status=:status,attempts=0,available_at=:available,locked_at=NULL,last_error=NULL,updated_at=:updated WHERE id=:id")->execute([':status'=>$jobStatus,':available'=>$now,':updated'=>$now,':id'=>$id]);
                $this->audit((int)$service['id'],(int)$service['server_id'],'package_change','admin_reconciled',$message);
                $this->db->commit();$this->go('/admin/automation','notice='.$message);
            }elseif(preg_match('/^(suspend|unsuspend)_([0-9]+)$/',(string)$job['job_type'],$lifeMatch)){
                if(!in_array($resolution,['remote_applied','remote_not_applied'],true))throw new \RuntimeException('Geçersiz uzak hizmet uzlaştırma kararı.');
                $action=$lifeMatch[1];$invoiceId=(int)$lifeMatch[2];
                $iq=$this->db->prepare('SELECT * FROM invoices WHERE id=:id AND service_id=:service FOR UPDATE');$iq->execute([':id'=>$invoiceId,':service'=>$service['id']]);$invoice=$iq->fetch();
                if(!$invoice)throw new \RuntimeException('İşlem faturası bulunamadı.');
                if($resolution==='remote_applied'){
                    if($action==='suspend'){
                        if(!in_array($invoice['status'],['unpaid','partially_paid','overdue'],true)||(float)$invoice['amount_paid']+0.005>=(float)$invoice['total']||empty($invoice['due_at'])||$invoice['due_at']>$now)throw new \RuntimeException('Fatura artık askıya alma koşullarını karşılamıyor; hizmeti askıda olarak eşitlemek güvenli değil.');
                        if($service['status']==='active'&&in_array(($service['suspension_source']??'none'),['none','review'],true)){
                            $this->db->prepare("UPDATE services SET status='suspended',suspension_source='billing',suspension_invoice_id=:invoice,suspended_at=:at,updated_at=:updated WHERE id=:id")->execute([':invoice'=>$invoiceId,':at'=>$now,':updated'=>$now,':id'=>$service['id']]);
                        }elseif(!($service['status']==='suspended'&&($service['suspension_source']??'')==='billing'&&(int)$service['suspension_invoice_id']===$invoiceId))throw new \RuntimeException('Hizmetin mevcut durumu fatura kaynaklı askıya alma ile çelişiyor.');
                    }else{
                        if($invoice['status']!=='paid'||(float)$invoice['amount_paid']+0.005<(float)$invoice['total'])throw new \RuntimeException('Fatura tamamen ödenmiş değil; hizmeti aktif olarak eşitlemek güvenli değil.');
                        if($service['status']==='suspended'&&($service['suspension_source']??'')==='billing'&&(int)$service['suspension_invoice_id']===$invoiceId){
                            $this->db->prepare("UPDATE services SET status='active',suspension_source='none',suspension_invoice_id=NULL,suspended_at=NULL,updated_at=:updated WHERE id=:id")->execute([':updated'=>$now,':id'=>$service['id']]);
                        }elseif(!($service['status']==='active'&&($service['suspension_source']??'none')==='none'))throw new \RuntimeException('Hizmetin mevcut durumu fatura kaynaklı yeniden etkinleştirme ile çelişiyor.');
                    }
                    $jobStatus='completed';$message='Yönetici uzak kontrol paneli durumunu doğruladı ve HostPilot kaydı eşitlendi.';
                }else{
                    if($action==='suspend'&&$service['status']==='active'&&($service['suspension_source']??'none')==='review'){
                        $this->db->prepare("UPDATE services SET suspension_source='none',updated_at=:updated WHERE id=:id")->execute([':updated'=>$now,':id'=>$service['id']]);
                    }
                    $jobStatus='queued';$message='Yönetici uzak işlemin uygulanmadığını doğruladı; kuyruk yeniden başlatıldı.';
                }
                $this->db->prepare("UPDATE provisioning_jobs SET status=:status,attempts=0,available_at=:available,locked_at=NULL,last_error=NULL,updated_at=:updated WHERE id=:id")->execute([':status'=>$jobStatus,':available'=>$now,':updated'=>$now,':id'=>$id]);
                $this->audit((int)$service['id'],(int)$service['server_id'],(string)$job['job_type'],'admin_reconciled',$message);
                $this->db->commit();
                $this->go('/admin/automation','notice='.$message);
            }elseif($job['job_type']==='create'&&$resolution==='remote_created'){
                if(empty($service['provisioning_username'])||empty($service['provisioning_password_enc'])||empty($service['domain'])||empty($service['server_id']))throw new \RuntimeException('Kayıtlı hesap oluşturma bilgileri eksik; manuel uzlaştırma yapılamıyor.');
                $this->db->prepare("UPDATE services SET status='active',provisioning_status='completed',remote_identifier=:username,remote_username=:username,remote_domain=:domain,remote_password_enc=:password,provisioning_username=NULL,provisioning_password_enc=NULL,provisioned_at=:at,provisioning_error=NULL,updated_at=:updated WHERE id=:id")
                    ->execute([':username'=>$service['provisioning_username'],':domain'=>$service['domain'],':password'=>$service['provisioning_password_enc'],':at'=>$now,':updated'=>$now,':id'=>$service['id']]);
                $message='Yönetici kontrol panelinde hesabın mevcut olduğunu doğruladı.';
                $jobStatus='completed';
            }elseif($resolution==='remote_absent'){
                if($service['status']!=='pending'||empty($service['domain']))throw new \RuntimeException('Hizmet yeniden denemeye uygun değil.');
                $this->db->prepare("UPDATE services SET provisioning_status='queued',provisioning_error=NULL,updated_at=:updated WHERE id=:id")->execute([':updated'=>$now,':id'=>$service['id']]);
                $message='Yönetici kontrol panelinde hesabın bulunmadığını doğruladı; aynı hesap bilgileriyle tekrar kuyruğa alındı.';
                $jobStatus='queued';
            }else{
                throw new \RuntimeException('Geçersiz uzlaştırma kararı.');
            }
            $this->db->prepare("UPDATE provisioning_jobs SET status=:status,attempts=0,available_at=:available,locked_at=NULL,last_error=NULL,updated_at=:updated WHERE id=:id")
                ->execute([':status'=>$jobStatus,':available'=>$now,':updated'=>$now,':id'=>$id]);
            $this->audit((int)$service['id'],(int)$service['server_id'],'create','admin_reconciled',$message);
            $this->db->commit();
            $this->go('/admin/automation','notice='.$message);
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            $this->go('/admin/automation','error='.$e->getMessage());
        }
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
            $stale=$this->db->prepare("SELECT id,service_id,job_type FROM provisioning_jobs WHERE status='processing' AND locked_at IS NOT NULL AND locked_at<:cutoff LIMIT 10 FOR UPDATE");
            $stale->execute([':cutoff'=>gmdate('Y-m-d H:i:s',time()-1200)]);
            foreach($stale->fetchAll() as $old){
                $this->db->prepare("UPDATE provisioning_jobs SET status='needs_review',last_error='Worker yanıtı zaman aşımına uğradı; uzak hesap durumu doğrulanmalı.',updated_at=:now WHERE id=:id")->execute([':now'=>$now,':id'=>$old['id']]);
                if($old['job_type']==='create'){
                    $this->db->prepare("UPDATE services SET provisioning_status='needs_review',provisioning_error='Otomatik işlem sonucu belirsiz; yönetici kontrolü gerekli.',updated_at=:now WHERE id=:id")->execute([':now'=>$now,':id'=>$old['service_id']]);
                }elseif(preg_match('/^package_([0-9]+)$/',(string)$old['job_type'],$stalePackage)){
                    $this->db->prepare("UPDATE service_package_changes SET status='needs_review',updated_at=:now WHERE id=:id")->execute([':now'=>$now,':id'=>(int)$stalePackage[1]]);
                }elseif(str_starts_with((string)$old['job_type'],'terminate_')){
                    $this->db->prepare("UPDATE services SET provisioning_status='needs_review',provisioning_error='Hesap sonlandırma yanıtı belirsiz; uzak hesap doğrulanmalı.',updated_at=:now WHERE id=:id")->execute([':now'=>$now,':id'=>$old['service_id']]);
                }else{
                    $this->db->prepare("UPDATE services SET suspension_source='review',updated_at=:now WHERE id=:id AND suspension_source='none'")->execute([':now'=>$now,':id'=>$old['service_id']]);
                }
            }
            $pick=$this->db->prepare("SELECT id FROM provisioning_jobs WHERE status='queued' AND available_at<=:now ORDER BY id ASC LIMIT 1 FOR UPDATE");
            $pick->execute([':now'=>$now]);$jobId=(int)$pick->fetchColumn();
            if(!$jobId){$this->db->commit();echo json_encode(['ok'=>true,'processed'=>0,'message'=>'Bekleyen iş yok.']);return;}
            $jobQuery=$this->db->prepare("SELECT * FROM provisioning_jobs WHERE id=:id FOR UPDATE");
            $jobQuery->execute([':id'=>$jobId]);$job=$jobQuery->fetch();
            if(!$job||($job['job_type']!=='create'&&!preg_match('/^(suspend|unsuspend)_[0-9]+$/',(string)$job['job_type'])&&!preg_match('/^terminate_[0-9]{14}_[a-f0-9]{6}$/',(string)$job['job_type'])&&!preg_match('/^package_[0-9]+$/',(string)$job['job_type'])))throw new \RuntimeException('Desteklenmeyen kuyruk işlemi.');
            $this->db->prepare("UPDATE provisioning_jobs SET status='processing',attempts=attempts+1,locked_at=:now,updated_at=:now2 WHERE id=:id")
                ->execute([':now'=>$now,':now2'=>$now,':id'=>$jobId]);
            $this->db->commit();

            $serviceQuery=$this->db->prepare("SELECT s.*,u.email FROM services s JOIN users u ON u.id=s.user_id WHERE s.id=:id LIMIT 1");
            $serviceQuery->execute([':id'=>$job['service_id']]);$service=$serviceQuery->fetch();
            if(!$service)throw new \RuntimeException('Hizmet kaydı bulunamadı.');
            if(str_starts_with((string)$job['job_type'],'terminate_')){
                $this->processTerminationJob($job,$service,$remoteCallStarted);
                return;
            }
            if(preg_match('/^package_([0-9]+)$/',(string)$job['job_type'],$packageMatch)){
                $this->processPackageChangeJob($job,$service,(int)$packageMatch[1],$remoteCallStarted);
                return;
            }
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
            $username=(string)($service['provisioning_username']??'');
            $passwordEnc=(string)($service['provisioning_password_enc']??'');
            if($username===''||$passwordEnc===''){
                $username='hp'.substr(bin2hex(random_bytes(6)),0,6);
                $password=rtrim(strtr(base64_encode(random_bytes(24)),'+/','-_'),'=');
                $passwordEnc=$this->vault->encrypt($password);
            }else{
                $password=$this->vault->decrypt($passwordEnc);
            }
            $this->db->prepare("UPDATE services SET server_id=:server,remote_domain=domain,provisioning_username=:username,provisioning_password_enc=:password,provisioning_status='in_progress',provisioning_error=NULL,updated_at=:now WHERE id=:id")
                ->execute([':server'=>$server['id'],':username'=>$username,':password'=>$passwordEnc,':now'=>$now,':id'=>$service['id']]);
            $remoteCallStarted=true;
            $result=$adapter->createAccount(['username'=>$username,'password'=>$password,'domain'=>$service['domain'],'email'=>$service['email'],'package'=>$server['remote_package']]);

            $finished=gmdate('Y-m-d H:i:s');
            $this->db->beginTransaction();
            $this->db->prepare("UPDATE services SET status='active',provisioning_status='completed',remote_identifier=:remote,remote_username=:username,remote_domain=domain,remote_password_enc=:password,provisioning_username=NULL,provisioning_password_enc=NULL,provisioned_at=:at,provisioning_error=NULL,updated_at=:updated WHERE id=:id")
                ->execute([':remote'=>$result['identifier'],':username'=>$result['username'],':password'=>$passwordEnc,':at'=>$finished,':updated'=>$finished,':id'=>$service['id']]);
            $this->db->prepare("UPDATE provisioning_jobs SET status='completed',locked_at=NULL,last_error=NULL,updated_at=:updated WHERE id=:id")
                ->execute([':updated'=>$finished,':id'=>$jobId]);
            $this->audit((int)$service['id'],(int)$server['id'],'create','success','Hosting hesabı oluşturuldu: '.(string)$result['identifier']);
            $this->db->commit();
            echo json_encode(['ok'=>true,'processed'=>1,'job_id'=>$jobId,'service_id'=>(int)$service['id'],'status'=>'completed'],JSON_UNESCAPED_SLASHES);
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            $finished=gmdate('Y-m-d H:i:s');
            $ambiguous=$remoteCallStarted;
            $attempts=(int)($job['attempts']??0)+1;
            $retry=!$ambiguous&&$attempts<5;
            $jobStatus=$ambiguous?'needs_review':($retry?'queued':'failed');
            $safe=substr($e->getMessage(),0,900);
            if($jobId>0){
                $delay=min(3600,300*(2**max(0,$attempts-1)));
                $available=$retry?gmdate('Y-m-d H:i:s',time()+$delay):$finished;
                $this->db->prepare("UPDATE provisioning_jobs SET status=:status,available_at=:available,locked_at=NULL,attempts=:attempts,last_error=:error,updated_at=:updated WHERE id=:id")
                    ->execute([':status'=>$jobStatus,':available'=>$available,':attempts'=>$attempts,':error'=>$safe,':updated'=>$finished,':id'=>$jobId]);
                if(is_array($service)&&isset($service['id'])){
                    if(($job['job_type']??'create')==='create'){
                        $serviceStatus=$ambiguous?'needs_review':($retry?'queued':'failed');
                        $this->db->prepare("UPDATE services SET provisioning_status=:status,provisioning_error=:error,updated_at=:updated WHERE id=:id")
                            ->execute([':status'=>$serviceStatus,':error'=>$ambiguous?'Uzak sunucu yanıtı belirsiz; yinelenen hesap açılmasını önlemek için yönetici kontrolü gerekiyor.':$safe,':updated'=>$finished,':id'=>$service['id']]);
                    }elseif(preg_match('/^package_([0-9]+)$/',(string)($job['job_type']??''),$failedPackage)){
                        $this->db->prepare('UPDATE service_package_changes SET status=:status,updated_at=:updated WHERE id=:id')->execute([':status'=>$ambiguous?'needs_review':($retry?'queued':'failed'),':updated'=>$finished,':id'=>(int)$failedPackage[1]]);
                    }elseif($ambiguous&&str_starts_with((string)($job['job_type']??''),'terminate_')){
                        $this->db->prepare("UPDATE services SET provisioning_status='needs_review',provisioning_error='Uzak hesap silme yanıtı belirsiz; yönetici kontrolü gerekli.',updated_at=:updated WHERE id=:id")->execute([':updated'=>$finished,':id'=>$service['id']]);
                    }elseif($ambiguous){
                        $this->db->prepare("UPDATE services SET suspension_source='review',updated_at=:updated WHERE id=:id AND suspension_source='none'")->execute([':updated'=>$finished,':id'=>$service['id']]);
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

            // A due downgrade must be applied before any renewal invoice is priced.
            $this->db->beginTransaction();
            $scheduled=$this->db->prepare("SELECT c.id AS change_id,c.service_id FROM service_package_changes c JOIN services s ON s.id=c.service_id WHERE c.change_type='downgrade' AND c.status='scheduled' AND c.invoice_id IS NULL AND c.effective_at<=:now AND s.status='active' AND s.provisioning_status='completed' AND s.next_due_date<=:today ORDER BY c.effective_at,c.id LIMIT 1 FOR UPDATE");
            $scheduled->execute([':now'=>$now,':today'=>$today]);$change=$scheduled->fetch();
            if($change){
                $type='package_'.(int)$change['change_id'];
                $insert=$this->db->prepare("INSERT IGNORE INTO provisioning_jobs(service_id,job_type,status,attempts,available_at,created_at,updated_at) VALUES(:service,:type,'queued',0,:available,:created,:updated)");
                $insert->execute([':service'=>$change['service_id'],':type'=>$type,':available'=>$now,':created'=>$now,':updated'=>$now]);
                $this->db->prepare("UPDATE service_package_changes SET status='queued',updated_at=:updated WHERE id=:id AND status='scheduled'")->execute([':updated'=>$now,':id'=>$change['change_id']]);
            }

            // Never bill the old package while a scheduled downgrade is queued, processing, failed, or under review.
            $due=$this->db->prepare("SELECT s.*,p.name AS product_name FROM services s JOIN products p ON p.id=s.product_id WHERE s.status='active' AND s.provisioning_status='completed' AND s.suspension_source='none' AND s.next_due_date IS NOT NULL AND s.next_due_date<=:today AND NOT EXISTS (SELECT 1 FROM service_package_changes c WHERE c.service_id=s.id AND c.change_type='downgrade' AND c.invoice_id IS NULL AND c.status IN ('scheduled','queued','processing','needs_review','failed')) ORDER BY s.next_due_date,s.id LIMIT 1 FOR UPDATE");
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

            $this->db->prepare("UPDATE invoices SET status='overdue',updated_at=:updated WHERE status='unpaid' AND due_at IS NOT NULL AND due_at<=:now")
                ->execute([':updated'=>$now,':now'=>$now]);

            // Queue suspension only for a provisioned service with a genuinely outstanding, expired invoice balance.
            $overdue=$this->db->prepare("SELECT i.id AS invoice_id,s.id AS service_id FROM invoices i JOIN services s ON s.id=i.service_id WHERE i.status IN ('unpaid','partially_paid','overdue') AND i.amount_paid+0.005<i.total AND i.due_at IS NOT NULL AND i.due_at<=:now AND s.status='active' AND s.suspension_source='none' AND s.provisioned_at IS NOT NULL ORDER BY i.due_at,i.id LIMIT 1");
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

    private function processPackageChangeJob(array $job,array $service,int $changeId,bool &$remoteCallStarted):void
    {
        $q=$this->db->prepare("SELECT * FROM service_package_changes WHERE id=:id AND service_id=:service LIMIT 1");$q->execute([':id'=>$changeId,':service'=>$service['id']]);$change=$q->fetch();
        if(!$change||$change['status']!=='queued'||!in_array($change['change_type'],['upgrade','downgrade'],true))throw new \RuntimeException('Paket değişikliği isteği sırada değil veya türü geçersiz.');
        $scheduled=$change['change_type']==='downgrade';
        $now=gmdate('Y-m-d H:i:s');
        if($scheduled){
            if($change['invoice_id']!==null||(float)$change['target_amount']>=(float)$service['amount'])throw new \RuntimeException('Faturasız planlı işlem yalnızca paket düşürme için kullanılabilir.');
            if($change['effective_at']>$now||empty($service['next_due_date'])||$service['next_due_date']>gmdate('Y-m-d'))throw new \RuntimeException('Paket düşürme henüz yenileme tarihine ulaşmadı.');
        }else{
            if($change['invoice_id']===null)throw new \RuntimeException('Yükseltme faturası bağlantısı bulunamadı.');
            $invoice=$this->db->prepare("SELECT status,total,amount_paid FROM invoices WHERE id=:id");$invoice->execute([':id'=>$change['invoice_id']]);$bill=$invoice->fetch();
            if(!$bill||$bill['status']!=='paid'||(float)$bill['amount_paid']+0.005<(float)$bill['total'])throw new \RuntimeException('Paket yükseltme faturası tamamen ödenmemiş.');
            if((float)$change['target_amount']<=(float)$service['amount'])throw new \RuntimeException('Ödemeli paket değişikliği geçerli bir yükseltme değil.');
        }
        if($service['status']!=='active'||$service['provisioning_status']!=='completed'||(int)$service['product_id']!==(int)$change['from_product_id']||empty($service['remote_username'])||empty($service['remote_domain'])||empty($service['server_id']))throw new \RuntimeException('Hizmet durumu değişti; paket değişikliği iptal edildi.');
        $sq=$this->db->prepare('SELECT * FROM servers WHERE id=:id AND enabled=1 LIMIT 1');$sq->execute([':id'=>$service['server_id']]);$server=$sq->fetch();
        if(!$server)throw new \RuntimeException('Hizmet sunucusu bulunamadı veya devre dışı.');
        if(!in_array($server['panel_type'],['cpanel','plesk'],true))throw new \RuntimeException('Bu sunucu panelinde paket değiştirme desteklenmiyor.');
        if($server['panel_type']==='plesk'&&(empty($change['target_package_guid'])||empty($change['target_package_external_id'])))throw new \RuntimeException('Plesk plan kimlikleri eksik.');
        $server['secret']=$this->vault->decrypt((string)$server['secret_enc']);
        $config=['base_url'=>$server['base_url'],'username'=>$server['username'],'secret'=>$server['secret'],'auth_mode'=>$server['auth_mode'],'shared_ip'=>$server['shared_ip']];
        $adapter=match($server['panel_type']){'cpanel'=>new CpanelWhmAdapter($config),'plesk'=>new PleskXmlAdapter($config),default=>throw new \RuntimeException('Desteklenmeyen kontrol paneli.')};
        $remoteCallStarted=true;
        $adapter->changePackage((string)$service['remote_username'],(string)$service['remote_domain'],(string)$change['target_package'],(string)($change['target_package_guid']??''),(string)($change['target_package_external_id']??''));
        $now=gmdate('Y-m-d H:i:s');$this->db->beginTransaction();
        $u=$this->db->prepare("UPDATE services SET product_id=:product,amount=:amount,updated_at=:updated WHERE id=:id AND product_id=:old AND status='active'");
        $u->execute([':product'=>$change['to_product_id'],':amount'=>$change['target_amount'],':updated'=>$now,':id'=>$service['id'],':old'=>$change['from_product_id']]);
        if($u->rowCount()!==1)throw new \RuntimeException('Uzak paket değişti ancak hizmet kaydı eşzamanlı güncellenemedi.');
        $this->db->prepare("UPDATE service_package_changes SET status='completed',updated_at=:updated WHERE id=:id")->execute([':updated'=>$now,':id'=>$changeId]);
        $this->db->prepare("UPDATE provisioning_jobs SET status='completed',locked_at=NULL,last_error=NULL,updated_at=:updated WHERE id=:id")->execute([':updated'=>$now,':id'=>$job['id']]);
        $this->audit((int)$service['id'],(int)$server['id'],'package_change','success',$scheduled?'Yenileme tarihinde planlı paket düşürme uygulandı.':'Ödenmiş yükseltme sonrası uzak paket değiştirildi.');
        $this->db->commit();
        echo json_encode(['ok'=>true,'processed'=>1,'job_id'=>(int)$job['id'],'status'=>$scheduled?'package_downgraded':'package_changed'],JSON_UNESCAPED_SLASHES);
    }

    private function processTerminationJob(array $job,array $service,bool &$remoteCallStarted):void
    {
        if(!preg_match('/^terminate_[0-9]{14}_[a-f0-9]{6}$/',(string)$job['job_type']))throw new \RuntimeException('Geçersiz sonlandırma işi.');
        if(!in_array($service['status'],['active','suspended'],true)||empty($service['provisioned_at'])||empty($service['server_id'])||empty($service['remote_username'])||empty($service['remote_domain']))throw new \RuntimeException('Hizmet uzak hesap sonlandırmaya uygun değil.');
        $q=$this->db->prepare('SELECT * FROM servers WHERE id=:id LIMIT 1');$q->execute([':id'=>$service['server_id']]);$server=$q->fetch();
        if(!$server||!(int)$server['enabled'])throw new \RuntimeException('Hizmetin sunucusu bulunamadı veya devre dışı.');
        $server['secret']=$this->vault->decrypt((string)$server['secret_enc']);
        $config=['base_url'=>$server['base_url'],'username'=>$server['username'],'secret'=>$server['secret'],'auth_mode'=>$server['auth_mode'],'shared_ip'=>$server['shared_ip']];
        $adapter=match($server['panel_type']){
            'cpanel'=>new CpanelWhmAdapter($config),
            'directadmin'=>new DirectAdminAdapter($config),
            'plesk'=>new PleskXmlAdapter($config),
            default=>throw new \RuntimeException('Desteklenmeyen kontrol paneli.')
        };
        $remoteCallStarted=true;
        $adapter->terminateAccount((string)$service['remote_username'],(string)$service['remote_domain']);
        $now=gmdate('Y-m-d H:i:s');
        $this->db->beginTransaction();
        $u=$this->db->prepare("UPDATE services SET status='terminated',provisioning_status='terminated',provisioning_error=NULL,remote_password_enc=NULL,suspension_source='none',suspension_invoice_id=NULL,suspended_at=NULL,updated_at=:updated WHERE id=:id AND status IN ('active','suspended')");
        $u->execute([':updated'=>$now,':id'=>$service['id']]);
        if($u->rowCount()!==1)throw new \RuntimeException('Uzak hesap silindi ancak HostPilot hizmet kaydı eşzamanlı değişti; yönetici uzlaştırması gerekli.');
        $this->db->prepare("UPDATE provisioning_jobs SET status='completed',locked_at=NULL,last_error=NULL,updated_at=:updated WHERE id=:id")->execute([':updated'=>$now,':id'=>$job['id']]);
        $this->audit((int)$service['id'],(int)$server['id'],'terminate','success','Uzak hosting hesabı kalıcı olarak sonlandırıldı.');
        $this->db->commit();
        echo json_encode(['ok'=>true,'processed'=>1,'job_id'=>(int)$job['id'],'status'=>'terminated'],JSON_UNESCAPED_SLASHES);
    }

    private function processLifecycleJob(array $job,array $service,bool &$remoteCallStarted):void
    {
        if(!preg_match('/^(suspend|unsuspend)_([0-9]+)$/',(string)$job['job_type'],$match))throw new \RuntimeException('Geçersiz hizmet yaşam döngüsü işi.');
        $action=$match[1];$invoiceId=(int)$match[2];$now=gmdate('Y-m-d H:i:s');
        $q=$this->db->prepare('SELECT * FROM invoices WHERE id=:id AND service_id=:service LIMIT 1');
        $q->execute([':id'=>$invoiceId,':service'=>$service['id']]);$invoice=$q->fetch();
        if(!$invoice)throw new \RuntimeException('Hizmetle ilişkili fatura bulunamadı.');

        if($action==='suspend'){
            $eligible=in_array($invoice['status'],['unpaid','partially_paid','overdue'],true)
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
    private function go(string $path,string $query=''):never
    {
        $location=rtrim($this->basePath,'/').$path;
        if($query!==''){parse_str($query,$params);if($params)$location.='?'.http_build_query($params);}
        header('Location: '.$location);exit;
    }
}
