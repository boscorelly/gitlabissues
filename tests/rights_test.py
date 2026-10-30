#!/usr/bin/env python3
"""Access-rights matrix on the real GLPI web app: who can see / create / refresh / unlink, per right combination.
Needs GLPI on :8088, the mock GitLab on :8099, MariaDB, and `integration.php` run before (it creates tickets and links)."""
import os, sys, re, json, subprocess, urllib.parse
HERE = os.path.dirname(os.path.abspath(__file__))
from webclient import *

R, U, C, D = 1, 2, 4, 8   # READ, UPDATE, CREATE, DELETE
TAB = urllib.parse.quote('GlpiPlugin\\Gitlabissues\\Link$1', safe='')

def sql(q):
    return subprocess.run(['mysql', '-uroot', 'glpi', '-N', '-e', q], capture_output=True, text=True).stdout.strip()

ticket_id = int(sys.argv[1])
tech_profile = int(sql("select profiles_id from glpi_profiles_users where users_id=(select id from glpi_users where name='tech') limit 1"))
original = sql(f"select rights from glpi_profilerights where profiles_id={tech_profile} and name='plugin_gitlabissues_issue'")

def set_rights(v):
    sql(f"update glpi_profilerights set rights={v} where profiles_id={tech_profile} and name='plugin_gitlabissues_issue'")

def n_issues(): return len(mock('/_mock/state')['issues'])
def link_exists(i): return sql(f"select count(*) from glpi_plugin_gitlabissues_links where id={i}") == '1'
def new_open_link():
    # a throw-away open link on the test ticket (inserted directly: this test is about access, not about GitLab)
    sql(f"insert into glpi_plugin_gitlabissues_links (tickets_id,users_id,gitlab_project_id,project_path,issue_iid,issue_id,issue_url,issue_title,state,date_creation,date_mod) "
        f"values ({ticket_id},2,1,'group/app',900+floor(rand()*90000),1,'http://x','probe','opened',now(),now())")
    return int(sql("select max(id) from glpi_plugin_gitlabissues_links"))

def session(user, pwd):
    c = Client(); assert c.login(user, pwd), 'login failed ' + user
    return c

def tab(c):
    hdr = {'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': c.ajax_token() or ''}
    return c.req(f'/ajax/common.tabs.php?_target=/front/ticket.form.php&_itemtype=Ticket&_glpi_tab={TAB}&id={ticket_id}', headers=hdr)

def post(c, path, data=None):
    return c.req('/plugins/gitlabissues' + path, dict({'_glpi_csrf_token': c.ajax_token()}, **(data or {})))

def tab_label_visible(c):
    s, page, _ = c.req(f'/front/ticket.form.php?id={ticket_id}')
    return s == 200 and 'Gitlabissues' in page

def try_all(c, label, expect):
    """expect: dict with keys see_tab, create, refresh, unlink, search -> bool"""
    s, content, _ = tab(c)
    sees = s == 200 and 'GitLab' in content and ('Create GitLab' in content or 'Créer une issue' in content or 'issue' in content.lower())
    has_form = s == 200 and 'name="followup"' in content
    has_refresh = s == 200 and '/Refresh' in content
    has_unlink = s == 200 and '/Unlink' in content
    check(f'[{label}] tab content shown' if expect['see_tab'] else f'[{label}] tab content hidden', (s == 200 and bool(content.strip()) and 'GitLab' in content) == expect['see_tab'], f'status {s}, {len(content)} bytes')
    check(f'[{label}] create form {"shown" if expect["create"] else "hidden"}', has_form == expect['create'])
    check(f'[{label}] refresh/unlink buttons match rights', (has_refresh == expect['refresh']) and (has_unlink == expect['unlink']), f'refresh={has_refresh} unlink={has_unlink}')

    before = n_issues()
    s, _, h = post(c, f'/Ticket/{ticket_id}/Issue', {'project_id': '2', 'allow_duplicate': '1'})
    created = n_issues() == before + 1
    check(f'[{label}] POST create -> {"allowed" if expect["create"] else "refused, no issue created"}', created == expect['create'], f'status {s}, issues {before}->{n_issues()}')

    l = new_open_link()
    s, _, _ = post(c, f'/Link/{l}/Refresh')
    refreshed = sql(f"select date_last_check is not null from glpi_plugin_gitlabissues_links where id={l}") == '1'
    # Refresh on a bogus issue would give an error but still stamps date_last_check only when allowed
    check(f'[{label}] POST refresh -> {"allowed" if expect["refresh"] else "refused"}', refreshed == expect['refresh'], f'status {s}')
    s, _, _ = post(c, f'/Link/{l}/Unlink')
    gone = not link_exists(l)
    check(f'[{label}] POST unlink -> {"allowed" if expect["unlink"] else "refused, link kept"}', gone == expect['unlink'], f'status {s}')
    if not gone: sql(f"delete from glpi_plugin_gitlabissues_links where id={l}")

    s, body, _ = c.req('/plugins/gitlabissues/Projects?term=a', headers={'X-Requested-With': 'XMLHttpRequest'})
    check(f'[{label}] project search -> {"allowed" if expect["search"] else "refused"}', (s == 200) == expect['search'], f'status {s}')
    s, _, _ = c.req('/plugins/gitlabissues/Config')
    check(f'[{label}] configuration page refused (needs GLPI "config" right)', s in (302, 403))

