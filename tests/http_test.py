"""HTTP-level test: real GLPI web app + mock GitLab. Needs GLPI on :8088 and the mock on :8099."""
import os, sys, re, json, subprocess, urllib.parse
from webclient import *

ticket_id = int(sys.argv[1])
TAB = urllib.parse.quote('GlpiPlugin\\Gitlabissues\\Link$1', safe='')

print('== admin (glpi)')
a = Client()
check('login', a.login('glpi', 'glpi'))
tok = a.ajax_token()
check('csrf token found in page', bool(tok))

s, page, _ = a.req(f'/ajax/common.tabs.php?_target=/front/ticket.form.php&_itemtype=Ticket&_glpi_tab={TAB}&id={ticket_id}', headers={'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': tok or ''})
check('ticket GitLab tab renders', s == 200 and ('GitLab issues' in page or 'Issues GitLab' in page) and ('Create GitLab issue' in page or 'Créer une issue GitLab' in page), page[:300])
check('tab form points to plugin route', f'/plugins/gitlabissues/Ticket/{ticket_id}/Issue' in page)
check('tab lists existing issue', 'group/app#1' in page)
check('tab offers the "inform the requester" box', 'name="notify_requester"' in page and 'type="checkbox"' in page)
check('tab offers the followup choice', 'name="followup"' in page and 'value="public"' in page and 'value="none"' in page)

def tab_entry(url, needle):
    s, p, _ = a.req(url)
    m = re.search(r"data-glpi-ajax-content='[^']*" + needle + r"[^']*'.*?</a>", p, re.S)
    return m.group(0) if m else ''
check('ticket tab icon: GLPI\'s GitLab glyph, as in 1.0.6', 'ti-brand-gitlab' in tab_entry('/front/ticket.form.php?id=%d' % ticket_id, 'Gitlabissues'))
check('profile tab icon: GLPI\'s GitLab glyph', 'ti-brand-gitlab' in tab_entry('/front/profile.form.php?id=4', 'Gitlabissues'))
PNG = b'\x89PNG\r\n\x1a\n'
logo_file = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'logo.png'), 'rb').read()
import base64, urllib.request as _ur, urllib.error as _ue
r_logo = a.op.open(BASE + '/Plugin/gitlabissues/Logo'); raw_logo = r_logo.read()
check('GLPI shows logo.png as the plugin icon (route used by Setup > Plugins / Marketplace)', r_logo.status == 200 and r_logo.headers.get('Content-Type', '').startswith('image/png') and raw_logo == logo_file, f'{r_logo.status} {len(raw_logo)} vs {len(logo_file)}')
def public(path):
    try: return _ur.urlopen(BASE + '/plugins/gitlabissues/' + path).status
    except _ue.HTTPError as e: return e.code
check('only public/ is exposed: sources, hook, locales, README, tests are NOT downloadable', all(public(p) == 404 for p in ('setup.php', 'hook.php', 'src/Link.php', 'src/Settings.php', 'logo.png', 'README.md', 'locales/fr_FR.po', 'tests/http_test.py', 'templates/ticket_tab.html.twig')), str({p: public(p) for p in ('setup.php', 'src/Link.php', 'logo.png', 'locales/fr_FR.po')}))

s, page, _ = a.req('/front/ticket.form.php?id=%d' % ticket_id)
check('ticket page lists the tab', s == 200 and ('GitLab issue' in page or 'Issue GitLab' in page or 'Issues GitLab' in page), str(s))

s, page, _ = a.req('/plugins/gitlabissues/Config')
tabs = sorted(set(re.findall(r"data-glpi-ajax-content='[^']*ConfigTabs%24(\d)", page)))
check('config page: GLPI standard page, tabs on the left, one tab per zone (4)', s == 200 and 'card-tabs' in page and ' vertical' in page and tabs == ['1', '2', '3', '4'], f'{s} {tabs}')
check('each tab has an icon', all(i in page for i in ('ti-plug-connected', 'ti-file-plus', 'ti-circle-check', 'ti-shield-lock')))
check('no "All" tab (each tab is a separate form)', '_glpi_tab=-1' not in page)
s, c1 = config_tab(a, 1)
check('connection tab renders with the URL', s == 200 and 'value="http://127.0.0.1:8099"' in c1, str(s))
check('token never sent back to the browser', 'test-token' not in c1 and 'test-token' not in page)
ctok = a.token(c1)

