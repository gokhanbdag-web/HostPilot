<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/app/Core/DatabaseBackup.php';
require_once dirname(__DIR__).'/app/Core/Authorization.php';

use HostPilot\Core\Authorization;
use HostPilot\Core\DatabaseBackup;

if(Authorization::permissionFor('/admin/database-restore','GET')!=='database.restore')throw new RuntimeException('Database restore route is not centrally permission-mapped.');
$source=file_get_contents(dirname(__DIR__).'/app/Http/DatabaseRestoreController.php');
if(!is_string($source))throw new RuntimeException('Database restore controller is missing.');
$required=[
    '($actor[\'role\']??\'\')!==\'super_admin\'',
    "Auth::verifyCredentials",
    "Auth::verifyCsrf",
    "DatabaseBackup::create",
    "DatabaseBackup::restore",
    "status'=>'restore_running'",
    "status'=>'restored'",
    "emergency_backup",
    "hash_equals",
];
foreach($required as $needle)if(!str_contains($source,$needle))throw new RuntimeException('Restore safety control missing: '.$needle);
$backupSource=file_get_contents(dirname(__DIR__).'/app/Core/DatabaseBackup.php');
foreach(["hash_equals","MAX_RESTORE_BYTES","FOREIGN_KEY_CHECKS=0","SET FOREIGN_KEY_CHECKS=1","Backup contains a SQL statement outside the supported restore format"] as $needle){
    if(!is_string($backupSource)||!str_contains($backupSource,$needle))throw new RuntimeException('Backup restore safety guard missing: '.$needle);
}
echo "Database restore authorization, re-authentication, emergency backup and SQL allowlist policy tests passed.\n";
