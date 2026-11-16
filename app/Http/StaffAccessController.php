<?php
declare(strict_types=1);

namespace HostPilot\Http;

use HostPilot\Core\Auth;
use HostPilot\Core\Authorization;
use HostPilot\Core\SecurityAudit;
use PDO;
use Throwable;

final class StaffAccessController
{
    private const DEPARTMENTS = ['General Support', 'Billing', 'Technical Support', 'Sales'];

    public function __construct(private readonly PDO $db, private readonly string $basePath = '') {}

    public function index(): void
    {
        $actor = Auth::user();
        if (!$actor || !in_array($actor['role'] ?? '', ['admin', 'super_admin'], true)) {
            http_response_code(403);
            echo 'Bu sayfaya yalnızca yöneticiler erişebilir.';
            return;
        }

        $staff = $this->db->query("SELECT id,name,email,status FROM users WHERE role='staff' ORDER BY name,id")->fetchAll(PDO::FETCH_ASSOC);
        $permissionRows = $this->db->query('SELECT user_id,permission FROM staff_permissions')->fetchAll(PDO::FETCH_ASSOC);
        $departmentRows = $this->db->query('SELECT user_id,department FROM staff_departments')->fetchAll(PDO::FETCH_ASSOC);
        $permissions = $departments = [];
        foreach ($permissionRows as $row) $permissions[(int)$row['user_id']][] = (string)$row['permission'];
        foreach ($departmentRows as $row) $departments[(int)$row['user_id']][] = (string)$row['department'];

        $this->start('Personel yetkileri');
        echo '<main class="wrap"><header><a href="' . $this->url('/admin') . '">← Yönetim paneli</a><h1>Personel yetkileri</h1><p>Her personelin erişebileceği modülleri ve destek departmanlarını belirleyin.</p></header>';
        if (!$staff) echo '<section class="card"><p>Henüz personel hesabı bulunmuyor. Personel hesapları eklendiğinde burada listelenir.</p></section>';
        foreach ($staff as $member) {
            $id = (int)$member['id'];
            echo '<section class="card"><h2>' . $this->e((string)$member['name']) . '</h2><p>' . $this->e((string)$member['email']) . ' · ' . $this->e((string)$member['status']) . '</p><form method="post" action="' . $this->url('/admin/staff/save') . '"><input type="hidden" name="_token" value="' . $this->e(Auth::csrfToken()) . '"><input type="hidden" name="user_id" value="' . $id . '"><h3>Modül izinleri</h3><div class="checks">';
            foreach (Authorization::staffPermissionGroups() as $group => $groupPermissions) {
                echo '<h4>' . $this->e($group) . '</h4>';
                foreach ($groupPermissions as $key => $label) {
                    $checked = in_array($key, $permissions[$id] ?? [], true) ? ' checked' : '';
                    echo '<label><input type="checkbox" name="permissions[]" value="' . $this->e($key) . '"' . $checked . '> ' . $this->e($label) . '</label>';
                }
            }
            echo '</div><h3>Destek departmanları</h3><div class="checks">';
            foreach (self::DEPARTMENTS as $department) {
                $checked = in_array($department, $departments[$id] ?? [], true) ? ' checked' : '';
                echo '<label><input type="checkbox" name="departments[]" value="' . $this->e($department) . '"' . $checked . '> ' . $this->e($department) . '</label>';
            }
            echo '</div><button type="submit">Yetkileri kaydet</button></form></section>';
        }
        echo '</main>';
        $this->end();
    }

    public function save(): void
    {
        $actor = Auth::user();
        if (!$actor || !in_array($actor['role'] ?? '', ['admin', 'super_admin'], true)) {
            http_response_code(403);
            echo 'Bu işlem için yönetici yetkisi gerekir.';
            return;
        }
        if (!Auth::verifyCsrf($_POST['_token'] ?? null)) {
            http_response_code(400);
            echo 'Güvenlik doğrulaması başarısız.';
            return;
        }

        $userId = (int)($_POST['user_id'] ?? 0);
        $user = $this->db->prepare("SELECT id FROM users WHERE id=:id AND role='staff' LIMIT 1");
        $user->execute([':id' => $userId]);
        if (!$user->fetchColumn()) {
            http_response_code(404);
            echo 'Personel hesabı bulunamadı.';
            return;
        }

        $permissions = array_values(array_unique(array_intersect(
            array_keys(Authorization::staffPermissionCatalog()),
            array_map('strval', (array)($_POST['permissions'] ?? []))
        )));
        $departments = array_values(array_unique(array_intersect(
            self::DEPARTMENTS,
            array_map('strval', (array)($_POST['departments'] ?? []))
        )));

        try {
            $this->db->beginTransaction();
            $this->db->prepare('DELETE FROM staff_permissions WHERE user_id=:id')->execute([':id' => $userId]);
            $insertPermission = $this->db->prepare('INSERT INTO staff_permissions(user_id,permission,created_at) VALUES(:id,:permission,:created)');
            foreach ($permissions as $permission) $insertPermission->execute([':id' => $userId, ':permission' => $permission, ':created' => gmdate('Y-m-d H:i:s')]);

            $this->db->prepare('DELETE FROM staff_departments WHERE user_id=:id')->execute([':id' => $userId]);
            $insertDepartment = $this->db->prepare('INSERT INTO staff_departments(user_id,department,created_at) VALUES(:id,:department,:created)');
            foreach ($departments as $department) $insertDepartment->execute([':id' => $userId, ':department' => $department, ':created' => gmdate('Y-m-d H:i:s')]);
            $this->db->commit();
            SecurityAudit::record($this->db,'staff.permissions_updated',(int)$actor['id'],['target_user_id'=>$userId,'action'=>'updated']);
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[HostPilot] Staff permission save failed: ' . $exception->getMessage());
            http_response_code(500);
            echo 'Personel yetkileri kaydedilemedi.';
            return;
        }

        header('Location: ' . $this->url('/admin/staff'));
        exit;
    }

    private function start(string $title): void
    {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $this->e($title) . ' · HostPilot</title><style>body{margin:0;background:#0a1020;color:#e8edf7;font:15px system-ui}.wrap{max-width:980px;margin:0 auto;padding:32px 20px}a{color:#8ab4ff}.card{background:#111d31;border:1px solid #293850;border-radius:14px;padding:22px;margin:20px 0}h1{margin-bottom:6px}.checks{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px;margin:14px 0 24px}.checks label{display:flex;gap:8px;align-items:center}button{background:#3478f6;color:#fff;border:0;border-radius:8px;padding:12px 18px;font-weight:600;cursor:pointer}input{accent-color:#3478f6}</style><body>';
    }

    private function end(): void { echo '</body></html>'; }
    private function url(string $path): string { return $this->basePath . $path; }
    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
