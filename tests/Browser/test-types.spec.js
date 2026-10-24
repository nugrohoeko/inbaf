import {test, expect} from '@playwright/test';
import fs from 'node:fs';
import {login, noHorizontalOverflow} from './helpers.js';

const browserErrors = new WeakMap();

async function writeApi(page, path, method, body) {
    return page.evaluate(async ({path, method, body}) => {
        const response = await fetch('/api' + path, {
            method, credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content},
            body: JSON.stringify(body),
        });
        return {status: response.status, body: await response.json()};
    }, {path, method, body});
}

test.beforeEach(async ({page}) => {
    browserErrors.set(page, []);
    page.on('pageerror', error => browserErrors.get(page).push(error.message));
    page.on('dialog', dialog => dialog.accept());
    await page.addInitScript(() => {
        localStorage.setItem('inbaf-language', 'en');
        localStorage.setItem('inbaf-theme', 'light');
    });
});

test.afterEach(async ({page}) => {
    expect(browserErrors.get(page), 'Uncaught browser errors').toEqual([]);
});

test('superadmin saves an independent test type policy through the editor', async ({page}) => {
    await login(page, 'superadmin');
    const original = (await (await page.request.get('/api/admin/test-types')).json()).test_types;
    const practice = original.find(type => type.code === 'practice');
    try {
        await page.goto('/admin/settings?tab=test-types');
        const editor = page.locator('.test-type-policy-form');
        await editor.getByRole('button', {name: practice.name_en, exact: true}).click();
        await editor.getByLabel('English name', {exact: true}).fill('Practice Test — UI policy check');
        await editor.getByLabel('Maximum displayed score', {exact: true}).fill('333');
        await editor.getByRole('combobox', {name: 'Competency scoring method', exact: true}).selectOption('weighted_questions');
        const saving = page.waitForResponse(response => response.url().endsWith('/api/admin/test-types/practice') && response.request().method() === 'PUT');
        await editor.getByRole('button', {name: 'Save test type policy', exact: true}).click();
        expect((await saving).status()).toBe(200);
        await expect(editor.getByLabel('English name', {exact: true})).toHaveValue('Practice Test — UI policy check');
        const updated = (await (await page.request.get('/api/admin/test-types')).json()).test_types;
        expect(updated.find(type => type.code === 'practice').score_scale).toBe(333);
        for (const code of ['placement', 'certification']) {
            expect(updated.find(type => type.code === code)).toEqual(original.find(type => type.code === code));
        }
    } finally {
        expect((await writeApi(page, '/admin/test-types/practice', 'PUT', practice)).status).toBe(200);
    }
});

test('question confidentiality persists and usage history opens without exposing it to practice', async ({page}) => {
    await login(page, 'superadmin');
    await page.goto('/admin/questions');
    await page.getByRole('button', {name: 'Create new', exact: true}).click();
    const dialog = page.getByRole('dialog', {name: 'Question bank', exact: true});
    await dialog.getByLabel('Title', {exact: true}).fill('E2E confidential certification question');
    await dialog.locator('[contenteditable="true"]').first().fill('Pilih sapaan yang tepat.');
    for (let i = 1; i <= 4; i++) await dialog.getByPlaceholder('Option ' + i, {exact: true}).fill(['Selamat pagi', 'Merci', 'Bonjour', 'Good night'][i - 1]);
    await dialog.getByRole('checkbox', {name: /^Confidential certification question/}).check();
    const permissions = dialog.getByRole('group', {name: 'Allowed test types', exact: true});
    await expect(permissions.getByRole('checkbox', {name: 'Practice Test', exact: true})).toBeDisabled();
    await expect(permissions.getByRole('checkbox', {name: 'Practice Test', exact: true})).not.toBeChecked();
    await expect(permissions.getByRole('checkbox', {name: 'Certification Test', exact: true})).toBeChecked();
    await dialog.getByLabel('Maximum uses (leave empty for no limit)', {exact: true}).fill('2');
    const saving = page.waitForResponse(response => response.url().endsWith('/api/admin/questions') && response.request().method() === 'POST');
    await dialog.getByRole('button', {name: 'Save changes', exact: true}).click();
    const response = await saving;
    expect(response.status()).toBe(201);
    const created = await response.json();
    const question = created.question || created;
    expect(question.allowed_test_types).toEqual(['certification']);
    expect(question.confidential).toBe(true);
    expect(question.usage_limit).toBe(2);
    await expect(dialog).not.toBeVisible();
    await page.getByRole('textbox', {name: 'Search records', exact: true}).fill('E2E confidential certification question');
    const row = page.locator('tbody tr').filter({hasText: 'E2E confidential certification question'});
    await row.getByRole('button', {name: 'Question usage history', exact: true}).click();
    const history = page.getByRole('dialog', {name: 'Question usage history', exact: true});
    await expect(history).toContainText('This question has not been used in an attempt yet.');
    await page.setViewportSize({width: 390, height: 1000});
    await noHorizontalOverflow(page);
    await history.getByRole('button', {name: 'Close', exact: true}).click();
    await row.getByRole('button', {name: 'Edit', exact: true}).click();
    await expect(dialog.getByRole('checkbox', {name: /^Confidential certification question/})).toBeChecked();
    await expect(dialog.getByLabel('Maximum uses (leave empty for no limit)', {exact: true})).toHaveValue('2');
});

