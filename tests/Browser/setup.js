import fs from 'node:fs';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {environmentValue} from './helpers.js';

export default async function setup() {
    if (!fs.existsSync(path.resolve('public/build/manifest.json'))) throw new Error('Run npm run build before browser tests.');
    if (fs.existsSync(path.resolve('bootstrap/cache/config.php'))) throw new Error('Clear Laravel config cache before isolated browser tests; cached MySQL settings must never be used.');
    if (!environmentValue('INBAF_ADMIN_PASSWORD') || !environmentValue('INBAF_PARTICIPANT_PASSWORD')) throw new Error('Local INBAF demo credentials are required for browser tests.');
    const databasePath = path.resolve('tmp/browser.sqlite');
    if (databasePath !== path.resolve('tmp', 'browser.sqlite')) throw new Error('Unexpected isolated browser database path.');
    fs.mkdirSync(path.dirname(databasePath), {recursive: true});
    if (!fs.existsSync(databasePath)) fs.writeFileSync(databasePath, '');
    // This command is pinned to the isolated SQLite file. It never uses the main MySQL database.
    execFileSync(process.env.INBAF_PHP_PATH || 'C:/laragon/bin/php/php-8.3.31-Win32-vs16-x64/php.exe', ['artisan', 'migrate:fresh', '--seed', '--force', '--no-interaction'], {
        cwd: path.resolve('.'),
        env: {...process.env, APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_DATABASE: databasePath, DB_URL: '', QUEUE_CONNECTION: 'sync', CACHE_STORE: 'array', SESSION_DRIVER: 'array'},
        stdio: 'pipe',
        timeout: 30000,
        windowsHide: true,
    });
    const response = await fetch('http://127.0.0.1:8001/api/bootstrap');
    if (!response.ok) throw new Error('Start the isolated Laravel browser-test server on 127.0.0.1:8001.');
}
