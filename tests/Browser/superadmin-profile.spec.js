import {test, expect} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import path from 'node:path';
import {noHorizontalOverflow} from './helpers.js';

// These tests use dedicated accounts in the isolated SQLite database. They never
// modify the seeded credentials, .env, or the main application's MySQL database.
test.use({screenshot: 'off'});

function fixture(key) {
    return JSON.parse(execFileSync(process.env.INBAF_PHP_PATH || 'C:/laragon/bin/php/php-8.3.31-Win32-vs16-x64/php.exe', ['tests/Browser/profile-fixture.php', key], {
        cwd: path.resolve('.'),
        env: {...process.env, APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_DATABASE: path.resolve('tmp/browser.sqlite'), DB_URL: '', CACHE_STORE: 'array'},
        encoding: 'utf8', stdio: 'pipe', timeout: 30000, windowsHide: true,
    }));
}

async function loginAs(page, account, password = account.password, expectedStatus = 200) {
    await page.goto('/login');
    await page.getByLabel('Email address', {exact: true}).fill(account.email);
    await page.getByLabel('Password', {exact: true}).fill(password);
    const response = page.waitForResponse(item => new URL(item.url()).pathname === '/api/login' && item.request().method() === 'POST');
    await page.getByRole('button', {name: 'Sign in', exact: true}).click();
    expect((await response).status()).toBe(expectedStatus);
    if (expectedStatus === 200) {
        await expect(page).toHaveURL(/\/dashboard$/);
        await expect(page.getByRole('button', {name: 'Account menu', exact: true})).toBeVisible();
    } else await expect(page).toHaveURL(/\/login$/);
}

async function photo(page, color = '#137f71') {
    return Buffer.from(await page.evaluate(fill => {
        const canvas = document.createElement('canvas');
        canvas.width = 800; canvas.height = 600;
        const context = canvas.getContext('2d');
        context.fillStyle = fill; context.fillRect(0, 0, 800, 600);
        context.fillStyle = '#e6f8ef'; context.fillRect(0, 0, 400, 300);
        context.fillStyle = '#dfad59'; context.fillRect(400, 300, 400, 300);
        return canvas.toDataURL('image/png').split(',')[1];
    }, color), 'base64');
}

async function choosePhoto(page, bytes, name = 'profile-fixture.png') {
    await page.getByLabel('Choose profile photo', {exact: true}).setInputFiles({name, mimeType: 'image/png', buffer: bytes});
    const crop = page.getByRole('dialog', {name: 'Crop your photo', exact: true});
    await expect(crop).toBeVisible();
    await expect(crop.locator('.crop-viewport img')).toBeVisible();
    await expect(crop.getByRole('button', {name: 'Use this photo', exact: true})).toBeEnabled();
    return crop;
}

async function saveProfile(page) {
    const response = page.waitForResponse(item => new URL(item.url()).pathname === '/api/account' && item.request().method() === 'POST');
    await page.getByRole('button', {name: 'Save Changes', exact: true}).click();
    const saved = await response;
    expect(saved.status()).toBe(200);
    await expect(page.getByRole('button', {name: 'Edit Profile', exact: true})).toBeVisible();
    return (await saved.json()).user;
}

async function logoutFromMenu(page) {
    await page.getByRole('button', {name: 'Account menu', exact: true}).click();
    const response = page.waitForResponse(item => new URL(item.url()).pathname === '/api/logout' && item.request().method() === 'POST');
    await page.getByRole('menuitem', {name: 'Sign out', exact: true}).click();
    expect((await response).status()).toBe(200);
    await expect(page).toHaveURL(/\/login$/);
}

test.beforeEach(async ({page}) => {
    await page.addInitScript(() => localStorage.setItem('inbaf-language', 'en'));
});

