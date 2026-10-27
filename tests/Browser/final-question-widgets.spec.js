import {test, expect} from '@playwright/test';
import {login} from './helpers.js';
import {observeHttp, saveHttpMetrics} from './final-qa-metrics.js';

test.use({screenshot: 'off', trace: 'off'});

async function write(page, endpoint, body, method = 'POST') {
    return page.evaluate(async ({endpoint, body, method}) => {
        const response = await fetch('/api' + endpoint, {method, credentials: 'same-origin', headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content}, body: JSON.stringify(body)});
        const data = await response.json();
        if (data.csrf_token) document.querySelector('meta[name="csrf-token"]').content = data.csrf_token;
        return {status: response.status, data};
    }, {endpoint, body, method});
}

test('all sixteen automatic question types accept actual widget responses and score the known correct reference answers', async ({page}, testInfo) => {
    test.setTimeout(240000);
    const metrics = observeHttp(page), errors = [];
    let fixturePackageId;
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => dialog.accept());
    await page.addInitScript(() => {
        localStorage.setItem('inbaf-language', 'en');
        localStorage.setItem('inbaf-theme', 'light');
    });
    try {
        await login(page, 'superadmin');
        const bootstrap = await (await page.request.get('/api/bootstrap')).json();
        const questionBank = (await (await page.request.get('/api/admin/questions?per_page=100')).json()).data;
        // The optional licensed starter recording may be absent on a clean checkout.
        // Upload a real self-contained WAV fixture through the authenticated API.
        const rate = 8000, samples = rate * 10, wav = Buffer.alloc(44 + samples * 2);
        wav.write('RIFF', 0); wav.writeUInt32LE(wav.length - 8, 4); wav.write('WAVEfmt ', 8);
        wav.writeUInt32LE(16, 16); wav.writeUInt16LE(1, 20); wav.writeUInt16LE(1, 22);
        wav.writeUInt32LE(rate, 24); wav.writeUInt32LE(rate * 2, 28); wav.writeUInt16LE(2, 32); wav.writeUInt16LE(16, 34);
        wav.write('data', 36); wav.writeUInt32LE(samples * 2, 40);
        for (let index = 0; index < samples; index++) wav.writeInt16LE(Math.round(Math.sin(index * 2 * Math.PI * 440 / rate) * 1000), 44 + index * 2);
        const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
        const uploaded = await page.request.post('/api/admin/media', {headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}, multipart: {file: {name: 'final-listening-tone.wav', mimeType: 'audio/wav', buffer: wav}}});
        expect(uploaded.status()).toBe(201);
        const audio = await uploaded.json();
        const group = await write(page, '/admin/groups', {title: 'Final QA listening fixture', kind: 'audio', media_asset_id: audio.id, metadata: {max_plays: 2, allow_replay: true}});
        expect(group.status).toBe(201);
        const listening = await write(page, '/admin/questions', {code: 'FINAL-LISTENING-' + Date.now().toString(36), title: 'Final listening widget', competency_id: bootstrap.competencies.find(row => row.code === 'listening').id, type: 'listening', question_group_id: group.data.id, prompt: 'Play the technical audio fixture and select tone.', difficulty: 'easy', weight: 1, status: 'active', allowed_test_types: ['practice'], options: [{id: 'a', text: 'Tone'}, {id: 'b', text: 'Speech'}], answer_schema: {correct: 'a'}, metadata: {max_plays: 2, allow_replay: true}});
        expect(listening.status).toBe(201);
        const types = bootstrap.question_types.map(row => row.code).filter(code => !['essay', 'speaking'].includes(code));
        expect(types).toHaveLength(16);
        const reference = types.map(type => {
            const question = type === 'listening' ? listening.data : questionBank.find(row => row.type === type && row.status === 'active' && row.code.startsWith('START-') && row.allowed_test_types.includes('practice'));
            expect(question, 'An authored reference question exists for ' + type).toBeTruthy();
            return question;
        });
        const grouped = new Map();
        for (const question of reference) {
            if (!grouped.has(question.competency_id)) grouped.set(question.competency_id, []);
            grouped.get(question.competency_id).push(question.id);
        }
        const suffix = Date.now().toString(36);
        const created = await write(page, '/admin/packages', {
            code: 'FINAL-WIDGETS-' + suffix, name: 'Final sixteen response widgets ' + suffix, test_type: 'practice',
            published: true, duration_minutes: 60, attempts_limit: 1, pass_score: 60, certificate_enabled: false,
            question_selection: 'manual', settings: {lock_sections: false, randomize_options: false},
            policy_overrides: {timer_enabled: false, lock_sections: false, randomize_options: false, attempts_limit: 1},
            sections: [...grouped.entries()].map(([competency_id, question_ids], position) => ({title: 'Reference widgets ' + position, competency_id, position, duration_minutes: 10, weight: 1, question_count: question_ids.length, question_ids, randomize: false})),
        });
        expect(created.status).toBe(201);
        fixturePackageId = created.data.id;
        await page.locator('.sidebar').getByRole('button', {name: 'Sign out', exact: true}).click();
        await page.goto('/register');
        await page.locator('#auth-name').fill('Final Widget Participant');
        await page.locator('#auth-email').fill('final-widgets-' + suffix + '@browser-qa.example.test');
        await page.locator('#auth-password').fill('FinalWidgets#2026');
        await page.locator('#auth-password-confirmation').fill('FinalWidgets#2026');
        const registered = page.waitForResponse(response => response.url().endsWith('/api/register') && response.request().method() === 'POST');
        await page.locator('.stack-form button.button').click();
        expect((await registered).status()).toBe(201);
        await expect(page).toHaveURL(/\/dashboard$/);
        await page.goto('/tests/' + created.data.id);
        await page.getByRole('checkbox', {name: 'I have read the instructions and I am ready to begin.', exact: true}).check();
        const starting = page.waitForResponse(response => response.url().endsWith('/api/packages/' + created.data.id + '/attempts') && response.request().method() === 'POST');
        await page.getByRole('button', {name: 'Start practice', exact: true}).click();
        const response = await starting;
        expect(response.status()).toBe(201);
        const payload = await response.json();
        const questions = payload.sections.flatMap(section => section.questions);
        expect(questions).toHaveLength(16);
        for (let index = 0; index < questions.length; index++) {
            const question = questions[index];
            const authored = reference.find(row => row.id === question.id);
            const correct = authored.answer_schema.correct;
            await test.step(question.type + ': save and recover the correct response', async () => {
                await page.getByRole('button', {name: 'Question ' + (index + 1), exact: true}).click();
                await expect(page.locator('.question-title')).toHaveText(question.title);
                await expect(page.locator('.exam-main .notice-banner').filter({hasText: 'Activating the server timer'})).toHaveCount(0);
                if (['matching', 'drag_drop'].includes(question.type)) {
                    for (let row = 0; row < question.metadata.left_items.length; row++) {
                        const id = question.metadata.left_items[row].id;
                        await page.locator('.matching-response select').nth(row).selectOption(String(correct[id]));
                    }
                } else if (question.type === 'dropdown' && question.metadata.blanks?.length) {
                    for (let blank = 0; blank < question.metadata.blanks.length; blank++) await page.locator('.blank-response select').nth(blank).selectOption(String(correct[question.metadata.blanks[blank].id]));
                } else if (question.type === 'fill_blank' && question.metadata.blanks?.length) {
                    for (let blank = 0; blank < question.metadata.blanks.length; blank++) await page.locator('.blank-response input').nth(blank).fill(String(correct[question.metadata.blanks[blank].id]));
                } else if (['fill_blank', 'short_answer'].includes(question.type)) {
                    await page.locator('.response-text input').fill(String(correct));
                } else if (['sentence_ordering', 'word_ordering'].includes(question.type)) {
                    for (let desiredIndex = 0; desiredIndex < correct.length; desiredIndex++) {
                        const text = question.options.find(option => option.id === correct[desiredIndex]).text;
                        let actualIndex = (await page.locator('.ordering-item>span:last-of-type').allTextContents()).findIndex(value => value === text);
                        expect(actualIndex).toBeGreaterThanOrEqual(0);
                        while (actualIndex > desiredIndex) {
                            await page.locator('.ordering-item').nth(actualIndex).getByRole('button', {name: 'Move up', exact: true}).click();
                            actualIndex--;
                        }
                    }
                    await page.getByRole('button', {name: 'Confirm this order', exact: true}).click();
                } else if (question.type === 'dropdown') {
                    await page.locator('.dropdown-response select').selectOption(String(correct));
                } else {
                    const expected = Array.isArray(correct) ? correct : [correct];
                    const values = await page.locator('.choice-option input').evaluateAll(inputs => inputs.map(input => input.value));
                    for (const value of expected) {
                        const optionIndex = values.indexOf(String(value));
                        expect(optionIndex).toBeGreaterThanOrEqual(0);
                        await page.locator('.choice-option').nth(optionIndex).click();
                    }
                }
                await expect(page.locator('.exam-saved')).toContainText('All changes saved');
                const saved = await (await page.request.get('/api/attempts/' + payload.attempt.id)).json();
                expect(saved.answers[question.key].response).toEqual(correct);
                if (question.type === 'reading') await expect(page.locator('.reading-passage')).toContainText('Universitas Nusantara');
                if (question.type === 'image') await expect.poll(() => page.locator('.question-media img').evaluate(image => image.complete && image.naturalWidth > 0)).toBeTruthy();
                if (question.type === 'listening') {
                    const audio = page.locator('.question-media audio');
                    await expect.poll(() => audio.evaluate(element => element.readyState)).toBeGreaterThanOrEqual(2);
                    await page.getByRole('button', {name: 'Play audio', exact: true}).click();
                    await expect.poll(() => audio.evaluate(element => element.currentTime)).toBeGreaterThan(0);
                    await page.getByRole('button', {name: 'Pause audio', exact: true}).click();
                    await expect.poll(() => audio.evaluate(element => element.paused)).toBeTruthy();
                }
                if (question.type === 'video') {
                    const video = page.locator('.question-media video');
                    await expect.poll(() => video.evaluate(element => element.readyState)).toBeGreaterThanOrEqual(2);
                    await video.evaluate(element => element.play());
                    await expect.poll(() => video.evaluate(element => element.currentTime)).toBeGreaterThan(0);
                    await video.evaluate(element => element.pause());
                }
            });
        }
        await page.reload();
        await expect(page.locator('.exam-saved')).toContainText('All changes saved');
        const recovered = await (await page.request.get('/api/attempts/' + payload.attempt.id)).json();
        for (const question of questions) expect(recovered.answers[question.key].response).toEqual(reference.find(row => row.id === question.id).answer_schema.correct);
        await page.locator('.exam-sidebar').getByRole('button', {name: 'Finish assessment', exact: true}).click();
        const submitting = page.waitForResponse(response => response.url().endsWith('/api/attempts/' + payload.attempt.id + '/submit'));
        await page.getByRole('dialog').getByRole('button', {name: 'Submit assessment', exact: true}).click();
        const submitted = await submitting;
        expect(submitted.status()).toBe(200);
        const result = await submitted.json();
        expect(result.attempt.status).toBe('completed');
        expect(result.result.manual_pending).toBe(0);
        expect(result.result.total_score).toBe(100);
        expect(result.result.question_scores).toHaveLength(16);
        for (const question of result.result.question_scores) expect(question.score).toBe(100);
        await expect(page.locator('.result-score strong')).toHaveText('100');
        expect((await (await page.request.get('/api/certificates')).json()).certificates).toEqual([]);
        expect(errors).toEqual([]);
    } finally {
        if (fixturePackageId) {
            await login(page, 'superadmin');
            expect((await write(page, '/admin/packages/' + fixturePackageId, {}, 'DELETE')).status).toBe(200);
        }
        saveHttpMetrics(testInfo, metrics);
    }
});
