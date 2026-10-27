import {test, expect} from '@playwright/test';
import {login, noHorizontalOverflow} from './helpers.js';

test.beforeEach(async ({page}) => {
    await page.addInitScript(() => localStorage.setItem('inbaf-language', 'en'));
});

for (const [size, pixels] of [['small', 14], ['normal', 16], ['large', 18], ['extra_large', 20]]) {
    test(`public navbar hides the size control and preserves saved ${size} typography`, async ({page}) => {
        await page.addInitScript(value => localStorage.setItem('inbaf-text-size', value), size);
        for (const route of ['/', '/login', '/verify']) {
            await page.goto(route);
            await expect(page.locator('.public-header')).toBeVisible();
            await expect(page.locator('.public-header .text-size-control')).toHaveCount(0);
            await expect(page.getByRole('combobox', {name: 'Text size', exact: true})).toHaveCount(0);
            await expect(page.locator('html')).toHaveAttribute('data-text-size', size);
            expect(await page.locator('html').evaluate(el => parseFloat(getComputedStyle(el).fontSize))).toBe(pixels);
            expect(await page.evaluate(() => localStorage.getItem('inbaf-text-size'))).toBe(size);
            await noHorizontalOverflow(page);
        }
        await page.goto('/');
        const typography = () => page.locator('.landing-redesign h1, .public-header nav a, .public-actions button, .public-actions a')
            .evaluateAll(elements => elements.map(el => getComputedStyle(el).fontSize));
        const before = await typography();
        await page.reload();
        await expect(page.locator('.public-header')).toBeVisible();
        expect(await typography()).toEqual(before);
        await expect(page.locator('html')).toHaveAttribute('data-text-size', size);
    });
}

test('public navbar stays visible in both scroll directions and clears anchor targets at every viewport', async ({page}) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(() => {
        localStorage.setItem('inbaf-text-size', 'extra_large');
        localStorage.setItem('inbaf-theme', 'dark');
    });
    const header = page.locator('.public-header');
    for (const width of [1440, 768, 390, 320]) {
        await page.setViewportSize({width, height: 1000});
        await page.goto('/');
        await expect(header).toBeVisible();
        await noHorizontalOverflow(page);
        expect(await page.locator('.public-shell > main').evaluate(el =>
            el.getBoundingClientRect().top - document.querySelector('.public-header').getBoundingClientRect().bottom
        )).toBeGreaterThanOrEqual(-1);
        for (const top of [1200, 500]) {
            await page.evaluate(value => window.scrollTo({top: value, behavior: 'instant'}), top);
            await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(0);
            await expect.poll(() => header.evaluate(el => el.getBoundingClientRect().top)).toBeCloseTo(0, 0);
            await expect(header.getByRole('link', {name: 'Sign in', exact: true})).toBeInViewport();
        }
        await page.locator('.landing-redesign a[href="#how-it-works"], .landing-redesign a[href="/#how-it-works"]').first().click();
        await expect(page).toHaveURL(/#how-it-works$/);
        await expect.poll(() => page.locator('#how-it-works').evaluate(el =>
            el.getBoundingClientRect().top - document.querySelector('.public-header').getBoundingClientRect().bottom
        )).toBeGreaterThanOrEqual(-1);
        await noHorizontalOverflow(page);
    }
    expect(errors).toEqual([]);
});

test('public navbar language, theme, certificate and sign-in actions keep working', async ({page}) => {
    await page.addInitScript(() => localStorage.setItem('inbaf-theme', 'light'));
    await page.goto('/');
    const header = page.locator('.public-header');
    const language = header.locator('.language-button');
    await expect(language).toHaveText('EN');
    await language.click();
    await expect(language).toHaveText('ID');
    await expect(page.locator('html')).toHaveAttribute('lang', 'id');
    await language.click();
    await expect(language).toHaveText('EN');
    const typography = () => header.locator('a, button').evaluateAll(elements => elements.map(el => getComputedStyle(el).fontSize));
    const before = await typography();
    await header.getByRole('button', {name: 'Change theme', exact: true}).click();
    await expect(page.locator('html')).toHaveClass(/dark/);
    expect(await typography()).toEqual(before);
    await header.getByRole('button', {name: 'Change theme', exact: true}).click();
    await expect(page.locator('html')).not.toHaveClass(/dark/);
    await header.getByRole('link', {name: 'Verify Certificate', exact: true}).click();
    await expect(page).toHaveURL(/\/verify$/);
    await expect(page.getByRole('textbox', {name: 'Certificate token', exact: true})).toBeVisible();
    await header.locator('a').filter({has: page.getByRole('img', {name: 'INBAF', exact: true})}).click();
    await expect(page.locator('.landing-redesign')).toBeVisible();
    await header.getByRole('link', {name: 'Sign in', exact: true}).click();
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByLabel('Email address', {exact: true})).toBeVisible();
});

