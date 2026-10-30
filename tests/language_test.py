#!/usr/bin/env python3
"""Language rule: the user's language if the plugin has a catalogue for it, otherwise English
(even when the instance's default language has a catalogue). Needs GLPI on :8088 and MariaDB."""
import os, re, sys, html, subprocess, tempfile, urllib.parse
from webclient import *
HERE = os.path.dirname(os.path.abspath(__file__))
LOCALES = os.path.join(os.path.dirname(HERE), 'locales')
q = lambda s: subprocess.run(['mysql', '-uroot', 'glpi', '-N', '-e', s], capture_output=True, text=True).stdout.strip()
def text(p): return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', html.unescape(p)))

def pages(lang):
    q(f"update glpi_users set language={'NULL' if lang is None else repr(lang)} where name='glpi'")
    a = Client(); assert a.login('glpi', 'glpi')
    _, cfg, _ = a.req('/plugins/gitlabissues/Config')
    _, prof, _ = a.req('/front/profile.form.php?id=4')
    tid = open('/tmp/last_ticket_id').read().strip()
    _, tick, _ = a.req('/front/ticket.form.php?id=' + tid)
    return text(cfg), text(prof), text(tick)

default = q("select value from glpi_configs where context='core' and name='language'")
print(f'instance default language: {default}')
FR = ('Connexion à GitLab', 'Issues GitLab')
EN = ('GitLab connection', 'GitLab issues')
try:
    for lang, expect in ((None, 'fr'), ('fr_FR', 'fr'), ('en_GB', 'en'), ('en_US', 'en'), ('de_DE', 'en'), ('es_ES', 'en'), ('ja_JP', 'en')):
        cfg, prof, tick = pages(lang)
        want = FR if expect == 'fr' else EN
        label = 'French' if expect == 'fr' else 'English'
        if lang is None and default != 'fr_FR':
            continue
        check(f'user language {lang}: configuration page in {label}', want[0] in cfg and (EN if expect == 'fr' else FR)[0] not in cfg)
        check(f'user language {lang}: profile tab and ticket tab in {label}', want[1] in prof and want[1] in tick, f'{want[1]!r}')

    # A language that gets a catalogue is used (temporary Italian stub with ONE translated string).
    # GLPI caches translations per language: a language not requested before is needed (after adding a
    # catalogue to a real installation, clear GLPI's cache).
    with tempfile.NamedTemporaryFile('w', suffix='.po', delete=False) as f:
        f.write('msgid ""\nmsgstr ""\n"Content-Type: text/plain; charset=UTF-8\\n"\n"Plural-Forms: nplurals=2; plural=(n != 1);\\n"\n\nmsgid "GitLab connection"\nmsgstr "Conexión con GitLab"\n')
    stub = os.path.join(LOCALES, 'it_IT.mo')
    subprocess.run(['msgfmt', '-o', stub, f.name], check=True); os.unlink(f.name)
    cfg, _, _ = pages('it_IT')
    check('it_IT catalogue present: its translation is used', 'Conexión con GitLab' in cfg and 'GitLab connection' not in cfg)
    check('it_IT catalogue present: untranslated strings stay English (never French)', 'Default project' in cfg and 'Projet par défaut' not in cfg, cfg[cfg.find('Default'):cfg.find('Default') + 40])
    os.unlink(stub)
finally:
    q("update glpi_users set language=NULL where name='glpi'")
    if os.path.exists(os.path.join(LOCALES, 'it_IT.mo')): os.unlink(os.path.join(LOCALES, 'it_IT.mo'))

# The neutral message written for the requester follows the language of the interface of the person who
# creates the issue: his language if the plugin has it, otherwise English.
tid = int(open('/tmp/last_ticket_id').read())
EN_CREATE = 'Your request has been taken into account by the technical team and forwarded to the people in charge of the development. You will be informed of the outcome.'
def last_public_followup():
    return html.unescape(re.sub(r'<[^>]+>', '', q(f"select content from glpi_itilfollowups where items_id={tid} and itemtype='Ticket' and is_private=0 order by id desc limit 1")))
try:
    for lang, expect in (('fr_FR', 'fr'), ('en_GB', 'en'), ('de_DE', 'en')):
        q(f"update glpi_users set language='{lang}' where name='glpi'")
        a = Client(); assert a.login('glpi', 'glpi')
        s, p, _ = a.req(f'/ajax/common.tabs.php?_target=/front/ticket.form.php&_itemtype=Ticket&_glpi_tab={urllib.parse.quote("GlpiPlugin\\Gitlabissues\\Link$1", safe="")}&id={tid}', headers={'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': a.ajax_token() or ''})
        a.req(f'/plugins/gitlabissues/Ticket/{tid}/Issue', {'_glpi_csrf_token': a.ajax_token(), 'project_id': '2', 'allow_duplicate': '1', 'followup': 'none', 'notify_requester': '1'})
        said = last_public_followup()
        check(f'interface language {lang}: message for the requester in ' + ('French' if expect == 'fr' else 'English'),
              ('transmise aux personnes' in said) if expect == 'fr' else said == EN_CREATE, said[:80])
finally:
    q("update glpi_users set language=NULL where name='glpi'")
sys.exit(summary())
