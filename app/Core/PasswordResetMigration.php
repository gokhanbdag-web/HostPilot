<?php
declare(strict_types=1);
namespace HostPilot\Core;
use PDO;
final class PasswordResetMigration
{
 public static function migrate(PDO $db): void
 {
  $name='20261113_0016_admin_password_resets';
  $q=$db->prepare('SELECT id FROM migrations WHERE migration=:name LIMIT 1');$q->execute([':name'=>$name]);
  if($q->fetchColumn())return;
  $db->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
   id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
   user_id BIGINT UNSIGNED NOT NULL, token_hash CHAR(64) NOT NULL,
   expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at DATETIME NOT NULL,
   UNIQUE KEY uq_password_reset_token_hash(token_hash),
   KEY idx_password_reset_user_created(user_id,created_at),
   KEY idx_password_reset_expiry(expires_at),
   CONSTRAINT fk_password_reset_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $r=$db->prepare('INSERT IGNORE INTO migrations(migration,batch,applied_at) VALUES(:name,16,:applied)');
  $r->execute([':name'=>$name,':applied'=>gmdate('Y-m-d H:i:s')]);
 }
}
