import {test, expect} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import path from 'node:path';
import {login, noHorizontalOverflow} from './helpers.js';

// Dedicated QA accounts in the guarded SQLite browser fixture. No seed account,
// application credential, or main MySQL data is modified by these tests.
test.use({screenshot: 'off'});
const browserErrors = new WeakMap();

function fixture(key) {
    return JSON.parse(execFileSync(process.env.INBAF_PHP_PATH || 'C:/laragon/bin/php/php-8.3.31-Win32-vs16-x64/php.exe', ['tests/Browser/profile-fixture.php', key], {
        cwd: path.resolve('.'),
        env: {...process.env, APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_DATABASE: path.resolve('tmp/browser.sqlite'), DB_URL: '', CACHE_STORE: 'array'},
        encoding: 'utf8', stdio: 'pipe', timeout: 30000, windowsHide: true,
    }));
}

async function loginAs(page, account, password = account.password, expectedStatus = 200) {
    await page.goto('/login');
    const form = page.locator('.auth-form-wrap form');
    await form.locator('input[autocomplete="email"]').fill(account.email);
    await form.locator('input[autocomplete="current-password"]').fill(password);
    const response = page.waitForResponse(item => new URL(item.url()).pathname === '/api/login' && item.request().method() === 'POST');
    await form.locator('button.button').click();
    expect((await response).status()).toBe(expectedStatus);
    if (expectedStatus === 200) {
        await expect(page).toHaveURL(/\/dashboard$/);
        await expect(page.locator('.account-menu>.user-button')).toBeVisible();
    } else await expect(page).toHaveURL(/\/login$/);
}

async function writeApi(page, endpoint, method, body) {
    return page.evaluate(async ({endpoint, method, body}) => {
        const response = await fetch('/api' + endpoint, {
            method, credentials: 'same-origin',
            headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content},
            body: JSON.stringify(body),
        });
        const data = await response.json();
        if (data.csrf_token) document.querySelector('meta[name="csrf-token"]').content = data.csrf_token;
        return {status: response.status, data};
    }, {endpoint, method, body});
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

async function choosePhoto(page, bytes, name = 'participant-fixture.png') {
    await page.getByLabel('Choose profile photo', {exact: true}).setInputFiles({name, mimeType: 'image/png', buffer: bytes});
    const crop = page.getByRole('dialog', {name: 'Crop your photo', exact: true});
    await expect(crop).toBeVisible();
    await expect(crop.locator('.crop-viewport img')).toBeVisible();
    await expect(crop.getByRole('button', {name: 'Use this photo', exact: true})).toBeEnabled();
    return crop;
}

async function saveProfile(page, language = 'en') {
    const saving = page.waitForResponse(item => new URL(item.url()).pathname === '/api/account' && item.request().method() === 'POST');
    await page.getByRole('button', {name: language === 'id' ? 'Simpan Perubahan' : 'Save Changes', exact: true}).click();
    const response = await saving;
    expect(response.status()).toBe(200);
    const user = (await response.json()).user;
    await expect(page.getByRole('button', {name: user.preferred_language === 'id' ? 'Edit Profil' : 'Edit Profile', exact: true})).toBeVisible();
    return user;
}

async function logoutFromMenu(page) {
    await page.locator('.account-menu>.user-button').click();
    const response = page.waitForResponse(item => new URL(item.url()).pathname === '/api/logout' && item.request().method() === 'POST');
    await page.getByRole('menuitem', {name: /^(Sign out|Keluar)$/}).click();
    expect((await response).status()).toBe(200);
    await expect(page).toHaveURL(/\/login$/);
}

test.beforeEach(async ({page}) => {
    browserErrors.set(page, []);
    page.on('pageerror', error => browserErrors.get(page).push(error.stack || error.message));
    await page.addInitScript(() => {
        localStorage.setItem('inbaf-language', 'en');
        localStorage.setItem('inbaf-theme', 'light');
    });
});

test.afterEach(async ({page}) => {
    expect(browserErrors.get(page) || [], 'Uncaught browser JavaScript errors').toEqual([]);
});