cases = [
    ('no plugin right',  0,         dict(see_tab=False, create=False, refresh=False, unlink=False, search=False)),
    ('READ only',        R,         dict(see_tab=True,  create=False, refresh=False, unlink=False, search=False)),
    ('READ+CREATE',      R | C,     dict(see_tab=True,  create=True,  refresh=False, unlink=False, search=True)),
    ('READ+UPDATE',      R | U,     dict(see_tab=True,  create=False, refresh=True,  unlink=False, search=False)),
    ('READ+DELETE',      R | D,     dict(see_tab=True,  create=False, refresh=False, unlink=True,  search=False)),
    ('CREATE without READ', C,      dict(see_tab=False, create=False, refresh=False, unlink=False, search=False)),
]
try:
    for label, rights, expect in cases:
        print(f'== technician, plugin rights = {rights} ({label})')
        set_rights(rights)
        c = session('tech', 'tech')
        try_all(c, label, expect)
    print('== tab label on the ticket page follows the rights')
    set_rights(0); check('no right: tab not listed', not tab_label_visible(session('tech', 'tech')))
    set_rights(R);  check('READ: tab listed', tab_label_visible(session('tech', 'tech')))

    print('== self-service user (helpdesk interface) even with every plugin right')
    ps = int(sql("select profiles_id from glpi_profiles_users where users_id=(select id from glpi_users where name='post-only') limit 1"))
    had = sql(f"select count(*) from glpi_profilerights where profiles_id={ps} and name='plugin_gitlabissues_issue'")
    sql(f"update glpi_profilerights set rights=15 where profiles_id={ps} and name='plugin_gitlabissues_issue'")
    uid = sql("select id from glpi_users where name='post-only'")
    own = subprocess.run(['php', os.path.join(HERE, 'make_ticket.php'), uid], capture_output=True, text=True, cwd=os.environ.get('GLPI_ROOT', '/opt/glpi-env/glpi')).stdout.strip().splitlines()[-1]
    own = int(own)
    c = session('post-only', 'postonly')
    s, page, _ = c.req(f'/front/ticket.form.php?id={own}')
    check('self-service user can open his own ticket (so a refusal below is about rights)', s == 200 and 'own ticket' in page, f'status {s}')
    before = n_issues()
    post(c, f'/Ticket/{own}/Issue', {'project_id': '2', 'allow_duplicate': '1'})
    check('self-service: create refused on his own ticket even with rights set in database', n_issues() == before)
    s, _, _ = c.req('/plugins/gitlabissues/Projects?term=a', headers={'X-Requested-With': 'XMLHttpRequest'})
    check('self-service: project search refused', s != 200)
    sql(f"update glpi_profilerights set rights=0 where profiles_id={ps} and name='plugin_gitlabissues_issue'")
finally:
    set_rights(original or 15)

sys.exit(summary())
