<?php
declare(strict_types=1);

namespace HostPilot\Core;

final class Authorization
{
    public static function staffPermissionGroups(): array
    {
        return [
            'Genel' => [
                'dashboard.view' => 'Kontrol panelini görüntüleme',
            ],
            'Müşteriler ve raporlar' => [
                'customers.view' => 'Müşterileri görüntüleme',
                'reports.view' => 'Raporları görüntüleme',
            ],
            'Destek' => [
                'support.view' => 'Destek taleplerini ve eklerini görüntüleme',
                'support.reply' => 'Destek taleplerine yanıt verme',
            ],
            'Mağaza ve finans' => [
                'catalog.manage' => 'Ürün ve paketleri yönetme',
                'orders.view' => 'Siparişleri görüntüleme',
                'invoices.view' => 'Faturaları görüntüleme',
                'payments.view' => 'Ödeme hareketlerini görüntüleme',
            ],
            'Domain işlemleri' => [
                'domains.orders.view' => 'Domain siparişlerini görüntüleme',
                'domains.operations.view' => 'Domain yenileme ve işlem durumlarını görüntüleme',
            ],
            'Altyapı' => [
                'servers.view' => 'Sunucu bağlantılarını görüntüleme',
                'services.view' => 'Hosting hizmetlerini görüntüleme',
            ],
        ];
    }

    public static function staffPermissionCatalog(): array
    {
        $catalog=[];
        foreach(self::staffPermissionGroups() as $permissions)$catalog+=$permissions;
        return $catalog;
    }

