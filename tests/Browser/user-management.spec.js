import {test, expect} from '@playwright/test';
import {login, noHorizontalOverflow} from './helpers.js';

test.use({screenshot:'off'});
test.beforeEach(async ({page}) => {
    await page.addInitScript(() => localStorage.setItem('inbaf-language', 'en'));
    await login(page, 'superadmin');
    await page.goto('/admin/users');
    await expect(page.getByRole('heading', {name:'User Management', exact:true})).toBeVisible();
    await expect(page.locator('.um-loading')).toHaveCount(0);
});

const userRow = (page, email) => page.locator('.um-table tbody tr').filter({hasText:email});
const editor = page => page.getByRole('dialog', {name:/^(Add new user|Edit user)$/});
const mutation = (page, pathname, method = 'POST') => page.waitForResponse(response => new URL(response.url()).pathname === pathname && response.request().method() === method);

async function fillManualUser(page, email, name = 'QA Managed Participant') {
    await page.getByRole('button', {name:'Add new user', exact:true}).first().click();
    const dialog = editor(page);
    await dialog.getByLabel(/^Full name/).fill(name);
    await dialog.getByLabel(/^Email address/).fill(email);
    await dialog.getByLabel('Password setup', {exact:true}).selectOption('manual');
    await dialog.getByLabel('Initial password', {exact:false}).first().fill('Managed-Account#2026');
    await dialog.getByLabel('Confirm password', {exact:false}).first().fill('Managed-Account#2026');
    return dialog;
}

test('manual participant creation, cropped photo, editing, role confirmation, status, and soft deletion work', async ({page}) => {
    test.setTimeout(120000);
    const email = `qa-managed-${Date.now()}@inbaf.test`;
    const dialog = await fillManualUser(page, email);
    await dialog.getByLabel('Country of origin', {exact:true}).fill('Japan');
    await dialog.getByLabel('Nationality', {exact:true}).fill('Japanese');
    const png = Buffer.from(await page.evaluate(() => {
        const canvas = document.createElement('canvas'); canvas.width = 200; canvas.height = 160;
        const context = canvas.getContext('2d'); context.fillStyle = '#193c31'; context.fillRect(0, 0, 200, 160);
        context.fillStyle = '#bef78f'; context.fillRect(0, 0, 100, 160);
        return canvas.toDataURL('image/png').split(',')[1];
    }), 'base64');
    await dialog.getByLabel('Profile photo file', {exact:true}).setInputFiles({name:'management-photo.png', mimeType:'image/png', buffer:png});
    const crop = page.getByRole('dialog', {name:'Crop your photo', exact:true});
    await expect(crop).toBeVisible();
    await crop.getByRole('button', {name:'Use this photo', exact:true}).click();
    await expect(crop).toHaveCount(0);
    const created = mutation(page, '/api/admin/users');
    await dialog.getByRole('button', {name:'Create account', exact:true}).click();
    const createResponse = await created;
    expect(createResponse.status()).toBe(201);
    const account = (await createResponse.json()).user;
    expect(account.role).toBe('participant');
    expect(account.must_change_password).toBe(true);
    expect(account.admin_avatar_url).toBeTruthy();
    await expect(dialog).not.toBeVisible();
    await page.getByLabel('Search users', {exact:true}).fill(email);
    let row = userRow(page, email);
    await expect(row).toBeVisible();
    await expect(row).toContainText('Participant');

    await row.getByRole('button', {name:'Edit QA Managed Participant', exact:true}).click();
    await editor(page).getByLabel(/^Full name/).fill('QA Managed Administrator');
    await editor(page).getByLabel('Role', {exact:false}).first().selectOption('superadmin');
    await expect(editor(page).getByLabel('Country of origin', {exact:true})).toHaveCount(0);
    await editor(page).getByRole('button', {name:'Save changes', exact:true}).click();
    const roleDialog = page.getByRole('dialog', {name:'Confirm role change', exact:true});
    await expect(roleDialog).toBeVisible();
    await expect(roleDialog).toContainText('full administrative access');
    const edited = mutation(page, `/api/admin/users/${account.id}`);
    await roleDialog.getByRole('button', {name:'Confirm and save', exact:true}).click();
    expect((await edited).status()).toBe(200);
    await expect(editor(page)).not.toBeVisible();
    row = userRow(page, email);
    await expect(row).toContainText('QA Managed Administrator');
    await expect(row).toContainText('Superadmin');

    await row.getByRole('button', {name:'Deactivate QA Managed Administrator', exact:true}).click();
    const deactivate = page.getByRole('dialog', {name:'Deactivate this account?', exact:true});
    const deactivated = mutation(page, `/api/admin/users/${account.id}/status`);
    await deactivate.getByRole('button', {name:'Deactivate account', exact:true}).click();
    expect((await deactivated).status()).toBe(200);
    await expect(row).toContainText('Inactive');
    await row.getByRole('button', {name:'Activate QA Managed Administrator', exact:true}).click();
    const activated = mutation(page, `/api/admin/users/${account.id}/status`);
    await page.getByRole('dialog', {name:'Activate this account?', exact:true}).getByRole('button', {name:'Activate account', exact:true}).click();
    expect((await activated).status()).toBe(200);
    await expect(row).toContainText('Active');

    await row.getByRole('button', {name:'View details for QA Managed Administrator', exact:true}).click();
    const details = page.getByRole('dialog', {name:'User details', exact:true});
    await expect(details.getByRole('heading', {name:'Recent account activity', exact:true})).toBeVisible();
    await expect(details.locator('.um-activity ol li')).not.toHaveCount(0);
    await details.getByRole('button', {name:'Close', exact:true}).click();
    await row.getByRole('button', {name:'Delete QA Managed Administrator', exact:true}).click();
    const deletion = page.getByRole('dialog', {name:'Delete this account?', exact:true});
    await expect(deletion).toContainText('test history and certificates will be retained');
    await expect(deletion.getByRole('button', {name:'Delete account', exact:true})).toBeDisabled();
    await deletion.getByRole('checkbox').check();
    const deleted = mutation(page, `/api/admin/users/${account.id}`, 'DELETE');
    await deletion.getByRole('button', {name:'Delete account', exact:true}).click();
    expect((await deleted).status()).toBe(200);
    await expect(row).toHaveCount(0);
    await page.getByLabel('Status', {exact:true}).selectOption('deleted');
    row = userRow(page, email);
    await expect(row).toContainText('Deleted');
    await expect(row.getByRole('button', {name:'Edit QA Managed Administrator', exact:true})).toBeDisabled();
    await expect(row.getByRole('button', {name:'View details for QA Managed Administrator', exact:true})).toBeEnabled();
});

