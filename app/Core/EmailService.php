<?php
declare(strict_types=1);
namespace HostPilot\Core;

use PDO;
use RuntimeException;
use Throwable;

final class EmailService
{
    private SecretVault $vault;
    public function __construct(private readonly PDO $db,string $applicationKey)
    {
        $this->vault=new SecretVault($applicationKey);
    }

    public function sendPackageChange(string $recipient,string $customerName,string $serviceDomain,string $fromPlan,string $toPlan,string $event,string $effectiveAt=''):bool
    {
        $subjects=[
            'scheduled'=>'Paket düşürme talebiniz planlandı',
            'requested'=>'Paket yükseltme talebiniz oluşturuldu',
            'cancelled'=>'Paket düşürme talebiniz iptal edildi',
            'payment'=>'Paket yükseltme ödemeniz onaylandı',
            'completed'=>'Hosting paketiniz güncellendi',
        ];
        $messages=[
            'scheduled'=>'Bir sonraki yenileme tarihinizde uygulanmak üzere paket düşürme talebiniz kaydedildi. Mevcut paketiniz bu tarihe kadar aktif kalacaktır.',
            'requested'=>'Paket yükseltme talebiniz alındı. Kalan dönem farkı için oluşturulan faturayı müşteri panelinizden ödeyebilirsiniz. Paketiniz, ödeme doğrulandıktan sonra değiştirilecektir.',
            'cancelled'=>'Planlanmış paket düşürme talebiniz iptal edildi. Mevcut paketiniz ve yenileme düzeniniz değişmedi.',
            'payment'=>'Paket yükseltme ödemeniz doğrulandı. Yeni paketiniz kontrol panelinize uygulanmak üzere işleme alındı.',
            'completed'=>'Hosting paket değişikliğiniz başarıyla tamamlandı.',
        ];
        if(!isset($subjects[$event])||!filter_var($recipient,FILTER_VALIDATE_EMAIL))return false;
        $body="Merhaba ".($customerName!==''?$customerName:'Müşterimiz').",\n\n".$messages[$event]."\n\nHizmet: ".$serviceDomain."\nMevcut paket: ".$fromPlan."\nHedef paket: ".$toPlan;
        if($effectiveAt!=='')$body.="\nUygulama tarihi: ".date('d.m.Y',strtotime($effectiveAt));
        $body.="\n\nHostPilot müşteri panelinizden hizmet durumunuzu takip edebilirsiniz.\n\nBu e-posta otomatik olarak gönderilmiştir.";
        $key=hash('sha256',implode('|',[$event,$recipient,$serviceDomain,$fromPlan,$toPlan,$effectiveAt]));
        return $this->queue($recipient,$subjects[$event],$body,$key,$event);
    }



    public function queueInvoiceNotice(string $recipient,string $customerName,string $invoiceNumber,float $amount,string $currency,string $dueAt,string $event,int $invoiceId):bool
    {
        $labels=[
            'created'=>['Yeni faturanız oluşturuldu','Yeni faturanız oluşturuldu.'],
            'paid'=>['Ödemeniz alındı','Faturanızın ödemesi başarıyla doğrulandı.'],
            'overdue'=>['Faturanızın vadesi geçti','Faturanızın son ödeme tarihi geçti. Hizmetinizin etkilenmemesi için lütfen müşteri panelinden ödeme durumunu kontrol edin.'],
        ];
        if(!isset($labels[$event])||$invoiceId<1)return false;
        [$subject,$message]=$labels[$event];
        $body="Merhaba ".($customerName!==''?$customerName:'Müşterimiz').",\n\n".$message."\n\nFatura: ".$invoiceNumber."\nTutar: ".number_format($amount,2,',','.')." ".$currency;
        if($dueAt!=='')$body.="\nSon ödeme tarihi: ".$dueAt;
        $body.="\n\nFaturalarınızı HostPilot müşteri panelinizden görüntüleyebilirsiniz.";
        return $this->queue($recipient,$subject,$body,'invoice:'.$event.':'.$invoiceId,'invoice_'.$event);
    }