test('participant account menu edits complete details and a staged photo, synchronizes avatars and restores the saved language on another device', async ({page, browser}) => {
    test.setTimeout(150000);
    const account = fixture('participant-edit');
    await loginAs(page, account);
    const trigger = page.getByRole('button', {name: 'Account menu', exact: true});
    const menu = page.getByRole('menu', {name: 'Participant account menu', exact: true});
    await trigger.click();
    await expect(menu.getByRole('menuitem')).toHaveCount(4);
    for (const name of ['My Profile', 'Edit Profile', 'Change Password', 'Sign out']) await expect(menu.getByRole('menuitem', {name, exact: true})).toBeVisible();
    await expect(menu.getByRole('menuitem', {name: 'My Profile', exact: true})).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(menu.getByRole('menuitem', {name: 'Edit Profile', exact: true})).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(menu).toHaveCount(0);
    await expect(trigger).toBeFocused();
    await trigger.click();
    await page.locator('.breadcrumb').click();
    await expect(menu).toHaveCount(0);
    await trigger.click();
    await menu.getByRole('menuitem', {name: 'Edit Profile', exact: true}).click();
    await expect(page).toHaveURL(/\/profile\?edit=1$/);
    await expect(page.getByLabel('Full name', {exact: true})).toBeVisible();
    await expect(page.getByRole('button', {name: 'Upload Photo', exact: true})).toBeVisible();

    const input = page.getByLabel('Choose profile photo', {exact: true});
    await input.setInputFiles({name: 'not-a-photo.txt', mimeType: 'text/plain', buffer: Buffer.from('This is not a photograph.')});
    await expect(page.locator('.account-photo-actions [role="alert"]')).toContainText('Choose a JPG, JPEG, PNG or WebP photo.');
    await input.setInputFiles({name: 'oversized.png', mimeType: 'image/png', buffer: Buffer.alloc(2 * 1024 * 1024 + 1)});
    await expect(page.locator('.account-photo-actions [role="alert"]')).toContainText('2 MB or smaller');
    await expect(page.getByRole('dialog')).toHaveCount(0);

    const nextName = 'QA Updated Participant', nextEmail = account.email.replace('@', '.updated@');
    await page.getByLabel('Full name', {exact: true}).fill(nextName);
    await page.getByLabel('Country of origin', {exact: true}).fill('Japan');
    await page.getByLabel('Nationality', {exact: true}).fill('Japanese');
    await page.getByLabel('Native language', {exact: true}).fill('Japanese');
    await page.getByLabel('Institution', {exact: true}).fill('QA International Language Institute');
    await page.getByLabel('Phone number', {exact: true}).fill('+81 90 1234 5678');
    await page.getByLabel('Date of birth', {exact: true}).fill('1994-05-06');
    await page.getByRole('combobox', {name: 'Gender (optional)', exact: true}).selectOption('prefer_not_to_say');
    await page.getByLabel('Email address', {exact: true}).fill(nextEmail);
    const crop = await choosePhoto(page, await photo(page));
    await crop.getByRole('slider', {name: /^Zoom/}).focus();
    await page.keyboard.press('End');
    await expect(crop.getByRole('slider', {name: /^Zoom/})).toHaveValue('3');
    await crop.getByRole('button', {name: 'Use this photo', exact: true}).click();
    await expect(page.getByRole('img', {name: 'Profile photo', exact: true})).toHaveAttribute('src', /^blob:/);
    await expect(page.locator('.account-menu .user-label strong')).toHaveText(account.name);
    const unsaved = (await (await page.request.get('/api/account')).json()).user;
    expect(unsaved.name).toBe(account.name);
    expect(unsaved.avatar_url).toBeNull();
    expect(unsaved.profile.phone).toBeNull();

    const saved = await saveProfile(page);
    expect(saved.name).toBe(nextName); expect(saved.email).toBe(nextEmail);
    expect(saved.role).toBe('participant'); expect(saved.active).toBe(true);
    expect(saved.profile).toMatchObject({country: 'Japan', nationality: 'Japanese', native_language: 'Japanese', institution: 'QA International Language Institute', phone: '+81 90 1234 5678', date_of_birth: '1994-05-06', gender: 'prefer_not_to_say'});
    expect(saved.avatar_url).toMatch(/^\/api\/account\/photo\?v=/);
    await expect(page).toHaveURL(/\/profile$/);
    await expect(page.locator('.account-menu .user-label strong')).toHaveText(nextName);
    await expect(page.locator('.account-menu>.user-button .account-avatar img')).toHaveAttribute('src', saved.avatar_url);
    await expect(page.locator('.account-summary')).toContainText('Japanese');
    await expect(page.locator('.account-status')).toHaveText('Active');
    await expect(page.locator('.participant-account-dates')).toContainText('Last login');
    await expect(page.locator('.participant-details')).toContainText('completed test results or issued certificates');
    const stored = (await (await page.request.get('/api/account')).json()).user;
    expect(stored.profile).toEqual(saved.profile);
    const avatar = await page.request.get(saved.avatar_url);
    expect(avatar.status()).toBe(200);
    expect(avatar.headers()['content-type']).toContain('image/jpeg');
    expect(avatar.headers()['cache-control']).toContain('no-store');
    await page.goto('/dashboard');
    await expect(page.locator('.dashboard-profile-avatar img')).toHaveAttribute('src', saved.avatar_url);
    await expect(page.locator('.page-heading h1')).toContainText('QA');

    await page.goto('/profile?edit=1');
    await expect(page.getByLabel('Phone number', {exact: true})).toHaveValue('+81 90 1234 5678');
    await page.getByRole('combobox', {name: 'Preferred interface language', exact: true}).selectOption('id');
    const localized = await saveProfile(page);
    expect(localized.preferred_language).toBe('id');
    await expect(page.locator('html')).toHaveAttribute('lang', 'id');
    await expect(page.getByRole('heading', {name: 'Profil Saya', exact: true})).toBeVisible();
    expect(await page.evaluate(() => localStorage.getItem('inbaf-language'))).toBe('id');

    const fresh = await browser.newContext({baseURL: 'http://127.0.0.1:8001'});
    try {
        const freshPage = await fresh.newPage();
        await freshPage.addInitScript(() => localStorage.setItem('inbaf-language', 'en'));
        await loginAs(freshPage, {...account, email: nextEmail});
        await expect(freshPage.locator('html')).toHaveAttribute('lang', 'id');
        await expect(freshPage.getByRole('button', {name: 'Menu akun', exact: true})).toBeVisible();
        await freshPage.goto('/profile');
        await expect(freshPage.getByRole('heading', {name: 'Profil Saya', exact: true})).toBeVisible();
        await expect(freshPage.locator('.participant-details')).toContainText('+81 90 1234 5678');
        expect((await (await freshPage.request.get('/api/account')).json()).user.preferred_language).toBe('id');
    } finally {await fresh.close();}
});