test('frontend password checks and duplicate email errors stay beside fields; self security actions are protected', async ({page}) => {
    const self = (await (await page.request.get('/api/account')).json()).user;
    await page.getByLabel('Search users', {exact:true}).fill(self.email);
    const row = userRow(page, self.email);
    await expect(row).toBeVisible();
    for (const action of ['Reset password for ', 'Deactivate ', 'Delete ']) await expect(row.getByRole('button', {name:action + self.name, exact:true})).toBeDisabled();
    await row.getByRole('button', {name:'Edit ' + self.name, exact:true}).click();
    await expect(editor(page).getByLabel('Role', {exact:false}).first()).toBeDisabled();
    await expect(editor(page).getByLabel(/^Account status/)).toBeDisabled();
    await editor(page).getByRole('button', {name:'Cancel', exact:true}).click();
    const dialog = await fillManualUser(page, self.email, 'Duplicate Email Check');
    await dialog.getByLabel('Initial password', {exact:false}).first().fill('weak');
    await dialog.getByRole('button', {name:'Create account', exact:true}).click();
    await expect(dialog.locator('#um-password')).toHaveAttribute('aria-invalid', 'true');
    await expect(dialog.locator('#um-password')).toBeFocused();
    await dialog.getByLabel('Initial password', {exact:false}).first().fill('Managed-Account#2026');
    const response = mutation(page, '/api/admin/users');
    await dialog.getByRole('button', {name:'Create account', exact:true}).click();
    expect((await response).status()).toBe(422);
    await expect(dialog.locator('#um-email')).toHaveAttribute('aria-invalid', 'true');
    await expect(dialog.locator('#um-error-email')).not.toHaveText('');
    await expect(dialog.locator('#um-email')).toBeFocused();
});

test('saved pending account clearly reports failed activation delivery', async ({page}) => {
    await page.route('**/api/admin/users', async route => {
        if (route.request().method() !== 'POST') return route.continue();
        await route.fulfill({status:201, contentType:'application/json', body:JSON.stringify({
            user:{id:999999, name:'QA Pending Delivery', email:'qa-pending-delivery@inbaf.test', role:'participant', active:true, account_status:'pending_activation'},
            message:'Account created.', delivery_status:{sent:false, status:'not_configured', message:'Configure the mail service before sending activation links.'},
        })});
    });
    await page.getByRole('button', {name:'Add new user', exact:true}).first().click();
    const dialog = editor(page);
    await expect(dialog.getByLabel('Password setup', {exact:true})).toHaveValue('activation');
    await dialog.getByLabel(/^Full name/).fill('QA Pending Delivery');
    await dialog.getByLabel(/^Email address/).fill('qa-pending-delivery@inbaf.test');
    await dialog.getByRole('button', {name:'Create account', exact:true}).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.locator('.user-management>.um-notice.warning')).toContainText('account was saved, but the email was not delivered');
});

test('directory and modal remain readable at 320, 768, and 1440 pixels in both themes, with native focus and dismissal', async ({page}) => {
    const failures = [];
    page.on('pageerror', error => failures.push(error.message));
    for (const theme of ['light', 'dark']) {
        await page.evaluate(value => {localStorage.setItem('inbaf-theme', value); document.documentElement.classList.toggle('dark', value === 'dark');}, theme);
        for (const width of [320, 768, 1440]) {
            await page.setViewportSize({width, height:1000});
            await noHorizontalOverflow(page);
            for (const badge of await page.locator('.um-role-badge').all()) {
                expect(await badge.evaluate(element => element.getBoundingClientRect().height <= parseFloat(getComputedStyle(element).lineHeight) + 12)).toBe(true);
            }
            const trigger = page.getByRole('button', {name:'Add new user', exact:true}).first();
            await trigger.click();
            const dialog = editor(page);
            await expect(dialog.locator('#um-name')).toBeFocused();
            await expect(dialog).toBeInViewport();
            expect(await dialog.evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1);
            await dialog.getByLabel('Password setup', {exact:true}).selectOption('manual');
            await noHorizontalOverflow(page);
            expect(await dialog.evaluate(element => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(1);
            if (width === 1440 || width === 320) await page.screenshot({path:`tmp/qa/user-management-${theme}-${width}.png`, fullPage:true});
            if (width === 1440 || width === 320) await dialog.screenshot({path:`tmp/qa/user-management-dialog-${theme}-${width}.png`});
            await page.keyboard.press('Escape');
            await expect(dialog).not.toBeVisible();
            await expect(trigger).toBeFocused();
        }
    }
    expect(failures).toEqual([]);
});
