import {test, expect} from '@playwright/test';
import {login, noHorizontalOverflow} from './helpers.js';

const widths = [320, 375, 768, 1024, 1366, 1440, 1920];
const resources = ['questions', 'packages', 'participants', 'competencies', 'groups', 'media', 'reviews', 'certificates', 'levels', 'audit'];
const routes = ['/dashboard', ...resources.map(resource => '/admin/' + resource), '/profile', '/admin/settings'];
const browserErrors = new WeakMap();

test.beforeEach(async ({page}) => {
    browserErrors.set(page, []);
    page.on('pageerror', error => browserErrors.get(page).push(error.stack || error.message));
    await page.addInitScript(() => {
        localStorage.setItem('inbaf-language', 'en');
        if (!localStorage.getItem('inbaf-theme')) localStorage.setItem('inbaf-theme', 'light');
    });
});

test.afterEach(async ({page}) => {
    expect(browserErrors.get(page), 'Uncaught browser JavaScript errors').toEqual([]);
});

async function setTheme(page, theme) {
    await page.evaluate(value => {
        localStorage.setItem('inbaf-theme', value);
        document.documentElement.classList.toggle('dark', value === 'dark');
    }, theme);
}

async function readyWorkspace(page) {
    await expect(page.locator('.main-content h1').first()).toBeVisible();
    const adminContent = page.locator('.admin-content');
    if (await adminContent.count()) await expect(adminContent).toHaveAttribute('aria-busy', 'false');
    await expect(page.locator('.main-content .loading-panel')).toHaveCount(0);
    await expect(page.locator('.main-content .inline-error')).toHaveCount(0);
}

async function resizeAndPaint(page, width) {
    await page.setViewportSize({width, height: 1000});
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
}

for (const theme of ['light', 'dark']) {
    test(`superadmin modules and settings resize through all seven widths in ${theme} mode`, async ({page}, testInfo) => {
        test.setTimeout(300000);
        await login(page, 'superadmin');
        await setTheme(page, theme);
        for (const route of routes) {
            await page.goto(route);
            await readyWorkspace(page);
            if (theme === 'dark') await expect(page.locator('html')).toHaveClass(/dark/);
            else await expect(page.locator('html')).not.toHaveClass(/dark/);
            for (const width of widths) {
                await test.step(`${route} at ${width}px`, async () => {
                    await resizeAndPaint(page, width);
                    await expect(page.locator('.main-content h1').first()).toBeVisible();
                    await expect(page.locator('.topbar')).toBeVisible();
                    await noHorizontalOverflow(page);
                    if (resources.some(resource => route === '/admin/' + resource)) {
                        const region = page.locator('.admin-table-panel .table-scroll');
                        await expect(region).toHaveAttribute('tabindex', '0');
                        await expect(region.locator('caption')).toHaveCount(1);
                        await expect(region.locator('thead th').last()).toHaveText('Actions');
                        // Every original column remains present throughout live viewport resizing.
                        const dimensions = await region.evaluate(element => ({scroll: element.scrollWidth, client: element.clientWidth, page: document.documentElement.scrollWidth}));
                        expect(dimensions.page).toBeLessThanOrEqual(width + 1);
                        if (width <= 768) expect(dimensions.scroll).toBeGreaterThan(dimensions.client);
                    }
                    if ([320, 1440].includes(width)) {
                        const name = route.replaceAll('/', '-').replace(/^-/, '');
                        await page.screenshot({path: testInfo.outputPath(`${name}-${theme}-${width}.png`), fullPage: true});
                    }
                });
            }
        }
        // Settings retain their live component state as every subsection is resized.
        for (const width of widths) {
            await test.step(`all settings sections at ${width}px`, async () => {
                await resizeAndPaint(page, width);
                for (const tab of ['Identity', 'Branding', 'Assessment', 'Certificates', 'Security', 'Notifications']) {
                    await page.locator('.settings-nav').getByRole('button', {name: tab, exact: true}).click();
                    await expect(page.locator('.settings-form .form-grid')).toBeVisible();
                    await noHorizontalOverflow(page);
                }
                await page.locator('.settings-nav').getByRole('button', {name: 'Test types', exact: true}).click();
                const policy = page.locator('.test-type-policy-form');
                await expect(policy.locator('.builder-tabs button')).toHaveCount(3);
                for (const button of await policy.locator('.builder-tabs button').all()) {
                    await button.click();
                    await expect(button).toHaveAttribute('aria-pressed', 'true');
                    await expect(policy.getByLabel('English name', {exact: true})).toBeVisible();
                    await noHorizontalOverflow(page);
                }
                await page.screenshot({path: testInfo.outputPath(`test-type-policy-${theme}-${width}.png`), fullPage: true});
            });
        }
    });
}

