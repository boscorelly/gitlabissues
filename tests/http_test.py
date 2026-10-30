#!/usr/bin/env python3
"""HTTP-level test: real GLPI web app + mock GitLab. Needs GLPI on :8088 and the mock on :8099."""
import re, sys, json, urllib.request, urllib.parse, http.cookiejar, html

BASE = 'http://127.0.0.1:8088'
ok_n = fail_n = 0

def check(name, cond, detail=''):
    global ok_n, fail_n
    if cond: ok_n += 1; print('  ok   - ' + name)
    else: fail_n += 1; print('  FAIL - ' + name + (f'  [{detail[:300]}]' if detail else ''))

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None

class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)
        self.csrf = None
    def req(self, path, data=None, headers=None, method=None):
        url = path if path.startswith('http') else BASE + path
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        r = urllib.request.Request(url, data=body, headers=headers or {}, method=method)
        try:
            resp = self.op.open(r, timeout=60)
        except urllib.error.HTTPError as e:
            resp = e
        return resp.status if hasattr(resp, 'status') else resp.code, resp.read().decode('utf-8', 'replace'), dict(resp.headers)
    def token(self, page):
        m = re.search(r'name="_glpi_csrf_token"\s+value="([0-9a-f]+)"', page) or re.search(r'value="([0-9a-f]{64})"\s+name="_glpi_csrf_token"', page)
        m = m or re.search(r'csrf[_-]token[^0-9a-f]{1,20}([0-9a-f]{64})', page, re.I)
        return m.group(1) if m else None
    def login(self, user, pwd):
        s, page, _ = self.req('/')
        tok = self.token(page)
        s, body, h = self.req('/front/login.php', {'_glpi_csrf_token': tok, 'login_name': user, 'login_password': pwd, 'auth': 'local', 'submit': 'Post'})
        return s in (302, 303)
    def ajax_token(self):
        s, page, _ = self.req('/front/central.php')
        m = re.search(r'property="glpi:csrf_token"\s+content="([0-9a-f]+)"', page)
        return m.group(1) if m else None

def form_token(page, action):
    # the CSRF token of the <form> posting to `action` (every form has its own single-use token)
    m = re.search(r'action="[^"]*' + re.escape(action) + r'"[^>]*>\s*<input type="hidden" name="_glpi_csrf_token" value="([0-9a-f]+)"', page)
    return m.group(1) if m else None

def mock(path, body=None):
    r = urllib.request.Request('http://127.0.0.1:8099' + path, data=json.dumps(body or {}).encode() if path != '/_mock/state' else None,
                               method='GET' if path == '/_mock/state' else 'POST', headers={'Content-Type': 'application/json'})
    return json.loads(urllib.request.urlopen(r).read())

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

s, page, _ = a.req('/front/ticket.form.php?id=%d' % ticket_id)
check('ticket page lists the tab', s == 200 and ('GitLab issue' in page or 'Issue GitLab' in page or 'Issues GitLab' in page), str(s))

s, page, _ = a.req('/plugins/gitlabissues/Config')
check('config page renders', s == 200 and ('GitLab connection' in page or 'Connexion à GitLab' in page) and 'value="http://127.0.0.1:8099"' in page, str(s) + page[:200])
check('token never sent back to the browser', 'test-token' not in page)
ctok = a.token(page)

s, page, h = a.req('/plugins/gitlabissues/Config', {'_glpi_csrf_token': ctok, 'gitlab_url': 'http://127.0.0.1:8099', 'gitlab_token': '', '_test': '1',
                    'title_template': '[GLPI #{id}] {title}', 'default_labels': 'glpi', 'max_attachment_mb': '10', 'creation_followup': '1', 'close_followup': '1',
                    'default_project_id': '1', 'default_project_path': 'group/app', 'include_people': '1', 'upload_attachments': '1'})
check('config save+test redirects', s in (302, 303), str(s) + page[:300])
s, page, _ = a.req('/plugins/gitlabissues/Config')
check('connection success message shown', 'Connection successful' in page or 'Connexion réussie' in page, re.sub(r'\s+', ' ', re.sub('<[^>]+>', ' ', page))[:300])

s, body, _ = a.req('/plugins/gitlabissues/Config', {'gitlab_url': 'http://127.0.0.1:8099'})
check('config POST without CSRF token rejected', s in (400, 403), str(s))

s, body, _ = a.req('/plugins/gitlabissues/Projects?term=infra', headers={'X-Requested-With': 'XMLHttpRequest'})
check('project search endpoint', s == 200 and json.loads(body)['results'][0]['text'] == 'group/infra', body[:200])

# create via the real form flow
s, page, _ = a.req(f'/ajax/common.tabs.php?_target=/front/ticket.form.php&_itemtype=Ticket&_glpi_tab={TAB}&id={ticket_id}', headers={'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': tok or ''})
ftok = a.token(page)
s, body, h = a.req(f'/plugins/gitlabissues/Ticket/{ticket_id}/Issue', {'_glpi_csrf_token': ftok, 'project_id': '3', 'labels': 'web'})
check('create issue via form -> redirect to ticket tab', s in (302, 303) and 'forcetab' in h.get('Location', '') , str(s) + h.get('Location', ''))
issues = mock('/_mock/state')['issues']
check('issue exists in GitLab project 3 with web label', '3/1' in issues and 'web' in issues['3/1']['labels'], str(list(issues)))
s, page, _ = a.req(f'/ajax/common.tabs.php?_target=/front/ticket.form.php&_itemtype=Ticket&_glpi_tab={TAB}&id={ticket_id}', headers={'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': tok or ''})
check('new issue now listed', 'other/tools#1' in page)
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
check('unlinked issue no longer listed', 'other/tools#1' not in page)

s, page, _ = a.req('/front/profile.form.php?id=4&forcetab=' + TAB.replace('Link', 'Profile'))
s, page, _ = a.req(f'/ajax/common.tabs.php?_target=/front/profile.form.php&_itemtype=Profile&_glpi_tab={urllib.parse.quote(chr(92).join(["GlpiPlugin","Gitlabissues","Profile"]) + "$1", safe="")}&id=4', headers={'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': tok or ''})
check('profile rights tab renders', s == 200 and 'Rights' not in page[:0] and 'name="rights[]"' in page, page[:200])
ptok = a.token(page)
s, body, h = a.req('/plugins/gitlabissues/Profile/4/Rights', {'_glpi_csrf_token': ptok, 'rights[]': '1'})
check('profile rights saved', s in (302, 303), str(s))
ptok = a.ajax_token()
s, body, h = a.req('/plugins/gitlabissues/Profile/4/Rights', {'_glpi_csrf_token': ptok, 'rights[]': ['1', '2', '4', '8']})
check('profile rights restored (all four)', s in (302, 303), str(s))

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
s, body, _ = u.req('/plugins/gitlabissues/Profile/4/Rights', {'_glpi_csrf_token': utok, 'rights[]': '15'})
check('profile rights change denied by rights (valid CSRF)', s in (302, 403))

print(f'\n{ok_n} passed, {fail_n} failed')
sys.exit(1 if fail_n else 0)
