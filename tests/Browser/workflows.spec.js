import {test, expect} from '@playwright/test';
import {login, noHorizontalOverflow} from './helpers.js';
const browserErrors = new WeakMap();

function answerSaved(page, attemptId, questionKey) {
    return page.waitForResponse(response => response.url().endsWith('/api/attempts/' + attemptId + '/answers') && response.request().method() === 'PUT' && response.request().postDataJSON()?.question_key === questionKey);
}

async function selectQuestion(page, questions, question) {
    const index = questions.findIndex(item => item.key === question?.key);
    expect(index, 'Expected the assessment to contain the requested question type').toBeGreaterThanOrEqual(0);
    await page.getByRole('button', {name: 'Question ' + (index + 1), exact: true}).click();
    await expect(page.locator('.question-title')).toHaveText(question.title);
}

test.beforeEach(async ({page}) => {
    browserErrors.set(page, []);
    page.on('pageerror', error => browserErrors.get(page).push(error.stack || error.message));
    await page.addInitScript(() => {
        localStorage.setItem('inbaf-language', 'en');
        localStorage.setItem('inbaf-theme', 'light');
    });
    page.on('dialog', dialog => dialog.accept());
});

test.afterEach(async ({page}) => {
    expect(browserErrors.get(page) || [], 'Uncaught browser JavaScript errors').toEqual([]);
});

test('participant completes a real assessment with autosave recovery, passage, image and video', async ({page}) => {
    await login(page);
    await page.locator('.sidebar a[href="/tests"]').click();
    await expect(page.locator('.test-type-card')).toHaveCount(3);
    await page.goto('/tests?test_type=placement');
    await expect(page.locator('.package-card')).toHaveCount(1);
    const placement = page.locator('.package-card').filter({hasText: 'INBAF Placement Test'});
    await placement.getByRole('button', {name: 'View details'}).click();
    await expect(page.locator('.readiness-main h2')).toHaveText('INBAF Placement Test');
    await page.getByRole('checkbox').check();
    const starting = page.waitForResponse(response => /\/api\/packages\/\d+\/attempts$/.test(response.url()) && response.request().method() === 'POST');
    await page.getByRole('button', {name: 'Measure my proficiency', exact: true}).click();
    const start = await starting;
    expect(start.status()).toBe(201);
    const payload = await start.json();
    await expect(page).toHaveURL(new RegExp('/exam/' + payload.attempt.id + '$'));
    const questions = payload.sections.flatMap(section => section.questions);
    const single = questions.findIndex(question => question.type === 'single_choice');
    expect(single).toBeGreaterThanOrEqual(0);
    await selectQuestion(page, questions, questions[single]);
    const saving = answerSaved(page, payload.attempt.id, questions[single].key);
    await page.locator('.choice-option').first().click();
    expect((await saving).status()).toBe(200);
    await expect(page.locator('.exam-saved')).toContainText('All changes saved');
    await page.reload();
    await expect(page.locator('.choice-option input').first()).toBeChecked();
    const recovered = await (await page.request.get('/api/attempts/' + payload.attempt.id)).json();
    expect(recovered.answers[questions[single].key].response).toBe(questions[single].options[0].id);

    const passage = questions.findIndex(question => question.group?.kind === 'passage');
    await page.getByRole('button', {name: 'Question ' + (passage + 1), exact: true}).click();
    await expect(page.locator('.reading-passage')).toContainText('Universitas Nusantara');
    const image = questions.findIndex(question => question.type === 'image');
    await page.getByRole('button', {name: 'Question ' + (image + 1), exact: true}).click();
    const imageElement = page.locator('.question-media img');
    await expect(imageElement).toBeVisible();
    await expect.poll(() => imageElement.evaluate(element => element.complete && element.naturalWidth > 0)).toBeTruthy();
    const imageResponse = await page.request.get(await imageElement.getAttribute('src'));
    expect(imageResponse.status()).toBe(200);
    expect(imageResponse.headers()['content-type']).toContain('image/png');
    const video = questions.findIndex(question => question.type === 'video');
    await page.getByRole('button', {name: 'Question ' + (video + 1), exact: true}).click();
    const videoElement = page.locator('.question-media video');
    await expect(videoElement).toBeVisible();
    const videoResponse = await page.request.get(await videoElement.getAttribute('src'));
    expect([200, 206]).toContain(videoResponse.status());
    expect(videoResponse.headers()['content-type']).toContain('video/mp4');
    expect((await videoResponse.body()).length).toBeGreaterThan(1000);

    for (const type of ['matching', 'drag_drop']) {
        const question = questions.find(question => question.type === type);
        await selectQuestion(page, questions, question);
        await expect(page.locator('.matching-response select')).toHaveCount(question.metadata.left_items.length);
        const saved = answerSaved(page, payload.attempt.id, question.key);
        const selected = String(question.metadata.right_items[0].id);
        await page.locator('.matching-response select').first().selectOption(selected);
        expect((await saved).status()).toBe(200);
        const server = await (await page.request.get('/api/attempts/' + payload.attempt.id)).json();
        expect(server.answers[question.key].response[question.metadata.left_items[0].id]).toBe(selected);
    }
    const dropdown = questions.find(question => question.type === 'dropdown' && question.metadata.blanks?.length);
    await selectQuestion(page, questions, dropdown);
    await expect(page.locator('.blank-response select')).toHaveCount(dropdown.metadata.blanks.length);
    const blank = dropdown.metadata.blanks[0];
    const blankSaved = answerSaved(page, payload.attempt.id, dropdown.key);
    await page.locator('.blank-response select').first().selectOption(String(blank.options[0].id));
    expect((await blankSaved).status()).toBe(200);
    await page.reload();
    await expect(page.locator('.blank-response select').first()).toHaveValue(String(blank.options[0].id));

    await page.locator('.exam-sidebar').getByRole('button', {name: 'Finish assessment', exact: true}).click();
    const submission = page.waitForResponse(response => response.url().endsWith('/api/attempts/' + payload.attempt.id + '/submit'));
    await page.getByRole('dialog').getByRole('button', {name: 'Submit assessment', exact: true}).click();
    expect((await submission).status()).toBe(200);
    await expect(page).toHaveURL(new RegExp('/results/' + payload.attempt.id + '$'));
    await expect(page.locator('.result-hero')).toContainText('INBAF Placement Test');
    await expect(page.locator('.result-score strong')).toHaveText(/\d/);
    const result = await (await page.request.get('/api/results/' + payload.attempt.id)).json();
    expect(result.attempt.status).toBe('completed');
    expect(result.result.manual_pending).toBe(0);
});

