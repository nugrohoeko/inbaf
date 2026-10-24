import {test, expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
import {login, noHorizontalOverflow} from './helpers.js';

const widths = [320, 375, 768, 1024, 1366, 1440, 1920];
async function writeApi(page, path, method, body = {}) {
    return page.evaluate(async ({path, method, body}) => {
        const response = await fetch('/api' + path, {method, credentials: 'same-origin', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content}, body: JSON.stringify(body)});
        return {status: response.status, body: await response.json()};
    }, {path, method, body});
}
async function paint(page) {
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
}

async function capture(page, path) {
    await expect(page.locator('.toast')).toHaveCount(0);
    await page.evaluate(async () => {await document.fonts.ready;document.activeElement?.blur();window.scrollTo({top: 0, behavior: 'instant'});});
    await paint(page);
    await page.screenshot({path, fullPage: true});
}
async function assertCanvasTickBounds(page, selector) {
    const chartAsset = Object.values(JSON.parse(readFileSync(new URL('../../public/build/manifest.json', import.meta.url), 'utf8'))).find(asset => asset.name === 'charts');
    expect(chartAsset, 'The existing production Chart.js module must be available').toBeTruthy();
    await expect(page.locator(selector).first()).toBeVisible();
    // Import the same public module as the app; no global or product-side probe is added.
    await expect.poll(() => page.locator(selector).evaluateAll(async (canvases, asset) => {
        await document.fonts.ready;
        const module = await import(asset);
        const Chart = Object.values(module).find(value => typeof value === 'function' && typeof value.getChart === 'function');
        const failures = [];
        for (const canvas of canvases) {
            const chart = Chart?.getChart(canvas);
            if (!chart) {failures.push({reason: 'Chart is not ready'});continue;}
            if (chart.options.indexAxis !== 'y' || !chart.scales.y) continue;
            const context = chart.ctx;
            context.save();context.textAlign = 'left';
            for (const item of chart.scales.y.getLabelItems()) {
                context.font = item.font.string;
                const lines = Array.isArray(item.label) ? item.label : [item.label];
                const top = item.options.translation[1] + item.textOffset - item.font.lineHeight / 2;
                const bottom = top + lines.length * item.font.lineHeight;
                for (const line of lines) {
                    const metrics = context.measureText(String(line));
                    const start = item.options.translation[0] - (item.options.textAlign === 'right' ? metrics.width : item.options.textAlign === 'center' ? metrics.width / 2 : 0);
                    const left = start - metrics.actualBoundingBoxLeft;
                    const right = start + metrics.actualBoundingBoxRight;
                    if (left < 0 || right > chart.width || top < 0 || bottom > chart.height) failures.push({label: line, left, right, top, bottom, canvasWidth: chart.width, canvasHeight: chart.height});
                }
            }
            context.restore();
        }
        return failures;
    }, '/build/' + chartAsset.file), {message: 'Every full-size category label must remain inside the canvas'}).toEqual([]);
}

test.beforeEach(async ({page}) => {
    await page.addInitScript(() => {
        localStorage.setItem('inbaf-language', 'en');
        localStorage.setItem('inbaf-theme', 'dark');
    });
    await page.emulateMedia({reducedMotion: 'reduce'});
    page.on('dialog', dialog => dialog.accept());
});