s, page, h_ = a.req('/plugins/gitlabissues/Config', {'_glpi_csrf_token': ctok, '_tab': 'connection', 'gitlab_url': 'http://127.0.0.1:8099', 'gitlab_token': '', '_test': '1'})
check('connection tab: save+test redirects to the page', s in (302, 303), str(s) + h_.get('Location', ''))
s, page, _ = a.req('/plugins/gitlabissues/Config')
check('after saving the connection tab, the page reopens tab 1', opened_tab(page) == '1', str(opened_tab(page)))
check('connection success message shown', 'Connection successful' in page or 'Connexion réussie' in page, re.sub(r'\s+', ' ', re.sub('<[^>]+>', ' ', page))[:300])

s, body, _ = a.req('/plugins/gitlabissues/Config', {'_tab': 'connection', 'gitlab_url': 'http://127.0.0.1:8099'})
check('config POST without CSRF token rejected', s in (400, 403), str(s))

s, body, _ = a.req('/plugins/gitlabissues/Projects?term=infra', headers={'X-Requested-With': 'XMLHttpRequest'})
check('project search endpoint', s == 200 and json.loads(body)['results'][0]['text'] == 'group/infra', body[:200])

def stored_rights(pid):
    return subprocess.run(['mysql', '-uroot', 'glpi', '-N', '-e', f"select rights from glpi_profilerights where profiles_id={pid} and name='plugin_gitlabissues_issue'"], capture_output=True, text=True).stdout.strip()

_, t2 = config_tab(a, 2); _, t3 = config_tab(a, 3); _, t4 = config_tab(a, 4)
check('rights tab shows the rights per profile', 'name="profiles[]"' in t4 and 'Technician' in t4 and 'name="rights[6][]"' in t4)
check('creation tab has the creation message for the requester', 'name="notify_requester"' in t2 and 'name="notify_requester_text"' in t2)
check('closure tab has the closure message for the requester', 'name="notify_requester_close_text"' in t3)
check('closure: one single choice for the requester (3 options)', all(f'name="requester_close_mode" value="{v}"' in t3 for v in ('none', 'followup', 'solve')))
check('no separate "inform the requester" tab', 'Informer le demandeur' not in page and 'Inform the requester' not in page)
def cfg(n):
    return subprocess.run(['mysql', '-uroot', 'glpi', '-N', '-e', f"select value from glpi_configs where context='plugin:gitlabissues' and name='{n}'"], capture_output=True, text=True).stdout.strip()
def save_tab(tab, **fields):
    _, c = config_tab(a, {'connection': 1, 'creation': 2, 'closure': 3}[tab])
    return a.req('/plugins/gitlabissues/Config', dict({'_glpi_csrf_token': a.token(c), '_tab': tab}, **fields))
for mode, (solve, notice) in (('solve', ('1', '0')), ('followup', ('0', '1')), ('none', ('0', '0'))):
    save_tab('closure', requester_close_mode=mode)
    _, c3 = config_tab(a, 3)
    check(f'closure choice "{mode}" is stored (solve={solve}, notice={notice}) and shown as selected',
          (cfg('close_solve_ticket'), cfg('notify_requester_close')) == (solve, notice) and re.search(r'name="requester_close_mode" value="%s"\s+checked' % mode, c3) is not None,
          f"{cfg('close_solve_ticket')}/{cfg('notify_requester_close')}")

# after a save, the page reopens the tab that was saved (not the first one)
save_tab('closure', requester_close_mode='none')
_, pg, _ = a.req('/plugins/gitlabissues/Config'); check('after saving the closure tab, the page reopens tab 3', opened_tab(pg) == '3', str(opened_tab(pg)))
save_tab('creation', title_template='[GLPI #{id}] {title}')
_, pg, _ = a.req('/plugins/gitlabissues/Config'); check('after saving the creation tab, the page reopens tab 2', opened_tab(pg) == '2', str(opened_tab(pg)))

