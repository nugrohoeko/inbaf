import {test, expect} from '@playwright/test';
import fs from 'node:fs';
import {login} from './helpers.js';
import {observeHttp, saveHttpMetrics, timedHttp} from './final-qa-metrics.js';

const metrics = new WeakMap();

test.beforeEach(async ({page}) => {
    metrics.set(page, observeHttp(page));
    page.on('dialog', dialog => dialog.accept());
    await page.addInitScript(() => {
        localStorage.setItem('inbaf-language', 'en');
        localStorage.setItem('inbaf-theme', 'light');
    });
});

test.afterEach(async ({page}, testInfo) => saveHttpMetrics(testInfo, metrics.get(page)));

function csv(rows) {
    return rows.map(row => row.map(cell => '"' + String(cell).replaceAll('"', '""') + '"').join(',')).join('\r\n') + '\r\n';
}

async function importFile(page, contents) {
    const choosing = page.waitForEvent('filechooser');
    await page.getByRole('button', {name: 'Import CSV', exact: true}).click();
    await (await choosing).setFiles({name: 'final-qa-questions.csv', mimeType: 'text/csv', buffer: Buffer.from(contents)});
}

test('question CSV import validates atomically, paginates, filters, bulk archives and downloads genuine CSV and XLSX', async ({page}) => {
    test.setTimeout(150000);
    await login(page, 'superadmin');
    await page.goto('/admin/questions');
    const suffix = Date.now().toString(36);
    const bootstrap = await (await page.request.get('/api/bootstrap')).json();
    const competency = bootstrap.competencies.find(row => row.code === 'grammar');
    const headers = ['code', 'title', 'competency_id', 'type', 'difficulty', 'weight', 'status', 'prompt', 'instructions', 'options', 'answer_schema', 'metadata', 'question_group_id', 'allowed_test_types', 'confidential', 'usage_limit', 'duration_seconds', 'explanation'];
    const row = index => ['FINAL-CSV-' + suffix + '-' + index, 'Final CSV ' + suffix + ' ' + index, competency.id, 'single_choice', 'easy', 1, 'active', 'Pilih sapaan yang tepat.', '', JSON.stringify([{id: 'a', text: 'Selamat pagi'}, {id: 'b', text: 'Good night'}]), JSON.stringify({correct: 'a'}), '{}', '', '["practice","placement"]', 0, '', '', 'Sapaan pagi dalam bahasa Indonesia.'];
    const valid = csv([headers, ...Array.from({length: 22}, (_, i) => row(i + 1))]);
    const invalid = csv([headers, row(1), [...row(2).slice(0, 3), 'unsupported-type', ...row(2).slice(4)]]);
    let imported = page.waitForResponse(response => response.url().endsWith('/api/admin/questions/import') && response.request().method() === 'POST');
    await importFile(page, invalid);
    expect((await imported).status()).toBe(422);
    await expect(page.getByRole('alert')).toBeVisible();
    const afterInvalid = await (await page.request.get('/api/admin/questions?search=' + encodeURIComponent('Final CSV ' + suffix))).json();
    expect(afterInvalid.total).toBe(0);
    imported = page.waitForResponse(response => response.url().endsWith('/api/admin/questions/import') && response.request().method() === 'POST');
    await importFile(page, valid);
    const successful = await imported;
    expect(successful.status()).toBe(201);
    expect((await successful.json()).count).toBe(22);
    await expect(page.locator('.notice-banner')).toContainText('Import: 22 questions added.');
    await page.getByRole('textbox', {name: 'Search records', exact: true}).fill('Final CSV ' + suffix);
    await expect(page.locator('.pagination')).toContainText('22 records');
    await expect(page.locator('tbody tr')).toHaveCount(20);
    await page.locator('.pagination').getByRole('button', {name: 'Next', exact: true}).click();
    await expect(page.locator('.pagination')).toContainText('Page 2 of 2');
    await expect(page.locator('tbody tr')).toHaveCount(2);
    await page.locator('.pagination').getByRole('button', {name: 'Previous', exact: true}).click();
    await expect(page.locator('tbody tr')).toHaveCount(20);
    await page.getByRole('combobox', {name: 'Filter question type', exact: true}).selectOption('essay');
    await expect(page.locator('.table-empty')).toContainText('No matching records');
    await page.getByRole('combobox', {name: 'Filter question type', exact: true}).selectOption('single_choice');
    await expect(page.locator('tbody tr')).toHaveCount(20);
    await page.getByRole('checkbox', {name: 'Select all questions on this page', exact: true}).check();
    await page.getByRole('combobox', {name: 'Bulk question action', exact: true}).selectOption('draft');
    let bulk = page.waitForResponse(response => response.url().endsWith('/api/admin/questions/bulk'));
    await page.getByRole('button', {name: 'Apply to selected', exact: true}).click();
    expect((await bulk).status()).toBe(200);
    await page.getByRole('combobox', {name: 'Filter status', exact: true}).selectOption('active');
    await expect(page.locator('tbody tr')).toHaveCount(2);
    const archive = page.waitForResponse(response => /\/api\/admin\/questions\/\d+$/.test(response.url()) && response.request().method() === 'DELETE');
    await page.locator('tbody tr').first().getByRole('button', {name: 'Archive', exact: true}).click();
    expect((await archive).status()).toBe(200);
    await expect(page.locator('tbody tr')).toHaveCount(1);
    const afterArchive = await (await page.request.get('/api/admin/questions?search=' + encodeURIComponent('Final CSV ' + suffix))).json();
    expect(afterArchive.total).toBe(22);
    expect(afterArchive.data.filter(question => question.status === 'draft')).toHaveLength(20);
    imported = page.waitForResponse(response => response.url().endsWith('/api/admin/questions/import') && response.request().method() === 'POST');
    await importFile(page, valid);
    expect((await imported).status()).toBe(422);
    expect((await (await page.request.get('/api/admin/questions?search=' + encodeURIComponent('Final CSV ' + suffix))).json()).total).toBe(22);
    const csvDownload = page.waitForEvent('download');
    await page.getByRole('link', {name: 'Export CSV', exact: true}).click();
    const downloadedCsv = await csvDownload;
    expect(downloadedCsv.suggestedFilename()).toBe('inbaf-questions.csv');
    fs.mkdirSync('tmp/qa/final', {recursive: true});
    await downloadedCsv.saveAs('tmp/qa/final/questions-export.csv');
    const exported = fs.readFileSync('tmp/qa/final/questions-export.csv', 'utf8');
    expect(exported).toContain('Final CSV ' + suffix);
    expect(exported).toContain('allowed_test_types');
    const xlsxDownload = page.waitForEvent('download');
    await page.getByRole('link', {name: 'Template', exact: true}).click();
    const downloadedXlsx = await xlsxDownload;
    expect(downloadedXlsx.suggestedFilename()).toBe('inbaf-question-template.xlsx');
    await downloadedXlsx.saveAs('tmp/qa/final/questions-template.xlsx');
    expect(fs.readFileSync('tmp/qa/final/questions-template.xlsx').subarray(0, 2).toString()).toBe('PK');
});

