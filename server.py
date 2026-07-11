#!/usr/bin/env python3
"""Tiny static server + /upload endpoint, so the demo runs end-to-end."""
import cgi, json, os, time
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer

ROOT = os.path.dirname(os.path.abspath(__file__))
UPLOADS = os.path.join(ROOT, 'uploads')
os.makedirs(UPLOADS, exist_ok=True)


class Handler(SimpleHTTPRequestHandler):
    def __init__(self, *a, **kw):
        super().__init__(*a, directory=ROOT, **kw)

    def log_message(self, *a):
        pass

    def do_POST(self):
        if self.path.rstrip('/') != '/upload':
            self.send_error(404)
            return

        form = cgi.FieldStorage(
            fp=self.rfile,
            headers=self.headers,
            environ={'REQUEST_METHOD': 'POST',
                     'CONTENT_TYPE': self.headers['Content-Type']},
        )
        # The real endpoint (prolancer_ajax_upload_message_attachment) takes the
        # file as `attachment`; accept `file` too so older captures still work.
        field = 'attachment' if 'attachment' in form else 'file'
        item = form[field]
        name = os.path.basename(item.filename)
        stamped = '%d-%s' % (int(time.time() * 1000), name)
        with open(os.path.join(UPLOADS, stamped), 'wb') as fh:
            fh.write(item.file.read())

        time.sleep(0.4)  # make the spinner/progress visible in the demo

        body = json.dumps({'url': '/uploads/' + stamped, 'id': stamped,
                           'name': name}).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)


if __name__ == '__main__':
    ThreadingHTTPServer(('127.0.0.1', 8811), Handler).serve_forever()
