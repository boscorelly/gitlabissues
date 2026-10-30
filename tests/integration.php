<?php
/**
 * End-to-end test on a real GLPI with the mock GitLab (tests/mock_gitlab.py on :8099).
 * Usage: GLPI_ROOT=/path/to/glpi php tests/integration.php
 */
require __DIR__ . '/bootstrap.php';

use GlpiPlugin\Gitlabissues\GitlabClient;
use GlpiPlugin\Gitlabissues\GitlabException;
use GlpiPlugin\Gitlabissues\Link;
use GlpiPlugin\Gitlabissues\Settings;
use GlpiPlugin\Gitlabissues\Sync;

global $DB;

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   - $name\n"; } else { $fail++; echo "  FAIL - $name" . ($detail !== '' ? "  [$detail]" : '') . "\n"; }
}
function mock(string $path, array $body = []): array {
    $ctx = stream_context_create(['http' => ['method' => $path === '/_mock/state' ? 'GET' : 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode($body), 'ignore_errors' => true]]);
    return json_decode((string) file_get_contents('http://127.0.0.1:8099' . $path, false, $ctx), true) ?? [];
}
function login(): void {
    $_SESSION['glpiID'] = 2; $_SESSION['glpiname'] = 'glpi';
    $_SESSION['glpiactiveprofile'] = ['id' => 4, 'interface' => 'central', 'plugin_gitlabissues_issue' => 15, 'ticket' => 2147483647, 'config' => 3, 'profile' => 3];
    $_SESSION['glpiactive_entity'] = 0; $_SESSION['glpiactiveentities'] = [0]; $_SESSION['glpiactiveentities_string'] = "'0'";
    $_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
    unset($_SESSION['glpicronuserrunning']);
}
function runCron(): void {
    global $DB;
    $DB->update('glpi_crontasks', ['lastrun' => null, 'state' => 1], ['name' => 'CheckIssues']);
    $saved = $_SESSION;
    unset($_SESSION['glpiID'], $_SESSION['glpiactiveprofile']); // a real cron run has no logged-in user
    CronTask::launch(CronTask::MODE_EXTERNAL, 1, 'CheckIssues');
    $_SESSION = $saved; unset($_SESSION['glpicronuserrunning']);
}
function newTicket(string $name, string $content): Ticket {
    $t = new Ticket();
    $id = $t->add(['name' => $name, 'content' => $content, 'entities_id' => 0, '_users_id_requester' => 2, 'itilcategories_id' => 0, 'priority' => 4, 'urgency' => 4, 'impact' => 3]);
    $t->getFromDB($id);
    return $t;
}
function followups(int $tid): array { global $DB; return array_values(iterator_to_array($DB->request(['FROM' => 'glpi_itilfollowups', 'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $tid], 'ORDER' => 'id']))); }
function linkOf(int $id): array { global $DB; return $DB->request(['FROM' => Link::getTable(), 'WHERE' => ['id' => $id]])->current(); }
function status(int $tid): int { $t = new Ticket(); $t->getFromDB($tid); return (int) $t->fields['status']; }

login();
Config::setConfigurationValues('core', ['use_notifications' => 1, 'notifications_mailing' => 1]);

echo "== Settings / connection\n";
Settings::save(['gitlab_url' => 'http://127.0.0.1:8099/', 'gitlab_token' => 'test-token', 'close_followup_private' => '0'] + Settings::DEFAULTS); // known starting point
$raw = $DB->request(['FROM' => 'glpi_configs', 'WHERE' => ['context' => Settings::CONTEXT, 'name' => 'gitlab_token']])->current()['value'];
ok('token stored encrypted', $raw !== '' && $raw !== 'test-token');
ok('token decrypted by Settings', Settings::get('gitlab_token') === 'test-token');
ok('url normalised (no trailing slash)', Settings::get('gitlab_url') === 'http://127.0.0.1:8099');
Settings::save(['gitlab_token' => '']);
ok('empty token keeps the stored one', Settings::get('gitlab_token') === 'test-token');

$info = GitlabClient::fromSettings()->testConnection();
ok('test connection', $info['user'] === 'glpi-bot' && $info['scopes'] === ['api'], json_encode($info));
try { (new GitlabClient('http://127.0.0.1:8099', 'bad'))->testConnection(); ok('bad token refused', false); }
catch (GitlabException $e) { ok('bad token -> AUTH, token not leaked', $e->getKind() === GitlabException::AUTH && !str_contains($e->getMessage(), 'bad')); }
try { (new GitlabClient('http://127.0.0.1:9', 'x'))->testConnection(); ok('unreachable', false); }
catch (GitlabException $e) { ok('unreachable -> NETWORK', $e->getKind() === GitlabException::NETWORK); }
$res = GitlabClient::fromSettings()->searchProjects('infra');
ok('project search', count($res['results']) === 1 && $res['results'][0]['text'] === 'group/infra');

echo "== Issue creation\n";
// attachments: one inline image, one regular file
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
@mkdir(GLPI_DOC_DIR . '/PNG/zz', 0777, true); @mkdir(GLPI_DOC_DIR . '/TXT/zz', 0777, true);
file_put_contents(GLPI_DOC_DIR . '/PNG/zz/shot.png', $png);
file_put_contents(GLPI_DOC_DIR . '/TXT/zz/notes.txt', "log line\n");
$t1 = newTicket('Imprimante "hors service" <étage 2>', '<p>Bonjour <strong>équipe</strong>,</p><ul><li>bac vide</li><li>voyant rouge</li></ul><p><img src="/front/document.send.php?docid=DOCID&amp;itemtype=Ticket" alt="photo"></p><p>a * b</p>');
$d1 = new Document(); $img_id = $d1->add(['name' => 'shot', 'filename' => 'shot.png', 'filepath' => 'PNG/zz/shot.png', 'mime' => 'image/png', 'entities_id' => 0]);
$d2 = new Document(); $txt_id = $d2->add(['name' => 'notes', 'filename' => 'notes.txt', 'filepath' => 'TXT/zz/notes.txt', 'mime' => 'text/plain', 'entities_id' => 0]);
// Document::add() ignores 'filepath' (set by the upload flow in real life): store it directly.
$DB->update('glpi_documents', ['filepath' => 'PNG/zz/shot.png'], ['id' => $img_id]);
$DB->update('glpi_documents', ['filepath' => 'TXT/zz/notes.txt'], ['id' => $txt_id]);
foreach ([$img_id, $txt_id] as $did) { (new Document_Item())->add(['documents_id' => $did, 'itemtype' => 'Ticket', 'items_id' => $t1->getID(), 'entities_id' => 0]); }
$DB->update('glpi_tickets', ['content' => str_replace('DOCID', (string) $img_id, $t1->fields['content'])], ['id' => $t1->getID()]);
$t1->getFromDB($t1->getID());

$r = Link::createFromTicket($t1, 1, 'urgent, GLPI', 2);
$link1 = $r['link'];
$issue = mock('/_mock/state')['issues']['1/1'] ?? [];
ok('issue created in GitLab', $issue !== []);
ok('title uses the template', ($issue['title'] ?? '') === '[GLPI #' . $t1->getID() . '] Imprimante "hors service" <étage 2>', $issue['title'] ?? '');
ok('labels merged without duplicates', ($issue['labels'] ?? []) === ['glpi', 'urgent'], json_encode($issue['labels'] ?? null));
$desc = $issue['description'] ?? '';
ok('description links the ticket', str_contains($desc, '/front/ticket.form.php?id=' . $t1->getID()));
ok('rich text converted', str_contains($desc, '**équipe**') && str_contains($desc, '- bac vide') && str_contains($desc, 'a \* b'), $desc);
ok('inline image replaced by the uploaded one', (bool) preg_match('#!\[shot\.png\]\(/uploads/[0-9a-f]+/shot\.png\)#', $desc), $desc);
ok('other attachment listed', (bool) preg_match('#\[notes\.txt\]\(/uploads/[0-9a-f]+/notes\.txt\)#', $desc) && (str_contains($desc, 'Attachments') || str_contains($desc, 'Pièces jointes')));
ok('inline image not duplicated in attachments', substr_count($desc, 'shot.png') === 2 /* alt + url */, (string) substr_count($desc, 'shot.png'));
ok('requester mentioned', str_contains($desc, 'glpi'));
$row = linkOf($link1->getID());
ok('link stored', $row['state'] === 'opened' && (int) $row['tickets_id'] === $t1->getID() && $row['project_path'] === 'group/app' && (int) $row['issue_iid'] === 1);
ok('private creation followup added', count($f = followups($t1->getID())) === 1 && (int) $f[0]['is_private'] === 1 && str_contains($f[0]['content'], 'group/app#1'));

try { Link::createFromTicket($t1, 1, '', 2); ok('duplicate blocked', false); }
catch (RuntimeException $e) { ok('duplicate in same project blocked', true); }
$r2 = Link::createFromTicket($t1, 2, '', 2);
ok('other project allowed', (int) $r2['link']->fields['issue_iid'] === 1 && $r2['link']->fields['project_path'] === 'group/infra');
try { Link::createFromTicket($t1, 99, '', 2); ok('unknown project', false); }
catch (GitlabException $e) { ok('unknown project -> NOT_FOUND, no issue/link created', $e->getKind() === GitlabException::NOT_FOUND && countElementsInTable(Link::getTable(), ['tickets_id' => $t1->getID()]) === 2); }

echo "== Creation followup: private / public / none\n";
$t8 = newTicket('Choix du suivi', '<p>x</p>');
Link::createFromTicket($t8, 1, '', 2, true, 'public');
$f = followups($t8->getID());
ok('explicit "public": public followup', count($f) === 1 && (int) $f[0]['is_private'] === 0);
Link::createFromTicket($t8, 2, '', 2, true, 'none');
ok('explicit "none": no followup', count(followups($t8->getID())) === 1);
Link::createFromTicket($t8, 3, '', 2, true, 'private');
$f = followups($t8->getID());
ok('explicit "private": private followup', count($f) === 2 && (int) $f[1]['is_private'] === 1);
Settings::save(['creation_followup_private' => '0']);
ok('default mode follows the setting (public)', Link::defaultFollowupMode() === 'public');
$t9 = newTicket('Suivi par defaut public', '<p>x</p>');
Link::createFromTicket($t9, 1, '', 2);
ok('no choice given: setting used (public)', (int) followups($t9->getID())[0]['is_private'] === 0);
Settings::save(['creation_followup_private' => '1', 'creation_followup' => '0']);
ok('followup disabled in settings -> none', Link::defaultFollowupMode() === 'none');
Settings::save(['creation_followup' => '1']);
ok('default back to private', Link::defaultFollowupMode() === 'private');

echo "== Cron: nothing to do while issues are open\n";
runCron();
$row = linkOf($link1->getID());
ok('still opened, checked', $row['state'] === 'opened' && $row['date_last_check'] !== null);
ok('no extra followup', count(followups($t1->getID())) === 2);

echo "== Cron: issue closed -> followup only\n";
mock('/_mock/close', ['project' => 1, 'iid' => 1, 'by' => 'Jane Dev']);
$before = status($t1->getID());
runCron();
$row = linkOf($link1->getID());
ok('link closed with closer', $row['state'] === 'closed' && $row['closed_by'] === 'Jane Dev' && $row['date_closed'] !== null);
$f = followups($t1->getID());
ok('public followup added with MR link', count($f) === 3 && (int) end($f)['is_private'] === 0 && str_contains(end($f)['content'], 'merge_requests/7') && str_contains(end($f)['content'], 'Jane Dev'), json_encode(end($f)['content'] ?? ''));
ok('ticket status unchanged (solve option off)', status($t1->getID()) === $before);
runCron();
ok('idempotent: no duplicate followup on next run', count(followups($t1->getID())) === 3);

echo "== Cron: issue closed -> ticket solved\n";
Settings::save(['close_solve_ticket' => '1', 'close_followup' => '0', 'solution_template' => '<p>Fixed by {closed_by}: <a href="{issue_url}">{issue}</a></p>']);
$t2 = newTicket('Panne VPN', '<p>Plus de VPN</p>');
$l2 = Link::createFromTicket($t2, 1, '', 2)['link'];
$q_before = countElementsInTable('glpi_queuednotifications');
mock('/_mock/close', ['project' => 1, 'iid' => $l2->fields['issue_iid'], 'by' => 'Bob']);
runCron();
ok('link closed', linkOf($l2->getID())['state'] === 'closed');
ok('ticket solved', status($t2->getID()) === Ticket::SOLVED, (string) status($t2->getID()));
$sol = $DB->request(['FROM' => 'glpi_itilsolutions', 'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $t2->getID()]])->current();
ok('solution uses the template', $sol && str_contains($sol['content'], 'Fixed by Bob') && str_contains($sol['content'], 'group/app#' . $l2->fields['issue_iid']), $sol['content'] ?? 'none');
ok('GLPI notifications queued (automatic actions chain works)', countElementsInTable('glpi_queuednotifications') > $q_before);
ok('no followup when close_followup is off', count(array_filter(followups($t2->getID()), fn($f) => str_contains($f['content'], 'closed'))) === 0);

echo "== Cron: edge cases\n";
Settings::save(['close_solve_ticket' => '0', 'close_followup' => '1']);
$t3 = newTicket('Ticket déplacé', '<p>x</p>');
$l3 = Link::createFromTicket($t3, 1, '', 2)['link'];
mock('/_mock/close', ['project' => 1, 'iid' => $l3->fields['issue_iid'], 'moved' => true]);
runCron();
ok('moved issue: state moved, ticket NOT solved', linkOf($l3->getID())['state'] === 'moved' && status($t3->getID()) !== Ticket::SOLVED);

$t4 = newTicket('Issue supprimée', '<p>x</p>');
$l4 = Link::createFromTicket($t4, 1, '', 2)['link'];
mock('/_mock/delete', ['project' => 1, 'iid' => $l4->fields['issue_iid']]);
runCron(); $s1 = linkOf($l4->getID()); runCron(); runCron(); $s3 = linkOf($l4->getID());
ok('deleted issue: kept opened after 1 miss', $s1['state'] === 'opened' && (int) $s1['error_count'] === 1);
ok('deleted issue: missing after 3 misses + ticket informed', $s3['state'] === 'missing' && count(followups($t4->getID())) >= 2);

$t5 = newTicket('Ticket clos entre-temps', '<p>x</p>');
$l5 = Link::createFromTicket($t5, 1, '', 2)['link'];
$DB->update('glpi_tickets', ['status' => Ticket::CLOSED], ['id' => $t5->getID()]);
$n5 = count(followups($t5->getID()));
mock('/_mock/close', ['project' => 1, 'iid' => $l5->fields['issue_iid']]);
runCron();
ok('closed ticket: link closed, ticket untouched', linkOf($l5->getID())['state'] === 'closed' && count(followups($t5->getID())) === $n5 && status($t5->getID()) === Ticket::CLOSED);

$t6 = newTicket('GitLab en panne', '<p>x</p>');
$l6 = Link::createFromTicket($t6, 2, '', 2)['link'];
mock('/_mock/close', ['project' => 2, 'iid' => $l6->fields['issue_iid']]);
mock('/_mock/fail', ['status' => 500]);
runCron();
$row = linkOf($l6->getID());
ok('GitLab 500: nothing lost, error recorded, stays opened', $row['state'] === 'opened' && str_contains((string) $row['last_error'], '500'), (string) $row['last_error']);
mock('/_mock/fail', ['status' => 0]);
runCron();
ok('GitLab back: pending closure processed', linkOf($l6->getID())['state'] === 'closed' && linkOf($l6->getID())['last_error'] === null);

echo "== Cron: failure while running the actions -> rolled back, retried\n";
$t7 = newTicket('Echec des actions', '<p>x</p>');
$l7 = Link::createFromTicket($t7, 1, '', 2)['link'];
mock('/_mock/close', ['project' => 1, 'iid' => $l7->fields['issue_iid']]);
$n7 = count(followups($t7->getID()));
global $PLUGIN_HOOKS;
$PLUGIN_HOOKS['pre_item_add']['gitlabissues'] = ['ITILFollowup' => static function ($item) { $item->input = false; }];
runCron();
unset($PLUGIN_HOOKS['pre_item_add']);
$row = linkOf($l7->getID());
ok('failed actions: link NOT marked closed (rolled back)', $row['state'] === 'opened' && (int) $row['error_count'] === 0, json_encode($row['state']));
ok('failed actions: error recorded for the admin', str_contains((string) $row['last_error'], 'followup'), (string) $row['last_error']);
ok('failed actions: ticket untouched', count(followups($t7->getID())) === $n7);
runCron();
$row = linkOf($l7->getID());
ok('retried at next run once the problem is gone', $row['state'] === 'closed' && count(followups($t7->getID())) === $n7 + 1 && $row['last_error'] === null);

echo "== Cron log / purge\n";
$log = $DB->request(['FROM' => 'glpi_crontasklogs', 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
ok('cron logged', $log !== null);
$tid = $t6->getID();
$t6->delete(['id' => $tid], true);
ok('links removed when the ticket is purged', countElementsInTable(Link::getTable(), ['tickets_id' => $tid]) === 0);

$up = mock('/_mock/state')['uploads'];
ok('attachments uploaded to the project of each issue', count($up) === 4 && $up[0]['project'] === 1 && $up[2]['project'] === 2, json_encode($up));

file_put_contents("/tmp/last_ticket_id", (string) $t1->getID());
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
