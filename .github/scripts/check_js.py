"""Syntax-check every JavaScript file and every inline <script> of the pages (needs node)."""
import glob
import re
import subprocess
import sys
import tempfile

SCRIPT = re.compile(r'<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>', re.S)
bad = 0


def check(path, code):
    global bad
    with tempfile.NamedTemporaryFile('w', suffix='.js', delete=False, encoding='utf-8') as f:
        f.write(code)
    if subprocess.run(['node', '--check', f.name], capture_output=True).returncode:
        print('syntax error in', path)
        bad += 1


for js in glob.glob('assets/*.js'):
    check(js, open(js, encoding='utf-8').read())
for page in glob.glob('*.html') + glob.glob('admin/*.html') + glob.glob('payments/*.html'):
    for n, m in enumerate(SCRIPT.finditer(open(page, encoding='utf-8').read())):
        if m.group(1).strip():
            check(f'{page} (script {n + 1})', m.group(1))
print('JavaScript syntax errors:', bad)
sys.exit(1 if bad else 0)