test('superadmin creates, versions and duplicates a question, saves settings and builds a package', async ({page}) => {
    test.setTimeout(90000);
    await login(page, 'superadmin');
    await page.locator('.sidebar a[href="/admin/questions"]').click();
    await page.getByRole('button', {name: 'Create new', exact: true}).click();
    let dialog = page.getByRole('dialog', {name: 'Question bank', exact: true});
    await dialog.getByLabel('Title', {exact: true}).fill('E2E purposeful greeting');
    await dialog.locator('[contenteditable="true"]').first().fill('Choose the appropriate Indonesian greeting.');
    for (let i = 1; i <= 4; i++) await dialog.getByPlaceholder('Option ' + i, {exact: true}).fill(['Selamat pagi', 'Good night', 'Merci', 'Bonjour'][i - 1]);
    let response = page.waitForResponse(response => response.url().endsWith('/api/admin/questions') && response.request().method() === 'POST');
    await dialog.getByRole('button', {name: 'Save changes', exact: true}).click();
    expect((await response).status()).toBe(201);
    await expect(dialog).not.toBeVisible();
    await page.getByRole('textbox', {name: 'Search records'}).fill('E2E purposeful greeting');
    let rows = page.locator('tbody tr').filter({hasText: 'E2E purposeful greeting'});
    await expect(rows).toHaveCount(1);
    await rows.getByRole('button', {name: 'Edit', exact: true}).click();
    dialog = page.getByRole('dialog', {name: 'Question bank', exact: true});
    await dialog.getByLabel('Title', {exact: true}).fill('E2E purposeful greeting revised');
    response = page.waitForResponse(response => /\/api\/admin\/questions\/\d+$/.test(response.url()) && response.request().method() === 'PUT');
    await dialog.getByRole('button', {name: 'Save changes', exact: true}).click();
    expect((await response).status()).toBe(200);
    await expect(dialog).not.toBeVisible();
    await expect(rows).toContainText('v2');
    response = page.waitForResponse(response => /\/api\/admin\/questions\/\d+\/duplicate$/.test(response.url()));
    await rows.getByRole('button', {name: 'Duplicate', exact: true}).click();
    expect((await response).status()).toBe(201);
    await expect(rows).toHaveCount(2);

    await page.getByRole('button', {name: 'Create new', exact: true}).click();
    dialog = page.getByRole('dialog', {name: 'Question bank', exact: true});
    await dialog.getByLabel('Title', {exact: true}).fill('E2E two blank completion');
    await dialog.getByRole('combobox', {name: 'Question type', exact: true}).selectOption('fill_blank');
    await dialog.locator('[contenteditable="true"]').first().fill('Lengkapi kedua bagian kalimat berikut.');
    await dialog.getByRole('button', {name: 'Add blank', exact: true}).click();
    await dialog.getByRole('button', {name: 'Add blank', exact: true}).click();
    const blanks = dialog.locator('.section-builder-card');
    await expect(blanks).toHaveCount(2);
    await blanks.nth(0).getByLabel('Blank label', {exact: true}).fill('Kegiatan');
    await blanks.nth(0).getByLabel('Correct answer', {exact: true}).fill('belajar');
    await blanks.nth(1).getByLabel('Blank label', {exact: true}).fill('Tempat');
    await blanks.nth(1).getByLabel('Correct answer', {exact: true}).fill('kampus');
    response = page.waitForResponse(response => response.url().endsWith('/api/admin/questions') && response.request().method() === 'POST');
    await dialog.getByRole('button', {name: 'Save changes', exact: true}).click();
    const groupedResponse = await response;
    expect(groupedResponse.status()).toBe(201);
    const grouped = await groupedResponse.json();
    expect(Object.values(grouped.answer_schema.correct)).toEqual(['belajar', 'kampus']);
    expect(grouped.metadata.blanks).toHaveLength(2);
    expect(grouped.options).toEqual([]);
    await expect(dialog).not.toBeVisible();

    await page.locator('.sidebar a[href="/admin/settings"]').click();
    await page.getByLabel('Institution Name', {exact: true}).fill('INBAF Browser QA Institute');
    response = page.waitForResponse(response => response.url().endsWith('/api/admin/settings') && response.request().method() === 'PUT');
    await page.locator('.settings-form').getByRole('button', {name: 'Save changes', exact: true}).click();
    expect((await response).status()).toBe(200);
    await page.reload();
    await expect(page.getByLabel('Institution Name', {exact: true})).toHaveValue('INBAF Browser QA Institute');

    const bootstrap = await (await page.request.get('/api/bootstrap')).json();
    const grammar = bootstrap.competencies.find(competency => competency.code === 'grammar');
    await page.locator('.sidebar a[href="/admin/packages"]').click();
    await page.getByRole('button', {name: 'Create new', exact: true}).click();
    dialog = page.getByRole('dialog', {name: 'Assessment packages', exact: true});
    await dialog.getByLabel('Code', {exact: true}).fill('E2E-ORIENTATION');
    await dialog.getByLabel('Assessment name', {exact: true}).fill('E2E Orientation Assessment');
    await dialog.getByLabel('Total duration (minutes)', {exact: true}).fill('20');
    await dialog.getByRole('combobox', {name: 'Question selection', exact: true}).selectOption('manual');
    await dialog.getByRole('button', {name: 'Add section', exact: true}).click();
    const section = dialog.locator('.section-builder-card');
    await section.getByLabel('Section name', {exact: true}).fill('Grammar orientation');
    await section.getByRole('combobox', {name: 'Competencies', exact: true}).selectOption(String(grammar.id));
    await section.getByLabel('Duration (minutes)', {exact: true}).fill('20');
    await section.locator('.question-selector input[type="checkbox"]').first().check();
    response = page.waitForResponse(response => response.url().endsWith('/api/admin/packages') && response.request().method() === 'POST');
    await dialog.getByRole('button', {name: 'Save changes', exact: true}).click();
    const packageResponse = await response;
    expect(packageResponse.status()).toBe(201);
    const created = await packageResponse.json();
    expect(created.sections).toHaveLength(1);
    expect(created.sections[0].question_count).toBe(1);
    await expect(dialog).not.toBeVisible();
    await expect(page.locator('tbody')).toContainText('E2E Orientation Assessment');
});

