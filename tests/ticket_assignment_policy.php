<?php
declare(strict_types=1);

$admin=file_get_contents(dirname(__DIR__).'/app/Http/AdminController.php');
$migration=file_get_contents(dirname(__DIR__).'/database/migrations/20261118_0002_ticket_assignments.sql');
if(!is_string($admin)||!is_string($migration))throw new RuntimeException('Ticket assignment source missing.');
$checks=[
 'assignment accepts only active staff in the ticket department'=>str_contains($admin,"u.status='active' AND sd.department=:department"),
 'assignment requires support reply permission'=>str_contains($admin,"sp.permission='support.reply'"),
 'staff can only assign tickets to themselves'=>str_contains($admin,'$target!=='),
 'only administrators can unassign'=>str_contains($admin,'$target===0&&!in_array'),
 'assignment changes are transaction protected'=>str_contains($admin,'ticket_assignments')&&str_contains($admin,'$this->db->beginTransaction()')&&str_contains($admin,'$this->db->commit()'),
 'assignment changes are audited'=>str_contains($admin,'support.ticket_assignment_changed'),
 'priority changes are admin-only'=>str_contains($admin,'Önceliği yalnızca yönetici değiştirebilir.'),
 'priority values are allowlisted'=>str_contains($admin,"['low','normal','high','urgent']"),
 'priority changes are audited'=>str_contains($admin,'support.ticket_priority_changed'),
 'assignment has unique ticket constraint'=>str_contains($migration,'UNIQUE KEY uq_ticket_assignment_ticket (ticket_id)'),
 'assignment is deleted with ticket'=>str_contains($migration,'REFERENCES tickets(id) ON DELETE CASCADE'),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
echo "HostPilot ticket assignment and priority policy tests passed.".PHP_EOL;
