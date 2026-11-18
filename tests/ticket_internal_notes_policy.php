<?php
declare(strict_types=1);

$admin = file_get_contents(dirname(__DIR__).'/app/Http/AdminController.php');
$client = file_get_contents(dirname(__DIR__).'/app/Http/ClientController.php');
$migration = file_get_contents(dirname(__DIR__).'/database/migrations/20261118_0001_ticket_internal_notes.sql');
if (!is_string($admin) || !is_string($client) || !is_string($migration)) throw new RuntimeException('Ticket internal note source is missing.');
$start = strpos($admin, "if(\$action==='internal_note')");
$end = strpos($admin, "elseif(\$action==='close'||\$action==='reopen')", $start === false ? 0 : $start);
$internalBranch = ($start !== false && $end !== false) ? substr($admin, $start, $end - $start) : '';
$checks = [
    'internal notes require a separate explicit action' => $internalBranch !== '' && str_contains($internalBranch, 'INSERT INTO ticket_internal_notes'),
    'internal note is stored outside customer-visible replies' => str_contains($admin, 'INSERT INTO ticket_internal_notes'),
    'internal note has a staff-only label in admin UI' => str_contains($admin, 'yalnızca personel görür'),
    'internal note action does not queue customer email' => $internalBranch !== '' && !str_contains($internalBranch, 'queueTicketNotice'),
    'internal note is transaction protected' => str_contains($internalBranch, '$this->db->beginTransaction()') && str_contains($internalBranch, '$this->db->commit()'),
    'internal note generates an audit event' => str_contains($internalBranch, 'support.internal_note_added'),
    'customer timeline reads only customer-visible ticket messages' => str_contains($client, 'FROM ticket_messages m JOIN users u ON u.id=m.user_id WHERE m.ticket_id=:id'),
    'internal notes cascade when a ticket is deleted' => str_contains($migration, 'REFERENCES tickets(id) ON DELETE CASCADE'),
];
foreach ($checks as $label => $ok) {
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label.PHP_EOL;
}
echo "HostPilot internal ticket note policy tests passed.".PHP_EOL;