test('manual essay submission withholds the final score until superadmin review and grades omitted speaking zero', async ({page}) => {
    test.setTimeout(90000);
    await login(page);
    await page.locator('.sidebar a[href="/tests"]').click();
    await page.locator('.package-card').filter({hasText: 'INBAF Academic'}).getByRole('button', {name: 'View details'}).click();
    await page.getByRole('checkbox').check();
    const starting = page.waitForResponse(response => /\/api\/packages\/\d+\/attempts$/.test(response.url()) && response.request().method() === 'POST');
    await page.getByRole('button', {name: 'Take a certification test', exact: true}).click();
    const start = await starting;
    expect(start.status()).toBe(201);
    const payload = await start.json();
    const questions = payload.sections.flatMap(section => section.questions);
    const essay = questions.find(question => question.type === 'essay');
    await selectQuestion(page, questions, essay);
    const text = 'Yth. Ibu Dosen, saya ingin meminta perubahan jadwal konsultasi karena pada waktu yang sama saya harus mengikuti kegiatan orientasi mahasiswa internasional. Apakah saya dapat berkonsultasi pada hari Kamis pukul sepuluh pagi? Saya sudah menyiapkan daftar pertanyaan dan rancangan tugas untuk dibahas bersama. Apabila waktu tersebut belum sesuai, saya bersedia mengikuti jadwal lain yang Ibu sarankan. Terima kasih atas waktu dan bantuan Ibu. Salam hormat, Alex.';
    const saved = answerSaved(page, payload.attempt.id, essay.key);
    await page.locator('.response-text textarea').fill(text);
    expect((await saved).status()).toBe(200);
    await expect(page.locator('.exam-saved')).toContainText('All changes saved');
    await page.locator('.exam-sidebar').getByRole('button', {name: 'Finish assessment', exact: true}).click();
    const submitting = page.waitForResponse(response => response.url().endsWith('/api/attempts/' + payload.attempt.id + '/submit'));
    await page.getByRole('dialog').getByRole('button', {name: 'Submit assessment', exact: true}).click();
    const submitted = await (await submitting).json();
    expect(submitted.attempt.status).toBe('pending_review');
    expect(submitted.attempt.score).toBeNull();
    expect(submitted.result.total_score).toBeNull();
    expect(submitted.result.manual_pending).toBe(1);
    await expect(page.locator('.notice-banner')).toContainText('being reviewed');
    await page.locator('.sidebar').getByRole('button', {name: 'Sign out', exact: true}).click();
    await expect(page).toHaveURL(/\/login$/);
    await login(page, 'superadmin');
    await page.locator('.sidebar a[href="/admin/reviews"]').click();
    const row = page.locator('tbody tr').filter({hasText: essay.title});
    await expect(row).toHaveCount(1);
    const queue = await (await page.request.get('/api/admin/reviews')).json();
    expect(queue.total).toBe(1);
    expect(queue.data[0].question_key).toBe(essay.key);
    await row.getByRole('button', {name: 'Review', exact: true}).click();
    const dialog = page.getByRole('dialog', {name: 'Manual assessment', exact: true});
    await expect(dialog.locator('.response-text textarea')).toHaveValue(text);
    await dialog.getByLabel('Overall score (0–100)', {exact: true}).fill('80');
    await dialog.getByLabel('Feedback', {exact: true}).fill('Clear purpose, polite register, and an appropriate suggested schedule.');
    const reviewed = page.waitForResponse(response => /\/api\/admin\/reviews\/\d+$/.test(response.url()) && response.request().method() === 'POST');
    await dialog.getByRole('button', {name: 'Save assessment', exact: true}).click();
    const reviewResponse = await reviewed;
    expect(reviewResponse.status()).toBe(200);
    const reviewResult = await reviewResponse.json();
    expect(reviewResult.attempt.status).toBe('completed');
    expect(reviewResult.attempt.result.manual_pending).toBe(0);
    expect(reviewResult.attempt.score).not.toBeNull();
    await expect(dialog).not.toBeVisible();
    await expect(row).toHaveCount(0);
    const finalResult = await (await page.request.get('/api/results/' + payload.attempt.id)).json();
    expect(finalResult.result.question_scores.find(question => question.type === 'speaking').score).toBe(0);
    await page.locator('.sidebar').getByRole('button', {name: 'Sign out', exact: true}).click();
    await expect(page).toHaveURL(/\/login$/);
    await login(page);
    await page.goto('/results/' + payload.attempt.id);
    await expect(page.locator('.result-score strong')).toHaveText(/\d/);
    await expect(page.locator('.notice-banner')).not.toBeVisible();
});

