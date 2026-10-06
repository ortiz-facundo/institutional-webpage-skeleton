#!/usr/bin/env python3
"""Build a standard public/-document-root Symfony release without local secrets."""
from pathlib import Path
import json
import shutil
import subprocess
import zipfile

root = Path(__file__).resolve().parents[1]
stage = root / 'dist/release'
if not (root / 'node_modules').is_dir():
    subprocess.run(['npm', 'ci'], cwd=root, check=True)
subprocess.run(['npm', 'run', 'build'], cwd=root, check=True)
if stage.exists():
    shutil.rmtree(stage)
stage.mkdir(parents=True)
for name in ['bin/console', 'config', 'src', 'templates', 'translations', 'public', 'composer.json', 'composer.lock', 'symfony.lock']:
    source = root / name
    destination = stage / name
    if source.is_dir():
        shutil.copytree(source, destination, ignore=shutil.ignore_patterns('prod.decrypt.private.php', 'reference.php'))
    else:
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(source, destination)
# Retain safe defaults and company locale; deliberately omit dev/test transports.
lines = (root / '.env').read_text().splitlines()
lines = [line for line in lines if not line.startswith(('COMPOSE_PROJECT_NAME=', 'HTTP_PORT=', 'MAILPIT_PORT=', 'VITE_', 'APP_ENV='))]
(stage / '.env').write_text('APP_ENV=prod\nAPP_DEBUG=0\n' + '\n'.join(lines) + '\n')
subprocess.run([
    'docker', 'compose', '--profile', 'tools', 'run', '--rm',
    '-v', f'{stage}:/release', '-w', '/release', 'composer',
    'install', '--no-dev', '--no-scripts', '--no-interaction', '--classmap-authoritative',
], cwd=root, check=True)
name = json.loads((root / 'package.json').read_text())['name']
archive = root / 'dist' / f'{name}-release.zip'
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as output:
    for path in sorted(stage.rglob('*')):
        if path.is_file():
            output.write(path, path.relative_to(stage))
print(f'Built {archive}; document root must point to public/.')