test('real reading attempt keeps large content, autosave, navigation and result charts usable at all target widths', async ({page}) => {
    test.setTimeout(150000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.stack || error.message));
    await login(page);
    const bootstrap = await (await page.request.get('/api/bootstrap')).json();
    const assessment = bootstrap.packages.find(item => item.test_type === 'placement');
    expect(assessment).toBeTruthy();
    const started = await writeApi(page, '/packages/' + assessment.id + '/attempts', 'POST');
    expect(started.status).toBe(201);
    const payload = started.body;
    const questions = payload.sections.flatMap(section => section.questions);
    const passageIndex = questions.findIndex(question => question.group?.kind === 'passage');
    expect(passageIndex).toBeGreaterThanOrEqual(0);
    await page.goto('/exam/' + payload.attempt.id);
    await page.getByRole('button', {name: 'Question ' + (passageIndex + 1), exact: true}).click();
    await expect(page.locator('.reading-passage')).toContainText('Universitas Nusantara');
    await expect(page.locator('.question-columns')).toHaveClass(/has-passage/);
    await expect(page.locator('.choice-option input').first()).toBeEnabled();
    const saving = page.waitForResponse(response => response.url().endsWith('/api/attempts/' + payload.attempt.id + '/answers') && response.request().method() === 'PUT');
    await page.locator('.choice-option').first().click();
    expect((await saving).status()).toBe(200);
    const saved = await (await page.request.get('/api/attempts/' + payload.attempt.id)).json();
    expect(saved.answers[questions[passageIndex].key].response).not.toBeNull();

    for (const width of widths) {
        await page.setViewportSize({width, height: 1000});
        await paint(page);
        await noHorizontalOverflow(page);
        expect(await page.locator('.question-prompt').evaluate(element => parseFloat(getComputedStyle(element).fontSize))).toBeGreaterThanOrEqual(20);
        if (width > 800) {
            await expect(page.locator('.exam-sidebar').getByRole('button', {name: 'Close question navigation', exact: true})).toBeHidden();
            await expect(page.locator('.exam-header .mobile-question-toggle')).toBeHidden();
        }
        expect(await page.locator('.exam-section>h4>span').evaluateAll(elements => elements.every(element => element.getBoundingClientRect().height <= parseFloat(getComputedStyle(element).lineHeight) + 1)), 'Section number must stay on one line').toBeTruthy();
        await expect(page.locator('.reading-passage')).toHaveAttribute('tabindex', '0');
        expect(await page.locator('.exam-timer strong').evaluate(element => {const range = document.createRange();range.selectNodeContents(element);return range.getClientRects().length;}), 'Timer value must stay on one line').toBe(1);
        const positions = await page.locator('.question-columns').evaluate(element => {
            const passage = element.querySelector('.reading-passage').getBoundingClientRect();
            const response = element.querySelector('.question-response').getBoundingClientRect();
            return {passageRight: passage.right, passageBottom: passage.bottom, responseLeft: response.left, responseTop: response.top};
        });
        if (width >= 1366) expect(positions.responseLeft).toBeGreaterThan(positions.passageRight);
        else expect(positions.responseTop).toBeGreaterThanOrEqual(positions.passageBottom - 1);
        await capture(page, 'tmp/qa/ui-audit/exam-reading-' + width + '.png');
    }

    await page.setViewportSize({width: 375, height: 1000});
    const navigation = page.locator('.exam-header').getByRole('button', {name: 'Question navigation / Daftar soal', exact: true});
    await navigation.click();
    await expect(page.locator('.exam-sidebar')).toHaveClass(/open/);
    await expect(page.locator('.exam-sidebar').getByRole('button', {name: 'Close question navigation', exact: true})).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(navigation).toBeFocused();
    await navigation.click();
    await page.locator('.exam-sidebar').getByRole('button', {name: 'Finish assessment', exact: true}).click();
    const dialog = page.locator('.submit-modal');
    await expect(dialog).toBeVisible();
    const keepGoing = dialog.getByRole('button', {name: 'Keep going', exact: true});
    const submit = dialog.getByRole('button', {name: 'Submit assessment', exact: true});
    await expect(keepGoing).toBeFocused();
    await page.keyboard.press('Shift+Tab');
    await expect(submit).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(keepGoing).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
    await expect(page.locator('.exam-sidebar')).toHaveClass(/open/);
    await page.locator('.exam-sidebar').getByRole('button', {name: 'Finish assessment', exact: true}).click();
    const submitting = page.waitForResponse(response => response.url().endsWith('/api/attempts/' + payload.attempt.id + '/submit'));
    await page.getByRole('dialog').getByRole('button', {name: 'Submit assessment', exact: true}).click();
    expect((await submitting).status()).toBe(200);
    await expect(page).toHaveURL(new RegExp('/results/' + payload.attempt.id + '$'));
    const result = await (await page.request.get('/api/results/' + payload.attempt.id)).json();
    const scores = result.result.competency_scores;
    expect(scores.length).toBeGreaterThan(0);
    const values = page.locator('.score-chart-details').first();
    await values.locator('summary').click();
    await expect(values.locator('dl>div')).toHaveCount(scores.length);
    await expect(values.locator('dd')).toHaveText(scores.map(score => (Number(score.normalized_score ?? score.score) || 0) + '%'));
    for (const width of widths) {
        await page.setViewportSize({width, height: 1000});
        await paint(page);
        await noHorizontalOverflow(page);
        for (const canvas of await page.locator('.score-chart-canvas canvas').all()) {
            expect(await canvas.evaluate(element => element.width)).toBeGreaterThan(0);
            await expect(canvas).toHaveAttribute('role', 'img');
        }
        await assertCanvasTickBounds(page, '.score-chart-canvas canvas');
        await capture(page, 'tmp/qa/ui-audit/result-charts-' + width + '.png');
    }
    await page.setViewportSize({width: 320, height: 1000});
    const textSize = page.locator('.topbar').getByRole('combobox', {name: 'Text size', exact: true});
    const originalSize = await textSize.inputValue();
    await textSize.selectOption('extra_large');
    await expect(textSize).toHaveAttribute('aria-busy', 'false');
    await noHorizontalOverflow(page);
    await assertCanvasTickBounds(page, '.score-chart-canvas canvas');
    await capture(page, 'tmp/qa/ui-audit/result-charts-extra-large-320.png');
    await textSize.selectOption(originalSize);
    await expect(textSize).toHaveAttribute('aria-busy', 'false');
    expect(errors, 'Uncaught errors from exam or chart resize').toEqual([]);
});