test('mobile landing, navigation, assessment list and readiness fit the viewport', async ({page}) => {
    await page.setViewportSize({width: 390, height: 844});
    await page.goto('/');
    await expect(page.locator('.landing-redesign')).toBeVisible();
    await noHorizontalOverflow(page);
    await login(page);
    await noHorizontalOverflow(page);
    await page.getByRole('button', {name: 'Open navigation'}).click();
    await page.locator('.sidebar a[href="/tests"]').click();
    await expect(page.locator('.test-type-card')).toHaveCount(3);
    await expect(page.locator('.package-card')).toHaveCount(1);
    await noHorizontalOverflow(page);
    await page.locator('.package-card').first().getByRole('button', {name: 'View details'}).click();
    await expect(page.locator('.readiness-main')).toBeVisible();
    await noHorizontalOverflow(page);
});

test('server question deadline survives reload and locks only its own response', async ({page}) => {
    test.setTimeout(90000);
    await login(page, 'superadmin');
    const bootstrap = await (await page.request.get('/api/bootstrap')).json();
    const grammar = bootstrap.competencies.find(competency => competency.code === 'grammar');
    async function create(path, body) {
        const result = await page.evaluate(async ({path, body}) => {
            const response = await fetch('/api/admin/' + path, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content}, body: JSON.stringify(body)});
            return {status: response.status, data: await response.json()};
        }, {path, body});
        expect(result.status).toBe(201);
        return result.data;
    }
    const base = {competency_id: grammar.id, type: 'single_choice', prompt: 'Pilih sapaan Bahasa Indonesia.', instructions: '', difficulty: 'easy', weight: 1, status: 'active', options: [{id: 'a', text: 'Selamat pagi'}, {id: 'b', text: 'Good evening'}], answer_schema: {correct: 'a'}, metadata: {}};
    const timed = await create('questions', {...base, code: 'E2E-TIMED', title: 'E2E timed question', duration_seconds: 2});
    const flexible = await create('questions', {...base, code: 'E2E-UNTIMED', title: 'E2E untimed question', duration_seconds: null});
    const packageData = await create('packages', {code: 'E2E-TIMED-PACKAGE', name: 'E2E timed assessment', published: true, duration_minutes: 10, attempts_limit: 1, pass_score: 60, test_type: 'certification', policy_overrides: {lock_sections: false, randomize_options: false, attempts_limit: 1}, certificate_enabled: false, question_selection: 'manual', settings: {lock_sections: false, randomize_options: false}, sections: [{title: 'Question timer regression', competency_id: grammar.id, position: 0, duration_minutes: 10, weight: 1, question_count: 2, question_ids: [timed.id, flexible.id], randomize: false}]});
    await page.locator('.sidebar').getByRole('button', {name: 'Sign out', exact: true}).click();
    await expect(page).toHaveURL(/\/login$/);
    await login(page);
    await page.goto('/tests/' + packageData.id);
    await page.getByRole('checkbox').check();
    const starting = page.waitForResponse(response => response.url().endsWith('/api/packages/' + packageData.id + '/attempts') && response.request().method() === 'POST');
    await page.getByRole('button', {name: 'Take a certification test', exact: true}).click();
    const response = await starting;
    expect(response.status()).toBe(201);
    const payload = await response.json();
    const questions = payload.sections.flatMap(section => section.questions);
    await expect(page.locator('.question-title')).toHaveText('E2E timed question');
    await expect(page.locator('.question-countdown')).toBeVisible();
    await expect(page.locator('.notice-banner').filter({hasText: 'The time for this question has ended'})).toBeVisible({timeout: 10000});
    await expect(page.locator('.choice-option input').first()).toBeDisabled();
    const firstState = await (await page.request.get('/api/attempts/' + payload.attempt.id)).json();
    const expiry = firstState.answers[questions[0].key].question_expires_at;
    expect(expiry).toBeTruthy();
    await selectQuestion(page, questions, questions[1]);
    await expect(page.locator('.choice-option input').first()).toBeEnabled();
    const saved = answerSaved(page, payload.attempt.id, questions[1].key);
    await page.locator('.choice-option').first().click();
    expect((await saved).status()).toBe(200);
    await selectQuestion(page, questions, questions[0]);
    await expect(page.locator('.choice-option input').first()).toBeDisabled();
    await page.reload();
    await expect(page.locator('.choice-option input').first()).toBeDisabled();
    const reloaded = await (await page.request.get('/api/attempts/' + payload.attempt.id)).json();
    expect(reloaded.answers[questions[0].key].question_expires_at).toBe(expiry);
    expect(reloaded.answers[questions[1].key].response).toBe('a');
});
