import {test, expect} from '@playwright/test';
import fs from 'node:fs';
import {login} from './helpers.js';
import {observeHttp, saveHttpMetrics, timedHttp} from './final-qa-metrics.js';

// Fixtures are created through the real authenticated API in the guarded
// browser.sqlite test environment. No application response is mocked.
test.use({screenshot: 'off', trace: 'off'});
const errors = new WeakMap();
const metrics = new WeakMap();
const fixturePackages = new WeakMap();

test.beforeEach(async ({page}) => {
    errors.set(page, []);
    metrics.set(page, observeHttp(page));
    page.on('pageerror', error => errors.get(page).push(error.message));
    page.on('dialog', dialog => dialog.type() === 'prompt' ? dialog.accept('Isolated final QA revocation check') : dialog.accept());
    await page.addInitScript(() => {
        localStorage.setItem('inbaf-language', 'en');
        localStorage.setItem('inbaf-theme', 'light');
    });
});

test.afterEach(async ({page}, testInfo) => {
    if (fixturePackages.has(page)) {
        await page.context().setOffline(false);
        await login(page, 'superadmin');
        expect((await write(page, '/admin/packages/' + fixturePackages.get(page), 'DELETE')).status).toBe(200);
    }
    saveHttpMetrics(testInfo, metrics.get(page));
    expect(errors.get(page), 'Uncaught browser JavaScript errors').toEqual([]);
});

