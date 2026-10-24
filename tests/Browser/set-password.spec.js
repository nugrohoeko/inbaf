import {test, expect} from '@playwright/test';
import {noHorizontalOverflow} from './helpers.js';

test.use({screenshot:'off'});
const fixtureEmail = 'qa-public-password@inbaf.test';
const fixtureToken = 'a'.repeat(64);
const link = () => '/set-password?' + new URLSearchParams({email:fixtureEmail, token:fixtureToken});

test.beforeEach(async ({page}) => {
    await page.addInitScript(() => localStorage.setItem('inbaf-language', 'en'));
});

test('invalid or expired public link clears query credentials and offers a new-link recovery path', async ({page}) => {
    const inspected = page.waitForResponse(response => new URL(response.url()).pathname === '/api/set-password/inspect');
    await page.goto(link());
    expect((await inspected).status()).toBe(422);
    await expect(page).toHaveURL(/\/set-password$/);
    const title = page.getByRole('heading', {name:'This link is invalid or expired', exact:true});
    await expect(title).toBeVisible();
    await expect(title).toBeFocused();
    await expect(page.locator('.sp-card')).toContainText('Ask your administrator for a new link');
    await expect(page.locator('input')).toHaveCount(0);
    await expect(page.getByRole('link', {name:'Back to sign in', exact:true})).toHaveAttribute('href', '/login');
    expect(await page.evaluate(token => JSON.stringify({...localStorage, ...sessionStorage}).includes(token), fixtureToken)).toBe(false);
    expect(await page.locator('body').innerText()).not.toContain(fixtureEmail);
    for (const href of await page.locator('a').evaluateAll(anchors => anchors.map(anchor => anchor.getAttribute('href') || ''))) {
        expect(href).not.toContain(fixtureToken);
        expect(href).not.toContain('token=');
    }
    await page.reload();
    await expect(page.getByRole('heading', {name:'This link is invalid or expired', exact:true})).toBeVisible();
    await expect(page).toHaveURL(/\/set-password$/);
});

test('activation validates passwords, supports reveal controls, and shows successful sign-in action without automatic login', async ({page}) => {
    let submissions = 0;
    await page.route('**/api/set-password/inspect', async route => {
        expect(route.request().method()).toBe('POST');
        expect(route.request().postDataJSON()).toEqual({email:fixtureEmail, token:fixtureToken});
        expect(route.request().headers().referer || '').not.toContain(fixtureToken);
        await route.fulfill({status:200, contentType:'application/json', body:JSON.stringify({valid:true, activation:true, expires_in_minutes:60})});
    });
    await page.route('**/api/set-password', async route => {
        submissions++;
        expect(route.request().method()).toBe('POST');
        expect(route.request().postDataJSON()).toEqual({email:fixtureEmail, token:fixtureToken, password:'New-Secure#2026', password_confirmation:'New-Secure#2026'});
        await route.fulfill({status:200, contentType:'application/json', body:JSON.stringify({message:'Password saved.'})});
    });
    await page.goto(link());
    await expect(page.getByRole('heading', {name:'Activate your account', exact:true})).toBeVisible();
    await expect(page).toHaveURL(/\/set-password$/);
    const password = page.getByLabel('New password', {exact:true}), confirmation = page.getByLabel('Confirm new password', {exact:true});
    await password.fill('weak');
    await confirmation.fill('different');
    await page.getByRole('button', {name:'Activate account', exact:true}).click();
    await expect(password).toHaveAttribute('aria-invalid', 'true');
    await expect(password).toBeFocused();
    await expect(confirmation).toHaveAttribute('aria-invalid', 'true');
    expect(submissions).toBe(0);
    await password.fill('New-Secure#2026');
    await confirmation.fill('New-Secure#2026');
    await page.getByRole('button', {name:'Show new password', exact:true}).click();
    await expect(password).toHaveAttribute('type', 'text');
    await expect(page.getByRole('button', {name:'Hide new password', exact:true})).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('button', {name:'Hide new password', exact:true}).click();
    await expect(password).toHaveAttribute('type', 'password');
    await page.getByRole('button', {name:'Activate account', exact:true}).click();
    await expect(page.getByRole('heading', {name:'Your password is ready', exact:true})).toBeVisible();
    await expect(page).toHaveURL(/\/set-password$/);
    await expect(page.locator('input')).toHaveCount(0);
    await expect(page.getByRole('link', {name:'Go to sign in', exact:true})).toHaveAttribute('href', '/login');
    expect(submissions).toBe(1);
});

test('a token that expires during password entry leaves an explicit recovery state', async ({page}) => {
    await page.route('**/api/set-password/inspect', route => route.fulfill({status:200, contentType:'application/json', body:JSON.stringify({valid:true, activation:false})}));
    await page.route('**/api/set-password', route => route.fulfill({status:422, contentType:'application/json', body:JSON.stringify({message:'This link is invalid or expired.', errors:{token:['This link is invalid or expired.']}})}));
    await page.goto(link());
    await expect(page.getByRole('heading', {name:'Reset your password', exact:true})).toBeVisible();
    await page.getByLabel('New password', {exact:true}).fill('New-Secure#2026');
    await page.getByLabel('Confirm new password', {exact:true}).fill('New-Secure#2026');
    await page.getByRole('button', {name:'Save new password', exact:true}).click();
    const title = page.getByRole('heading', {name:'This link is invalid or expired', exact:true});
    await expect(title).toBeVisible();
    await expect(title).toBeFocused();
    await expect(page.locator('input')).toHaveCount(0);
    await expect(page).toHaveURL(/\/set-password$/);
});

test('public password setup remains accessible at 320, 768, and 1440 pixels in light and dark themes', async ({page}) => {
    test.setTimeout(120000);
    const failures = [];
    page.on('pageerror', error => failures.push(error.message));
    await page.route('**/api/set-password/inspect', route => route.fulfill({status:200, contentType:'application/json', body:JSON.stringify({valid:true, activation:false})}));
    for (const theme of ['light', 'dark']) {
        await page.addInitScript(value => localStorage.setItem('inbaf-theme', value), theme);
        for (const width of [320, 768, 1440]) {
            await page.setViewportSize({width, height:1000});
            await page.goto(link());
            const title = page.getByRole('heading', {name:'Reset your password', exact:true});
            await expect(title).toBeVisible();
            await expect(title).toBeFocused();
            await noHorizontalOverflow(page);
            await page.getByLabel('New password', {exact:true}).fill('New-Secure#2026');
            await page.getByLabel('Confirm new password', {exact:true}).fill('New-Secure#2026');
            await noHorizontalOverflow(page);
            for (const input of await page.locator('.sp-form input').all()) {
                expect(await input.evaluate(element => element.clientWidth)).toBeGreaterThan(150);
                expect(await input.evaluate(element => element.getBoundingClientRect().height)).toBeGreaterThanOrEqual(48);
            }
            await page.getByRole('button', {name:'Show password confirmation', exact:true}).focus();
            await expect(page.getByRole('button', {name:'Show password confirmation', exact:true})).toBeFocused();
            await page.keyboard.press('Enter');
            await expect(page.getByLabel('Confirm new password', {exact:true})).toHaveAttribute('type', 'text');
        }
    }
    expect(failures).toEqual([]);
});