test('account menu supports keyboard dismissal and profile routes; dark XL mobile editing and crop cancellation preserve the account', async ({page}) => {
    const account = fixture('profile-responsive'), errors = [];
    page.on('pageerror', error => errors.push(error.stack || error.message));
    await page.addInitScript(() => localStorage.setItem('inbaf-theme', 'dark'));
    await loginAs(page, account);
    const trigger = page.getByRole('button', {name: 'Account menu', exact: true});
    const menu = page.getByRole('menu', {name: 'Superadmin account menu', exact: true});
    await trigger.click();
    await expect(menu.getByRole('menuitem', {name: 'My Profile', exact: true})).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(menu.getByRole('menuitem', {name: 'Change Password', exact: true})).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(menu).toHaveCount(0);
    await expect(trigger).toBeFocused();
    await trigger.click();
    await page.keyboard.press('Tab');
    await expect(menu).toHaveCount(0);
    await trigger.click();
    await page.locator('.breadcrumb').click();
    await expect(menu).toHaveCount(0);
    await trigger.click();
    await menu.getByRole('menuitem', {name: 'Change Password', exact: true}).click();
    await expect(page).toHaveURL(/\/profile\?tab=password$/);
    await expect(page.getByLabel('Current Password', {exact: true})).toBeVisible();
    await trigger.click();
    await menu.getByRole('menuitem', {name: 'My Profile', exact: true}).click();
    await expect(page).toHaveURL(/\/profile$/);
    await expect(page.locator('.account-info')).toContainText(account.email);
    await expect(page.locator('.account-info')).toContainText('Last login');

    const textSize = page.getByRole('combobox', {name: 'Text size', exact: true});
    await textSize.selectOption('extra_large');
    await expect(textSize).toHaveAttribute('aria-busy', 'false');
    for (const width of [390, 320]) {
        await page.setViewportSize({width, height: 1000});
        await expect(page.locator('html')).toHaveClass(/dark/);
        await expect(page.locator('html')).toHaveAttribute('data-text-size', 'extra_large');
        await noHorizontalOverflow(page);
        await trigger.click();
        await expect(menu).toBeInViewport();
        await noHorizontalOverflow(page);
        await page.keyboard.press('Escape');
    }
    await page.screenshot({path: 'tmp/qa/superadmin-profile-dark-xl-320.png', fullPage: true});
    await page.getByRole('button', {name: 'Edit Profile', exact: true}).click();
    await page.getByLabel('Full name', {exact: true}).fill('Unsaved profile change');
    const crop = await choosePhoto(page, await photo(page));
    await noHorizontalOverflow(page);
    expect(await crop.evaluate(el => el.scrollWidth - el.clientWidth)).toBeLessThanOrEqual(1);
    await page.screenshot({path: 'tmp/qa/superadmin-crop-dark-xl-320.png'});
    await crop.getByRole('button', {name: 'Close photo crop', exact: true}).click();
    await expect(crop).toHaveCount(0);
    const secondCrop = await choosePhoto(page, await photo(page));
    await page.keyboard.press('Escape');
    await expect(secondCrop).toHaveCount(0);
    await page.screenshot({path: 'tmp/qa/superadmin-profile-edit-dark-xl-320.png', fullPage: true});
    await page.locator('.account-edit-form').getByRole('button', {name: 'Cancel', exact: true}).click();
    const stored = await (await page.request.get('/api/account')).json();
    expect(stored.user.name).toBe(account.name);
    expect(stored.user.email).toBe(account.email);
    expect(stored.user.avatar_url).toBeNull();
    await expect(page.locator('.account-summary h2')).toHaveText(account.name);
    await noHorizontalOverflow(page);
    expect(errors).toEqual([]);
});