# a tab only saves its own zone
save_tab('closure', close_followup='1', requester_close_mode='followup')
before = (cfg('close_followup'), cfg('notify_requester_close'), cfg('gitlab_url'), cfg('title_template'))
s, _, hh = save_tab('creation', title_template='[T #{id}] {title}', close_followup='0', notify_requester_close_text='hacked', gitlab_url='http://evil.example')
after = (cfg('close_followup'), cfg('notify_requester_close'), cfg('gitlab_url'), cfg('title_template'))
check('creation tab saves its own settings (title template)', after[3] == '[T #{id}] {title}', after[3])
check('creation tab leaves the other tabs alone, even with forged fields', after[:3] == before[:3] and cfg('notify_requester_close_text') != 'hacked', f'{before[:3]} -> {after[:3]}')
save_tab('creation', title_template='[GLPI #{id}] {title}')
check('self-service profile is not listed', 'Self-Service' not in t4)
a.req('/plugins/gitlabissues/Config/Rights', {'_glpi_csrf_token': a.ajax_token(), 'profiles[]': ['6', '2'], 'rights[6][]': ['4']})
check('rights table saves; ticking only "create" also stores "read" (5); unticked profile -> 0', stored_rights(6) == '5' and stored_rights(2) == '0', f'{stored_rights(6)} / {stored_rights(2)}')
a.req('/plugins/gitlabissues/Config/Rights', {'_glpi_csrf_token': a.ajax_token(), 'profiles[]': ['1'], 'rights[1][]': ['1', '2', '4', '8']})
check('a self-service profile cannot be given rights through the form', stored_rights(1) in ('0', ''), stored_rights(1))
a.req('/plugins/gitlabissues/Config/Rights', {'_glpi_csrf_token': a.ajax_token(), 'profiles[]': ['6'], 'rights[6][]': ['1', '2', '4', '8']})
check('technician rights restored (15)', stored_rights(6) == '15', stored_rights(6))
_, pg, _ = a.req('/plugins/gitlabissues/Config'); check('after saving the rights tab, the page reopens tab 4', opened_tab(pg) == '4', str(opened_tab(pg)))

# solved or closed ticket: no form, an explanation, and a forged request is refused too; an open one keeps the form
import os
def ticket_with_status(status):
    tid = int(subprocess.run(['php', os.path.join(os.path.dirname(os.path.abspath(__file__)), 'make_ticket.php'), '2'], capture_output=True, text=True, cwd=os.environ.get('GLPI_ROOT', '/opt/glpi-env/glpi')).stdout.strip().splitlines()[-1])
    subprocess.run(['mysql', '-uroot', 'glpi', '-e', f'update glpi_tickets set status={status} where id={tid}'])
    return tid
for status, label, locked in ((2, 'processing (assigned)', False), (5, 'solved', True), (6, 'closed', True)):
    tid_ = ticket_with_status(status)
    s, page, _ = a.req(f'/ajax/common.tabs.php?_target=/front/ticket.form.php&_itemtype=Ticket&_glpi_tab={TAB}&id={tid_}', headers={'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': tok or ''})
    has_form = 'name="project_id"' in page and 'name="followup"' in page
    check(f'{label} ticket: create form {"hidden" if locked else "shown"}', s == 200 and has_form == (not locked), f'{s} form={has_form}')
    if locked:
        check(f'{label} ticket: the tab explains why', 'solved, closed or deleted ticket' in page or 'résolu, clos ou placé dans la corbeille' in page, page[-250:])
        n_before = len(mock('/_mock/state')['issues'])
        a.req(f'/plugins/gitlabissues/Ticket/{tid_}/Issue', {'_glpi_csrf_token': a.ajax_token(), 'project_id': '2', 'allow_duplicate': '1', 'followup': 'none'})
        check(f'{label} ticket: a forged creation request is refused (no issue created)', len(mock('/_mock/state')['issues']) == n_before)

