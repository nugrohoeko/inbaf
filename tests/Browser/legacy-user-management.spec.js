import {test, expect} from '@playwright/test';
import {randomUUID} from 'node:crypto';
import {execFileSync} from 'node:child_process';
import path from 'node:path';
import {login} from './helpers.js';

test.use({screenshot:'off', trace:'off', video:'off'});

async function postApi(page, endpoint, body) {
    return page.evaluate(async ({endpoint, body}) => {
        const response = await fetch('/api' + endpoint, {
            method:'POST', credentials:'same-origin',
            headers:{Accept:'application/json', 'Content-Type':'application/json', 'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},
            body:JSON.stringify(body),
        });
        const data = await response.json();
        if (data.csrf_token) document.querySelector('meta[name="csrf-token"]').content = data.csrf_token;
        return {status:response.status, data};
    }, {endpoint, body});
}

function privatePendingToken(userId) {
    // No token is returned by the application's API. This CLI fixture is locked
    // to the same isolated SQLite database and the account created by this test.
    const fixture = String.raw`
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite') throw new RuntimeException('Isolated testing environment required.');
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$expected = realpath(base_path('tmp/browser.sqlite'));
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! $expected || realpath((string) config('database.connections.sqlite.database')) !== $expected) throw new RuntimeException('Isolated browser SQLite required.');
$user = \App\Models\User::findOrFail((int) ($argv[1] ?? 0));
if (! str_starts_with($user->email, 'qa-legacy-pending-') || ! $user->activation_pending || $user->active) throw new RuntimeException('Pending QA account required.');
echo \Illuminate\Support\Facades\Password::broker()->createToken($user);
`;
    try {
        const token = execFileSync(process.env.INBAF_PHP_PATH || 'C:/laragon/bin/php/php-8.3.31-Win32-vs16-x64/php.exe', ['-r', fixture, String(userId)], {
            cwd:path.resolve('.'),
            env:{...process.env, APP_ENV:'testing', DB_CONNECTION:'sqlite', DB_DATABASE:path.resolve('tmp/browser.sqlite'), DB_URL:'', CACHE_STORE:'array'},
            encoding:'utf8', stdio:'pipe', timeout:30000, windowsHide:true,
        }).trim();
        if (! token) throw new Error();
        return token;
    } catch {
        // Child-process failures may include stdout, so never report the cause.
        throw new Error('Could not prepare the isolated pending-account token.');
    }
}

test('existing participant workspace can create, edit, and archive an account with the stricter user backend', async ({page}) => {
    test.setTimeout(90000);
    const failures = [];
    page.on('pageerror', error => failures.push(error.message));
    await page.addInitScript(() => {localStorage.setItem('inbaf-language', 'en'); localStorage.setItem('inbaf-theme', 'light');});
    await login(page, 'superadmin');
    await page.goto('/admin/participants');
    await expect(page.getByRole('heading', {name:'Participants', exact:true})).toBeVisible();
    const email = `qa-legacy-participant-${randomUUID()}@browser-qa.example.test`;
    await page.getByLabel('Search records', {exact:true}).fill(email);
    await page.getByRole('button', {name:'Create new', exact:true}).click();
    const dialog = page.getByRole('dialog', {name:'Participants', exact:true});
    await expect(dialog).toBeVisible();
    await dialog.getByLabel('Full name', {exact:true}).fill('QA Legacy Participant');
    await dialog.getByLabel('Email address', {exact:true}).fill(email);
    await dialog.getByLabel('Initial password', {exact:true}).fill('LegacyInitial#2026');
    await dialog.getByLabel('Country', {exact:true}).fill('Japan');
    await dialog.getByLabel('Nationality', {exact:true}).fill('Japanese');
    await expect(dialog.getByLabel('Participant account active', {exact:true})).toBeChecked();
    const creating = page.waitForResponse(response => new URL(response.url()).pathname === '/api/admin/participants' && response.request().method() === 'POST');
    await dialog.getByRole('button', {name:'Save changes', exact:true}).click();
    const creation = await creating;
    expect(creation.status()).toBe(201);
    const account = await creation.json();
    expect(account.role).toBe('participant');
    expect(account.active).toBe(true);
    expect(account.must_change_password).toBe(true);
    expect(account.activation_pending).toBe(false);
    await expect(dialog).toHaveCount(0);
    const row = page.locator('.admin-table-panel tbody tr').filter({hasText:email});
    await expect(row).toContainText('QA Legacy Participant');
    await expect(row).toContainText('Japan');

    await row.getByRole('button', {name:'Edit', exact:true}).click();
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel('Full name', {exact:true})).toHaveValue('QA Legacy Participant');
    await dialog.getByLabel('Full name', {exact:true}).fill('QA Legacy Participant Updated');
    await dialog.getByLabel('Country', {exact:true}).fill('Indonesia');
    await expect(dialog.getByLabel('New password (leave empty to keep current)', {exact:true})).toHaveValue('');
    const editing = page.waitForResponse(response => new URL(response.url()).pathname === `/api/admin/participants/${account.id}` && response.request().method() === 'PUT');
    await dialog.getByRole('button', {name:'Save changes', exact:true}).click();
    const edit = await editing;
    expect(edit.status()).toBe(200);
    // The old editor fetches a complete User. Its update request must contain
    // only writable legacy fields, while the URL retains the original ID.
    expect(Object.keys(edit.request().postDataJSON()).sort()).toEqual(['active','email','name','profile']);
    const updated = await edit.json();
    expect(updated.id).toBe(account.id);
    expect(updated.name).toBe('QA Legacy Participant Updated');
    expect(updated.role).toBe('participant');
    expect(updated.active).toBe(true);
    expect(updated.must_change_password).toBe(true);
    expect(updated.profile.country).toBe('Indonesia');
    expect(updated.profile.nationality).toBe('Japanese');
    await expect(dialog).toHaveCount(0);
    await expect(row).toContainText('QA Legacy Participant Updated');
    await expect(row).toContainText('Indonesia');

    page.once('dialog', prompt => prompt.accept());
    const archiving = page.waitForResponse(response => new URL(response.url()).pathname === `/api/admin/participants/${account.id}` && response.request().method() === 'DELETE');
    await row.getByRole('button', {name:'Archive', exact:true}).click();
    expect((await archiving).status()).toBe(200);
    await expect(row).toContainText('Inactive');
    const stored = await (await page.request.get(`/api/admin/users/${account.id}`)).json();
    expect(stored.user.active).toBe(false);
    expect(stored.user.deleted_at).toBeNull();
    expect(stored.user.role).toBe('participant');
    expect(stored.user.name).toBe('QA Legacy Participant Updated');
    expect(stored.user.profile.nationality).toBe('Japanese');
    expect(failures).toEqual([]);
});