test('participant photo replacement, removal and cancellation remain usable in dark extra large desktop, tablet and mobile layouts', async ({page}) => {
    test.setTimeout(90000);
    const account = fixture('participant-responsive');
    await page.addInitScript(() => localStorage.setItem('inbaf-theme', 'dark'));
    await loginAs(page, account);
    expect((await writeApi(page, '/preferences', 'PUT', {text_size: 'extra_large'})).status).toBe(200);
    await page.goto('/profile');
    await expect(page.locator('html')).toHaveAttribute('data-text-size', 'extra_large');
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expect(page.locator('.account-page .text-size-control')).toHaveCount(0);
    for (const width of [1440, 768, 390, 320]) {
        await page.setViewportSize({width, height: 1000});
        await noHorizontalOverflow(page);
        await page.getByRole('button', {name: 'Account menu', exact: true}).click();
        await expect(page.getByRole('menu', {name: 'Participant account menu', exact: true})).toBeInViewport();
        await noHorizontalOverflow(page);
        await page.keyboard.press('Escape');
    }
    await page.screenshot({path: 'tmp/qa/participant-profile-dark-xl-320.png', fullPage: true});
    await page.getByRole('button', {name: 'Edit Profile', exact: true}).click();
    await page.getByLabel('Full name', {exact: true}).fill('Unsaved participant name');
    const firstCrop = await choosePhoto(page, await photo(page));
    await noHorizontalOverflow(page);
    expect(await firstCrop.evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1);
    await page.screenshot({path: 'tmp/qa/participant-crop-dark-xl-320.png'});
    await page.keyboard.press('Escape');
    await expect(firstCrop).toHaveCount(0);
    await page.locator('.participant-edit-form').getByRole('button', {name: 'Cancel', exact: true}).click();
    expect((await (await page.request.get('/api/account')).json()).user.name).toBe(account.name);

    await page.getByRole('button', {name: 'Edit Profile', exact: true}).click();
    const crop = await choosePhoto(page, await photo(page));
    await crop.getByRole('button', {name: 'Use this photo', exact: true}).click();
    await page.getByLabel('Phone number', {exact: true}).fill('+62 812 3456 7890');
    await noHorizontalOverflow(page);
    const actions = page.locator('.participant-edit-form .account-actions');
    await expect(actions.getByRole('button', {name: 'Save Changes', exact: true})).toBeVisible();
    await expect(actions.getByRole('button', {name: 'Cancel', exact: true})).toBeVisible();
    expect(await actions.locator('button').evaluateAll(buttons => buttons.every(button => button.scrollWidth <= button.clientWidth + 1))).toBeTruthy();
    await page.screenshot({path: 'tmp/qa/participant-profile-edit-dark-xl-320.png', fullPage: true});
    const saved = await saveProfile(page);
    expect(saved.profile.phone).toBe('+62 812 3456 7890');
    await expect(page.getByRole('img', {name: 'Profile photo', exact: true})).toHaveAttribute('src', saved.avatar_url);

    await page.getByRole('button', {name: 'Edit Profile', exact: true}).click();
    await expect(page.getByRole('button', {name: 'Change Photo', exact: true})).toBeVisible();
    const stagedCrop = await choosePhoto(page, await photo(page, '#493a84'), 'replacement.png');
    await stagedCrop.getByRole('button', {name: 'Use this photo', exact: true}).click();
    await page.locator('.participant-edit-form').getByRole('button', {name: 'Cancel', exact: true}).click();
    expect((await (await page.request.get('/api/account')).json()).user.avatar_url).toBe(saved.avatar_url);
    await expect(page.getByRole('img', {name: 'Profile photo', exact: true})).toHaveAttribute('src', saved.avatar_url);

    await page.getByRole('button', {name: 'Edit Profile', exact: true}).click();
    const replacementCrop = await choosePhoto(page, await photo(page, '#493a84'), 'replacement.png');
    await replacementCrop.getByRole('button', {name: 'Use this photo', exact: true}).click();
    const replacement = await saveProfile(page);
    expect(replacement.profile.phone).toBe(saved.profile.phone);
    expect(replacement.avatar_url).not.toBe(saved.avatar_url);
    await expect(page.locator('.account-menu>.user-button .account-avatar img')).toHaveAttribute('src', replacement.avatar_url);

    await page.getByRole('button', {name: 'Edit Profile', exact: true}).click();
    await page.getByRole('button', {name: 'Remove Photo', exact: true}).click();
    const removal = page.getByRole('dialog', {name: 'Remove profile photo?', exact: true});
    await removal.getByRole('button', {name: 'Keep Photo', exact: true}).click();
    await expect(page.getByRole('img', {name: 'Profile photo', exact: true})).toHaveAttribute('src', replacement.avatar_url);
    await page.getByRole('button', {name: 'Remove Photo', exact: true}).click();
    await removal.getByRole('button', {name: 'Remove Photo', exact: true}).click();
    await expect(page.getByRole('img', {name: 'Profile photo', exact: true})).toHaveCount(0);
    expect((await (await page.request.get('/api/account')).json()).user.avatar_url).toBe(replacement.avatar_url);
    expect((await saveProfile(page)).avatar_url).toBeNull();
    await expect(page.locator('.account-menu>.user-button .account-avatar img')).toHaveCount(0);
    await page.reload();
    expect((await page.request.get('/api/account/photo')).status()).toBe(404);
    expect((await (await page.request.get('/api/account')).json()).user.profile.phone).toBe(saved.profile.phone);
    await noHorizontalOverflow(page);
});