test('package builder saves its type, inherited overrides, and difficulty selection', async ({page}) => {
    await login(page, 'superadmin');
    await page.goto('/admin/packages');
    await page.getByRole('button', {name: 'Create new', exact: true}).click();
    const dialog = page.getByRole('dialog', {name: 'Assessment packages', exact: true});
    await dialog.getByRole('combobox', {name: /^Test type/}).selectOption('practice');
    await dialog.getByLabel('Code', {exact: true}).fill('E2E-PRACTICE-POLICY');
    await dialog.getByLabel('Assessment name', {exact: true}).fill('E2E practice with explicit overrides');
    await dialog.getByRole('combobox', {name: 'Enable assessment timers', exact: true}).selectOption('false');
    await dialog.getByRole('spinbutton', {name: /^Maximum attempts per package/}).fill('2');
    await dialog.getByRole('button', {name: 'Add section', exact: true}).click();
    const section = dialog.locator('.section-builder-card');
    await section.getByLabel('Section name', {exact: true}).fill('Practice grammar');
    await section.getByRole('checkbox', {name: 'Easy', exact: true}).check();
    const saving = page.waitForResponse(response => response.url().endsWith('/api/admin/packages') && response.request().method() === 'POST');
    await dialog.getByRole('button', {name: 'Save changes', exact: true}).click();
    const response = await saving;
    expect(response.status()).toBe(201);
    const created = await response.json();
    const assessment = created.package || created;
    expect(assessment.test_type).toBe('practice');
    expect(assessment.certificate_enabled).toBe(false);
    expect(assessment.policy_overrides).toEqual({timer_enabled: false, attempts_limit: 2});
    expect(assessment.sections[0].difficulties).toEqual(['easy']);
    await expect(dialog).not.toBeVisible();
});

test('three participant cards and saved extra large text remain usable on small screens', async ({page}) => {
    await login(page);
    await expect(page.locator('.test-type-card')).toHaveCount(3);
    for (const title of ['Practice Test', 'Placement Test', 'Certification Test']) {
        await expect(page.locator('.test-type-card').getByRole('heading', {name: title, exact: true})).toBeVisible();
    }
    const size = page.getByRole('combobox', {name: 'Text size', exact: true});
    const before = await page.locator('.test-type-card h3').first().evaluate(element => parseFloat(getComputedStyle(element).fontSize));
    await size.selectOption('extra_large');
    await expect(size).toHaveAttribute('aria-busy', 'false');
    await expect.poll(() => page.locator('.test-type-card h3').first().evaluate(element => parseFloat(getComputedStyle(element).fontSize))).toBeGreaterThan(before);
    await page.evaluate(() => localStorage.removeItem('inbaf-text-size'));
    await page.reload();
    await expect(size).toHaveValue('extra_large');
    for (const width of [1440, 768, 390, 320]) {
        await page.setViewportSize({width, height: 1000});
        await noHorizontalOverflow(page);
    }
    await page.screenshot({path: 'tmp/qa/test-types-extra-large-320.png', fullPage: true});
    await page.setViewportSize({width: 1440, height: 1000});
    await size.selectOption('normal');
    await expect(size).toHaveAttribute('aria-busy', 'false');
});