test('account preference restores from the server and rapid changes keep the final choice', async ({page}) => {
    await login(page);
    const control = page.getByRole('combobox', {name: 'Text size', exact: true});
    await control.selectOption('extra_large');
    await expect(control).toHaveAttribute('aria-busy', 'false');
    expect((await (await page.request.get('/api/bootstrap')).json()).user.text_size).toBe('extra_large');
    await page.evaluate(() => localStorage.removeItem('inbaf-text-size'));
    await page.reload();
    await expect(control).toHaveValue('extra_large');
    for (const width of [1440, 390, 320]) {
        await page.setViewportSize({width, height: 1000});
        await noHorizontalOverflow(page);
        await expect(control).toBeVisible();
    }
    await page.screenshot({path: 'tmp/qa/text-size-dashboard-320.png', fullPage: true});

    const writes = [];
    let releaseFirst, firstArrived;
    const delayed = new Promise(resolve => releaseFirst = resolve);
    const arrived = new Promise(resolve => firstArrived = resolve);
    await page.route('**/api/preferences', async route => {
        writes.push(route.request().postDataJSON().text_size);
        if (writes.length === 1) {firstArrived(); await delayed;}
        await route.continue();
    });
    await control.selectOption('large');
    await arrived;
    await control.selectOption('normal');
    expect(writes).toEqual(['large']);
    releaseFirst();
    await expect(control).toHaveAttribute('aria-busy', 'false');
    expect(writes).toEqual(['large', 'normal']);
    await page.unroute('**/api/preferences');
    await page.reload();
    await expect(control).toHaveValue('normal');
    expect((await (await page.request.get('/api/bootstrap')).json()).user.text_size).toBe('normal');
});

test('superadmin can enlarge the question bank on small screens', async ({page}) => {
    await login(page, 'superadmin');
    const control = page.getByRole('combobox', {name: 'Text size', exact: true});
    await control.selectOption('extra_large');
    await expect(control).toHaveAttribute('aria-busy', 'false');
    await page.goto('/admin/questions');
    await expect(control).toHaveValue('extra_large');
    for (const width of [1440, 390, 320]) {
        await page.setViewportSize({width, height: 1000});
        await noHorizontalOverflow(page);
    }
    await page.screenshot({path: 'tmp/qa/text-size-admin-320.png', fullPage: true});
    await control.selectOption('normal');
    await expect(control).toHaveAttribute('aria-busy', 'false');
});

test('text size enlarges exam questions while keeping mobile navigation available', async ({page}) => {
    await login(page);
    const started = await page.evaluate(async () => {
        const bootstrap = await (await fetch('/api/bootstrap')).json();
        const chosen = bootstrap.packages.find(item => item.name.includes('Placement')) || bootstrap.packages[0];
        const response = await fetch('/api/packages/' + chosen.id + '/attempts', {
            method: 'POST', headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content}, body: '{}',
        });
        return {status: response.status, data: await response.json()};
    });
    expect(started.status).toBe(201);
    await page.goto('/exam/' + started.data.attempt.id);
    const prompt = page.locator('.question-title');
    await expect(prompt).toBeVisible();
    const normal = await prompt.evaluate(el => parseFloat(getComputedStyle(el).fontSize));
    const control = page.getByRole('combobox', {name: 'Text size', exact: true});
    await control.selectOption('extra_large');
    await expect.poll(() => prompt.evaluate(el => parseFloat(getComputedStyle(el).fontSize))).toBeGreaterThan(normal);
    for (const width of [1440, 390, 320]) {
        await page.setViewportSize({width, height: 1000});
        await noHorizontalOverflow(page);
        await expect(control).toBeVisible();
    }
    await page.screenshot({path: 'tmp/qa/text-size-exam-320.png', fullPage: true});
    await control.selectOption('normal');
    await expect(control).toHaveAttribute('aria-busy', 'false');
});