test('participant password policy, visibility and strength validate correctly, preserving this session and revoking other sessions', async ({page, browser}) => {
    test.setTimeout(90000);
    const account = fixture('participant-password'), nextPassword = 'ParticipantNewPass456!';
    const otherContext = await browser.newContext({baseURL: 'http://127.0.0.1:8001'});
    try {
        const otherPage = await otherContext.newPage();
        await otherPage.addInitScript(() => localStorage.setItem('inbaf-language', 'en'));
        await loginAs(page, account); await loginAs(otherPage, account);
        await page.goto('/profile?tab=password');
        const current = page.getByLabel('Current Password', {exact: true}), password = page.getByLabel('New Password', {exact: true}), confirmation = page.getByLabel('Confirm New Password', {exact: true});
        await current.fill(account.password); await password.fill('weakpass'); await confirmation.fill('weakpass');
        await expect(page.getByRole('progressbar', {name: 'Password strength', exact: true})).toHaveAttribute('aria-valuetext', 'Needs improvement');
        await page.getByRole('button', {name: 'Show New Password', exact: true}).click();
        await expect(password).toHaveAttribute('type', 'text');
        await page.getByRole('button', {name: 'Hide New Password', exact: true}).click();
        await expect(password).toHaveAttribute('type', 'password');
        await page.getByRole('button', {name: 'Update Password', exact: true}).click();
        await expect(page.locator('#account-password-error')).toContainText('uppercase, lowercase, a number and a symbol');
        await password.fill(account.password); await confirmation.fill(account.password);
        await page.getByRole('button', {name: 'Update Password', exact: true}).click();
        await expect(page.locator('#account-password-error')).toContainText('different from your current password');
        await password.fill(nextPassword); await confirmation.fill('DifferentPassword789!');
        await expect(page.getByRole('progressbar', {name: 'Password strength', exact: true})).toHaveAttribute('aria-valuenow', '100');
        await expect(page.getByRole('progressbar', {name: 'Password strength', exact: true})).toHaveAttribute('aria-valuetext', 'Strong');
        await page.getByRole('button', {name: 'Update Password', exact: true}).click();
        await expect(page.locator('#account-password_confirmation-error')).toContainText('does not match');
        await current.fill('IncorrectCurrent123!'); await confirmation.fill(nextPassword);
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
    } finally {await otherContext.close();}
});

