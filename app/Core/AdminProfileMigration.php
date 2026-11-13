<?php
declare(strict_types=1);
namespace HostPilot\\Core;
use PDO;
final class AdminProfileMigration
{
    public static function migrate(PDO $db): void
    {
        $name = '20261113_0017_admin_first_last_name';
        $q = $db->prepare('SELECT id FROM migrations WHERE migration = :name LIMIT 1');
        $q->execute([':name' => $name]);
        if ($q->fetchColumn()) return;
        foreach (['first_name' => 'VARCHAR(80) NULL', 'last_name' => 'VARCHAR(80) NULL'] as $column => $definition) {
            $check = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME=:column");
            $check->execute([':column' => $column]);
            if (!(int)$check->fetchColumn()) $db->exec('ALTER TABLE users ADD COLUMN ' . $column . ' ' . $definition);
        }
        $db->exec("UPDATE users SET first_name = CASE WHEN first_name IS NULL OR first_name='' THEN SUBSTRING_INDEX(name,' ',1) ELSE first_name END, last_name = CASE WHEN last_name IS NULL THEN CASE WHEN LOCATE(' ',name)>0 THEN TRIM(SUBSTRING(name,LOCATE(' ',name)+1)) ELSE '' END ELSE last_name END WHERE first_name IS NULL OR first_name='' OR last_name IS NULL");
        $record = $db->prepare('INSERT IGNORE INTO migrations(migration,batch,applied_at) VALUES(:name,17,:applied)');
        $record->execute([':name' => $name, ':applied' => gmdate('Y-m-d H:i:s')]);
    }
}