# unlink follows the creation rule: possible on an open ticket, frozen once solved / closed
def link_on(tid_):
    subprocess.run(['mysql', '-uroot', 'glpi', '-e', f"insert into glpi_plugin_gitlabissues_links (tickets_id,users_id,gitlab_project_id,project_path,issue_iid,issue_id,issue_url,issue_title,state,date_creation,date_mod) values ({tid_},2,1,'group/app',800000+{tid_},1,'https://g.example/x','probe','opened',now(),now())"])
    return subprocess.run(['mysql', '-uroot', 'glpi', '-N', '-e', f'select max(id) from glpi_plugin_gitlabissues_links where tickets_id={tid_}'], capture_output=True, text=True).stdout.strip()
for status, label, frozen in ((2, 'processing (assigned)', False), (5, 'solved', True), (6, 'closed', True)):
    tid_ = ticket_with_status(2)
    lid = link_on(tid_)
    subprocess.run(['mysql', '-uroot', 'glpi', '-e', f'update glpi_tickets set status={status} where id={tid_}'])
    s, page, _ = a.req(f'/ajax/common.tabs.php?_target=/front/ticket.form.php&_itemtype=Ticket&_glpi_tab={TAB}&id={tid_}', headers={'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': tok or ''})
    check(f'{label} ticket: "Unlink" button {"hidden" if frozen else "shown"}', (f'/Link/{lid}/Unlink' in page) == (not frozen), f'{s}')
    check(f'{label} ticket: the linked issue stays listed', 'group/app#' in page)
    a.req(f'/plugins/gitlabissues/Link/{lid}/Unlink', {'_glpi_csrf_token': a.ajax_token()})
    still = subprocess.run(['mysql', '-uroot', 'glpi', '-N', '-e', f'select count(*) from glpi_plugin_gitlabissues_links where id={lid}'], capture_output=True, text=True).stdout.strip()
    check(f'{label} ticket: unlink request {"refused, link kept" if frozen else "accepted"}', still == ('1' if frozen else '0'), still)
s, pg, _ = a.req('/front/ticket.form.php?id=%d' % ticket_id)

# create via the real form flow
s, page, _ = a.req(f'/ajax/common.tabs.php?_target=/front/ticket.form.php&_itemtype=Ticket&_glpi_tab={TAB}&id={ticket_id}', headers={'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': tok or ''})
ftok = a.token(page)
s, body, h = a.req(f'/plugins/gitlabissues/Ticket/{ticket_id}/Issue', {'_glpi_csrf_token': ftok, 'project_id': '3', 'labels': 'web', 'followup': 'public'})
check('create issue via form -> redirect to ticket tab', s in (302, 303) and 'forcetab' in h.get('Location', '') , str(s) + h.get('Location', ''))
issues = mock('/_mock/state')['issues']
web_key = next((k for k, v in issues.items() if k.startswith('3/') and 'web' in v['labels']), None)
check('issue exists in GitLab project 3 with web label', web_key is not None, str(list(issues)))
web_iid = web_key.split('/')[1] if web_key else '0'
s, page, _ = a.req(f'/ajax/common.tabs.php?_target=/front/ticket.form.php&_itemtype=Ticket&_glpi_tab={TAB}&id={ticket_id}', headers={'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': tok or ''})
check('new issue now listed', f'other/tools#{web_iid}' in page)
ids = re.findall(r'/plugins/gitlabissues/Link/(\d+)/Refresh', page)
check('refresh button present', bool(ids))
class _M:  # the newest link (list is ordered by id): the one just created
    def group(self, i): return ids[-1]
m = _M()
s, body, h = a.req(f'/plugins/gitlabissues/Link/{m.group(1)}/Refresh', {'_glpi_csrf_token': form_token(page, f'Link/{m.group(1)}/Refresh')})
check('refresh works', s in (302, 303), str(s) + body[:200])
# like a browser: the redirect reloads the tab, which brings fresh single-use CSRF tokens
s, page, _ = a.req(f'/ajax/common.tabs.php?_target=/front/ticket.form.php&_itemtype=Ticket&_glpi_tab={TAB}&id={ticket_id}', headers={'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': tok or ''})
s, body, h = a.req(f'/plugins/gitlabissues/Link/{m.group(1)}/Unlink', {'_glpi_csrf_token': form_token(page, f'Link/{m.group(1)}/Unlink')})
check('unlink works', s in (302, 303))
s, page, _ = a.req(f'/ajax/common.tabs.php?_target=/front/ticket.form.php&_itemtype=Ticket&_glpi_tab={TAB}&id={ticket_id}', headers={'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': tok or ''})
check('unlinked issue no longer listed', f'other/tools#{web_iid}' not in page and f'/Link/' in page)

