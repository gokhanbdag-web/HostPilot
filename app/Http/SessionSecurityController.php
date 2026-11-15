<?php
declare(strict_types=1);
namespace HostPilot\Http;
use HostPilot\Core\Auth;
use PDO;
final class SessionSecurityController
{
    public function __construct(private readonly PDO $db, private readonly string $basePath = '') {}
    public function index(): void
    {
        $user = Auth::user();
        if ($user === null || !in_array((string)$user['role'], ['super_admin','admin'], true)) {
            http_response_code(403);
            echo 'Bu güvenlik ayarını yalnızca yöneticiler kullanabilir.';
            return;
        }
        $message = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Auth::verifyCsrf($_POST['_token'] ?? null)) {
                http_response_code(400);
                $message = 'Güvenlik doğrulaması başarısız. Sayfayı yenileyin.';
            } elseif (($_POST['action'] ?? '') !== 'revoke_others') {
                http_response_code(400);
                $message = 'Geçersiz işlem.';
            } elseif (Auth::revokeOtherSessions($this->db)) {
                $message = 'Diğer tüm yönetici ve müşteri oturumları kapatıldı. Bu oturum açık bırakıldı.';
            } else {
                http_response_code(500);
                $message = 'Oturumlar kapatılamadı. Lütfen tekrar deneyin.';
            }
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        echo '<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Oturum güvenliği · HostPilot</title><style>body{margin:0;background:#0b1220;color:#e8eef8;font:16px system-ui;display:grid;place-items:center;min-height:100vh}.card{width:min(92vw,620px);box-sizing:border-box;padding:30px;background:#111d31;border:1px solid #263750;border-radius:18px}p{color:#afbdd2;line-height:1.65}.notice{padding:12px;background:#1b304a;border-radius:9px;margin:16px 0}button{padding:13px 18px;border:0;border-radius:9px;background:#4b8cff;color:white;font-weight:700;cursor:pointer}a{color:#83b4ff}</style><main class="card"><small style="color:#83b4ff;letter-spacing:.15em">HOSTPILOT CONTROL CENTER</small><h1>Oturum güvenliği</h1><p>Şifrenizin başka bir cihazda açık kalmış olabileceğini düşünüyorsanız diğer tüm oturumları kapatabilirsiniz. Bu işlem mevcut oturumunuzu açık tutar; diğer cihazlar bir sonraki istekte yeniden girişe yönlendirilir.</p>';
        if ($message !== '') echo '<div class="notice">' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
        echo '<form method="post"><input type="hidden" name="_token" value="' . htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') . '"><input type="hidden" name="action" value="revoke_others"><button type="submit">Diğer tüm oturumları kapat</button></form><p><a href="' . htmlspecialchars($this->basePath . '/admin', ENT_QUOTES, 'UTF-8') . '">Yönetim paneline dön</a></p></main></html>';
    }
}
