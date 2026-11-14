<?php
declare(strict_types=1);
namespace HostPilot\Http;
use HostPilot\Core\Auth;
use HostPilot\Core\EmailService;
use PDO;
use Throwable;
final class PasswordResetController
{
 public function __construct(private readonly PDO $db,private readonly string $basePath='',private readonly string $baseUrl='',private readonly string $applicationKey=''){}
 public function request():void
 {
  $msg='';
  if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
   if(!Auth::verifyCsrf($_POST['_token']??null))$msg='Oturum doğrulaması başarısız. Sayfayı yenileyin.';
   else{
    $email=strtolower(trim((string)($_POST['email']??'')));
    if(filter_var($email,FILTER_VALIDATE_EMAIL)){
     try{
      $q=$this->db->prepare("SELECT id,name,email FROM users WHERE email=:email AND status='active' AND role IN ('super_admin','admin') LIMIT 1");$q->execute([':email'=>$email]);$u=$q->fetch(PDO::FETCH_ASSOC);
      if($u&&$this->applicationKey!==''&&$this->baseUrl!==''){
       $cooldown=$this->db->prepare('SELECT id FROM password_reset_tokens WHERE user_id=:id AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 60 SECOND) LIMIT 1');$cooldown->execute([':id'=>(int)$u['id']]);
       if(!$cooldown->fetchColumn()){
       $this->db->prepare('UPDATE password_reset_tokens SET used_at=UTC_TIMESTAMP() WHERE user_id=:id AND used_at IS NULL')->execute([':id'=>(int)$u['id']]);
       $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$now=gmdate('Y-m-d H:i:s');
       $this->db->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at,created_at) VALUES(:id,:hash,:expires,:created)')
        ->execute([':id'=>(int)$u['id'],':hash'=>$hash,':expires'=>gmdate('Y-m-d H:i:s',time()+1800),':created'=>$now]);
       $link=rtrim($this->baseUrl,'/').$this->basePath.'/admin/reset-password?token='.rawurlencode($token);
       $body="Merhaba ".$u['name'].",\n\nHostPilot yönetici parolanızı sıfırlamak için 30 dakika içinde bu tek kullanımlık bağlantıyı açın:\n\n".$link."\n\nBu isteği siz yapmadıysanız e-postayı dikkate almayın.";
       (new EmailService($this->db,$this->applicationKey))->queue((string)$u['email'],'HostPilot yönetici parola sıfırlama',$body,'admin-password-reset:'.$hash,'admin_password_reset');
       }
      }
     }catch(Throwable $e){error_log('[HostPilot] Password reset request failed: '.$e->getMessage());}
    }
    $msg='Adres kayıtlı ve etkin bir yönetici hesabına aitse sıfırlama bağlantısı e-posta kuyruğuna eklendi. E-posta teslimini kontrol edin.';
   }
  }
  $this->page('Yönetici parolasını sıfırla','<p>Yönetici hesabınıza bağlı e-posta adresini girin. Güvenlik nedeniyle adresin kayıtlı olup olmadığını açıklamıyoruz.</p>'.($msg!==''?'<div class="notice">'.$this->e($msg).'</div>':'').'<form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>E-posta adresi<input type="email" name="email" autocomplete="email" required maxlength="254"></label><button>Bağlantı gönder</button></form><p><a href="'.$this->url('/admin/login').'">Yönetici girişine dön</a></p>');
 }
 public function reset():void
 {
  $token=trim((string)($_GET['token']??$_POST['token']??''));$valid=preg_match('/^[a-f0-9]{64}$/',$token)===1;$error='';$success=false;
  if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
   $pass=(string)($_POST['password']??'');$confirm=(string)($_POST['password_confirm']??'');
   if(!Auth::verifyCsrf($_POST['_token']??null))$error='Oturum doğrulaması başarısız.';
   elseif(!$valid)$error='Bağlantı geçersiz veya süresi dolmuş.';
   elseif(strlen($pass)<12||strlen($pass)>4096)$error='Parola en az 12 karakter olmalıdır.';
   elseif(!hash_equals($pass,$confirm))$error='Parola doğrulaması eşleşmiyor.';
   else{
    try{
     $this->db->beginTransaction();
     $q=$this->db->prepare("SELECT t.id,t.user_id FROM password_reset_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=:hash AND t.used_at IS NULL AND t.expires_at>UTC_TIMESTAMP() AND u.status='active' AND u.role IN ('super_admin','admin') LIMIT 1 FOR UPDATE");
     $q->execute([':hash'=>hash('sha256',$token)]);$row=$q->fetch(PDO::FETCH_ASSOC);
     if(!$row){$this->db->rollBack();$error='Bağlantı geçersiz veya süresi dolmuş.';}
     else{
      $this->db->prepare('UPDATE users SET password_hash=:hash WHERE id=:id')->execute([':hash'=>password_hash($pass,PASSWORD_DEFAULT),':id'=>(int)$row['user_id']]);
      $this->db->prepare('UPDATE password_reset_tokens SET used_at=UTC_TIMESTAMP() WHERE user_id=:id AND used_at IS NULL')->execute([':id'=>(int)$row['user_id']]);
      $this->db->commit();$success=true;
     }
    }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();error_log('[HostPilot] Password reset failed: '.$e->getMessage());$error='İşlem tamamlanamadı. Daha sonra tekrar deneyin.';}
   }
  }elseif(!$valid)$error='Bağlantı geçersiz veya süresi dolmuş.';
  else{$q=$this->db->prepare('SELECT id FROM password_reset_tokens WHERE token_hash=:hash AND used_at IS NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1');$q->execute([':hash'=>hash('sha256',$token)]);if(!$q->fetchColumn())$error='Bağlantı geçersiz veya süresi dolmuş.';}
  if($success){$this->page('Parola güncellendi','<div class="notice">Yönetici parolanız güncellendi.</div><p><a href="'.$this->url('/admin/login').'">Giriş yap</a></p>');return;}
  $content=$error!==''?'<div class="notice">'.$this->e($error).'</div>':'';
  if($valid&&$error==='')$content.='<form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="token" value="'.$this->e($token).'"><label>Yeni parola<input type="password" name="password" minlength="12" autocomplete="new-password" required></label><label>Yeni parola tekrar<input type="password" name="password_confirm" minlength="12" autocomplete="new-password" required></label><button>Parolayı güncelle</button></form>';
  $this->page('Yeni yönetici parolası',$content.'<p><a href="'.$this->url('/admin/login').'">Giriş sayfası</a></p>');
 }
 private function page(string $title,string $content):void
 {
  header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store, max-age=0');
  echo '<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->e($title).' · HostPilot</title><style>body{margin:0;background:#0b1220;color:#e8eef8;font:16px system-ui;display:grid;place-items:center;min-height:100vh}.card{width:min(92vw,460px);background:#111d31;border:1px solid #263750;border-radius:18px;padding:30px;box-sizing:border-box}h1{font-size:25px}p{color:#aebbd0;line-height:1.6}label{display:block;margin:16px 0}input{display:block;box-sizing:border-box;width:100%;margin-top:7px;padding:13px;border-radius:9px;border:1px solid #344761;background:#091426;color:white}button{width:100%;padding:13px;border:0;border-radius:9px;background:#4b8cff;color:white;font-weight:700}.notice{padding:12px;border-radius:9px;background:#1b304a;margin:16px 0}a{color:#83b4ff}</style><main class="card"><div style="color:#83b4ff;font-weight:800;letter-spacing:.15em">HOSTPILOT CONTROL CENTER</div><h1>'.$this->e($title).'</h1>'.$content.'</main></html>';
 }
 private function url(string $path):string{return $this->basePath.$path;}
 private function e(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