for (const {width, theme} of [{width: 320, theme: 'light'}, {width: 1440, theme: 'dark'}]) {
    test(`admin builders, nested dialogs, keyboard focus and empty search at ${width}px`, async ({page}, testInfo) => {
        test.setTimeout(120000);
        await page.setViewportSize({width, height: 1000});
        await login(page, 'superadmin');
        await setTheme(page, theme);
        await page.goto('/admin/questions');
        await readyWorkspace(page);
        const create = page.getByRole('button', {name: 'Create new', exact: true});
        await create.click();
        const editor = page.locator('[data-dialog="editor"]');
        await expect(editor).toBeVisible();
        await expect(page.locator('.admin-content')).toHaveAttribute('inert', '');
        await expect(editor.getByLabel('Title', {exact: true})).toBeVisible();
        await editor.getByLabel('Title', {exact: true}).fill('Responsive draft preview');
        const typeSelect = editor.getByRole('combobox', {name: 'Question type', exact: true});
        const types = await typeSelect.locator('option').evaluateAll(options => options.map(option => option.value));
        for (const type of types) {
            await typeSelect.selectOption(type);
            await noHorizontalOverflow(page);
            expect(await editor.evaluate(element => element.scrollWidth <= element.clientWidth + 1), `${type} editor fits its modal`).toBeTruthy();
            expect(await editor.locator('.modal-body').evaluate(element => element.scrollWidth <= element.clientWidth + 1), `${type} form body fits without horizontal scrolling`).toBeTruthy();
        }
        await typeSelect.selectOption('matching');
        await expect(editor.locator('.option-builder-row select')).toHaveCount(4);
        await editor.locator('.option-builder-row').first().scrollIntoViewIfNeeded();
        await expect(editor.locator('.option-builder-row').first()).toBeInViewport();
        await expect(editor.locator('.modal-header')).toBeInViewport({ratio: 1});
        await expect(editor.locator('.modal-footer')).toBeInViewport({ratio: 1});
        await page.screenshot({path: testInfo.outputPath(`question-matching-${theme}-${width}.png`), fullPage: true});

        // Tab and Shift+Tab cannot move focus behind the active dialog.
        await editor.locator('.modal-header button').focus();
        await page.keyboard.press('Shift+Tab');
        await expect(editor.locator('.modal-footer button').last()).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(editor.locator('.modal-header button')).toBeFocused();

        const previewTrigger = editor.getByRole('button', {name: 'Preview', exact: true}).first();
        await previewTrigger.click();
        const preview = page.locator('[data-dialog="preview"]');
        await expect(preview).toBeVisible();
        await expect(editor.locator('..')).toHaveAttribute('inert', '');
        await expect(preview.locator('.modal-header button')).toBeFocused();
        await noHorizontalOverflow(page);
        await page.keyboard.press('Escape');
        await expect(preview).toHaveCount(0);
        await expect(previewTrigger).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(editor).toHaveCount(0);
        await expect(create).toBeFocused();
        await expect(page.locator('.admin-content')).not.toHaveAttribute('inert', '');

        await page.locator('tbody tr').first().getByRole('button', {name: 'Question usage history', exact: true}).click();
        const usage = page.locator('[data-dialog="usage"]');
        await expect(usage).toBeVisible();
        await expect(usage.locator('.loading-panel')).toHaveCount(0);
        await noHorizontalOverflow(page);
        await page.keyboard.press('Escape');
        await expect(usage).toHaveCount(0);

        await page.getByRole('textbox', {name: 'Search records', exact: true}).fill('__no_matching_inbaf_record__');
        await expect(page.locator('.table-empty')).toContainText('No matching records');
        await page.locator('.table-empty').getByRole('button', {name: 'Clear filters', exact: true}).click();
        await expect(page.getByRole('textbox', {name: 'Search records', exact: true})).toHaveValue('');
        await expect(page.locator('tbody tr').first().getByRole('button', {name: 'Edit', exact: true})).toBeVisible();

        for (const resource of ['packages', 'participants', 'competencies', 'groups', 'levels']) {
            await page.goto('/admin/' + resource);
            await readyWorkspace(page);
            await page.getByRole('button', {name: 'Create new', exact: true}).click();
            const dialog = page.locator('[data-dialog="editor"]');
            await expect(dialog).toBeVisible();
            if (resource === 'packages') {
                await dialog.getByRole('button', {name: 'Add section', exact: true}).click();
                await dialog.getByRole('combobox', {name: 'Question selection', exact: true}).selectOption('manual');
                await expect(dialog.locator('.question-selector')).toBeVisible();
                await dialog.locator('input,select,textarea').last().scrollIntoViewIfNeeded();
                await expect(dialog.locator('.modal-header')).toBeInViewport({ratio: 1});
                await expect(dialog.locator('.modal-footer')).toBeInViewport({ratio: 1});
            }
            await noHorizontalOverflow(page);
            expect(await dialog.evaluate(element => element.scrollWidth <= element.clientWidth + 1), `${resource} form fits its modal`).toBeTruthy();
            await page.screenshot({path: testInfo.outputPath(`${resource}-editor-${theme}-${width}.png`), fullPage: true});
            await page.keyboard.press('Escape');
            await expect(dialog).toHaveCount(0);
        }
        await page.goto('/admin/audit');
        await readyWorkspace(page);
        await page.locator('tbody tr').first().getByRole('button', {name: 'View event', exact: true}).click();
        await expect(page.locator('[data-dialog="detail"]')).toBeVisible();
        await noHorizontalOverflow(page);
        await page.keyboard.press('Escape');
        await expect(page.locator('[data-dialog="detail"]')).toHaveCount(0);
    });
}