test('legacy participant name edits preserve pending activation and the existing one-use token', async ({page}) => {
    test.setTimeout(90000);
    await page.addInitScript(() => {localStorage.setItem('inbaf-language', 'en'); localStorage.setItem('inbaf-theme', 'light');});
    await login(page, 'superadmin');
    const email = `qa-legacy-pending-${randomUUID()}@browser-qa.example.test`;
    const created = await postApi(page, '/admin/users', {name:'QA Legacy Pending Participant', email, role:'participant', status:'active', password_method:'activation'});
    expect(created.status).toBe(201);
    expect(created.data.user.activation_pending).toBe(true);
    expect(created.data.user.active).toBe(false);
    expect(created.data.user.account_status).toBe('pending_activation');
    expect(created.data.delivery_status.sent).toBe(false);
    let token = privatePendingToken(created.data.user.id);
    try {
        await page.goto('/admin/participants');
        await page.getByLabel('Search records', {exact:true}).fill(email);
        const row = page.locator('.admin-table-panel tbody tr').filter({hasText:email});
        await expect(row).toContainText('Pending activation');
        await row.getByRole('button', {name:'Edit', exact:true}).click();
        const dialog = page.getByRole('dialog', {name:'Participants', exact:true});
        await expect(dialog.getByLabel('Participant account active', {exact:true})).toBeChecked();
        await expect(dialog.locator('[role="status"]')).toContainText('waiting for email verification');
        await dialog.getByLabel('Full name', {exact:true}).fill('QA Legacy Pending Participant Updated');
        const editing = page.waitForResponse(response => new URL(response.url()).pathname === `/api/admin/participants/${created.data.user.id}` && response.request().method() === 'PUT');
        await dialog.getByRole('button', {name:'Save changes', exact:true}).click();
        const edited = await editing;
        expect(edited.status()).toBe(200);
        expect(edited.request().postDataJSON().active).toBe(true);
        const updated = await edited.json();
        expect(updated.name).toBe('QA Legacy Pending Participant Updated');
        expect(updated.active).toBe(false);
        expect(updated.activation_pending).toBe(true);
        expect(updated.account_status).toBe('pending_activation');
        await expect(dialog).toHaveCount(0);
        await expect(row).toContainText('Pending activation');
        const retained = await (await page.request.get(`/api/admin/users/${created.data.user.id}`)).json();
        expect(retained.user.activation_pending).toBe(true);
        expect(retained.user.account_status).toBe('pending_activation');
        const inspected = await postApi(page, '/set-password/inspect', {email, token});
        expect(inspected.status).toBe(200);
        expect(inspected.data.valid).toBe(true);
        expect(inspected.data.activation).toBe(true);
    } finally { token = ''; }
});
