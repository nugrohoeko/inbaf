import {test, expect} from '@playwright/test';
import {login, noHorizontalOverflow} from './helpers.js';

const widths = [320, 375, 768, 1024, 1366, 1440, 1920];
const types = ['practice', 'placement', 'certification'];
const snapshots = new Set([320, 1440]);
const rows = payload => Array.isArray(payload) ? payload : payload.data || payload.packages || payload.results || [];

async function settle(page) {
    await expect(page.locator('.loading-panel')).toHaveCount(0);
    await page.evaluate(async () => {
        await document.fonts.ready;
        await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    });
}

async function checkLayout(page, route, width, theme, name, publicPage = false) {
    await expect(page.locator(publicPage ? '.public-header' : '.topbar')).toBeVisible();
    const title = page.locator(publicPage ? (route.startsWith('/verify') ? '.verification-page h1' : '.auth-form-wrap h1') : 'main h1:visible').first();
    await expect(title).toBeVisible();
    await settle(page);
    await noHorizontalOverflow(page);
    const headingSize = await title.evaluate(el => parseFloat(getComputedStyle(el).fontSize));
    expect(headingSize, `${route}: readable page heading at ${width}px`).toBeGreaterThanOrEqual(28);
    const header = page.locator(publicPage ? '.public-header' : '.topbar');
    await page.evaluate(() => window.scrollTo({top: 600, behavior: 'instant'}));
    await expect.poll(() => header.evaluate(el => Math.abs(el.getBoundingClientRect().top))).toBeLessThanOrEqual(1);
    await page.evaluate(() => window.scrollTo({top: 0, behavior: 'instant'}));
    if (snapshots.has(width)) {
        await page.screenshot({path: `tmp/qa/ui-audit/participant-${name}-${theme}-${width}.png`, fullPage: true, animations: 'disabled'});
    }
}

async function checkAccountMenu(page, width) {
    // The trigger must fit even when a live resize wraps the mobile toolbar.
    const trigger = page.getByRole('button', {name: 'Account menu', exact: true});
    await trigger.focus();
    await trigger.press('ArrowDown');
    const menu = page.getByRole('menu');
    await expect(menu).toBeVisible();
    await expect(menu.getByRole('menuitem').first()).toBeFocused();
    const bounds = await menu.boundingBox();
    expect(bounds.x).toBeGreaterThanOrEqual(0);
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(width + 1);
    expect(bounds.y + bounds.height).toBeLessThanOrEqual(1001);
    await menu.getByRole('menuitem').first().press('Escape');
    await expect(menu).toBeHidden();
    await expect(trigger).toBeFocused();
}

for (const theme of ['light', 'dark']) {
    test(`participant pages retain their layout across seven widths in ${theme} mode`, async ({page}) => {
        test.setTimeout(600000);
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.addInitScript(value => {
            localStorage.setItem('inbaf-language', 'en');
            localStorage.setItem('inbaf-theme', value);
            localStorage.setItem('inbaf-text-size', 'normal');
        }, theme);
        await login(page);
        const packages = rows(await (await page.request.get('/api/packages')).json());
        expect(packages.length, 'Seeded published assessments are available').toBeGreaterThan(0);
        const routes = [
            ['/dashboard', 'dashboard'],
            ...types.map(type => [`/tests?test_type=${type}`, `tests-${type}`]),
            ...types.map(type => [`/results?test_type=${type}`, `results-${type}`]),
            ['/certificates', 'certificates'],
            ['/profile', 'profile'],
            ['/profile?edit=1', 'profile-edit'],
            ['/profile?tab=password', 'password'],
            ['/architecture', 'architecture'],
        ];
        for (const type of types) {
            const assessment = packages.find(item => (typeof item.test_type === 'object' ? item.test_type?.code : item.test_type) === type);
            if (assessment) routes.push([`/tests/${assessment.id}`, `readiness-${type}`]);
        }
        for (const [route, name] of routes) {
            await page.goto(route);
            for (const width of widths) {
                await page.setViewportSize({width, height: 1000});
                await checkLayout(page, route, width, theme, name);
                if (route === '/dashboard') await checkAccountMenu(page, width);
            }
        }
        expect(errors, 'No uncaught browser errors during the participant layout matrix').toEqual([]);
    });

    test(`public forms retain their layout across seven widths in ${theme} mode`, async ({page}) => {
        test.setTimeout(240000);
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.addInitScript(value => {
            localStorage.setItem('inbaf-language', 'en');
            localStorage.setItem('inbaf-theme', value);
            localStorage.setItem('inbaf-text-size', 'normal');
        }, theme);
        for (const [route, name] of [['/login', 'login'], ['/register', 'register'], ['/verify', 'verify'], ['/verify/ui-audit-not-a-certificate', 'verify-not-found']]) {
            await page.goto(route);
            for (const width of widths) {
                await page.setViewportSize({width, height: 1000});
                await checkLayout(page, route, width, theme, name, true);
                if (route === '/login') {
                    const password = page.getByLabel('Password', {exact: true});
                    await page.getByRole('button', {name: 'Show password', exact: true}).click();
                    await expect(password).toHaveAttribute('type', 'text');
                    await page.getByRole('button', {name: 'Hide password', exact: true}).click();
                    await expect(password).toHaveAttribute('type', 'password');
                }
            }
        }
        expect(errors, 'No uncaught browser errors during the public layout matrix').toEqual([]);
    });
}
