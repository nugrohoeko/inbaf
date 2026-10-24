import {test, expect} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import {randomUUID} from 'node:crypto';
import path from 'node:path';
import {login} from './helpers.js';

// Live credentials and one-use tokens stay only in test memory. Do not enable
// screenshots or traces for this file, attach fixture output, or print it.
test.use({screenshot:'off', trace:'off', video:'off'});

function credentialFixture(key) {
    try {
        return JSON.parse(execFileSync(process.env.INBAF_PHP_PATH || 'C:/laragon/bin/php/php-8.3.31-Win32-vs16-x64/php.exe', ['tests/Browser/credential-fixture.php', key], {
            cwd:path.resolve('.'),
            env:{...process.env, APP_ENV:'testing', DB_CONNECTION:'sqlite', DB_DATABASE:path.resolve('tmp/browser.sqlite'), DB_URL:'', CACHE_STORE:'array'},
            encoding:'utf8', stdio:'pipe', timeout:30000, windowsHide:true,
        }));
    } catch {
        // Child-process errors can include stdout. Never include it in reports.
        throw new Error('The isolated credential fixture could not be created.');
    }
}

async function openCredentialLink(page, account) {
    try {
        await page.goto('/set-password?' + new URLSearchParams({email:account.email, token:account.token}));
    } catch {
        // Playwright navigation errors can include the full URL. Replace them
        // with a fixed message so a test failure cannot publish a live token.
        throw new Error('The isolated credential page could not be opened.');
    }
}

async function signIn(page, email, password, expectedStatus = 200, required = false) {
    await page.goto('/login');
    await page.getByLabel('Email address', {exact:true}).fill(email);
    await page.getByLabel('Password', {exact:true}).fill(password);
    const response = page.waitForResponse(item => new URL(item.url()).pathname === '/api/login' && item.request().method() === 'POST');
    await page.getByRole('button', {name:'Sign in', exact:true}).click();
    expect((await response).status()).toBe(expectedStatus);
    if (expectedStatus === 200) await expect(page).toHaveURL(required ? /\/profile\?tab=password&required=1$/ : /\/dashboard$/);
    else await expect(page).toHaveURL(/\/login$/);
}

async function signOut(page) {
    await page.getByRole('button', {name:'Account menu', exact:true}).click();
    const response = page.waitForResponse(item => new URL(item.url()).pathname === '/api/logout' && item.request().method() === 'POST');
    await page.getByRole('menuitem', {name:'Sign out', exact:true}).click();
    expect((await response).status()).toBe(200);
    await expect(page).toHaveURL(/\/login$/);
}