test('duplicate competency code shows the real 422 beside its field and restores correction focus', async ({page}, testInfo) => {
    await page.setViewportSize({width: 320, height: 1000});
    await login(page, 'superadmin');
    const bootstrap = await (await page.request.get('/api/bootstrap')).json();
    expect(bootstrap.competencies.length).toBeGreaterThan(0);
    await page.goto('/admin/competencies');
    await readyWorkspace(page);
    const create = page.getByRole('button', {name: 'Create new', exact: true});
    await create.click();
    const dialog = page.locator('[data-dialog="editor"]');
    const code = dialog.getByLabel('Code', {exact: true});
    await code.fill(bootstrap.competencies[0].code);
    await dialog.getByLabel('Indonesian name', {exact: true}).fill('Kompetensi untuk validasi antarmuka');
    await dialog.getByLabel('English name', {exact: true}).fill('UI validation competency');
    const saving = page.waitForResponse(response => new URL(response.url()).pathname === '/api/admin/competencies' && response.request().method() === 'POST');
    await dialog.getByRole('button', {name: 'Save changes', exact: true}).click();
    const response = await saving;
    expect(response.status()).toBe(422);
    const payload = await response.json();
    expect(payload.errors.code).toHaveLength(1);
    await expect(code).toHaveAttribute('name', 'code');
    await expect(code).toHaveAttribute('aria-invalid', 'true');
    await expect(code).toBeFocused();
    const message = dialog.locator('.admin-field-error');
    await expect(message).toHaveText(payload.errors.code[0]);
    await expect(code).toHaveAttribute('aria-describedby', await message.getAttribute('id'));
    await expect(message).toBeInViewport();
    await expect(dialog.locator('.inline-error')).toContainText(payload.errors.code[0]);
    await noHorizontalOverflow(page);
    await page.screenshot({path: testInfo.outputPath('competency-validation-320.png'), fullPage: true});
    await page.keyboard.press('Escape');
    await create.click();
    await expect(dialog.locator('.admin-field-error')).toHaveCount(0);
    await expect(dialog.getByLabel('Code', {exact: true})).not.toHaveAttribute('aria-invalid', 'true');
});
