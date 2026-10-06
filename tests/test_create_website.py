import json
from pathlib import Path
import subprocess
import tempfile
import unittest


class WebsiteInitializerTest(unittest.TestCase):
    root = Path(__file__).resolve().parents[1]

    def command(self, target):
        return ['python3', str(self.root / 'scripts/create-website.py'), str(target),
                '--name', 'Example Company', '--slug', 'example-webpage',
                '--http-port', '8090', '--mailpit-port', '8095', '--vite-port', '5179']

    def test_snapshot_has_public_source_history_and_independent_config(self):
        with tempfile.TemporaryDirectory() as temporary:
            target = Path(temporary) / 'website'
            subprocess.run(self.command(target), check=True, capture_output=True)
            self.assertEqual((self.root / 'src/Mailer/ContactMailer.php').read_bytes(),
                             (target / 'src/Mailer/ContactMailer.php').read_bytes())
            for directory in ['vendor', 'node_modules', 'var', 'dist', 'config/reference.php', 'src/Entity', 'src/Controller/Administration']:
                self.assertFalse((target / directory).exists(), directory)
            self.assertNotEqual((self.root / '.env.local').read_text(), (target / '.env.local').read_text())
            env = (target / '.env').read_text()
            self.assertIn('COMPOSE_PROJECT_NAME=example-webpage', env)
            self.assertIn('PUBLIC_URL=http://localhost:8090', env)
            self.assertIn('site.name: "Example Company"', (target / 'config/site.yaml').read_text())
            baseline = json.loads((target / 'SKELETON_BASELINE.json').read_text())
            self.assertIn('templates/contact/_form.html.twig', baseline['files_before_company_customization'])
            self.assertNotIn('.env.local', baseline['files_before_company_customization'])
            revision = subprocess.check_output(['git', '-C', str(target), 'rev-parse', 'HEAD'])
            self.assertEqual(revision, subprocess.check_output(['git', '-C', str(self.root), 'rev-parse', 'HEAD']))
            self.assertEqual(subprocess.check_output(['git', '-C', str(target), 'remote']).decode().strip(), 'skeleton')

    def test_nonempty_target_is_preserved(self):
        with tempfile.TemporaryDirectory() as temporary:
            target = Path(temporary) / 'website'
            target.mkdir()
            marker = target / 'user-work.txt'
            marker.write_text('keep me')
            result = subprocess.run(self.command(target), capture_output=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(marker.read_text(), 'keep me')
            self.assertFalse((target / '.git').exists())

    def test_invalid_slug_does_not_create_target(self):
        with tempfile.TemporaryDirectory() as temporary:
            target = Path(temporary) / 'website'
            command = self.command(target)
            command[command.index('--slug') + 1] = '../bad'
            result = subprocess.run(command, capture_output=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse(target.exists())


if __name__ == '__main__':
    unittest.main()
