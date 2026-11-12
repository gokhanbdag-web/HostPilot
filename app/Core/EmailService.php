<?php
declare(strict_types=1);
namespace HostPilot\\Core;

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
        return $this->send($recipient,$subjects[$event],$body);
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
