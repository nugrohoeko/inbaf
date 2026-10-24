import {test, expect} from '@playwright/test';
import {login} from './helpers.js';

function deferred() {
    let resolve;
    const promise = new Promise(done => {resolve = done;});
    return {promise, resolve};
}

async function paint(page) {
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
}

function attempt(type, name) {
    return {id: type + '-history-fixture', test_type: type, status: 'completed', score: 75, level: 'B1', submitted_at: '2026-10-20T00:00:00Z', package: {name, test_type: type}};
}

function tone() {
    const sampleRate = 8000, samples = sampleRate * 3, buffer = Buffer.alloc(44 + samples * 2);
    buffer.write('RIFF', 0); buffer.writeUInt32LE(buffer.length - 8, 4); buffer.write('WAVEfmt ', 8);
    buffer.writeUInt32LE(16, 16); buffer.writeUInt16LE(1, 20); buffer.writeUInt16LE(1, 22);
    buffer.writeUInt32LE(sampleRate, 24); buffer.writeUInt32LE(sampleRate * 2, 28);
    buffer.writeUInt16LE(2, 32); buffer.writeUInt16LE(16, 34); buffer.write('data', 36);
    buffer.writeUInt32LE(samples * 2, 40);
    for (let i = 0; i < samples; i++) buffer.writeInt16LE(Math.round(Math.sin(i * 2 * Math.PI * 440 / sampleRate) * 1000), 44 + i * 2);
    return buffer;
}

test.beforeEach(async ({page}) => {
    await page.addInitScript(() => {
        localStorage.setItem('inbaf-language', 'en');
        localStorage.setItem('inbaf-theme', 'light');
    });
});

test('restricted listening audio resumes the same play even when paused immediately', async ({page}) => {
    await login(page);
    const id = 'audio-resume-fixture', key = 'audio-resume-question';
    await page.route('**/participant-regression-tone.wav', route => route.fulfill({contentType: 'audio/wav', body: tone()}));
    await page.route('**/api/attempts/' + id, route => route.fulfill({json: {
        attempt: {id, status: 'in_progress', test_type: 'practice', expires_at: null, package: {name: 'Listening playback fixture', test_type: 'practice'}},
        server_time: new Date().toISOString(), rules: {timer_enabled: false, lock_sections: false, security_monitoring: false}, answers: {},
        sections: [{id: 1, title: 'Listening', questions: [{id: 1, key, type: 'listening', title: 'Listen to the recording', prompt: 'Pause and resume the same recording.', options: [{id: 'a', text: 'Continue'}], metadata: {max_plays: 1, audio_url: '/participant-regression-tone.wav'}}]}],
    }}));
    await page.route('**/api/attempts/' + id + '/questions/**/activate', route => route.fulfill({json: {question_started_at: new Date().toISOString(), question_expires_at: null}}));
    await page.goto('/exam/' + id);
    const audio = page.locator('.question-media audio');
    await expect.poll(() => audio.evaluate(element => element.readyState)).toBeGreaterThanOrEqual(2);
    await page.getByRole('button', {name: 'Play audio', exact: true}).click();
    await audio.evaluate(element => {element.pause(); element.currentTime = 0.1;});
    await expect(page.locator('.question-media')).toContainText('Playback 1 / 1');
    await page.getByRole('button', {name: 'Play audio', exact: true}).click();
    await expect.poll(() => audio.evaluate(element => !element.paused && element.currentTime > 0.15)).toBeTruthy();
    await expect(page.locator('.question-media')).toContainText('Playback 1 / 1');
    await audio.evaluate(element => {element.currentTime = element.duration - 0.05;});
    await expect(page.getByRole('button', {name: 'Play audio', exact: true})).toBeDisabled();
});

test('answer reviews resolve matching and blank labels and omit manual answer keys', async ({page}) => {
    await login(page);
    const id = 'review-label-fixture';
    const result = {total_score: 75, normalized_score: 75, score_max: 100, competency_scores: [], recommendations: []};
    const review = [
        {key: 'matching', type: 'matching', prompt: 'Match the greetings.', options: [], response: {left_a: 'right_a'}, answer_schema: {correct: {left_a: 'right_a'}}, metadata: {left_items: [{id: 'left_a', text: 'Selamat pagi'}], right_items: [{id: 'right_a', text: 'Good morning'}]}},
        {key: 'dropdown', type: 'dropdown', prompt: 'Complete the city name.', options: [], response: {capital: 'jakarta'}, answer_schema: {correct: {capital: 'jakarta'}}, metadata: {blanks: [{id: 'capital', label: 'Capital city', options: [{id: 'jakarta', text: 'Jakarta'}]}]}},
        {key: 'essay', type: 'essay', prompt: 'Write your introduction.', response: 'Saya belajar bahasa Indonesia.', answer_schema: [], metadata: {manual: true}},
        {key: 'speaking', type: 'speaking', prompt: 'Introduce yourself.', response: {recording_id: 123}, answer_schema: [], metadata: {manual: true}},
        {key: 'custom-manual', type: 'short_answer', prompt: 'An assessor reviews this response.', response: 'An individual answer', answer_schema: {correct: 'unused-key'}, metadata: {manual: true}},
    ];
    await page.route('**/api/results/' + id, route => route.fulfill({json: {attempt: {...attempt('practice', 'Review labels fixture'), id, result}, result, review_answers: review, previous_result: null}}));
    await page.goto('/results/' + id);
    const questions = page.locator('.review-question');
    await expect(questions).toHaveCount(5);
    for (let i = 0; i < 5; i++) await questions.nth(i).locator('summary').click();
    await expect(questions.nth(0).locator('dd')).toHaveText(['Selamat pagi: Good morning', 'Selamat pagi: Good morning']);
    await expect(questions.nth(1).locator('dd')).toHaveText(['Capital city: Jakarta', 'Capital city: Jakarta']);
    for (let i = 2; i < 5; i++) {
        await expect(questions.nth(i).locator('dt')).toHaveText(['Your response']);
        await expect(questions.nth(i)).not.toContainText('Correct response');
    }
    await expect(questions.nth(3).locator('dd')).toHaveText('Recorded response');
    await expect(page.locator('.answer-review')).not.toContainText('unused-key');
});