test('practice starts without a clock and keeps its result separate from certification', async ({page}) => {
    await login(page);
    const bootstrap = await (await page.request.get('/api/bootstrap')).json();
    const assessment = bootstrap.packages.find(item => item.test_type === 'practice');
    expect(assessment).toBeTruthy();
    await page.goto('/tests/' + assessment.id);
    await expect(page.locator('.readiness-main')).toContainText('No time limit');
    await page.getByRole('checkbox', {name: 'I have read the instructions and I am ready to begin.', exact: true}).check();
    const starting = page.waitForResponse(response => response.url().endsWith('/api/packages/' + assessment.id + '/attempts') && response.request().method() === 'POST');
    await page.getByRole('button', {name: 'Start practice', exact: true}).click();
    const response = await starting;
    expect(response.status()).toBe(201);
    const payload = await response.json();
    expect(payload.attempt.expires_at).toBeNull();
    await expect(page.locator('.exam-untimed')).toHaveText('No time limit');
    await expect(page.locator('.exam-timer')).toHaveCount(0);
    const size = page.locator('.exam-header').getByRole('combobox', {name: 'Text size', exact: true});
    await size.selectOption('large');
    await expect(size).toHaveAttribute('aria-busy', 'false');
    const onServer = await (await page.request.get('/api/attempts/' + payload.attempt.id)).json();
    expect(onServer.attempt.expires_at).toBeNull();
    await page.locator('.exam-sidebar').getByRole('button', {name: 'Finish assessment', exact: true}).click();
    const submitting = page.waitForResponse(response => response.url().endsWith('/api/attempts/' + payload.attempt.id + '/submit'));
    await page.getByRole('dialog').getByRole('button', {name: 'Submit assessment', exact: true}).click();
    expect((await submitting).status()).toBe(200);
    await expect(page).toHaveURL(new RegExp('/results/' + payload.attempt.id + '$'));
    await expect(page.locator('.result-hero')).toContainText('Practice Test');
    await expect(page.locator('.result-score')).toContainText('Practice score');
    await expect(page.locator('.answer-review')).toContainText('Answers & explanations');
    const result = await (await page.request.get('/api/results/' + payload.attempt.id)).json();
    expect(result.attempt.test_type).toBe('practice');
    expect(result.certificate || result.attempt.certificate).toBeFalsy();
    await page.goto('/results?test_type=certification');
    await expect(page.locator('.main-content')).not.toContainText(assessment.name);
});

test('placement results provide a printable report with internal level information', async ({page}) => {
    await login(page);
    const bootstrap = await (await page.request.get('/api/bootstrap')).json();
    const assessment = bootstrap.packages.find(item => item.test_type === 'placement');
    const started = await writeApi(page, '/packages/' + assessment.id + '/attempts', 'POST', {});
    expect(started.status).toBe(201);
    const attemptId = started.body.attempt.id;
    expect((await writeApi(page, '/attempts/' + attemptId + '/submit', 'POST', {})).status).toBe(200);
    await page.goto('/results/' + attemptId);
    await expect(page.locator('.result-hero')).toContainText('Placement Test');
    await expect(page.locator('.result-level')).not.toHaveText('');
    await expect(page.locator('.main-content')).toContainText('Institutional placement levels');
    const report = page.getByRole('link', {name: 'Print placement report', exact: true});
    await expect(report).toBeVisible();
    const response = await page.request.get(await report.getAttribute('href'));
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('application/pdf');
    const bytes = await response.body();
    expect(bytes.subarray(0, 4).toString()).toBe('%PDF');
    fs.mkdirSync('tmp/pdfs', {recursive: true});
    fs.writeFileSync('tmp/pdfs/placement-report.pdf', bytes);
    await expect(page.getByRole('link', {name: 'Download PDF', exact: true})).toHaveCount(0);
});
