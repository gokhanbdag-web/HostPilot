<?php
declare(strict_types=1);
namespace HostPilot\Hosting;

use RuntimeException;

final class PleskXmlAdapter implements HostingAdapter
{
    public function __construct(private readonly array $server){}
    private function xml(string $body):string
    {
        $headers=['Content-Type: text/xml','HTTP_PRETTY_PRINT: TRUE'];
        if(($this->server['auth_mode']??'password')==='key')$headers[]='KEY: '.$this->server['secret'];
        else{$headers[]='HTTP_AUTH_LOGIN: '.$this->server['username'];$headers[]='HTTP_AUTH_PASSWD: '.$this->server['secret'];}
        $r=HostingTransport::request($this->server['base_url'],'/enterprise/control/agent.php','POST',$headers,$body);
        if(!preg_match('/<status>\s*ok\s*<\/status>/i',$r['body'])){
            preg_match('/<errtext>(.*?)<\/errtext>/si',$r['body'],$m);
            throw new RuntimeException('Plesk API hatası: '.html_entity_decode(strip_tags($m[1]??'İşlem onaylanmadı.'),ENT_QUOTES|ENT_XML1,'UTF-8'));
        }
        return $r['body'];
    }
    public function testConnection():string
    {
        $response=$this->xml('<?xml version="1.0" encoding="UTF-8"?><packet><server><get><gen_info/></get></server></packet>');
        return 'Plesk XML API bağlantısı başarılı.';
    }
    public function createAccount(array $a):array
    {
        $ip=(string)($this->server['shared_ip']??'');
        if(!filter_var($ip,FILTER_VALIDATE_IP))throw new RuntimeException('Plesk sunucusu için geçerli paylaşımlı IP adresi tanımlanmalıdır.');
        $esc=static fn(string $v):string=>htmlspecialchars($v,ENT_XML1|ENT_QUOTES,'UTF-8');
        $domain=$esc((string)$a['domain']);$ftp=$esc((string)$a['username']);$pass=$esc((string)$a['password']);$package=$esc((string)$a['package']);$ipXml=$esc($ip);
        $body='<?xml version="1.0" encoding="UTF-8"?><packet><webspace><add><gen_setup><name>'.$domain.'</name><htype>vrt_hst</htype><ip_address>'.$ipXml.'</ip_address><status>0</status></gen_setup><hosting><vrt_hst><property><name>ftp_login</name><value>'.$ftp.'</value></property><property><name>ftp_password</name><value>'.$pass.'</value></property><ip_address>'.$ipXml.'</ip_address></vrt_hst></hosting><plan-name>'.$package.'</plan-name></add></webspace></packet>';
        $response=$this->xml($body);preg_match('/<webspace>.*?<id>(\d+)<\/id>/si',$response,$m);
        $id=(string)($m[1]??$a['domain']);
        return ['identifier'=>$id,'username'=>(string)$a['username'],'domain'=>(string)$a['domain']];
    }
}