test('admin analytics exposes actual chart values and editor formatting works with keyboard', async ({page}) => {
    test.setTimeout(100000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page, 'superadmin');
    await page.goto('/dashboard');
    await expect(page.locator('.analytics-grid')).toBeVisible();
    // Read a settled request directly; login may still have an earlier dashboard response in flight.
    const data = await (await page.request.get('/api/dashboard')).json();
    if (data.trend?.length) {
        const values = page.locator('.analytics-grid .analytics-values').first();
        await values.locator('summary').click();
        await expect(values.locator('dd')).toHaveText(data.trend.map(row => String(row.attempts)));
    }
    await expect(page.locator('.distribution-legend li')).toHaveCount((data.level_distribution || []).length);
    for (const width of widths) {
        await page.setViewportSize({width, height: 1000});
        await paint(page);
        await noHorizontalOverflow(page);
        if (data.competency_scores?.length) await assertCanvasTickBounds(page, '.analytics-bars canvas');
        await capture(page, 'tmp/qa/ui-audit/admin-analytics-' + width + '.png');
    }
    await page.setViewportSize({width: 320, height: 1000});
    const textSize = page.locator('.topbar').getByRole('combobox', {name: 'Text size', exact: true});
    const originalSize = await textSize.inputValue();
    await textSize.selectOption('extra_large');
    await expect(textSize).toHaveAttribute('aria-busy', 'false');
    await noHorizontalOverflow(page);
    if (data.competency_scores?.length) await assertCanvasTickBounds(page, '.analytics-bars canvas');
    await capture(page, 'tmp/qa/ui-audit/admin-analytics-extra-large-320.png');
    await textSize.selectOption(originalSize);
    await expect(textSize).toHaveAttribute('aria-busy', 'false');
    await page.setViewportSize({width: 1440, height: 1000});
    await page.goto('/admin/questions');
    await page.getByRole('button', {name: 'Create new', exact: true}).click();
    const dialog = page.getByRole('dialog', {name: 'Question bank', exact: true});
    const editor = dialog.locator('.editor-body').first();
    await editor.fill('Keyboard formatting check');
    await editor.focus();
    await page.keyboard.press('Control+A');
    const bold = dialog.locator('.editor-toolbar').first().getByRole('button', {name: 'Bold', exact: true});
    await bold.focus();
    await page.keyboard.press('Enter');
    await expect(editor.locator('b,strong')).toHaveText('Keyboard formatting check');
    for (const width of [320, 768, 1440]) {
        await page.setViewportSize({width, height: 1000});
        await noHorizontalOverflow(page);
        await expect(bold).toBeVisible();
    }
    expect(errors).toEqual([]);
});