test('profile validates photos and saves cropped replacements, name and email only after confirmation, then removes the photo', async ({page}) => {
    test.setTimeout(150000);
    const account = fixture('profile-edit'), errors = [];
    page.on('pageerror', error => errors.push(error.stack || error.message));
    await loginAs(page, account);
    await page.goto('/profile');
    await page.getByRole('button', {name: 'Edit Profile', exact: true}).click();
    const input = page.getByLabel('Choose profile photo', {exact: true});
    await input.setInputFiles({name: 'document.txt', mimeType: 'text/plain', buffer: Buffer.from('This is not an image.')});
    await expect(page.locator('.account-photo-actions [role="alert"]')).toContainText('Choose a JPG, JPEG, PNG or WebP photo.');
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await input.setInputFiles({name: 'oversized.png', mimeType: 'image/png', buffer: Buffer.alloc(2 * 1024 * 1024 + 1)});
    await expect(page.locator('.account-photo-actions [role="alert"]')).toContainText('2 MB or smaller');
    await expect(page.getByRole('dialog')).toHaveCount(0);

    const nextName = 'QA Updated Administrator', nextEmail = account.email.replace('@', '.updated@');
    await page.getByLabel('Full name', {exact: true}).fill(nextName);
    await page.getByLabel('Email address', {exact: true}).fill(nextEmail);
    const crop = await choosePhoto(page, await photo(page));
    const image = crop.locator('.crop-viewport img'), originalStyle = await image.getAttribute('style');
    await crop.getByRole('slider', {name: /^Zoom/}).focus();
    await page.keyboard.press('End');
    await expect(crop.getByRole('slider', {name: /^Zoom/})).toHaveValue('3');
    await crop.getByRole('slider', {name: 'Horizontal position', exact: true}).focus();
    await page.keyboard.press('End');
    await expect(crop.getByRole('slider', {name: 'Horizontal position', exact: true})).toHaveValue('100');
    await crop.getByRole('slider', {name: 'Vertical position', exact: true}).focus();
    await page.keyboard.press('Home');
    await expect(crop.getByRole('slider', {name: 'Vertical position', exact: true})).toHaveValue('-100');
    const box = await crop.locator('.crop-viewport').boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width / 2 - 40, box.y + box.height / 2 + 25, {steps: 5});
    await page.mouse.up();
    expect(await image.getAttribute('style')).not.toBe(originalStyle);
    expect(Number(await crop.getByRole('slider', {name: 'Horizontal position', exact: true}).inputValue())).toBeLessThan(100);
    await crop.getByRole('button', {name: 'Use this photo', exact: true}).click();
    await expect(crop).toHaveCount(0);
    await expect(page.getByRole('img', {name: 'Profile photo', exact: true})).toHaveAttribute('src', /^blob:/);
    await expect(page.locator('.account-menu .user-label strong')).toHaveText(account.name);
    await expect(page.locator('.account-menu .account-avatar img')).toHaveCount(0);
    const unsaved = await (await page.request.get('/api/account')).json();
    expect(unsaved.user.name).toBe(account.name);
    expect(unsaved.user.email).toBe(account.email);
    expect(unsaved.user.avatar_url).toBeNull();

    const saved = await saveProfile(page);
    expect(saved.name).toBe(nextName); expect(saved.email).toBe(nextEmail);
    expect(saved.role).toBe('superadmin'); expect(saved.avatar_url).toMatch(/^\/api\/account\/photo\?v=/);
    await expect(page.locator('.account-menu .user-label strong')).toHaveText(nextName);
    expect(await page.locator('.account-menu .user-label strong').evaluate(element => element.scrollWidth <= element.clientWidth)).toBeTruthy();
    await expect(page.locator('.account-menu .account-avatar img')).toHaveAttribute('src', saved.avatar_url);
    const photoResponse = await page.request.get(saved.avatar_url);
    expect(photoResponse.status()).toBe(200);
    expect(photoResponse.headers()['content-type']).toContain('image/jpeg');
    expect(photoResponse.headers()['cache-control']).toContain('no-store');
    await page.reload();
    await expect(page.locator('.account-info')).toContainText(nextEmail);
    await expect(page.getByRole('img', {name: 'Profile photo', exact: true})).toHaveAttribute('src', saved.avatar_url);
    await page.screenshot({path: 'tmp/qa/superadmin-profile-photo-saved.png', fullPage: true});

    await page.getByRole('button', {name: 'Edit Profile', exact: true}).click();
    const replacementCrop = await choosePhoto(page, await photo(page, '#493a84'), 'replacement.png');
    await replacementCrop.getByRole('button', {name: 'Use this photo', exact: true}).click();
    await expect(page.locator('.account-menu .account-avatar img')).toHaveAttribute('src', saved.avatar_url);
    const replacement = await saveProfile(page);
    expect(replacement.avatar_url).not.toBe(saved.avatar_url);
    await expect(page.locator('.account-menu .account-avatar img')).toHaveAttribute('src', replacement.avatar_url);

    await page.getByRole('button', {name: 'Edit Profile', exact: true}).click();
    await page.getByRole('button', {name: 'Remove Photo', exact: true}).click();
    const removal = page.getByRole('dialog', {name: 'Remove profile photo?', exact: true});
    await removal.getByRole('button', {name: 'Keep Photo', exact: true}).click();
    await expect(page.getByRole('img', {name: 'Profile photo', exact: true})).toHaveAttribute('src', replacement.avatar_url);
    await page.getByRole('button', {name: 'Remove Photo', exact: true}).click();
    await removal.getByRole('button', {name: 'Remove Photo', exact: true}).click();
    await expect(page.getByRole('img', {name: 'Profile photo', exact: true})).toHaveCount(0);
    await expect(page.locator('.account-menu .account-avatar img')).toHaveAttribute('src', replacement.avatar_url);
    expect((await saveProfile(page)).avatar_url).toBeNull();
    await expect(page.locator('.account-menu .account-avatar img')).toHaveCount(0);
    await expect(page.locator('.account-menu .account-avatar > span')).toHaveText('QU');
    await page.reload();
    await expect(page.getByRole('img', {name: 'Profile photo', exact: true})).toHaveCount(0);
    expect((await page.request.get('/api/account/photo')).status()).toBe(404);
    expect(errors).toEqual([]);
});