    public function queueServiceNotice(string $recipient,string $customerName,string $domain,string $event,int $serviceId):bool
    {
        $labels=[
            'provisioned'=>['Hosting hesabınız hazır','Hosting hesabınız başarıyla oluşturuldu ve kullanıma hazır.'],
            'suspended'=>['Hosting hizmetiniz askıya alındı','Vadesi geçmiş ödenmemiş fatura nedeniyle hosting hizmetiniz askıya alındı. Ödeme sonrası yeniden etkinleştirme otomasyonu çalışacaktır.'],
            'reactivated'=>['Hosting hizmetiniz yeniden etkinleştirildi','Ödeme doğrulandı ve hosting hizmetiniz yeniden etkinleştirildi.'],
        ];
        if(!isset($labels[$event])||$serviceId<1)return false;
        [$subject,$message]=$labels[$event];
        $body="Merhaba ".($customerName!==''?$customerName:'Müşterimiz').",\n\n".$message."\n\nDomain: ".$domain."\n\nHizmet durumunuzu müşteri panelinizden takip edebilirsiniz.";
        return $this->queue($recipient,$subject,$body,'service:'.$event.':'.$serviceId,'service_'.$event);
    }

    public function queue(string $recipient,string $subject,string $body,string $idempotencyKey,string $eventType='general'):bool
    {
        if(!filter_var($recipient,FILTER_VALIDATE_EMAIL)||$subject===''||strlen($subject)>255||preg_match('/[\\r\\n]/',$subject))return false;
        $now=gmdate('Y-m-d H:i:s');
        $q=$this->db->prepare("INSERT IGNORE INTO email_outbox(idempotency_key,event_type,recipient,subject,body,status,attempts,available_at,created_at,updated_at) VALUES(:key,:event,:recipient,:subject,:body,'pending',0,:available,:created,:updated)");
        $q->execute([':key'=>hash('sha256',$idempotencyKey),':event'=>substr($eventType,0,64),':recipient'=>$recipient,':subject'=>$subject,':body'=>$body,':available'=>$now,':created'=>$now,':updated'=>$now]);
        return $q->rowCount()===1;
    }

    /** Process at most $limit messages; stale in-flight messages are held for review to avoid blind duplicate delivery. */
    public function processQueue(int $limit=10):array
    {
        $limit=max(1,min(25,$limit));$stats=['sent'=>0,'retry'=>0,'failed'=>0,'needs_review'=>0];
        $cutoff=gmdate('Y-m-d H:i:s',time()-900);
        $stale=$this->db->prepare("UPDATE email_outbox SET status='needs_review',locked_at=NULL,last_error='Gönderim sırasında worker zaman aşımına uğradı; olası çift gönderimi önlemek için manuel kontrol gerekli.',updated_at=:now WHERE status='sending' AND locked_at<:cutoff");
        $stale->execute([':now'=>gmdate('Y-m-d H:i:s'),':cutoff'=>$cutoff]);
        for($i=0;$i<$limit;$i++){
            $this->db->beginTransaction();
            try{
                $pick=$this->db->prepare("SELECT * FROM email_outbox WHERE status='pending' AND available_at<=:now ORDER BY id LIMIT 1 FOR UPDATE");
                $pick->execute([':now'=>gmdate('Y-m-d H:i:s')]);$row=$pick->fetch(PDO::FETCH_ASSOC);
                if(!$row){$this->db->commit();break;}
                $this->db->prepare("UPDATE email_outbox SET status='sending',locked_at=:now,updated_at=:now WHERE id=:id AND status='pending'")
                    ->execute([':now'=>gmdate('Y-m-d H:i:s'),':id'=>$row['id']]);
                $this->db->commit();
            }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
            $sent=$this->send((string)$row['recipient'],(string)$row['subject'],(string)$row['body']);
            $attempts=(int)$row['attempts']+1;$now=gmdate('Y-m-d H:i:s');
            if($sent){
                $this->db->prepare("UPDATE email_outbox SET status='sent',attempts=:attempts,locked_at=NULL,last_error=NULL,sent_at=:sent,updated_at=:updated WHERE id=:id AND status='sending'")
                    ->execute([':attempts'=>$attempts,':sent'=>$now,':updated'=>$now,':id'=>$row['id']]);$stats['sent']++;
            }else{
                $failed=$attempts>=8;$delay=min(3600,60*(2**min(6,$attempts-1)));
                $next=gmdate('Y-m-d H:i:s',time()+$delay);
                $this->db->prepare("UPDATE email_outbox SET status=:status,attempts=:attempts,available_at=:available,locked_at=NULL,last_error=:error,updated_at=:updated WHERE id=:id AND status='sending'")
                    ->execute([':status'=>$failed?'failed':'pending',':attempts'=>$attempts,':available'=>$next,':error'=>'SMTP gönderimi başarısız. Sunucu hata kaydını kontrol edin.',':updated'=>$now,':id'=>$row['id']]);
                $stats[$failed?'failed':'retry']++;
            }
        }
        return $stats;
    }

