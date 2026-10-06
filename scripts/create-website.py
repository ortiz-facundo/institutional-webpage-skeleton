#!/usr/bin/env python3
"""Initialize a company website from this skeleton's current source snapshot.

Keeps Git ancestry, includes uncommitted source changes, excludes ignored output,
and never commits, stages files, sets Git config, or overwrites a nonempty target.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
import shutil
import subprocess


def git(root, *args):
    return subprocess.check_output(['git', '-C', str(root), *args])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('destination', type=Path)
    parser.add_argument('--name', required=True)
    parser.add_argument('--slug', required=True)
    parser.add_argument('--http-port', type=int, required=True)
    parser.add_argument('--mailpit-port', type=int, required=True)
    parser.add_argument('--vite-port', type=int, required=True)
    args = parser.parse_args()
    source = Path(__file__).resolve().parents[1]
    target = args.destination.resolve()
    if not re.fullmatch(r'[a-z][a-z0-9-]*', args.slug):
        parser.error('Slug must contain lowercase letters, numbers and hyphens.')
    if not args.name.strip() or '\n' in args.name or '\r' in args.name:
        parser.error('Name must be a nonempty single line.')
    ports = [args.http_port, args.mailpit_port, args.vite_port]
    if len(set(ports)) != 3 or any(port < 1024 or port > 65535 for port in ports):
        parser.error('Choose three distinct ports between 1024 and 65535.')
    if not target.parent.is_dir() or target == source or source in target.parents:
        parser.error('Destination must have an existing parent outside the source repository.')
    if target.exists() and (not target.is_dir() or any(target.iterdir())):
        parser.error('Destination must be absent or an empty directory.')

    files = []
    paths = git(source, 'ls-files', '--cached', '--others', '--exclude-standard', '-z').decode().split('\0')
    for relative in sorted(set(filter(None, paths))):
        parts = Path(relative).parts
        if (any(part in {'vendor', 'node_modules', 'var', 'dist', '.git', '__pycache__', '.phpunit.cache'} for part in parts)
                or relative.startswith(('public/build/', 'public/bundles/'))
                or relative in {'.env.local', '.env.local.php', 'config/reference.php', 'SKELETON_BASELINE.json'}
                or (relative.startswith('.env.') and relative.endswith('.local'))
                or relative.endswith(('prod.decrypt.private.php', '.zip'))):
            continue
        path = source / relative
        if path.is_symlink():
            parser.error(f'Symlink not supported in scaffold source: {relative}')
        if path.is_file():
            files.append(relative)

    revision = git(source, 'rev-parse', 'HEAD').decode().strip()
    dirty = bool(git(source, 'status', '--porcelain'))
    subprocess.run(['git', 'clone', '--no-checkout', '--no-hardlinks', str(source), str(target)], check=True)
    # Populate the index with the ancestor; absent backoffice files show as
    # intentional deletions, while the copied institutional files remain unstaged.
    subprocess.run(['git', '-C', str(target), 'read-tree', 'HEAD'], check=True)
    subprocess.run(['git', '-C', str(target), 'remote', 'rename', 'origin', 'skeleton'], check=True)
    subprocess.run(['git', '-C', str(target), 'branch', '--unset-upstream'], check=True)
    hashes = {}
    for relative in files:
        destination = target / relative
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(source / relative, destination)
        hashes[relative] = hashlib.sha256(destination.read_bytes()).hexdigest()

    def replace_json(filename, changes):
        path = target / filename
        data = json.loads(path.read_text())
        data.update(changes)
        path.write_text(json.dumps(data, indent=2, ensure_ascii=False) + '\n')

    replace_json('package.json', {'name': args.slug, 'description': f'{args.name} institutional website'})
    subprocess.run(['npm', 'install', '--package-lock-only', '--ignore-scripts', '--no-audit', '--no-fund'], cwd=target, check=True)
    # Keep composer manifests identical to the skeleton so their lock hash stays valid.
    site = 'parameters:\n' + ''.join(f'    site.{key}: {json.dumps(value, ensure_ascii=False)}\n' for key, value in {
        'name': args.name, 'description': '', 'logo': '', 'mark': '', 'favicon': '',
    }.items())
    (target / 'config/site.yaml').write_text(site)
    overrides = {
        'COMPOSE_PROJECT_NAME': args.slug,
        'HTTP_PORT': str(args.http_port),
        'MAILPIT_PORT': str(args.mailpit_port),
        'VITE_PORT': str(args.vite_port),
        'PUBLIC_URL': f'http://localhost:{args.http_port}',
        'CONTACT_MAIL_FROM_NAME': json.dumps(args.name, ensure_ascii=False),
        'CONTACT_MAIL_TO_NAME': json.dumps(args.name, ensure_ascii=False),
    }
    lines = []
    for line in (target / '.env').read_text().splitlines():
        key = line.split('=', 1)[0]
        lines.append(f'{key}={overrides[key]}' if key in overrides else line)
    (target / '.env').write_text('\n'.join(lines) + '\n')
    (target / 'SKELETON_BASELINE.json').write_text(json.dumps({
        'source_repository': source.name,
        'source_head': revision,
        'institutional_baseline_commit': None if dirty else revision,
        'includes_uncommitted_source': dirty,
        'files_before_company_customization': hashes,
    }, indent=2) + '\n')
    (target / 'PROJECT.md').write_text(
        f'# {args.name}\n\nPublic-only institutional website derived from `{source.name}`.\n\n'
        f'- Website: http://localhost:{args.http_port}\n'
        f'- Mailpit: http://localhost:{args.mailpit_port}\n'
        f'- Vite: http://localhost:{args.vite_port}\n\n'
        'See README.md for setup, README_DEPLOY.md for deployment, and BRANCHING.md for maintenance.\n'
        'Company presentation belongs in config/site.yaml, assets/brand.js, and public/images/.\n'
        'The homepage is intentionally blank and the contact partial is unmounted.\n'
    )
    subprocess.run(['python3', str(target / 'scripts/setup-local.py')], check=True)
    print(f'Initialized {target}. Source history retained; all scaffold changes are uncommitted.')
    if dirty:
        print('Baseline includes uncommitted skeleton work. See BRANCHING.md before the first sync.')


if __name__ == '__main__':
    main()
