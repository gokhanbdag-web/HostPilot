<?php
declare(strict_types=1);

$admin=file_get_contents(dirname(__DIR__).'/app/Http/AnnouncementAdminController.php');
$client=file_get_contents(dirname(__DIR__).'/app/Http/ClientController.php');
$index=file_get_contents(dirname(__DIR__).'/index.php');
$auth=file_get_contents(dirname(__DIR__).'/app/Core/Authorization.php');
$navigation=file_get_contents(dirname(__DIR__).'/app/Http/AdminController.php');
$migration=file_get_contents(dirname(__DIR__).'/database/migrations/20261118_0007_cms_announcements.sql');
foreach([$admin,$client,$index,$auth,$navigation,$migration] as $source)if(!is_string($source))throw new RuntimeException('Announcement source missing.');
$checks=[
 'announcement editor is restricted to administrators'=>str_contains($admin,"['admin','super_admin']"),
 'announcement create and edit require CSRF'=>str_contains($admin,'Auth::verifyCsrf($_POST[\'_token\']??null)'),
 'announcement create update delete actions are audited'=>str_contains($admin,'announcement.created')&&str_contains($admin,'announcement.updated')&&str_contains($admin,'announcement.deleted'),
 'severity is limited to known visual levels'=>str_contains($admin,"['info','success','warning','critical']"),
 'status is limited to draft or published'=>str_contains($admin,"['draft','published']"),
 'date range is validated'=>str_contains($admin,'$start>=$end')&&str_contains($admin,'parseDate'),
 'announcement schedule converts Istanbul local time to UTC'=>str_contains($admin,"new \\DateTimeZone('Europe/Istanbul')")&&str_contains($admin,"new \\DateTimeZone('UTC')"),
 'customer dashboard queries only active scheduled published announcements'=>str_contains($client,"a.status='published'")&&str_contains($client,'a.starts_at<=UTC_TIMESTAMP()')&&str_contains($client,'a.ends_at>=UTC_TIMESTAMP()'),
 'announcement body is escaped before rendering'=>str_contains($client,'nl2br($this->e((string)$announcement[\'body\']))'),
 'dismiss action requires customer authentication and CSRF'=>str_contains($client,'public function dismissAnnouncement')&&str_contains($client,'Auth::verifyCsrf($_POST[\'_token\']??null)'),
 'dismissal is scoped to the current customer'=>str_contains($client,'announcement_id,user_id,dismissed_at')&&str_contains($client,':user=>$user[\'id\']'),
 'dismiss route is POST-only'=>str_contains($index,"'/client/announcements/dismiss' && $method === 'POST'"),
 'admin announcement routes are registered'=>str_contains($index,"'/admin/announcements/new'")&&str_contains($index,"^/admin/announcements/"),
 'announcement routes are centrally authorized'=>str_contains($auth,'announcements.manage'),
 'staff cannot manage announcements through staff permissions'=>!str_contains($auth,"'announcements.manage' =>"),
 'admin navigation links to announcements'=>str_contains($navigation,"['Duyurular','/admin/announcements','◉']"),
 'dismissals cascade with announcement and customer deletion'=>str_contains($migration,'ON DELETE CASCADE'),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
echo "HostPilot customer announcements policy tests passed.".PHP_EOL;