async function write(page, endpoint, method, body = {}) {
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

async function setupPackage(page, kind, manual = false) {
    await login(page, 'superadmin');
    const suffix = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 7)}`;
    const bootstrap = await (await page.request.get('/api/bootstrap')).json();
    const grammar = bootstrap.competencies.find(row => row.code === 'grammar');
    const writing = bootstrap.competencies.find(row => row.code === 'writing');
    const base = {competency_id: grammar.id, type: 'single_choice', instructions: 'Final browser QA fixture.', prompt: 'Pilih sapaan yang tepat.', difficulty: 'easy', weight: 1, status: 'active', options: [{id: 'a', text: 'Selamat pagi'}, {id: 'b', text: 'Good night'}], answer_schema: {correct: 'a'}, metadata: {}, allowed_test_types: [kind]};
    const objective = await write(page, '/admin/questions', 'POST', {...base, code: 'FINAL-A-' + suffix, title: 'Final QA objective ' + suffix});
    expect(objective.status).toBe(201);
    const second = await write(page, '/admin/questions', 'POST', manual ? {
        ...base, code: 'FINAL-B-' + suffix, title: 'Final QA essay ' + suffix, competency_id: writing.id, type: 'essay',
        prompt: 'Tulis permintaan perubahan jadwal secara santun.', options: [], answer_schema: {},
        metadata: {manual: true, rubric: [{name: 'Clarity', description: 'Purpose is clear.', weight: 3}, {name: 'Register', description: 'Polite language is appropriate.', weight: 1}]},
    } : {...base, code: 'FINAL-B-' + suffix, title: 'Final QA second objective ' + suffix});
    expect(second.status).toBe(201);
    const sections = manual ? [
        {title: 'Final QA grammar', competency_id: grammar.id, position: 0, duration_minutes: 5, weight: 1, question_count: 1, question_ids: [objective.data.id], randomize: false},
        {title: 'Final QA writing', competency_id: writing.id, position: 1, duration_minutes: 5, weight: 1, question_count: 1, question_ids: [second.data.id], randomize: false},
    ] : [{title: 'Final QA recovery', competency_id: grammar.id, position: 0, duration_minutes: 10, weight: 1, question_count: 2, question_ids: [objective.data.id, second.data.id], randomize: false}];
    const created = await write(page, '/admin/packages', 'POST', {
        code: 'FINAL-' + suffix, name: 'Final QA ' + kind + ' ' + suffix, test_type: kind, published: true,
        duration_minutes: 10, attempts_limit: 1, pass_score: 60, certificate_enabled: manual,
        question_selection: 'manual', settings: {lock_sections: false, randomize_options: false},
        policy_overrides: {lock_sections: false, randomize_options: false, security_monitoring: false, require_fullscreen: false, attempts_limit: 1}, sections,
    });
    expect(created.status).toBe(201);
    fixturePackages.set(page, created.data.id);
    await page.locator('.sidebar').getByRole('button', {name: 'Sign out', exact: true}).click();
    await expect(page).toHaveURL(/\/login$/);
    return {package: created.data, objective: objective.data, second: second.data, suffix};
}

async function register(page, suffix) {
    const account = {name: 'Final QA Participant', email: `final-${suffix}@browser-qa.example.test`, password: 'FinalBrowser#2026'};
    await page.goto('/register');
    await page.locator('#auth-name').fill(account.name);
    await page.locator('#auth-email').fill(account.email);
    await page.locator('#auth-password').fill(account.password);
    await page.locator('#auth-password-confirmation').fill(account.password);
    const registered = page.waitForResponse(response => response.url().endsWith('/api/register') && response.request().method() === 'POST');
    await page.locator('.stack-form button.button').click();
    expect((await registered).status()).toBe(201);
    await expect(page).toHaveURL(/\/dashboard$/);
    return account;
}

async function loginAs(page, account) {
    await page.goto('/login');
    await page.locator('#auth-email').fill(account.email);
    await page.locator('#auth-password').fill(account.password);
    const signedIn = page.waitForResponse(response => response.url().endsWith('/api/login') && response.request().method() === 'POST');
    await page.locator('.stack-form button.button').click();
    expect((await signedIn).status()).toBe(200);
    await expect(page).toHaveURL(/\/dashboard$/);
}

async function start(page, assessment, kind) {
    await page.goto('/tests/' + assessment.id);
    await expect(page.locator('.readiness-main h2')).toHaveText(assessment.name);
    await page.getByRole('checkbox', {name: 'I have read the instructions and I am ready to begin.', exact: true}).check();
    const starting = page.waitForResponse(response => response.url().endsWith('/api/packages/' + assessment.id + '/attempts') && response.request().method() === 'POST');
    await page.getByRole('button', {name: kind === 'practice' ? 'Start practice' : 'Take a certification test', exact: true}).click();
    const response = await starting;
    expect(response.status()).toBe(201);
    const payload = await response.json();
    await expect(page).toHaveURL(new RegExp('/exam/' + payload.attempt.id + '$'));
    await expect(page.locator('.choice-option input').first()).toBeEnabled();
    return {...payload, questions: payload.sections.flatMap(section => section.questions)};
}

function saved(page, attemptId, key) {
    return page.waitForResponse(response => response.url().endsWith('/api/attempts/' + attemptId + '/answers') && response.request().method() === 'PUT' && response.request().postDataJSON()?.question_key === key);
}

async function choose(page, payload, questionIndex, optionIndex = 0) {
    await page.getByRole('button', {name: 'Question ' + (questionIndex + 1), exact: true}).click();
    await expect(page.locator('.choice-option input').first()).toBeEnabled();
    const saving = saved(page, payload.attempt.id, payload.questions[questionIndex].key);
    await page.locator('.choice-option').nth(optionIndex).click();
    expect((await saving).status()).toBe(200);
    await expect(page.locator('.exam-saved')).toContainText('All changes saved');
}

async function finish(page, id) {
    await page.locator('.exam-sidebar').getByRole('button', {name: 'Finish assessment', exact: true}).click();
    const submitting = page.waitForResponse(response => response.url().endsWith('/api/attempts/' + id + '/submit') && response.request().method() === 'POST');
    await page.getByRole('dialog').getByRole('button', {name: 'Submit assessment', exact: true}).click();
    const response = await submitting;
    expect(response.status()).toBe(200);
    await expect(page).toHaveURL(new RegExp('/results/' + id + '$'));
    return response.json();
}

test('real certification reaches weighted manual grading, one certificate, PDF, public verification and revocation', async ({page}) => {
    test.setTimeout(150000);
    const fixture = await setupPackage(page, 'certification', true);
    const account = await register(page, fixture.suffix);
    const payload = await start(page, fixture.package, 'certification');
    await choose(page, payload, 0);
    await page.getByRole('button', {name: 'Question 2', exact: true}).click();
    const saving = saved(page, payload.attempt.id, payload.questions[1].key);
    await page.locator('.response-text textarea').fill('Yth. Ibu Dosen, saya memohon perubahan jadwal konsultasi ke hari Kamis karena harus mengikuti orientasi mahasiswa. Terima kasih atas bantuan Ibu.');
    expect((await saving).status()).toBe(200);
    const submitted = await finish(page, payload.attempt.id);
    expect(submitted.attempt.status).toBe('pending_review');
    expect(submitted.result.total_score).toBeNull();
    await expect(page.locator('.notice-banner')).toContainText('being reviewed');
    await expect(page.getByRole('link', {name: 'Download PDF', exact: true})).toHaveCount(0);
    expect((await (await page.request.get('/api/certificates')).json()).certificates).toEqual([]);
    await page.locator('.sidebar').getByRole('button', {name: 'Sign out', exact: true}).click();
    await login(page, 'superadmin');
    await page.goto('/admin/reviews');
    const row = page.locator('tbody tr').filter({hasText: fixture.second.title});
    await row.getByRole('button', {name: 'Review', exact: true}).click();
    const dialog = page.getByRole('dialog', {name: 'Manual assessment', exact: true});
    await dialog.getByRole('spinbutton', {name: /^Clarity \(0–100\)/}).fill('90');
    await dialog.getByRole('spinbutton', {name: /^Register \(0–100\)/}).fill('70');
    await expect(dialog.locator('.rubric-calculation')).toContainText('85');
    await dialog.getByRole('button', {name: 'Use weighted score', exact: true}).click();
    await expect(dialog.getByLabel('Overall score (0–100)', {exact: true})).toHaveValue('85');
    await dialog.getByLabel('Feedback', {exact: true}).fill('Clear purpose with polite wording; final QA reference rubric score 85.');
    const reviewing = page.waitForResponse(response => /\/api\/admin\/reviews\/\d+$/.test(response.url()) && response.request().method() === 'POST');
    await dialog.getByRole('button', {name: 'Save assessment', exact: true}).click();
    const reviewed = await reviewing;
    expect(reviewed.status()).toBe(200);
    const completed = (await reviewed.json()).attempt;
    expect(completed.status).toBe('completed');
    expect(completed.score).toBe(92.5);
    expect(completed.result.manual_pending).toBe(0);
    await expect(row).toHaveCount(0);
    await page.locator('.sidebar').getByRole('button', {name: 'Sign out', exact: true}).click();
    await loginAs(page, account);
    await page.goto('/results/' + payload.attempt.id);
    await expect(page.locator('.result-score strong')).toHaveText('92.5');
    const result = await (await page.request.get('/api/results/' + payload.attempt.id)).json();
    const certificate = result.certificate;
    expect(certificate.payload.total_score).toBe(92.5);
    expect(certificate.payload.holder_name).toBe(account.name);
    const download = page.getByRole('link', {name: 'Download PDF', exact: true});
    const pdfUrl = await download.getAttribute('href');
    const pdf = await timedHttp(metrics.get(page), 'certificate-pdf', () => page.request.get(pdfUrl));
    expect(pdf.status()).toBe(200);
    expect(pdf.headers()['content-type']).toContain('application/pdf');
    const bytes = await pdf.body();
    expect(bytes.subarray(0, 5).toString()).toBe('%PDF-');
    expect(bytes.subarray(-50).toString()).toContain('%%EOF');
    expect(bytes.length).toBeGreaterThan(10000);
    fs.mkdirSync('tmp/qa/final', {recursive: true});
    fs.writeFileSync('tmp/qa/final/certification-e2e.pdf', bytes);
    // Repeated finalization cannot issue a second logical certificate.
    expect((await write(page, '/attempts/' + payload.attempt.id + '/submit', 'POST')).status).toBe(200);
    const certificates = (await (await page.request.get('/api/certificates')).json()).certificates;
    expect(certificates).toHaveLength(1);
    expect(certificates[0].id).toBe(certificate.id);
    await page.goto('/certificates');
    await page.locator('.certificate-card').filter({hasText: certificate.number}).getByRole('button', {name: 'Verify a certificate', exact: true}).click();
    await expect(page.locator('.verified-card')).toContainText('Certificate verified');
    await expect(page.locator('.verified-card')).toContainText('92.5 / 100');
    const verification = await (await page.request.get('/api/verify/' + certificate.token)).json();
    expect(verification.status).toBe('valid');
    expect(verification.certificate).not.toHaveProperty('email');
    expect(verification.certificate).not.toHaveProperty('user_id');
    await page.goto('/dashboard');
    await page.locator('.sidebar').getByRole('button', {name: 'Sign out', exact: true}).click();
    await login(page, 'superadmin');
    await page.goto('/admin/certificates');
    const certificateRow = page.locator('tbody tr').filter({hasText: certificate.number});
    const revoking = page.waitForResponse(response => response.url().endsWith('/api/admin/certificates/' + certificate.id + '/revoke'));
    await certificateRow.getByRole('button', {name: 'Revoke certificate', exact: true}).click();
    expect((await revoking).status()).toBe(200);
    await expect(certificateRow).toContainText('Revoked');
    await page.locator('.sidebar').getByRole('button', {name: 'Sign out', exact: true}).click();
    await page.goto('/verify/' + certificate.token);
    await expect(page.locator('.verified-card .badge')).toHaveText('revoked');
    const publicResult = await (await page.request.get('/api/verify/' + certificate.token)).json();
    expect(publicResult.status).toBe('revoked');
    expect(publicResult.certificate.number).toBe(certificate.number);
});

test('offline responses and review marks survive reconnect, refresh and missing-session reauthentication', async ({page, context}) => {
    test.setTimeout(150000);
    const fixture = await setupPackage(page, 'practice');
    const account = await register(page, fixture.suffix);
    const payload = await start(page, fixture.package, 'practice');
    await choose(page, payload, 0);
    await page.getByRole('button', {name: 'Question 2', exact: true}).click();
    await expect(page.locator('.choice-option input').first()).toBeEnabled();
    await context.setOffline(true);
    try {
        await expect(page.locator('.exam-saved')).toContainText('Offline');
        await page.locator('.choice-option').nth(1).click();
        await page.getByRole('button', {name: 'Mark for review', exact: true}).click();
        const queued = await page.evaluate(id => JSON.parse(localStorage.getItem('inbaf-attempt-' + id)), payload.attempt.id);
        expect(queued.answers[payload.questions[1].key]).toBe('b');
        expect(queued.marked[payload.questions[1].key]).toBe(true);
        expect(Object.keys(queued.dirty)).toContain(payload.questions[1].key);
        const syncing = saved(page, payload.attempt.id, payload.questions[1].key);
        await context.setOffline(false);
        expect((await syncing).status()).toBe(200);
    } finally {await context.setOffline(false);}
    await expect(page.locator('.exam-saved')).toContainText('All changes saved');
    await page.reload();
    await expect(page.locator('.choice-option input').nth(1)).toBeChecked();
    await expect(page.getByRole('button', {name: 'Marked for review', exact: true})).toBeVisible();
    const before = await (await page.request.get('/api/attempts/' + payload.attempt.id)).json();
    // Dropping the real session cookie makes the next actual save unauthorized.
    // This does not mock a response and does not alter another user's session.
    await context.clearCookies();
    await page.locator('.choice-option').first().click();
    await expect(page).toHaveURL(/\/login$/);
    const pending = await page.evaluate(id => JSON.parse(localStorage.getItem('inbaf-attempt-' + id)), payload.attempt.id);
    expect(pending.answers[payload.questions[1].key]).toBe('a');
    expect(Object.keys(pending.dirty)).toContain(payload.questions[1].key);
    await loginAs(page, account);
    const resuming = await write(page, '/packages/' + fixture.package.id + '/attempts', 'POST');
    expect(resuming.status).toBe(201);
    expect(resuming.data.attempt.id).toBe(payload.attempt.id);
    await page.goto('/exam/' + payload.attempt.id);
    await expect(page.locator('.choice-option input').first()).toBeChecked();
    await expect(page.locator('.exam-saved')).toContainText('All changes saved');
    const recovered = await (await page.request.get('/api/attempts/' + payload.attempt.id)).json();
    expect(recovered.attempt.started_at).toBe(before.attempt.started_at);
    expect(recovered.attempt.expires_at).toBeNull();
    expect(recovered.answers[payload.questions[0].key].response).toBe('a');
    expect(recovered.answers[payload.questions[1].key].response).toBe('a');
    expect(recovered.answers[payload.questions[1].key].marked).toBe(true);
    await finish(page, payload.attempt.id);
    await expect(page.locator('.result-score strong')).toHaveText('100');
    await page.goto('/results?test_type=practice');
    await expect(page.locator('tbody')).toContainText(fixture.package.name);
    expect((await (await page.request.get('/api/certificates')).json()).certificates).toEqual([]);
});

test('two real tabs preserve distinct responses and repeated finalization keeps one result', async ({page, context}) => {
    test.setTimeout(120000);
    const fixture = await setupPackage(page, 'practice');
    await register(page, fixture.suffix);
    const payload = await start(page, fixture.package, 'practice');
    const other = await context.newPage();
    other.on('dialog', dialog => dialog.accept());
    try {
        await other.goto('/exam/' + payload.attempt.id);
        await expect(other.locator('.choice-option input').first()).toBeEnabled();
        await choose(page, payload, 0);
        await choose(other, payload, 1);
        await page.reload();
        const merged = await (await page.request.get('/api/attempts/' + payload.attempt.id)).json();
        expect(merged.answers[payload.questions[0].key].response).toBe('a');
        expect(merged.answers[payload.questions[1].key].response).toBe('a');
        const first = await finish(page, payload.attempt.id);
        const second = await write(other, '/attempts/' + payload.attempt.id + '/submit', 'POST');
        expect(second.status).toBe(200);
        expect(second.data.attempt.id).toBe(first.attempt.id);
        expect(second.data.result).toEqual(first.result);
        await other.reload();
        await expect(other).toHaveURL(new RegExp('/results/' + payload.attempt.id + '$'));
        await expect(other.locator('.result-score strong')).toHaveText('100');
        const results = (await (await page.request.get('/api/results?test_type=practice')).json()).results;
        expect(results.filter(item => item.id === payload.attempt.id)).toHaveLength(1);
        expect((await (await page.request.get('/api/certificates')).json()).certificates).toEqual([]);
    } finally {await other.close();}
});