test('password validation, visibility and strength work; changing a password keeps this session and revokes another session', async ({page, browser}) => {
    test.setTimeout(150000);
    const account = fixture('profile-password'), errors = [], nextPassword = 'ProfileNewPass456!';
    page.on('pageerror', error => errors.push(error.stack || error.message));
    const otherContext = await browser.newContext({baseURL: 'http://127.0.0.1:8001'});
    try {
        const otherPage = await otherContext.newPage();
        await otherPage.addInitScript(() => localStorage.setItem('inbaf-language', 'en'));
        await loginAs(page, account); await loginAs(otherPage, account);
        await page.goto('/profile?tab=password');
        const current = page.getByLabel('Current Password', {exact: true}), password = page.getByLabel('New Password', {exact: true}), confirmation = page.getByLabel('Confirm New Password', {exact: true});
        await current.fill('IncorrectCurrent123!'); await password.fill('weakpass'); await confirmation.fill('weakpass');
        await expect(page.getByRole('progressbar', {name: 'Password strength', exact: true})).toHaveAttribute('aria-valuetext', 'Needs improvement');
        await page.getByRole('button', {name: 'Show New Password', exact: true}).click();
        await expect(password).toHaveAttribute('type', 'text');
        await page.getByRole('button', {name: 'Hide New Password', exact: true}).click();
        await expect(password).toHaveAttribute('type', 'password');
        await page.getByRole('button', {name: 'Update Password', exact: true}).click();
        await expect(page.locator('#account-password-error')).toContainText('uppercase, lowercase, a number and a symbol');
        await password.fill(nextPassword); await confirmation.fill('DifferentPassword789!');
        await expect(page.getByRole('progressbar', {name: 'Password strength', exact: true})).toHaveAttribute('aria-valuenow', '100');
        await expect(page.getByRole('progressbar', {name: 'Password strength', exact: true})).toHaveAttribute('aria-valuetext', 'Strong');
        await page.getByRole('button', {name: 'Update Password', exact: true}).click();
        await expect(page.locator('#account-password_confirmation-error')).toContainText('does not match');
        await confirmation.fill(nextPassword);
        const rejected = page.waitForResponse(item => new URL(item.url()).pathname === '/api/account/password' && item.request().method() === 'PUT');
        await page.getByRole('button', {name: 'Update Password', exact: true}).click();
        expect((await rejected).status()).toBe(422);
        await expect(current).toHaveAttribute('aria-invalid', 'true');
        await expect(page.locator('#account-current_password-error')).not.toBeEmpty();
        await current.fill(account.password);
        const accepted = page.waitForResponse(item => new URL(item.url()).pathname === '/api/account/password' && item.request().method() === 'PUT');
        await page.getByRole('button', {name: 'Update Password', exact: true}).click();
        expect((await accepted).status()).toBe(200);
        for (const input of [current, password, confirmation]) await expect(input).toHaveValue('');
        expect((await page.request.get('/api/account')).status()).toBe(200);
        expect((await otherPage.request.get('/api/account')).status()).toBe(401);
        await otherPage.reload(); await expect(otherPage).toHaveURL(/\/login$/);
        await logoutFromMenu(page);
        await loginAs(page, account, account.password, 422);
        await loginAs(page, account, nextPassword);
        expect((await (await page.request.get('/api/account')).json()).user.id).toBe(account.id);
        expect(errors).toEqual([]);
    } finally {await otherContext.close();}
});

test('account menu logout invalidates the session, clears other tabs and protects cached or direct private routes', async ({page, context}) => {
    test.setTimeout(150000);
    const account = fixture('profile-logout'), errors = [];
    page.on('pageerror', error => errors.push(error.stack || error.message));
    await loginAs(page, account);
    const otherPage = await context.newPage();
    try {
        await otherPage.goto('/dashboard');
        await expect(otherPage.getByRole('button', {name: 'Account menu', exact: true})).toBeVisible();
        const document = await page.goto('/profile');
        expect(document.headers()['cache-control']).toContain('no-store');
        const privateResponse = await page.request.get('/api/account');
        expect(privateResponse.status()).toBe(200);
        expect(privateResponse.headers()['cache-control']).toContain('no-store');
        await logoutFromMenu(page);
        await expect(otherPage).toHaveURL(/\/login$/);
        await expect(otherPage.locator('.main-content')).toHaveCount(0);
        expect((await page.request.get('/api/account')).status()).toBe(401);
        await page.goBack();
        await expect(page).toHaveURL(/\/login$/);
        await expect(page.locator('.account-page')).toHaveCount(0);
        for (const route of ['/profile', '/profile?tab=password', '/admin/questions']) {
            await page.goto(route);
            await expect(page).toHaveURL(/\/login$/);
            await expect(page.getByRole('button', {name: 'Sign in', exact: true})).toBeVisible();
            await expect(page.locator('.main-content')).toHaveCount(0);
        }
        expect(errors).toEqual([]);
    } finally {await otherPage.close();}
});
