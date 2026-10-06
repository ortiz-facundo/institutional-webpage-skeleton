#!/usr/bin/env python3
"""Create the ignored local application secret once, without overwriting overrides."""
from pathlib import Path
import secrets

target = Path(__file__).resolve().parents[1] / '.env.local'
if target.exists():
    print('Existing .env.local retained.')
else:
    target.write_text(f'APP_SECRET={secrets.token_hex(32)}\n')
    # Apache's www-data must be able to read the host bind-mounted file.
    target.chmod(0o644)
    print('Created ignored .env.local with a random application secret.')