    public function send(string $recipient,string $subject,string $body):bool
    {
        try{
            $q=$this->db->prepare("SELECT setting_key,setting_value FROM settings WHERE setting_group='email'");
            $q->execute();$cfg=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$cfg[$row['setting_key']]=$row['setting_value'];
            if(($cfg['enabled']??'0')!=='1')return false;
            $host=trim((string)($cfg['host']??''));$port=(int)($cfg['port']??587);
            $from=trim((string)($cfg['from_email']??''));$fromName=trim((string)($cfg['from_name']??'HostPilot'));
            $encryption=(string)($cfg['encryption']??'tls');$username=trim((string)($cfg['username']??''));
            if($host===''||$port<1||$port>65535||!filter_var($from,FILTER_VALIDATE_EMAIL)||!filter_var($recipient,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$subject)||!in_array($encryption,['tls','ssl','none'],true))return false;
            $password='';if(!empty($cfg['password_enc']))$password=$this->vault->decrypt((string)$cfg['password_enc']);
            $target=($encryption==='ssl'?'ssl://':'').$host.':'.$port;
            $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]]);
            $socket=@stream_socket_client($target,$errno,$errstr,10,STREAM_CLIENT_CONNECT,$context);
            if(!$socket)throw new RuntimeException('SMTP connection failed: '.$errstr);
            stream_set_timeout($socket,10);
            $this->expect($socket,[220]);
            $helo=gethostname()?:'localhost';
            $this->command($socket,'EHLO '.$helo,[250]);
            if($encryption==='tls'){
                $this->command($socket,'STARTTLS',[220]);
                if(!stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new RuntimeException('SMTP TLS negotiation failed.');
                $this->command($socket,'EHLO '.$helo,[250]);
            }
            if($username!==''){
                $this->command($socket,'AUTH LOGIN',[334]);
                $this->command($socket,base64_encode($username),[334]);
                $this->command($socket,base64_encode($password),[235]);
            }
            $this->command($socket,'MAIL FROM:<'.$from.'>',[250]);
            $this->command($socket,'RCPT TO:<'.$recipient.'>',[250,251]);
            $this->command($socket,'DATA',[354]);
            $encodedSubject='=?UTF-8?B?'.base64_encode($subject).'?=';
            $safeName='=?UTF-8?B?'.base64_encode($fromName).'?=';
            $headers="From: ".$safeName." <".$from.">\r\nTo: <".$recipient.">\r\nSubject: ".$encodedSubject."\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
            $message=$headers."\r\n".str_replace(["\r\n","\r"],"\n",$body);
            $message=str_replace("\n","\r\n",$message);
            $message=preg_replace('/(^|\r\n)\./','$1..',$message)??$message;
            fwrite($socket,$message."\r\n.\r\n");$this->expect($socket,[250]);
            $this->command($socket,'QUIT',[221]);fclose($socket);return true;
        }catch(Throwable $e){if(isset($socket)&&is_resource($socket))fclose($socket);error_log('[HostPilot] Email delivery failed: '.$e->getMessage());return false;}
    }

    private function command($socket,string $command,array $codes):string
    {
        fwrite($socket,$command."\r\n");return $this->expect($socket,$codes);
    }
    private function expect($socket,array $codes):string
    {
        $response='';do{$line=fgets($socket,515);if($line===false)throw new RuntimeException('SMTP server closed the connection.');$response.=$line;}while(isset($line[3])&&$line[3]==='-');
        $code=(int)substr($response,0,3);if(!in_array($code,$codes,true))throw new RuntimeException('SMTP rejected command: '.trim($response));
        return $response;
    }
}