test('participant secure logout protects private pages and preserves a saved practice answer and its original untimed attempt for resumption', async ({page}) => {
    test.setTimeout(90000);
    const account = fixture('participant-logout');
    await loginAs(page, account);
    expect((await page.request.get('/api/admin/questions')).status()).toBe(403);
    const tampered = await writeApi(page, '/account', 'PUT', {name: 'Unauthorized owner change', email: account.email, user_id: account.id + 1});
    expect(tampered.status).toBe(422);
    const untouched = (await (await page.request.get('/api/account')).json()).user;
    expect(untouched.id).toBe(account.id); expect(untouched.name).toBe(account.name);
    const bootstrap = await (await page.request.get('/api/bootstrap')).json();
    const practice = bootstrap.packages.find(item => item.test_type === 'practice');
    expect(practice).toBeTruthy();
    const started = await writeApi(page, '/packages/' + practice.id + '/attempts', 'POST', {});
    expect(started.status).toBe(201);
    const payload = started.data, attemptId = payload.attempt.id;
    expect(payload.attempt.expires_at).toBeNull();
    const questions = payload.sections.flatMap(section => section.questions);
    const question = questions.find(item => item.type === 'single_choice' && item.options.length);
    expect(question).toBeTruthy();
    const response = question.options[0].id;
    expect((await writeApi(page, '/attempts/' + attemptId + '/answers', 'PUT', {question_key: question.key, response, marked: true})).status).toBe(200);
    await page.goto('/exam/' + attemptId);
    await page.getByRole('button', {name: 'Question ' + (questions.findIndex(item => item.key === question.key) + 1), exact: true}).click();
    await expect(page.locator('.question-title')).toHaveText(question.title);
    await expect(page.locator('.choice-option input').first()).toBeChecked();
    await expect(page.locator('.exam-untimed')).toHaveText('No time limit');
    await expect(page.locator('.exam-timer')).toHaveCount(0);
    const before = await (await page.request.get('/api/attempts/' + attemptId)).json();
    expect(before.answers[question.key].response).toBe(response);
    const document = await page.goto('/dashboard');
    expect(document.headers()['cache-control']).toContain('no-store');
    await logoutFromMenu(page);
    expect((await page.request.get('/api/account')).status()).toBe(401);
    expect((await page.request.get('/api/attempts/' + attemptId)).status()).toBe(401);
    await page.goBack();
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.locator('.main-content')).toHaveCount(0);
    for (const route of ['/dashboard', '/profile', '/profile?tab=password', '/exam/' + attemptId]) {
        await page.goto(route);
        await expect(page).toHaveURL(/\/login$/);
        await expect(page.locator('.account-page,.exam-shell,.main-content')).toHaveCount(0);
    }
    await loginAs(page, account);
    const resumed = await writeApi(page, '/packages/' + practice.id + '/attempts', 'POST', {});
    expect(resumed.status).toBe(201);
    expect(resumed.data.attempt.id).toBe(attemptId);
    const after = await (await page.request.get('/api/attempts/' + attemptId)).json();
    expect(after.attempt.status).toBe('in_progress');
    expect(after.attempt.started_at).toBe(before.attempt.started_at);
    expect(after.attempt.expires_at).toBe(before.attempt.expires_at);
    expect(after.answers[question.key].response).toBe(response);
    expect(after.answers[question.key].marked).toBe(true);
    await page.goto('/exam/' + attemptId);
    await page.getByRole('button', {name: 'Question ' + (questions.findIndex(item => item.key === question.key) + 1), exact: true}).click();
    await expect(page.locator('.choice-option input').first()).toBeChecked();
    await expect(page.locator('.exam-untimed')).toHaveText('No time limit');
    await expect(page.locator('.exam-timer')).toHaveCount(0);
});