test('admin media upload stores genuine image and audio bytes behind authenticated media links', async ({page}) => {
    test.setTimeout(90000);
    await login(page, 'superadmin');
    await page.goto('/admin/media');
    const png = Buffer.from(await page.evaluate(() => {
        const canvas = document.createElement('canvas'); canvas.width = 80; canvas.height = 80;
        const context = canvas.getContext('2d'); context.fillStyle = '#193c31'; context.fillRect(0, 0, 80, 80);
        return canvas.toDataURL('image/png').split(',')[1];
    }), 'base64');
    const rate = 8000, samples = rate, wav = Buffer.alloc(44 + samples * 2);
    wav.write('RIFF', 0); wav.writeUInt32LE(wav.length - 8, 4); wav.write('WAVEfmt ', 8);
    wav.writeUInt32LE(16, 16); wav.writeUInt16LE(1, 20); wav.writeUInt16LE(1, 22);
    wav.writeUInt32LE(rate, 24); wav.writeUInt32LE(rate * 2, 28); wav.writeUInt16LE(2, 32); wav.writeUInt16LE(16, 34);
    wav.write('data', 36); wav.writeUInt32LE(samples * 2, 40);
    for (let index = 0; index < samples; index++) wav.writeInt16LE(Math.round(Math.sin(index * 2 * Math.PI * 440 / rate) * 1000), 44 + index * 2);
    for (const file of [{name: 'final-qa-image.png', mimeType: 'image/png', buffer: png}, {name: 'final-qa-audio.wav', mimeType: 'audio/wav', buffer: wav}]) {
        const choosing = page.waitForEvent('filechooser');
        await page.getByRole('button', {name: 'Upload file', exact: true}).click();
        const uploaded = page.waitForResponse(response => response.url().endsWith('/api/admin/media') && response.request().method() === 'POST');
        await (await choosing).setFiles(file);
        const response = await uploaded;
        expect(response.status()).toBe(201);
        const asset = await response.json();
        const row = page.locator('tbody tr').filter({hasText: file.name});
        await expect(row).toHaveCount(1);
        const mediaUrl = await row.getByRole('link', {name: 'Open media', exact: true}).getAttribute('href');
        const media = await timedHttp(metrics.get(page), 'media-read', () => page.request.get(mediaUrl));
        expect(media.status()).toBe(200);
        if (file.mimeType === 'audio/wav') expect(media.headers()['content-type']).toMatch(/^audio\/(x-)?wav/);
        else expect(media.headers()['content-type']).toContain(file.mimeType);
        expect((await media.body()).equals(file.buffer)).toBeTruthy();
        expect(asset.id).toBeGreaterThan(0);
    }
});

