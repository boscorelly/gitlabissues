#!/usr/bin/env python3
"""Consistency of the plugin descriptor (gitlabissues.xml) with the package. No GLPI needed."""
import os, re, struct, sys
import xml.etree.ElementTree as ET
from webclient import check, summary
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
x = ET.parse(os.path.join(ROOT, 'gitlabissues.xml')).getroot()
setup = open(os.path.join(ROOT, 'setup.php')).read()
version = re.search(r"define\('PLUGIN_GITLABISSUES_VERSION', '([^']+)'\)", setup).group(1)
order = [c.tag for c in x]
check('<logo> is declared right after <state> (like the other plugins)', order[:4] == ['name', 'key', 'state', 'logo'], str(order[:5]))
logo = x.findtext('logo')
check('<logo> points to logo.png at the root of the repository', re.fullmatch(r'https://raw\.githubusercontent\.com/boscorelly/gitlabissues/main/logo\.png', logo or '') is not None, str(logo))
png = open(os.path.join(ROOT, 'logo.png'), 'rb').read()
w, h = struct.unpack('>II', png[16:24])
check('logo.png is a real PNG of 40x40 pixels (size asked by the directory)', png[:8] == b'\x89PNG\r\n\x1a\n' and (w, h) == (40, 40), f'{w}x{h}')
check('GLPI reads the same file as the plugin icon (logo.png at the plugin root)', os.path.isfile(os.path.join(ROOT, 'logo.png')))
check('no generated stylesheet left (the tabs use GLPI\'s glyph)', not os.path.exists(os.path.join(ROOT, 'public', 'css', 'gitlabissues.css')))
v = x.find('versions/version')
check('newest version of the descriptor = version of setup.php', v.findtext('num') == version, f"{v.findtext('num')} vs {version}")
check('its download_url is the release archive', v.findtext('download_url') == f'https://github.com/boscorelly/gitlabissues/releases/download/{version}/glpi-gitlabissues-{version}.tar.bz2', v.findtext('download_url'))
check('compatibility is ~11.0.0', v.findtext('compatibility') == '~11.0.0')
check('changelog has an entry for this version', f'## {version}' in open(os.path.join(ROOT, 'CHANGELOG.md')).read())
check('license of the descriptor matches setup.php', 'AGPL' in (x.findtext('license') or '') and 'AGPL' in setup)
sys.exit(summary())
