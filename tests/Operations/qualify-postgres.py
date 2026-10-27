"""Run the existing PHPUnit suite on a new loopback-only PostgreSQL cluster.

No configured application database is opened. Requires PostgreSQL binaries and
PHP with pdo_pgsql available (enabled only for this child process).
"""
import argparse
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import tempfile
import time
import xml.etree.ElementTree as ET


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--bin-dir', required=True, type=Path)
    parser.add_argument('--php', default='php')
    parser.add_argument('--filter')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[2]
    if (root / 'bootstrap/cache/config.php').exists():
        parser.error('Cached application configuration must not be used.')
    suffix = '.exe' if os.name == 'nt' else ''
    binaries = {name: args.bin_dir.resolve() / (name + suffix) for name in ['initdb', 'pg_ctl', 'createdb', 'psql']}
    if not all(path.is_file() for path in binaries.values()):
        parser.error('PostgreSQL initdb, pg_ctl, createdb and psql are required.')
    scratch = Path(os.environ.get('LOCALAPPDATA', str(Path.home()))) / 'hermes/cache/scratch'
    scratch.mkdir(parents=True, exist_ok=True)
    work = Path(tempfile.mkdtemp(prefix='inbaf-pg-qa-', dir=scratch))
    output = root / 'tmp/qa/postgresql' / work.name
    output.mkdir(parents=True)
    database = 'inbaf_qa_pg_' + secrets.token_hex(6)
    password = secrets.token_urlsafe(32)
    password_file = work / 'password'
    password_file.write_text(password, encoding='utf-8')
    password_file.chmod(0o600)
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    env = dict(os.environ, APP_ENV='testing', APP_DEBUG='false', DB_CONNECTION='pgsql',
               DB_HOST='127.0.0.1', DB_PORT=str(port), DB_DATABASE=database,
               DB_USERNAME='inbaf_qa', DB_PASSWORD=password, DB_URL='', DB_SSLMODE='disable',
               CACHE_STORE='array', SESSION_DRIVER='array', MAIL_MAILER='array',
               QUEUE_CONNECTION='sync', BCRYPT_ROUNDS='4',
               APP_KEY='base64:' + __import__('base64').b64encode(secrets.token_bytes(32)).decode(),
               PGPASSWORD=password)
    # Test files may exercise real storage; redirect it away from active media.
    env['INBAF_QA_PRIVATE_ROOT'] = str(work / 'private')
    env['INBAF_QA_EXPECTED_DATABASE'] = database
    (work / 'private').mkdir()
    bootstrap = work / 'bootstrap.php'
    bootstrap.write_text('<?php\nrequire ' + json.dumps(str(root / 'vendor/autoload.php').replace('\\', '/')) + ';\n', encoding='utf-8')
    tree = ET.parse(root / 'phpunit.xml')
    tree.getroot().set('bootstrap', str(bootstrap))
    for node in tree.findall('.//directory'):
        node.text = str(root / node.text)
    config = work / 'phpunit.xml'
    tree.write(config, encoding='utf-8', xml_declaration=True)
    report = {'scope': 'Isolated local PostgreSQL; not Neon, TLS, pooler or Vercel qualification',
              'database': database, 'host': '127.0.0.1', 'port': port,
              'checks': {}, 'artifacts': str(output), 'cluster': str(work / 'data')}
    started = False

    def run(command, label, timeout=180):
        result = subprocess.run([str(x) for x in command], cwd=root, env=env,
                                capture_output=True, text=True, timeout=timeout)
        text = (result.stdout + result.stderr).replace(password, '[REDACTED]')
        (output / (label + '.log')).write_text(text, encoding='utf-8')
        report['checks'][label] = 'PASS' if result.returncode == 0 else 'FAIL'
        if result.returncode:
            raise RuntimeError(label + ' failed; see isolated artifact log.')
        return text

    begin = time.monotonic()
    try:
        run([binaries['initdb'], '-D', work / 'data', '-U', 'inbaf_qa',
             '--pwfile', password_file, '--auth=scram-sha-256', '--encoding=UTF8', '--locale=C'], 'initdb')
        password_file.unlink()
        # Never install a service or bind to a public interface.
        run([binaries['pg_ctl'], '-D', work / 'data', '-l', work / 'postgres.log',
             '-o', f'-h 127.0.0.1 -p {port}', '-w', 'start'], 'start')
        started = True
        run([binaries['createdb'], '-h', '127.0.0.1', '-p', port, '-U', 'inbaf_qa', database], 'create_database')
        report['server_version'] = run([binaries['psql'], '-X', '-h', '127.0.0.1', '-p', port,
                                       '-U', 'inbaf_qa', '-d', database, '-Atc', 'SHOW server_version'], 'server_version').strip()
        command = [args.php, '-d', 'extension=pdo_pgsql', root / 'vendor/phpunit/phpunit/phpunit',
                   '--configuration', config, '--colors=never', '--log-junit', output / 'phpunit.xml']
        if args.filter:
            command.extend(['--filter', args.filter])
        run(command, 'phpunit', timeout=600)
    except Exception as error:
        report['error'] = str(error).replace(password, '[REDACTED]')
    finally:
        if started:
            try:
                run([binaries['pg_ctl'], '-D', work / 'data', '-m', 'fast', '-w', 'stop'], 'stop')
            except Exception as error:
                report['cleanup_error'] = str(error)
        if password_file.exists():
            password_file.unlink()
        report['elapsed_seconds'] = round(time.monotonic() - begin, 2)
        report['status'] = 'PASS' if report['checks'].get('phpunit') == 'PASS' and report['checks'].get('stop') == 'PASS' else 'FAIL'
        (output / 'report.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
        print(json.dumps(report, indent=2))
    return 0 if report['status'] == 'PASS' else 1


if __name__ == '__main__':
    raise SystemExit(main())