test('issued certificate holder names stay immutable on participant cards, public verification and the Superadmin list after a profile rename', async ({page}) => {
    test.setTimeout(90000);
    const account = fixture('participant-certificate'), certificate = account.certificate;
    const renamed = 'QA Renamed Certificate Holder';
    await loginAs(page, account);
    await page.goto('/certificates');
    const card = page.locator('.certificate-card').filter({hasText: certificate.number});
    await expect(card.locator('.certificate-visual>strong')).toHaveText(certificate.holder_name);
    const before = (await (await page.request.get('/api/certificates')).json()).certificates.find(item => item.id === certificate.id);
    expect(before.payload.holder_name).toBe(account.name);
    const verificationBefore = await (await page.request.get('/api/verify/' + certificate.token)).json();
    expect(verificationBefore.status).toBe('valid');
    expect(verificationBefore.certificate.holder_name).toBe(account.name);

    await page.goto('/profile?edit=1');
    await page.getByLabel('Full name', {exact: true}).fill(renamed);
    const saved = await saveProfile(page);
    expect(saved.name).toBe(renamed);
    await expect(page.locator('.account-menu .user-label strong')).toHaveText(renamed);
    await page.goto('/certificates');
    await expect(card).toBeVisible();
    await page.screenshot({path: 'tmp/qa/participant-certificate-after-profile-rename.png', fullPage: true});
    await expect(card.locator('.certificate-visual>strong')).toHaveText(certificate.holder_name);
    await page.reload();
    await expect(card.locator('.certificate-visual>strong')).toHaveText(certificate.holder_name);
    const after = (await (await page.request.get('/api/certificates')).json()).certificates.find(item => item.id === certificate.id);
    expect(after.payload).toEqual(before.payload);
    expect(after.signature).toBe(before.signature);
    const result = await (await page.request.get('/api/results/' + certificate.attempt_id)).json();
    expect(result.certificate.payload.holder_name).toBe(account.name);
    const verificationAfter = await (await page.request.get('/api/verify/' + certificate.token)).json();
    expect(verificationAfter).toEqual(verificationBefore);
    await page.goto('/verify/' + certificate.token);
    await expect(page.locator('.verified-card')).toContainText(account.name);
    await expect(page.locator('.verified-card')).not.toContainText(renamed);
    await expect(page.locator('.verified-card')).toContainText('Certificate verified');

    await page.goto('/dashboard');
    await logoutFromMenu(page);
    await login(page, 'superadmin');
    await page.goto('/admin/certificates');
    const row = page.locator('tbody tr').filter({hasText: certificate.number});
    await expect(row).toHaveCount(1);
    await expect(row.locator('td').nth(1)).toHaveText(certificate.holder_name);
    await expect(row.locator('td').nth(1)).not.toContainText(renamed);
});