test('a delayed practice history response cannot replace the history selected with browser Back', async ({page}) => {
    await login(page);
    const arrived = deferred(), release = deferred();
    await page.route('**/api/results?*', async route => {
        const type = new URL(route.request().url()).searchParams.get('test_type');
        if (type === 'practice') {arrived.resolve(); await release.promise;}
        await route.fulfill({json: {results: [attempt(type, type === 'practice' ? 'Practice history fixture' : 'Certification history fixture')]}});
    });
    await page.goto('/results?test_type=certification');
    await expect(page.locator('tbody')).toContainText('Certification history fixture');
    const delayed = page.waitForResponse(response => response.url().endsWith('/api/results?test_type=practice'));
    await page.getByRole('button', {name: 'Practice history', exact: true}).click();
    await arrived.promise;
    await page.goBack();
    await expect(page).toHaveURL(/\/results\?test_type=certification$/);
    await expect(page.locator('tbody')).toContainText('Certification history fixture');
    release.resolve();
    await (await delayed).finished();
    await paint(page);
    await expect(page.getByRole('button', {name: 'Certification history', exact: true})).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('tbody')).toContainText('Certification history fixture');
    await expect(page.locator('tbody')).not.toContainText('Practice history fixture');
});

test('dashboard metrics stay with the last selected test type after responses arrive out of order', async ({page}) => {
    await login(page);
    const arrived = deferred(), release = deferred();
    await page.route('**/api/dashboard?*', async route => {
        const type = new URL(route.request().url()).searchParams.get('test_type');
        if (type === 'practice') {arrived.resolve(); await release.promise;}
        await route.fulfill({json: {stats: {total_attempts: type === 'placement' ? 22 : 11, best_score: type === 'placement' ? 82 : 11, aggregate_score_max: 100}, recent_attempts: [], trend: [], competency_scores: [], level_distribution: []}});
    });
    await page.goto('/dashboard');
    const type = page.locator('.dashboard-filters').getByRole('combobox', {name: 'Test type', exact: true});
    const delayed = page.waitForResponse(response => response.url().endsWith('/api/dashboard?test_type=practice'));
    await type.selectOption('practice');
    await arrived.promise;
    await type.selectOption('placement');
    await expect(page.locator('.metric-value').nth(0)).toHaveText('22');
    await expect(page.locator('.metric-value').nth(1)).toHaveText('82 / 100');
    release.resolve();
    await (await delayed).finished();
    await paint(page);
    await expect(type).toHaveValue('placement');
    await expect(page.locator('.metric-value').nth(0)).toHaveText('22');
    await expect(page.locator('.metric-value').nth(1)).toHaveText('82 / 100');
});

test('dashboard charts finish a queued resize safely after immediate navigation', async ({page}) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.stack || error.message));
    await page.emulateMedia({reducedMotion: 'reduce'});
    await login(page);
    await page.route('**/api/dashboard?*', route => route.fulfill({json: {
        stats: {total_attempts: 1, best_score: 75, aggregate_score_max: 100}, recent_attempts: [],
        trend: [{month: 'Oct', attempts: 1}], competency_scores: [], level_distribution: [{level: 'B1', count: 1}],
    }}));
    for (let cycle = 0; cycle < 3; cycle++) {
        await page.goto('/dashboard');
        const container = page.locator('.analytics-grid .chart-wrap').first();
        await expect(container.locator('canvas')).toBeVisible();
        await container.evaluate(element => new Promise(resolve => {
            const originalWidth = element.clientWidth;
            // Chart.js registered its resize observer first. Its animation-frame
            // callback schedules resizeDelay before this frame navigates away.
            const observer = new ResizeObserver(() => {
                if (element.clientWidth === originalWidth) return;
                observer.disconnect();
                requestAnimationFrame(() => {
                    document.querySelector('.sidebar a[href="/tests"]').click();
                    resolve();
                });
            });
            observer.observe(element);
            element.style.width = Math.max(100, originalWidth - 37) + 'px';
        }));
        await expect(page).toHaveURL(/\/tests$/);
        await expect(page.locator('.test-type-card')).toHaveCount(3);
        // Let the former 80 ms debounce finish after its canvas is destroyed
        // so this test also catches the original delayed-callback regression.
        await page.evaluate(() => new Promise(resolve => setTimeout(resolve, 120)));
    }
    expect(errors, 'Uncaught errors after chart resize and destruction').toEqual([]);
});
