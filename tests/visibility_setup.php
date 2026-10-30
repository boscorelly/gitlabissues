<?php
// Creates tickets owned by the self-service user "post-only" in the situations of visibility_test.py. Prints JSON ids.
require __DIR__ . '/bootstrap.php';
use GlpiPlugin\Gitlabissues\{Link, Settings, Sync};
global $DB;
$_SESSION['glpiID'] = 2; $_SESSION['glpiname'] = 'glpi';
$_SESSION['glpiactiveprofile'] = ['id' => 4, 'interface' => 'central'];
$_SESSION['glpiactive_entity'] = 0; $_SESSION['glpiactiveentities'] = [0]; $_SESSION['glpiactiveentities_string'] = "'0'";
$_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
$uid = (int) $DB->request(['FROM' => 'glpi_users', 'WHERE' => ['name' => 'post-only']])->current()['id'];
function mockPost(string $path, array $b): void { file_get_contents('http://127.0.0.1:8099' . $path, false, stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode($b)]])); }
function mk(string $name, int $uid): Ticket { $t = new Ticket(); $id = $t->add(['name' => $name, 'content' => '<p>Mon souci</p>', 'entities_id' => 0, '_users_id_requester' => $uid]); $t->getFromDB($id); return $t; }
$base = ['notify_requester' => '0', 'notify_requester_text' => '', 'notify_requester_close' => '0', 'notify_requester_close_text' => '', 'close_followup' => '1', 'close_followup_private' => '1', 'close_solve_ticket' => '0', 'solution_template' => '', 'creation_followup' => '1', 'creation_followup_private' => '1'];
$ids = [];
Settings::save($base);
$a = mk('A defaults', $uid); Link::createFromTicket($a, 1, '', 2, true); $ids['A'] = $a->getID();                                   // nothing for the requester
$b = mk('B creation notice', $uid); Link::createFromTicket($b, 1, '', 2, true, null, true); $ids['B'] = $b->getID();                     // neutral public message
$c = mk('C closure notice', $uid); $lc = Link::createFromTicket($c, 3, '', 2, true, 'none', false)['link']; $ids['C'] = $c->getID();
Settings::save(['notify_requester_close' => '1']);
mockPost('/_mock/close', ['project' => 3, 'iid' => (int) $lc->fields['issue_iid'], 'by' => 'Jane Dev']); Sync::run(1000);
Settings::save(['close_solve_ticket' => '1']);
$d = mk('D closure solved', $uid); $ld = Link::createFromTicket($d, 1, '', 2, true, 'none', false)['link']; $ids['D'] = $d->getID();
mockPost('/_mock/close', ['project' => 1, 'iid' => (int) $ld->fields['issue_iid'], 'by' => 'Jane Dev']); Sync::run(1000);
Settings::save($base);
echo "\nIDS=" . json_encode($ids) . "\n";