    public static function allowsCurrentRequest(?array $user, ?\PDO $db, string $basePath=''): bool
    {
        $path=(string)(parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)?:'/');
        $base=rtrim($basePath,'/');
        if($base!==''&&($path===$base||str_starts_with($path,$base.'/')))$path=substr($path,strlen($base))?:'/';
        return self::allowsAdminRequest($path,(string)($_SERVER['REQUEST_METHOD']??'GET'),$user,$db);
    }

    public static function isAdminPath(string $path): bool
    {
        return $path === '/admin' || str_starts_with($path, '/admin/');
    }

    public static function isPublicRoute(string $path): bool
    {
        return in_array($path, ['/admin/login', '/admin/forgot-password', '/admin/reset-password'], true);
    }

    public static function allowsAdminRequest(string $path, string $method, ?array $user, ?\PDO $db = null): bool
    {
        if (!self::isAdminPath($path)) return true;
        if (self::isPublicRoute($path)) return true;
        if ($user === null || !isset($user['role'])) return false;

        $permission = self::permissionFor($path, strtoupper($method));
        if ($permission === null) return false;
        $role = (string)$user['role'];
        if (in_array($role, ['super_admin', 'admin'], true)) return true;
        // MFA is self-service for privileged operators, never for customer accounts.
        if (in_array($permission, ['security.mfa.manage','admin.logout'], true) && $role === 'staff') return true;
        if ($role !== 'staff' || $db === null) return false;
        try {
            $statement = $db->prepare('SELECT 1 FROM staff_permissions WHERE user_id = :user_id AND permission = :permission LIMIT 1');
            $statement->execute([':user_id' => (int)$user['id'], ':permission' => $permission]);
            return (bool)$statement->fetchColumn();
        } catch (\Throwable $exception) {
            error_log('[HostPilot] Staff permission lookup failed: ' . $exception->getMessage());
            return false;
        }
    }

    public static function permissionFor(string $path, string $method): ?string
    {
        $method = strtoupper($method);

        if (in_array($path, ['/admin', '/admin/'], true) && $method === 'GET') return 'dashboard.view';
        if ($path === '/admin/security/sessions' && in_array($method, ['GET', 'POST'], true)) return 'security.sessions.manage';
        if ($path === '/admin/security/mfa' && in_array($method, ['GET', 'POST'], true)) return 'security.mfa.manage';
        if ($path === '/admin/security/attachments' && in_array($method, ['GET', 'POST'], true)) return 'security.attachments.manage';
        if ($path === '/admin/staff' && $method === 'GET') return 'staff.permissions.manage';
        if ($path === '/admin/staff/save' && $method === 'POST') return 'staff.permissions.manage';
        if (preg_match('#^/admin/customers/[0-9]+$#', $path) && $method === 'GET') return 'customers.view';
        if ($path === '/admin/customers/status' && $method === 'POST') return 'customers.manage';
        if ($path === '/admin/customers' && $method === 'GET') return 'customers.view';
        if ($path === '/admin/reports' && $method === 'GET') return 'reports.view';
        if ($path === '/admin/tickets' && $method === 'GET') return 'support.view';
        if ($path === '/admin/ticket-departments' && in_array($method, ['GET','POST'], true)) return 'support.departments.manage';
        if (in_array($path, ['/admin/knowledgebase','/admin/knowledgebase/new'], true) && in_array($method, ['GET','POST'], true)) return 'knowledgebase.manage';
        if (preg_match('#^/admin/knowledgebase/[1-9][0-9]*$#D', $path) && in_array($method, ['GET','POST'], true)) return 'knowledgebase.manage';
        if (in_array($path, ['/admin/pages','/admin/pages/new'], true) && in_array($method, ['GET','POST'], true)) return 'cms.pages.manage';
        if (preg_match('#^/admin/pages/[1-9][0-9]*$#D', $path) && in_array($method, ['GET','POST'], true)) return 'cms.pages.manage';
        if ($path === '/admin/menus' && $method === 'GET') return 'cms.menus.manage';
        if (preg_match('#^/admin/menus/[1-9][0-9]*$#D', $path) && in_array($method, ['GET','POST'], true)) return 'cms.menus.manage';
        if ($path === '/admin/announcements' && $method === 'GET') return 'announcements.manage';
        if (in_array($path, ['/admin/announcements/new'], true) && in_array($method, ['GET','POST'], true)) return 'announcements.manage';
        if (preg_match('#^/admin/announcements/[1-9][0-9]*$#D', $path) && in_array($method, ['GET','POST'], true)) return 'announcements.manage';
        if (preg_match('#^/admin/tickets/attachment/[0-9]+$#D', $path) && $method === 'GET') return 'support.view';
        if (preg_match('#^/admin/tickets/[0-9]+$#D', $path)) {
            if ($method === 'GET') return 'support.view';
            if ($method === 'POST') return 'support.reply';
            return null;
        }
        if ($path === '/admin/logout' && $method === 'POST') return 'admin.logout';
        if ($path === '/admin/integrations' && $method === 'GET') return 'integrations.manage';
        if ($path === '/admin/themes' && $method === 'GET') return 'themes.manage';
        if ($path === '/admin/tax' && in_array($method, ['GET', 'POST'], true)) return 'tax.settings.manage';
        if ($path === '/admin/settings' && in_array($method, ['GET', 'POST'], true)) return 'email.settings.manage';
        if ($path === '/admin/email-queue' && in_array($method, ['GET', 'POST'], true)) return 'email.queue.manage';
        if (in_array($path, ['/admin/updates', '/admin/updates/status'], true) && $method === 'GET') return 'system.update';

        if (in_array($path, ['/admin/domains', '/admin/domains/registrar'], true) && in_array($method, ['GET', 'POST'], true)) return 'domains.registrar.manage';
        if ($path === '/admin/domains/export' && $method === 'GET') return 'domains.export';
        if ($path === '/admin/domains/orders' && $method === 'GET') return 'domains.orders.view';
        if (in_array($path, [
            '/admin/domains/expiry-sync/queue',
            '/admin/domains/expiry-sync/bulk-queue',
            '/admin/domains/renewals/reconcile',
            '/admin/domains/reconcile',
            '/admin/domains/nameservers/reconcile',
        ], true) && $method === 'POST') return 'domains.operations.manage';
        if ($path === '/admin/domains/renewals' && $method === 'GET') return 'domains.operations.view';

        if ($path === '/admin/servers' && $method === 'GET') return 'servers.view';
        if (in_array($path, ['/admin/servers/save', '/admin/servers/test', '/admin/servers/map'], true) && $method === 'POST') return 'servers.manage';
        if (preg_match('#^/admin/services/[0-9]+$#', $path) && $method === 'GET') return 'services.view';
        if ($path === '/admin/services' && $method === 'GET') return 'services.view';
        if (in_array($path, ['/admin/services/provision', '/admin/services/lifecycle', '/admin/services/terminate'], true) && $method === 'POST') return 'services.manage';
        if ($path === '/admin/automation' && in_array($method, ['GET', 'POST'], true)) return 'automation.manage';
        if (in_array($path, ['/admin/automation/jobs/retry', '/admin/automation/jobs/resolve'], true) && $method === 'POST') return 'automation.manage';

        if ($path === '/admin/payments' && in_array($method, ['GET', 'POST'], true)) return 'payments.manage';
        if (in_array($path, ['/admin/payment-transactions','/admin/finance','/admin/finance/export','/admin/finance/import'], true) && $method === 'GET') return 'payments.view';
        if ($path === '/admin/finance/import' && $method === 'POST') return 'payments.manage';
        if (in_array($path, ['/admin/finance/import/paytr-fetch','/admin/finance/import/iyzico-fetch'], true) && $method === 'POST') return 'payments.manage';
        if ($path === '/admin/finance/import/movements/review' && $method === 'POST') return 'payments.manage';
        if ($path === '/admin/payment-transactions/reconcile' && $method === 'POST') return 'payments.reconcile';
        if ($path === '/admin/products' && in_array($method, ['GET', 'POST'], true)) return 'catalog.manage';
        if (preg_match('#^/admin/orders/[0-9]+$#', $path) && $method === 'GET') return 'orders.view';
        if ($path === '/admin/orders' && $method === 'GET') return 'orders.view';
        if (preg_match('#^/admin/invoices/[0-9]+$#', $path) && $method === 'GET') return 'invoices.view';
        if ($path === '/admin/invoices' && $method === 'GET') return 'invoices.view';
        if ($path === '/admin/database-upgrade' && in_array($method, ['GET', 'POST'], true)) return 'database.upgrade';
        if ($path === '/admin/database-restore' && in_array($method, ['GET', 'POST'], true)) return 'database.restore';
        if ($path === '/admin/privacy' && in_array($method, ['GET', 'POST'], true)) return 'privacy.requests.manage';

        return null;
    }
}
