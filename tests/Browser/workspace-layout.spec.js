import {test, expect} from '@playwright/test';
import {login, noHorizontalOverflow} from './helpers.js';
import fs from 'node:fs';

test.beforeEach(async ({page}) => {
    await page.addInitScript(() => {
        localStorage.setItem('inbaf-language', 'en');
        localStorage.setItem('inbaf-theme', 'light');
    });
});

test('mobile navigation traps focus, closes with Escape and restores its trigger', async ({page}) => {
    await page.setViewportSize({width: 320, height: 740});
    await login(page, 'superadmin');
    const trigger = page.getByRole('button', {name: 'Open navigation', exact: true});
    await trigger.click();
    const drawer = page.locator('#workspace-navigation');
    await expect(drawer).toHaveAttribute('aria-modal', 'true');
    await expect(page.getByRole('button', {name: 'Close navigation', exact: true}).last()).toBeFocused();
    const controls = drawer.locator('a[href],button:not([disabled])');
    await controls.last().focus();
    await page.keyboard.press('Tab');
    await expect(controls.first()).toBeFocused();
    await page.keyboard.press('Shift+Tab');
    await expect(controls.last()).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(trigger).toBeFocused();
    await expect(drawer).toHaveAttribute('inert', '');
    expect(await page.evaluate(() => document.body.style.overflow)).toBe('');
    await trigger.click();
    await drawer.locator('a[href="/admin/media"]').click();
    await expect(page).toHaveURL(/\/admin\/media$/);
    await expect(page.locator('#main-content')).toBeFocused();
    await noHorizontalOverflow(page);
});

test('desktop navigation collapses and remains usable after resizing', async ({page}) => {
    await login(page, 'superadmin');
    const sidebar = page.locator('.sidebar');
    const originalWidth = await sidebar.evaluate(node => node.getBoundingClientRect().width);
    await expect(page.getByRole('button', {name: 'Open navigation', exact: true})).toBeHidden();
    await page.getByRole('button', {name: 'Collapse navigation', exact: true}).click();
    expect(await sidebar.evaluate(node => node.getBoundingClientRect().width)).toBeLessThan(originalWidth);
    await sidebar.getByRole('link', {name: 'Question bank', exact: true}).click();
    await expect(page).toHaveURL(/\/admin\/questions$/);
    await expect(sidebar.locator('a[href="/admin/questions"]')).toHaveAttribute('aria-current', 'page');
    await page.setViewportSize({width: 768, height: 1000});
    await page.getByRole('button', {name: 'Open navigation', exact: true}).click();
    await expect(sidebar.getByRole('link', {name: 'Question bank', exact: true})).toBeVisible();
    await page.setViewportSize({width: 1440, height: 1000});
    await expect(sidebar).not.toHaveAttribute('inert', '');
    await page.getByRole('button', {name: 'Expand navigation', exact: true}).click();
    await noHorizontalOverflow(page);
});

test('workspace header stays visible and menus fit the narrow viewport in both themes', async ({page}) => {
    await page.setViewportSize({width: 320, height: 740});
    await login(page);
    const utilityRows = await page.locator('.topbar-actions>.language-button,.topbar-actions>.icon-button,.topbar-actions>.notification-wrap,.topbar-actions>.account-menu').evaluateAll(nodes => nodes.map(node => node.getBoundingClientRect().top));
    expect(Math.max(...utilityRows)-Math.min(...utilityRows), 'Mobile utility actions share a row').toBeLessThanOrEqual(1);
    for (const theme of ['light', 'dark']) {
        await page.evaluate(theme => {
            document.documentElement.classList.toggle('dark', theme === 'dark');
            localStorage.setItem('inbaf-theme', theme);
        }, theme);
        await page.evaluate(() => scrollTo({top: 900, behavior: 'instant'}));
        expect(await page.locator('.topbar').evaluate(node => node.getBoundingClientRect().top)).toBeCloseTo(0, 0);
        await page.getByRole('button', {name: 'Notifications', exact: true}).click();
        const bounds = await page.locator('.notification-popover').boundingBox();
        expect(bounds.x).toBeGreaterThanOrEqual(0);
        expect(bounds.x + bounds.width).toBeLessThanOrEqual(320);
        await page.keyboard.press('Escape');
        await expect(page.locator('.notification-popover')).toHaveCount(0);
        await noHorizontalOverflow(page);
    }
    fs.mkdirSync('tmp/qa/ui-audit', {recursive: true});
    await page.screenshot({path: 'tmp/qa/ui-audit/workspace-dark-320.png'});
});
