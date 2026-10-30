#!/usr/bin/env python3
"""Tiny stateful GitLab API mock used by the integration tests (no dependencies).

Control endpoints (not part of GitLab):
  POST /_mock/close  {"project":1,"iid":1,"by":"Jane Dev","moved":false}
  POST /_mock/delete {"project":1,"iid":1}
  POST /_mock/fail   {"status":500}   (0 = stop failing)
  GET  /_mock/state  -> everything received so far
"""
import json, re, sys, hashlib, urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

TOKEN = 'test-token'
PROJECTS = {1: 'group/app', 2: 'group/infra', 3: 'other/tools'}
state = {'issues': {}, 'uploads': [], 'requests': [], 'fail': 0, 'next_id': 1000, 'iid': {}}


def project_id(ref):
    ref = urllib.parse.unquote(ref)
    if ref.isdigit():
        return int(ref) if int(ref) in PROJECTS else None
    for pid, path in PROJECTS.items():
        if path == ref:
            return pid
    return None


class H(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def send(self, code, data=None, headers=None):
        body = json.dumps(data).encode() if data is not None else b''
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        for k, v in (headers or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(body)

    def body(self):
        n = int(self.headers.get('Content-Length') or 0)
        return self.rfile.read(n) if n else b''

    def handle_any(self, method):
        url = urllib.parse.urlparse(self.path)
        raw_body = self.body()
        path = url.path
        qs = urllib.parse.parse_qs(url.query)

        if path.startswith('/_mock/'):
            data = json.loads(raw_body or b'{}')
            if path == '/_mock/state':
                return self.send(200, {**state, 'issues': {f'{k[0]}/{k[1]}': v for k, v in state['issues'].items()}})
            if path == '/_mock/fail':
                state['fail'] = int(data['status']); return self.send(200, {})
            key = (int(data['project']), int(data['iid']))
            issue = state['issues'].get(key)
            if path == '/_mock/close':
                issue['state'] = 'closed'
                issue['closed_at'] = '2026-10-27T10:30:00.000Z'
                issue['closed_by'] = {'name': data.get('by', 'Jane Dev'), 'username': 'jane'}
                if data.get('moved'):
                    issue['moved_to_id'] = 4242
                return self.send(200, issue)
            if path == '/_mock/delete':
                del state['issues'][key]; return self.send(200, {})
            return self.send(404, {})

        state['requests'].append({'method': method, 'path': self.path, 'ctype': self.headers.get('Content-Type', ''),
                                  'body': raw_body.decode('utf-8', 'replace')[:20000] if 'multipart' not in self.headers.get('Content-Type', '') else '<multipart>'})
        if self.headers.get('PRIVATE-TOKEN') != TOKEN:
            return self.send(401, {'message': '401 Unauthorized'})
        if state['fail']:
            return self.send(state['fail'], {'message': 'forced failure'})

        m = re.match(r'^/api/v4/(.*)$', path)
        if not m:
            return self.send(404, {'message': '404 Not Found'})
        route = m.group(1)

        if route == 'version':
            return self.send(200, {'version': '17.5.0-mock', 'revision': 'abc'})
        if route == 'user':
            return self.send(200, {'username': 'glpi-bot', 'name': 'GLPI Bot'})
        if route == 'personal_access_tokens/self':
            return self.send(200, {'scopes': ['api']})
        if route == 'projects':
            term = (qs.get('search', [''])[0]).lower()
            per_page = int(qs.get('per_page', ['20'])[0]); page = int(qs.get('page', ['1'])[0])
            items = [{'id': i, 'path_with_namespace': p, 'name': p.split('/')[1]} for i, p in PROJECTS.items() if term in p.lower()]
            chunk = items[(page - 1) * per_page: page * per_page]
            nxt = str(page + 1) if len(items) > page * per_page else ''
            return self.send(200, chunk, {'X-Next-Page': nxt})

        m = re.match(r'^projects/([^/]+)(?:/(.*))?$', route)
        if not m:
            return self.send(404, {'message': '404 Not Found'})
        pid = project_id(m.group(1)); sub = m.group(2)
        if pid is None:
            return self.send(404, {'message': '404 Project Not Found'})
        if sub is None:
            return self.send(200, {'id': pid, 'path_with_namespace': PROJECTS[pid]})

        if sub == 'issues' and method == 'POST':
            form = urllib.parse.parse_qs(raw_body.decode())
            iid = state['iid'].get(pid, 0) + 1  # like GitLab, iids are never reused
            state['iid'][pid] = iid
            state['next_id'] += 1
            issue = {'id': state['next_id'], 'iid': iid, 'project_id': pid, 'title': form['title'][0], 'description': form.get('description', [''])[0],
                     'labels': form.get('labels', [''])[0].split(','), 'confidential': form.get('confidential', ['false'])[0] == 'true',
                     'state': 'opened', 'web_url': f'http://127.0.0.1:8099/{PROJECTS[pid]}/-/issues/{iid}', 'closed_at': None, 'closed_by': None}
            state['issues'][(pid, iid)] = issue
            return self.send(201, issue)

        if sub == 'issues' and method == 'GET':
            raw_q = url.query
            iids = [int(x) for x in re.findall(r'iids%5B%5D=(\d+)|iids\[\]=(\d+)', raw_q) for x in x if x]
            res = [i for (p, n), i in state['issues'].items() if p == pid and (not iids or n in iids)]
            return self.send(200, res)

        if sub == 'uploads' and method == 'POST':
            fn = re.search(rb'filename="([^"]+)"', raw_body)
            name = fn.group(1).decode() if fn else 'file'
            h = hashlib.md5(raw_body).hexdigest()[:8]
            url_ = f'/uploads/{h}/{name}'
            is_img = name.lower().endswith(('.png', '.jpg', '.gif'))
            state['uploads'].append({'project': pid, 'name': name, 'size': len(raw_body)})
            return self.send(201, {'alt': name, 'url': url_, 'full_path': f'/{PROJECTS[pid]}{url_}',
                                   'markdown': ('!' if is_img else '') + f'[{name}]({url_})'})

        m = re.match(r'^issues/(\d+)(?:/(closed_by))?$', sub)
        if m:
            issue = state['issues'].get((pid, int(m.group(1))))
            if issue is None:
                return self.send(404, {'message': '404 Not found'})
            if m.group(2):
                return self.send(200, [{'iid': 7, 'title': 'Fix the thing', 'web_url': 'http://127.0.0.1:8099/group/app/-/merge_requests/7'}] if issue['state'] == 'closed' else [])
            return self.send(200, issue)
        return self.send(404, {'message': '404 Not Found'})

    def do_GET(self): self.handle_any('GET')
    def do_POST(self): self.handle_any('POST')


if __name__ == '__main__':
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8099
    ThreadingHTTPServer(('127.0.0.1', port), H).serve_forever()