async function writeApi(page, endpoint, body) {
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

test.beforeEach(async ({page}) => {
    await page.addInitScript(() => {localStorage.setItem('inbaf-language', 'en'); localStorage.setItem('inbaf-theme', 'light');});
});

for (const role of ['participant', 'superadmin']) {
    for (const purpose of ['activation', 'reset']) {
        test(`real ${purpose} link for ${role} sets the password once and permits role-appropriate sign in`, async ({page}) => {
            test.setTimeout(90000);
            const account = credentialFixture(`${purpose}-${role}`), newPassword = 'CredentialUpdated#2026';
            try {
                if (purpose === 'activation') await signIn(page, account.email, account.password, 422);
                const inspected = page.waitForResponse(response => new URL(response.url()).pathname === '/api/set-password/inspect');
                await openCredentialLink(page, account);
                expect((await inspected).status()).toBe(200);
                const title = page.getByRole('heading', {name:purpose === 'activation' ? 'Activate your account' : 'Reset your password', exact:true});
                await expect(title).toBeVisible();
                // Assert a boolean so even failure reports cannot reveal the URL.
                await expect.poll(() => new URL(page.url()).search === '').toBe(true);
                await page.getByLabel('New password', {exact:true}).fill(newPassword);
                await page.getByLabel('Confirm new password', {exact:true}).fill(newPassword);
                const saved = page.waitForResponse(response => new URL(response.url()).pathname === '/api/set-password' && response.request().method() === 'POST');
                await page.getByRole('button', {name:purpose === 'activation' ? 'Activate account' : 'Save new password', exact:true}).click();
                expect((await saved).status()).toBe(200);
                await expect(page.getByRole('heading', {name:'Your password is ready', exact:true})).toBeVisible();
                await expect(page.getByRole('link', {name:'Go to sign in', exact:true})).toHaveAttribute('href', '/login');

                const replay = page.waitForResponse(response => new URL(response.url()).pathname === '/api/set-password/inspect');
                await openCredentialLink(page, account);
                expect((await replay).status()).toBe(422);
                await expect(page.getByRole('heading', {name:'This link is invalid or expired', exact:true})).toBeVisible();
                await expect.poll(() => new URL(page.url()).search === '').toBe(true);
                await signIn(page, account.email, account.password, 422);
                await signIn(page, account.email, newPassword);
                const current = await (await page.request.get('/api/account')).json();
                expect(current.user.role).toBe(role);
                expect(current.user.active).toBe(true);
                expect(current.user.activation_pending).toBe(false);
                expect(current.user.must_change_password).toBe(false);
                if (purpose === 'activation') expect(Boolean(current.user.email_verified_at)).toBe(true);
                if (role === 'superadmin') await expect(page.locator('.sidebar a[href="/admin/users"]')).toBeVisible();
                else expect((await page.request.get('/api/admin/users')).status()).toBe(403);
            } finally { account.token = ''; account.password = ''; }
        });
    }

    test(`admin-created manual ${role} account must change its initial password before dashboard access`, async ({page}) => {
        test.setTimeout(90000);
        await login(page, 'superadmin');
        const initialPassword = 'ManualInitial#2026', newPassword = 'ManualChanged#2026';
        const email = `qa-manual-${role}-${randomUUID()}@browser-qa.example.test`;
        const created = await writeApi(page, '/admin/users', {
            name:'QA Manual ' + role, email, role, status:'active', password_method:'manual',
            password:initialPassword, password_confirmation:initialPassword,
        });
        expect(created.status).toBe(201);
        expect(created.data.user.role).toBe(role);
        expect(created.data.user.must_change_password).toBe(true);
        await signOut(page);
        await signIn(page, email, initialPassword, 200, true);
        await expect(page.locator('.password-required')).toContainText('Change your initial password to continue');
        const forbidden = await page.request.get('/api/dashboard');
        expect(forbidden.status()).toBe(403);
        expect((await forbidden.json()).code).toBe('PASSWORD_CHANGE_REQUIRED');
        await page.goto('/dashboard');
        await expect(page).toHaveURL(/\/profile\?tab=password&required=1$/);
        await expect(page.getByRole('heading', {name:'Change Password', exact:true})).toBeVisible();
        await page.locator('#account-current_password').fill(initialPassword);
        await page.locator('#account-password').fill(newPassword);
        await page.locator('#account-password_confirmation').fill(newPassword);
        const updated = page.waitForResponse(response => new URL(response.url()).pathname === '/api/account/password' && response.request().method() === 'PUT');
        await page.getByRole('button', {name:'Update Password', exact:true}).click();
        const changed = await updated;
        expect(changed.status()).toBe(200);
        expect((await changed.json()).user.must_change_password).toBe(false);
        await expect(page).toHaveURL(/\/dashboard$/);
        expect((await page.request.get('/api/dashboard')).status()).toBe(200);
        const current = await (await page.request.get('/api/account')).json();
        expect(current.user.role).toBe(role);
        expect(current.user.must_change_password).toBe(false);
        if (role === 'superadmin') expect((await page.request.get('/api/admin/users')).status()).toBe(200);
        else expect((await page.request.get('/api/admin/users')).status()).toBe(403);
        await signOut(page);
        await signIn(page, email, initialPassword, 422);
        await signIn(page, email, newPassword);
    });
}
