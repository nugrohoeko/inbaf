import {test, expect} from '@playwright/test';
import {login} from './helpers.js';

test.use({
    permissions: ['microphone'],
    launchOptions: {
        executablePath: process.env.INBAF_CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe',
        args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream'],
    },
});

test('fake microphone speaking pipeline records uploads plays and completes manual review', async ({page}) => {
    test.setTimeout(120000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => dialog.accept());
    await page.addInitScript(() => {
        localStorage.setItem('inbaf-language', 'en');
        localStorage.setItem('inbaf-theme', 'light');
    });
    await login(page, 'superadmin');
    const bootstrap = await (await page.request.get('/api/bootstrap')).json();
    const competency = bootstrap.competencies.find(competency => competency.code === 'speaking');
    async function create(path, body) {
        const result = await page.evaluate(async ({path, body}) => {
            const response = await fetch('/api/admin/' + path, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content}, body: JSON.stringify(body)});
            return {status: response.status, data: await response.json()};
        }, {path, body});
        expect(result.status).toBe(201);
        return result.data;
    }
    const question = await create('questions', {code: 'E2E-FAKE-MIC', title: 'E2E fake microphone recording', competency_id: competency.id, type: 'speaking', instructions: 'Automated QA fixture using a synthetic microphone; no physical microphone.', prompt: 'Record the browser test audio for three seconds.', difficulty: 'easy', weight: 1, status: 'active', options: [], answer_schema: {}, metadata: {manual: true, preparation_seconds: 0, recording_seconds: 3, max_recordings: 1, rubric: [{name: 'Clarity', description: 'QA playback is clear and accessible.', weight: 1}]}, duration_seconds: null});
    const packageData = await create('packages', {code: 'E2E-FAKE-MIC-PACKAGE', name: 'E2E fake microphone pipeline', published: true, duration_minutes: 10, attempts_limit: 1, pass_score: 60, test_type: 'certification', policy_overrides: {lock_sections: false, randomize_options: false, attempts_limit: 1}, certificate_enabled: false, question_selection: 'manual', settings: {lock_sections: false, randomize_options: false}, sections: [{title: 'Speaking pipeline fixture', competency_id: competency.id, position: 0, duration_minutes: 10, weight: 1, question_count: 1, question_ids: [question.id], randomize: false}]});
    await page.locator('.sidebar').getByRole('button', {name: 'Sign out', exact: true}).click();
    await expect(page).toHaveURL(/\/login$/);
    await login(page);
    await page.goto('/tests/' + packageData.id);
    await page.getByRole('checkbox').check();
    const starting = page.waitForResponse(response => response.url().endsWith('/api/packages/' + packageData.id + '/attempts') && response.request().method() === 'POST');
    await page.getByRole('button', {name: 'Take a certification test', exact: true}).click();
    const start = await starting;
    expect(start.status()).toBe(201);
    const payload = await start.json();
    const key = payload.sections[0].questions[0].key;
    await expect(page.locator('.speaking-response')).toBeVisible();
    const uploading = page.waitForResponse(response => response.url().endsWith('/api/attempts/' + payload.attempt.id + '/recordings') && response.request().method() === 'POST', {timeout: 20000});
    await page.getByRole('button', {name: 'Start recording', exact: true}).click();
    await expect(page.getByRole('button', {name: 'Stop recording', exact: true})).toBeVisible();
    const uploaded = await uploading;
    expect(uploaded.status()).toBe(201);
    const recording = (await uploaded.json()).answer.response;
    expect(recording.media_asset_id).toBeGreaterThan(0);
    expect(Number(recording.duration)).toBeGreaterThanOrEqual(3);
    await expect(page.locator('.exam-saved')).toContainText('All changes saved');
    await expect(page.getByRole('button', {name: 'Record again', exact: true})).toBeDisabled();
    const saved = await (await page.request.get('/api/attempts/' + payload.attempt.id)).json();
    expect(saved.answers[key].response.media_asset_id).toBe(recording.media_asset_id);
    const audio = await page.request.get('/api/media/' + recording.media_asset_id);
    expect(audio.status()).toBe(200);
    expect(audio.headers()['content-type']).toMatch(/^(audio|video)\//);
    expect((await audio.body()).length).toBeGreaterThan(1000);
    await page.reload();
    await expect(page.locator('.speaking-response audio')).toHaveAttribute('src', recording.url);
    await expect(page.getByRole('button', {name: 'Start recording', exact: true})).toBeDisabled();
    await page.locator('.exam-sidebar').getByRole('button', {name: 'Finish assessment', exact: true}).click();
    const submitting = page.waitForResponse(response => response.url().endsWith('/api/attempts/' + payload.attempt.id + '/submit'));
    await page.getByRole('dialog').getByRole('button', {name: 'Submit assessment', exact: true}).click();
    const submitted = await (await submitting).json();
    expect(submitted.attempt.status).toBe('pending_review');
    expect(submitted.attempt.score).toBeNull();
    expect(submitted.result.manual_pending).toBe(1);
    await expect(page.locator('.notice-banner')).toContainText('being reviewed');
    await page.locator('.sidebar').getByRole('button', {name: 'Sign out', exact: true}).click();
    await expect(page).toHaveURL(/\/login$/);
    expect((await page.request.get('/api/media/' + recording.media_asset_id)).status()).toBe(401);
    await login(page, 'superadmin');
    await page.locator('.sidebar a[href="/admin/reviews"]').click();
    const row = page.locator('tbody tr').filter({hasText: question.title});
    await expect(row).toHaveCount(1);
    await row.getByRole('button', {name: 'Review', exact: true}).click();
    const dialog = page.getByRole('dialog', {name: 'Manual assessment', exact: true});
    const player = dialog.locator('.speaking-response audio');
    await expect(player).toHaveAttribute('src', recording.url);
    await expect.poll(() => player.evaluate(audio => audio.readyState >= 2)).toBeTruthy();
    await player.evaluate(async audio => { await audio.play(); });
    await expect.poll(() => player.evaluate(audio => audio.currentTime)).toBeGreaterThan(0);
    await player.evaluate(audio => audio.pause());
    await dialog.getByLabel('Overall score (0–100)', {exact: true}).fill('85');
    await dialog.getByLabel('Feedback', {exact: true}).fill('Automated QA fixture: recording uploaded, authorized playback succeeded, and review saved.');
    const reviewing = page.waitForResponse(response => /\/api\/admin\/reviews\/\d+$/.test(response.url()) && response.request().method() === 'POST');
    await dialog.getByRole('button', {name: 'Save assessment', exact: true}).click();
    const reviewed = await reviewing;
    expect(reviewed.status()).toBe(200);
    const final = (await reviewed.json()).attempt;
    expect(final.status).toBe('completed');
    expect(final.score).toBe(85);
    expect(final.result.manual_pending).toBe(0);
    await expect(row).toHaveCount(0);
    expect(errors, 'Uncaught browser JavaScript errors').toEqual([]);
    // Restore the three published starter packages for any later browser workflows.
    const archived = await page.evaluate(async packageId => {
        const response = await fetch('/api/admin/packages/' + packageId, {method: 'DELETE', credentials: 'same-origin', headers: {Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content}});
        return response.status;
    }, packageData.id);
    expect(archived).toBe(200);
});
