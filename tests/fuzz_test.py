#!/usr/bin/env python3
"""Robustness: unexpected input on every route must never give a 500 nor a PHP warning, and must change nothing."""
import os, sys, subprocess
from webclient import *
LOG = os.path.join(os.environ.get('GLPI_ROOT', '/opt/glpi-env/glpi'), 'files/_log/php-errors.log')
q = lambda s: subprocess.run(['mysql', '-uroot', 'glpi', '-N', '-e', s], capture_output=True, text=True).stdout.strip()
snapshot = lambda: q("select group_concat(name, '=', value order by name separator '|') from glpi_configs where context='plugin:gitlabissues'") + q("select group_concat(profiles_id, ':', rights order by profiles_id) from glpi_profilerights where name='plugin_gitlabissues_issue'")
a = Client(); assert a.login('glpi', 'glpi')
tid = int(open('/tmp/last_ticket_id').read())
log_before = os.path.getsize(LOG) if os.path.exists(LOG) else 0
before = snapshot()
cases = [
    ('POST', '/Config', {'_tab': ['connection']}),
    ('POST', '/Config', {'_tab': 'bogus', 'gitlab_url': 'http://evil'}),
    ('POST', '/Config', {'_tab': 'rights', 'gitlab_url': 'http://evil'}),
    ('POST', '/Config', {'_tab': 'connection', 'gitlab_url[]': 'http://evil', 'gitlab_token[]': 'x'}),
    ('POST', '/Config', {'_tab': 'connection', 'gitlab_url': 'javascript:alert(1)', 'gitlab_token': 'x'}),
    ('POST', '/Config', {'_tab': 'creation', 'title_template[]': 'x', 'default_project_id': 'abc', 'max_attachment_mb': '-5', 'default_labels[a]': 'b'}),
    ('POST', '/Config', {'_tab': 'closure', 'requester_close_mode[]': 'solve', 'solutiontypes_id[]': '1', 'actor_users_id': '999999999999999999999'}),
    ('POST', '/Config/Rights', {'profiles': 'x', 'rights': 'y'}),
    ('POST', '/Config/Rights', {'profiles[]': ['999999', 'abc'], 'rights[999999][]': ['15'], 'rights[abc][]': ['1']}),
    ('POST', '/Profile/999999/Rights', {'rights[]': ['15']}),
    ('POST', '/Profile/6/Rights', {'rights': 'x'}),
    ('POST', f'/Ticket/{tid}/Issue', {'project_id[]': '1', 'labels[]': 'x', 'followup[]': 'public', 'notify_requester[]': '1'}),
    ('POST', f'/Ticket/{tid}/Issue', {'project_id': '-3', 'allow_duplicate': 'maybe'}),
    ('POST', '/Ticket/999999999/Issue', {'project_id': '1'}),
    ('POST', '/Link/999999999/Refresh', {}),
    ('POST', '/Link/999999999/Unlink', {}),
    ('GET', '/Projects?term[]=x&page[]=1', None),
    ('GET', '/Projects?page=-7&term=%00%27%22', None),
]
issues_before = len(mock('/_mock/state')['issues'])
for method, path, data in cases:
    if method == 'POST':
        d = dict({'_glpi_csrf_token': a.ajax_token()}, **data)
        s, body, h = a.req('/plugins/gitlabissues' + path, d)
    else:
        s, body, h = a.req('/plugins/gitlabissues' + path, headers={'X-Requested-With': 'XMLHttpRequest'})
    check(f'{method} {path} {sorted((data or {}).keys())}: no server error', s < 500, str(s))
after = snapshot()
check('no setting nor right changed by the invalid requests', after == before, '')
_, c2 = config_tab(a, 2)
s, _, _ = a.req('/plugins/gitlabissues/Config', {'_glpi_csrf_token': a.token(c2), '_tab': 'creation', 'max_attachment_mb': '', 'title_template': '[GLPI #{id}] {title}', 'creation_followup': '1', 'creation_followup_private': '1', 'upload_attachments': '1', 'include_people': '1'})
check('an emptied number field is still accepted (back to the default)', q("select value from glpi_configs where context='plugin:gitlabissues' and name='max_attachment_mb'") == '10')
check('no GitLab issue created by the invalid requests', len(mock('/_mock/state')['issues']) == issues_before)
new_log = open(LOG).read()[log_before:] if os.path.exists(LOG) else ''
plugin_lines = [l for l in new_log.splitlines() if 'gitlabissues' in l or 'Gitlabissues' in l]
check('no PHP warning/error coming from the plugin', plugin_lines == [], '\n'.join(plugin_lines[:6]))
sys.exit(summary())