s, page, _ = a.req('/front/profile.form.php?id=4&forcetab=' + TAB.replace('Link', 'Profile'))
s, page, _ = a.req(f'/ajax/common.tabs.php?_target=/front/profile.form.php&_itemtype=Profile&_glpi_tab={urllib.parse.quote(chr(92).join(["GlpiPlugin","Gitlabissues","Profile"]) + "$1", safe="")}&id=4', headers={'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': tok or ''})
check('profile rights tab renders', s == 200 and 'Rights' not in page[:0] and 'name="rights[]"' in page, page[:200])
ptok = a.token(page)
s, body, h = a.req('/plugins/gitlabissues/Profile/4/Rights', {'_glpi_csrf_token': ptok, 'rights[]': '1'})
check('profile rights saved', s in (302, 303), str(s))
ptok = a.ajax_token()
s, body, h = a.req('/plugins/gitlabissues/Profile/4/Rights', {'_glpi_csrf_token': ptok, 'rights[]': ['1', '2', '4', '8']})
check('profile rights restored (all four)', s in (302, 303), str(s))
import subprocess
ptok = a.ajax_token()
a.req('/plugins/gitlabissues/Profile/4/Rights', {'_glpi_csrf_token': ptok, 'rights[]': ['4']})
stored = subprocess.run(['mysql', '-uroot', 'glpi', '-N', '-e', "select rights from glpi_profilerights where profiles_id=4 and name='plugin_gitlabissues_issue'"], capture_output=True, text=True).stdout.strip()
check('profile form: ticking only "create" also grants "read" (stored 5)', stored == '5', stored)
ptok = a.ajax_token()
a.req('/plugins/gitlabissues/Profile/4/Rights', {'_glpi_csrf_token': ptok, 'rights[]': ['1', '2', '4', '8']})

print('== unprivileged user (post-only, no plugin right, no config right)')
u = Client()
check('login post-only', u.login('post-only', 'postonly'))
for n in (1, 2, 3, 4):
    s, c = config_tab(u, n)
    check(f'configuration tab {n}: content not given to an unprivileged user', 'gitlab_url' not in c and '<form' not in c and 'name="profiles[]"' not in c, f'{s} {len(c)} bytes')
for path, label in [('/plugins/gitlabissues/Config', 'config page'), ('/plugins/gitlabissues/Projects?term=a', 'project search')]:
    s, body, _ = u.req(path, headers={'X-Requested-With': 'XMLHttpRequest'})
    check(f'{label} denied', s in (302, 401, 403), str(s))
n_before = len(mock('/_mock/state')['issues'])
utok = u.ajax_token()   # a VALID token: the refusal must come from the rights check, not from CSRF
check('post-only user has a valid CSRF token', bool(utok))
s, body, _ = u.req(f'/plugins/gitlabissues/Ticket/{ticket_id}/Issue', {'_glpi_csrf_token': utok, 'project_id': '1'})
check('create issue denied by rights (valid CSRF)', s in (302, 403, 404) and len(mock('/_mock/state')['issues']) == n_before, str(s))
utok = u.ajax_token()
u.req('/plugins/gitlabissues/Config/Rights', {'_glpi_csrf_token': utok, 'profiles[]': ['6'], 'rights[6][]': []})
check('rights table: change denied for an unprivileged user (valid CSRF)', stored_rights(6) == '15', stored_rights(6))
utok = u.ajax_token()
s, body, _ = u.req('/plugins/gitlabissues/Profile/4/Rights', {'_glpi_csrf_token': utok, 'rights[]': '15'})
check('profile rights change denied by rights (valid CSRF)', s in (302, 403))

sys.exit(summary())