test('generic competency and passage editors persist changes, level edits restore safely, and directory sorting reaches the server', async ({page}) => {
    test.setTimeout(150000);
    await login(page, 'superadmin');
    const suffix = Date.now().toString(36);
    for (const resource of ['competencies', 'groups']) {
        await page.goto('/admin/' + resource);
        await page.getByRole('button', {name: 'Create new', exact: true}).click();
        let editor = page.locator('[data-dialog="editor"]');
        const name = 'Final editor ' + resource + ' ' + suffix;
        if (resource === 'competencies') {
            await editor.getByLabel('Code', {exact: true}).fill('final-' + suffix);
            await editor.getByLabel('Indonesian name', {exact: true}).fill(name);
            await editor.getByLabel('English name', {exact: true}).fill(name);
        } else {
            await editor.getByLabel('Title', {exact: true}).fill(name);
            await editor.getByRole('combobox', {name: 'Group kind', exact: true}).selectOption('passage');
            await editor.locator('[contenteditable="true"]').fill('Bacaan uji final yang tersimpan di server.');
        }
        let saving = page.waitForResponse(response => response.url().endsWith('/api/admin/' + resource) && response.request().method() === 'POST');
        await editor.getByRole('button', {name: 'Save changes', exact: true}).click();
        expect((await saving).status()).toBe(201);
        await expect(editor).toHaveCount(0);
        await page.getByRole('textbox', {name: 'Search records', exact: true}).fill(name);
        const row = page.locator('tbody tr').filter({hasText: name});
        await expect(row).toHaveCount(1);
        await row.getByRole('button', {name: 'Edit', exact: true}).click();
        editor = page.locator('[data-dialog="editor"]');
        await editor.getByLabel(resource === 'competencies' ? 'English name' : 'Title', {exact: true}).fill(name + ' revised');
        saving = page.waitForResponse(response => new URL(response.url()).pathname.startsWith('/api/admin/' + resource + '/') && response.request().method() === 'PUT');
        await editor.getByRole('button', {name: 'Save changes', exact: true}).click();
        expect((await saving).status()).toBe(200);
        await expect(row).toContainText('revised');
        const archiving = page.waitForResponse(response => new URL(response.url()).pathname.startsWith('/api/admin/' + resource + '/') && response.request().method() === 'DELETE');
        const confirmation = resource === 'groups' ? page.waitForEvent('dialog') : null;
        await row.getByRole('button', {name: resource === 'groups' ? 'Delete group' : 'Archive', exact: true}).click();
        if (confirmation) expect((await confirmation).message()).toContain('Permanently delete');
        expect((await archiving).status()).toBe(200);
        if (resource === 'competencies') await expect(row).toContainText('Inactive');
        else await expect(row).toHaveCount(0);
    }
    const original = (await (await page.request.get('/api/admin/levels?per_page=100')).json()).data.find(row => row.code === 'C2');
    try {
        await page.goto('/admin/levels');
        const row = page.locator('tbody tr').filter({hasText: 'C2'});
        await row.getByRole('button', {name: 'Edit', exact: true}).click();
        const editor = page.locator('[data-dialog="editor"]');
        await editor.getByLabel('Level name', {exact: true}).fill(original.name + ' final QA');
        const saving = page.waitForResponse(response => response.url().endsWith('/api/admin/levels/' + original.id) && response.request().method() === 'PUT');
        await editor.getByRole('button', {name: 'Save changes', exact: true}).click();
        expect((await saving).status()).toBe(200);
        await expect(row).toContainText(original.name + ' final QA');
    } finally {
        const restored = await page.evaluate(async original => {
            const response = await fetch('/api/admin/levels/' + original.id, {method: 'PUT', credentials: 'same-origin', headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content}, body: JSON.stringify(original)});
            return response.status;
        }, original);
        expect(restored).toBe(200);
    }
    await page.goto('/admin/users');
    await expect(page.locator('.um-loading')).toHaveCount(0);
    for (const direction of ['asc', 'desc']) {
        const loading = page.waitForResponse(response => {
            const url = new URL(response.url());
            return url.pathname === '/api/admin/users' && url.searchParams.get('sort') === 'name' && url.searchParams.get('direction') === direction;
        });
        await page.getByRole('combobox', {name: 'Sort by', exact: true}).selectOption('name:' + direction);
        const response = await loading;
        expect(response.status()).toBe(200);
        const payload = await response.json();
        const names = payload.data.map(user => user.name);
        const sorted = [...names].sort((a, b) => a.localeCompare(b, 'en', {sensitivity: 'base'}));
        expect(names).toEqual(direction === 'asc' ? sorted : sorted.reverse());
        await expect(page.locator('.um-table tbody tr').first()).toContainText(names[0]);
    }
    const resized = page.waitForResponse(response => new URL(response.url()).pathname === '/api/admin/users' && new URL(response.url()).searchParams.get('per_page') === '25');
    await page.getByRole('combobox', {name: 'Rows per page', exact: true}).selectOption('25');
    expect((await resized).status()).toBe(200);
});
