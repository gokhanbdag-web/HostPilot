<?php
declare(strict_types=1);

namespace HostPilot\Http;

use HostPilot\Core\Auth;
use HostPilot\Core\Mfa;
use HostPilot\Core\SecurityAudit;
use PDO;
use Throwable;

final class MfaController
{
    public function __construct(private readonly PDO $db, private readonly string $basePath = '', private readonly string $applicationKey = '') {}

    public function index(): void
    {
        $user = Auth::user();
        if ($user === null || !in_array((string)$user['role'], ['super_admin', 'admin', 'staff'], true)) {
            http_response_code(403);
            echo 'Bu güvenlik ayarını yalnızca giriş yapmış personel kullanabilir.';
            return;
        }
        $userId = (int)$user['id'];
        $message = '';
        $error = '';
        $codesToShow = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Auth::verifyCsrf($_POST['_token'] ?? null)) {
                http_response_code(400);
                $error = 'Güvenlik doğrulaması başarısız. Sayfayı yenileyin.';
            } else {
                try {
                    $action = (string)($_POST['action'] ?? '');
                    if ($action === 'start') {
                        if (Mfa::isEnabled($this->db, $userId)) throw new \DomainException('İki aşamalı doğrulama zaten etkin.');
                        $this->assertCurrentPassword($userId, (string)($_POST['password'] ?? ''));
                        $_SESSION['hostpilot_mfa_pending_secret'] = Mfa::generateSecret();
                        $message = 'Kurulum anahtarı oluşturuldu. Authenticator uygulamanıza ekleyip doğrulama kodunu girin.';
                    } elseif ($action === 'enable') {
                        $secret = (string)($_SESSION['hostpilot_mfa_pending_secret'] ?? '');
                        if ($secret === '') throw new \DomainException('Kurulum oturumu sona ermiş. Yeniden başlatın.');
                        $this->assertCurrentPassword($userId, (string)($_POST['password'] ?? ''));
                        $step = Mfa::matchingStep($secret, trim((string)($_POST['code'] ?? '')));
                        if ($step === null) throw new \DomainException('Doğrulama kodu geçersiz veya süresi dolmuş.');
                        $codes = Mfa::generateRecoveryCodes();
                        Mfa::enable($this->db, $userId, $secret, $step, $codes, $this->applicationKey);
                        unset($_SESSION['hostpilot_mfa_pending_secret']);
                        $codesToShow = $codes;
                        SecurityAudit::record($this->db, 'auth.mfa_enabled', $userId);
                        $message = 'İki aşamalı doğrulama etkinleştirildi. Kurtarma kodlarını güvenli bir yerde saklayın.';
                    } elseif ($action === 'rotate' || $action === 'disable') {
                        if (!Mfa::isEnabled($this->db, $userId)) throw new \DomainException('İki aşamalı doğrulama etkin değil.');
                        $this->assertCurrentPassword($userId, (string)($_POST['password'] ?? ''));
                        $method = Mfa::verifyCode($this->db, $userId, trim((string)($_POST['code'] ?? '')), $this->applicationKey);
                        if ($method === null) throw new \DomainException('Doğrulama veya kurtarma kodu geçersiz ya da daha önce kullanılmış.');
                        if ($method === 'recovery') SecurityAudit::record($this->db, 'auth.mfa_recovery_used', $userId);
                        if ($action === 'rotate') {
                            $codes = Mfa::generateRecoveryCodes();
                            Mfa::rotateRecoveryCodes($this->db, $userId, $codes);
                            $codesToShow = $codes;
                            SecurityAudit::record($this->db, 'auth.mfa_recovery_rotated', $userId);
                            $message = 'Kurtarma kodları yenilendi. Eski kodların tamamı geçersiz kılındı.';
                        } else {
                            Mfa::disable($this->db, $userId);
                            Auth::revokeOtherSessions($this->db);
                            SecurityAudit::record($this->db, 'auth.mfa_disabled', $userId);
                            $message = 'İki aşamalı doğrulama kapatıldı. Diğer oturumlar güvenlik amacıyla sonlandırıldı.';
                        }
                    } else {
                        throw new \DomainException('Geçersiz işlem.');
                    }
                } catch (Throwable $exception) {
                    $error = $exception instanceof \DomainException ? $exception->getMessage() : 'İşlem tamamlanamadı. Lütfen tekrar deneyin.';
                    if (!($exception instanceof \DomainException)) error_log('[HostPilot] MFA settings failed: ' . $exception->getMessage());
                }
            }
        }

        $enabled = Mfa::isEnabled($this->db, $userId);
        $pendingSecret = (string)($_SESSION['hostpilot_mfa_pending_secret'] ?? '');
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('Referrer-Policy: no-referrer');
        $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>İki aşamalı doğrulama · HostPilot</title><style>body{margin:0;background:#0b1220;color:#e8eef8;font:16px system-ui;display:grid;place-items:center;min-height:100vh}.card{width:min(94vw,680px);box-sizing:border-box;padding:30px;background:#111d31;border:1px solid #263750;border-radius:18px}p{color:#afbdd2;line-height:1.65}label{display:block;margin:14px 0}input{display:block;box-sizing:border-box;width:100%;margin-top:7px;padding:12px;border-radius:9px;border:1px solid #344761;background:#091426;color:white}button{padding:12px 18px;border:0;border-radius:9px;background:#4b8cff;color:white;font-weight:700;cursor:pointer}.notice,.error{padding:12px;border-radius:9px;margin:14px 0;background:#1b304a}.error{background:#49252d}.secret{font:700 19px ui-monospace,monospace;overflow-wrap:anywhere;background:#091426;padding:14px;border-radius:9px}.codes{white-space:pre-wrap;columns:2;font:700 16px ui-monospace,monospace;background:#091426;padding:16px;border-radius:9px}a{color:#83b4ff}</style><main class="card"><small style="color:#83b4ff;letter-spacing:.15em">HOSTPILOT CONTROL CENTER</small><h1>İki aşamalı doğrulama</h1><p>Authenticator uygulamasıyla giriş güvenliğini artırın. Kurtarma kodları yalnızca oluşturulduklarında gösterilir ve her biri bir kez kullanılabilir.</p>';
        if ($message !== '') echo '<div class="notice">' . $e($message) . '</div>';
        if ($error !== '') echo '<div class="error">' . $e($error) . '</div>';
        if (is_array($codesToShow) && count($codesToShow) === 10) {
            echo '<h2>Kurtarma kodlarınız</h2><p>Bu kodları şimdi güvenli bir yere kaydedin. Sayfadan ayrıldıktan sonra tekrar görüntülenemez.</p><pre class="codes">' . $e(implode("\n", $codesToShow)) . '</pre>';
        }
        if ($enabled) {
            echo '<h2>Durum: Etkin</h2><p>Kullanılabilir kurtarma kodu: <strong>' . Mfa::remainingRecoveryCodes($this->db, $userId) . '</strong></p><form method="post"><input type="hidden" name="_token" value="' . $e(Auth::csrfToken()) . '"><input type="hidden" name="action" value="rotate"><label>Mevcut parolanız<input type="password" name="password" autocomplete="current-password" required></label><label>Authenticator veya kurtarma kodu<input name="code" autocomplete="one-time-code" required maxlength="32"></label><button>Kurtarma kodlarını yenile</button></form><hr><form method="post" onsubmit="return confirm(\'İki aşamalı doğrulamayı kapatmak istediğinize emin misiniz?\')"><input type="hidden" name="_token" value="' . $e(Auth::csrfToken()) . '"><input type="hidden" name="action" value="disable"><label>Mevcut parolanız<input type="password" name="password" autocomplete="current-password" required></label><label>Authenticator veya kurtarma kodu<input name="code" autocomplete="one-time-code" required maxlength="32"></label><button>İki aşamalı doğrulamayı kapat</button></form>';
        } elseif ($pendingSecret !== '') {
            $label = rawurlencode('HostPilot:' . (string)$user['email']);
            $issuer = rawurlencode('HostPilot');
            echo '<h2>Authenticator uygulamasına ekleyin</h2><p>Google Authenticator, Microsoft Authenticator, 1Password veya uyumlu bir TOTP uygulamasında anahtarı manuel ekleyin.</p><div class="secret">' . $e($pendingSecret) . '</div><p>Hesap: ' . $e((string)$user['email']) . '</p><p>Kurulum URI: <code>' . $e('otpauth://totp/' . $label . '?secret=' . rawurlencode($pendingSecret) . '&issuer=' . $issuer . '&algorithm=SHA1&digits=6&period=30') . '</code></p><form method="post"><input type="hidden" name="_token" value="' . $e(Auth::csrfToken()) . '"><input type="hidden" name="action" value="enable"><label>Mevcut parolanız<input type="password" name="password" autocomplete="current-password" required></label><label>Uygulamadaki 6 haneli kod<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label><button>Doğrula ve etkinleştir</button></form>';
        } else {
            echo '<h2>Durum: Kapalı</h2><p>Etkinleştirme sırasında 10 adet tek kullanımlık kurtarma kodu oluşturulur.</p><form method="post"><input type="hidden" name="_token" value="' . $e(Auth::csrfToken()) . '"><input type="hidden" name="action" value="start"><label>Mevcut parolanız<input type="password" name="password" autocomplete="current-password" required></label><button>Kurulumu başlat</button></form>';
        }
        echo '<p><a href="' . $e($this->basePath . '/admin') . '">Yönetim paneline dön</a></p></main></html>';
    }

    private function assertCurrentPassword(int $userId, string $password): void
    {
        if ($password === '' || strlen($password) > 4096) throw new \DomainException('Mevcut parola doğrulanamadı.');
        if (Auth::consumeMfaAttempt()) throw new \DomainException('Çok fazla güvenlik doğrulaması denendi. Bir süre sonra tekrar deneyin.');
        $query = $this->db->prepare("SELECT password_hash FROM users WHERE id = :id AND status = 'active' AND role IN ('super_admin','admin','staff') LIMIT 1");
        $query->execute([':id' => $userId]);
        $hash = $query->fetchColumn();
        if (!is_string($hash) || !password_verify($password, $hash)) throw new \DomainException('Mevcut parola hatalı.');
    }
}
