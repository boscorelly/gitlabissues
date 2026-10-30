#!/usr/bin/env python3
"""What a self-service requester (user "post-only") really reads in his own tickets, per setting.
Needs GLPI on :8088, mock GitLab on :8099, MariaDB. Creates its own tickets."""
import os, re, json, html, subprocess, urllib.parse
from webclient import *
HERE = os.path.dirname(os.path.abspath(__file__))
out = subprocess.run(['php', os.path.join(HERE, 'visibility_setup.php')], capture_output=True, text=True, cwd=os.environ.get('GLPI_ROOT', '/opt/glpi-env/glpi')).stdout
ids = json.loads(re.search(r'IDS=(\{.*\})', out).group(1))

u = Client(); assert u.login('post-only', 'postonly')
TAB = urllib.parse.quote('GlpiPlugin\\Gitlabissues\\Link$1', safe='')
HDR = lambda: {'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': u.ajax_token() or ''}
def text(h): return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', html.unescape(h)))
def read(tid):
    s, p, _ = u.req(f'/ajax/common.tabs.php?_target=/front/ticket.form.php&_itemtype=Ticket&_glpi_tab=Ticket%24main&id={tid}', headers=HDR())
    return s, p, text(p)
def leaks(p, t):
    return [w for w in ('group/', 'other/tools', '127.0.0.1', 'Jane', 'Merge request', 'merge_requests', 'Fix the thing') if w in p or w in t]

for k, label in {'A': 'defaults: no notice', 'B': 'creation notice ON', 'C': 'closure notice ON', 'D': 'closure: solve the ticket ON'}.items():
    tid = ids[k]
    s, p, t = read(tid)
    check(f'[{k}] {label}: the requester opens his ticket', s == 200 and 'Mon souci' in t, str(s))
    check(f'[{k}] {label}: no GitLab detail visible (project, link, developer, merge request)', leaks(p, t) == [], str(leaks(p, t)))
    s2, tab, _ = u.req(f'/ajax/common.tabs.php?_target=/front/ticket.form.php&_itemtype=Ticket&_glpi_tab={TAB}&id={tid}', headers=HDR())
    s3, page, _ = u.req(f'/front/ticket.form.php?id={tid}')
    check(f'[{k}] {label}: no plugin tab for the requester', 'Gitlabissues' not in page and not tab.strip())

_, _, tA = read(ids['A'])
check('[A] nothing is said about the request being forwarded', 'transmise aux personnes' not in tA and 'forwarded to the people' not in tA)
_, _, tB = read(ids['B'])
check('[B] the requester reads the neutral "forwarded" message', 'transmise aux personnes' in tB or 'forwarded to the people' in tB, tB[-200:])
_, _, tC = read(ids['C'])
check('[C] the requester reads the neutral "complete" message', 'traitement technique lié à votre demande est terminé' in tC or 'technical work related to your request is complete' in tC, tC[-200:])
_, _, tD = read(ids['D'])
check('[D] the solution he reads is the neutral one', 'traitement technique lié à votre demande est terminé' in tD or 'technical work related to your request is complete' in tD, tD[-200:])
sys.exit(summary())
