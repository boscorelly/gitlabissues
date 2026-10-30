"""HTTP-level test: real GLPI web app + mock GitLab. Needs GLPI on :8088 and the mock on :8099."""
import sys, re, json, subprocess, urllib.parse
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
check('ticket tab has the GitLab icon', 'ti-brand-gitlab' in tab_entry('/front/ticket.form.php?id=%d' % ticket_id, 'Gitlabissues'))
check('profile tab has the GitLab icon', 'ti-brand-gitlab' in tab_entry('/front/profile.form.php?id=4', 'Gitlabissues'), tab_entry('/front/profile.form.php?id=4', 'Gitlabissues')[-200:])

s, page, _ = a.req('/front/ticket.form.php?id=%d' % ticket_id)
check('ticket page lists the tab', s == 200 and ('GitLab issue' in page or 'Issue GitLab' in page or 'Issues GitLab' in page), str(s))

s, page, _ = a.req('/plugins/gitlabissues/Config')
check('config page renders', s == 200 and ('GitLab connection' in page or 'Connexion à GitLab' in page) and 'value="http://127.0.0.1:8099"' in page, str(s) + page[:200])
check('token never sent back to the browser', 'test-token' not in page)
ctok = a.token(page)

s, page, h = a.req('/plugins/gitlabissues/Config', {'_glpi_csrf_token': ctok, 'gitlab_url': 'http://127.0.0.1:8099', 'gitlab_token': '', '_test': '1',
                    'title_template': '[GLPI #{id}] {title}', 'default_labels': 'glpi', 'max_attachment_mb': '10', 'creation_followup': '1', 'creation_followup_private': '1', 'close_followup': '1',
                    'default_project_id': '1', 'default_project_path': 'group/app', 'include_people': '1', 'upload_attachments': '1'})
check('config save+test redirects', s in (302, 303), str(s) + page[:300])
s, page, _ = a.req('/plugins/gitlabissues/Config')
check('connection success message shown', 'Connection successful' in page or 'Connexion réussie' in page, re.sub(r'\s+', ' ', re.sub('<[^>]+>', ' ', page))[:300])

s, body, _ = a.req('/plugins/gitlabissues/Config', {'gitlab_url': 'http://127.0.0.1:8099'})
check('config POST without CSRF token rejected', s in (400, 403), str(s))

s, body, _ = a.req('/plugins/gitlabissues/Projects?term=infra', headers={'X-Requested-With': 'XMLHttpRequest'})
check('project search endpoint', s == 200 and json.loads(body)['results'][0]['text'] == 'group/infra', body[:200])

def stored_rights(pid):
    return subprocess.run(['mysql', '-uroot', 'glpi', '-N', '-e', f"select rights from glpi_profilerights where profiles_id={pid} and name='plugin_gitlabissues_issue'"], capture_output=True, text=True).stdout.strip()

s, page, _ = a.req('/plugins/gitlabissues/Config')
check('configuration page shows the rights per profile', 'name="profiles[]"' in page and 'Technician' in page and 'name="rights[6][]"' in page)
check('configuration page has the requester messages', 'name="notify_requester"' in page and 'name="notify_requester_close_text"' in page)
check('self-service profile is not listed', 'Self-Service' not in page)
a.req('/plugins/gitlabissues/Config/Rights', {'_glpi_csrf_token': a.ajax_token(), 'profiles[]': ['6', '2'], 'rights[6][]': ['4']})
check('rights table saves; ticking only "create" also stores "read" (5); unticked profile -> 0', stored_rights(6) == '5' and stored_rights(2) == '0', f'{stored_rights(6)} / {stored_rights(2)}')
a.req('/plugins/gitlabissues/Config/Rights', {'_glpi_csrf_token': a.ajax_token(), 'profiles[]': ['1'], 'rights[1][]': ['1', '2', '4', '8']})
check('a self-service profile cannot be given rights through the form', stored_rights(1) in ('0', ''), stored_rights(1))
a.req('/plugins/gitlabissues/Config/Rights', {'_glpi_csrf_token': a.ajax_token(), 'profiles[]': ['6'], 'rights[6][]': ['1', '2', '4', '8']})
check('technician rights restored (15)', stored_rights(6) == '15', stored_rights(6))

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
