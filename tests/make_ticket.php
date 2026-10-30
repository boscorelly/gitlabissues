<?php
// php tests/make_ticket.php <requester users_id>  -> prints the id of a new ticket (used by rights_test.py)
require __DIR__ . '/bootstrap.php';
$_SESSION['glpiID'] = 2; $_SESSION['glpiname'] = 'glpi';
$_SESSION['glpiactiveprofile'] = ['id' => 4, 'interface' => 'central'];
$_SESSION['glpiactive_entity'] = 0; $_SESSION['glpiactiveentities'] = [0]; $_SESSION['glpiactiveentities_string'] = "'0'";
$t = new Ticket();
echo "\n" . $t->add(['name' => 'own ticket', 'content' => '<p>x</p>', 'entities_id' => 0, '_users_id_requester' => (int) ($argv[1] ?? 2)]) . "\n";
