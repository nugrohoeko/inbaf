import {test, expect} from '@playwright/test';
import {environmentValue, noHorizontalOverflow} from './helpers.js';

const browserErrors = new WeakMap();
const kinds = [
    {code: 'practice', anchor: 'simulation', title: 'Simulation Test'},
    {code: 'placement', anchor: 'placement', title: 'Placement Test'},
    {code: 'certification', anchor: 'certification', title: 'Certification Test'},
];
test.use({screenshot: 'off'});

test.beforeEach(async ({page}) => {
    browserErrors.set(page, []);
    page.on('pageerror', error => browserErrors.get(page).push(error.stack || error.message));
    await page.addInitScript(() => {
        localStorage.setItem('inbaf-language', 'en');
        localStorage.setItem('inbaf-theme', 'light');
        localStorage.setItem('inbaf-text-size', 'normal');
    });
});

test.afterEach(async ({page}) => {
    expect(browserErrors.get(page), 'Uncaught landing-page JavaScript errors').toEqual([]);
});

async function expectAnchorClear(page, id) {
    await expect(page).toHaveURL(new RegExp('#' + id + '$'));
    await expect.poll(() => page.locator('#' + id).evaluate(element =>
        element.getBoundingClientRect().top - document.querySelector('.public-header').getBoundingClientRect().bottom
    )).toBeGreaterThanOrEqual(-1);
}

async function openNavigation(page) {
    const trigger = page.getByRole('button', {name: 'Open navigation', exact: true});
    if (await trigger.isVisible()) await trigger.click();
}

test('landing assessment choices preserve intent through sign-in and open the correct real packages', async ({page}) => {
    test.setTimeout(90000);
    await page.goto('/');
    const landing = page.locator('.landing-redesign');
    await expect(landing).toBeVisible();
    await expect(landing.getByRole('heading', {level: 1})).toHaveCount(1);
    for (const id of ['home', 'about', 'assessments', 'simulation', 'placement', 'certification', 'how-it-works', 'proficiency', 'competencies']) {
        await expect(page.locator('#' + id)).toHaveCount(1);
    }
    expect(await landing.locator('h1').evaluate(element => parseFloat(getComputedStyle(element).fontSize))).toBeGreaterThanOrEqual(52);
    await expect(page.locator('.public-header .text-size-control')).toHaveCount(0);
    await noHorizontalOverflow(page);
    const bootstrap = await (await page.request.get('/api/bootstrap')).json();

    const tabs = landing.locator('.lp-assessment-tab');
    await expect(tabs).toHaveCount(3);
    for (const [index, kind] of kinds.entries()) {
        await tabs.nth(index).click();
        await expect(tabs.nth(index)).toHaveAttribute('aria-pressed', 'true');
        expect(await tabs.evaluateAll(elements => elements.filter(element => element.getAttribute('aria-pressed') === 'true').length)).toBe(1);
        await expect(landing.locator('.lp-assessment-panel')).toContainText(kind.title);
        await landing.locator('.lp-assessment-start').click();
        await expect(page).toHaveURL(new RegExp('/register\\?test_type=' + kind.code + '$'));
        await expect(page.getByLabel('Full name', {exact: true})).toBeVisible();
        await page.locator('.auth-switch').getByRole('link', {name: 'Sign in', exact: true}).click();
        await expect(page).toHaveURL(new RegExp('/login\\?test_type=' + kind.code + '$'));
        await expect(page.getByLabel('Email address', {exact: true})).toBeVisible();
        if (kind.code !== 'certification') await page.goto('/');
    }

    await page.getByLabel('Email address', {exact: true}).fill(environmentValue('INBAF_PARTICIPANT_EMAIL', 'participant@inbaf.test'));
    const password = environmentValue('INBAF_PARTICIPANT_PASSWORD');
    if (!password) throw new Error('Local participant credentials are required for the isolated browser fixture.');
    await page.getByLabel('Password', {exact: true}).fill(password);
    const signingIn = page.waitForResponse(response => response.url().endsWith('/api/login') && response.request().method() === 'POST');
    await page.locator('.auth-form-wrap').getByRole('button', {name: 'Sign in', exact: true}).click();
    expect((await signingIn).status()).toBe(200);
    await expect(page).toHaveURL(/\/tests\?test_type=certification$/);
    const certification = bootstrap.test_types.find(type => type.code === 'certification');
    await expect(page.locator('.type-history-heading h2')).toHaveText(certification.name_en);
    const expectedPackages = bootstrap.packages.filter(assessment => assessment.test_type === 'certification');
    expect(expectedPackages.length).toBeGreaterThan(0);
    await expect(page.locator('.package-card')).toHaveCount(expectedPackages.length);
    for (const assessment of expectedPackages) await expect(page.locator('.package-card h3').filter({hasText: assessment.name})).toHaveCount(1);

    await page.goto('/');
    await expect(page.locator('.public-header').getByRole('button', {name: 'Account menu', exact: true})).toBeVisible();
    await page.locator('.lp-assessment-tab').first().click();
    await page.locator('.lp-assessment-start').click();
    await expect(page).toHaveURL(/\/tests\?test_type=practice$/);
    const practice = bootstrap.test_types.find(type => type.code === 'practice');
    await expect(page.locator('.type-history-heading h2')).toHaveText(practice.name_en);
    await expect(page.locator('.test-type-card.practice')).toHaveClass(/selected/);
});

test('language atlas explains all competencies and configured proficiency levels in both languages', async ({page}) => {
    test.setTimeout(90000);
    let bootstrap;
    // Supply institution-specific public metadata without changing any database records.
    await page.route('**/api/bootstrap', async route => {
        const response = await route.fetch();
        bootstrap = await response.json();
        const grammar = bootstrap.competencies.find(skill => skill.code === 'grammar');
        grammar.name_en = 'Grammar for real conversations';
        grammar.description = 'An institution-configured explanation of grammar in everyday communication.';
        const independent = bootstrap.levels.find(level => level.code === 'B2');
        independent.name = 'Independent explorer';
        independent.description = 'An institution-configured proficiency description for independent expression.';
        await route.fulfill({response, json: bootstrap});
    });
    await page.goto('/');
    await expect(page.locator('.landing-redesign')).toBeVisible();
    const skills = page.locator('.atlas-skill-button');
    const levels = page.locator('.atlas-level-button');
    await expect(skills).toHaveCount(bootstrap.competencies.length);
    expect(bootstrap.competencies).toHaveLength(7);
    await expect(levels).toHaveCount(bootstrap.levels.length);
    expect(bootstrap.levels.map(level => level.code)).toEqual(['A1', 'A2', 'B1', 'B2', 'C1', 'C2']);

    for (let index = 0; index < bootstrap.competencies.length; index++) {
        await skills.nth(index).click();
        await expect(skills.nth(index)).toHaveAttribute('aria-pressed', 'true');
        expect(await skills.evaluateAll(elements => elements.filter(element => element.getAttribute('aria-pressed') === 'true').length)).toBe(1);
        await expect(page.locator('.atlas-skill-detail')).toContainText(bootstrap.competencies[index].name_en);
        if (bootstrap.competencies[index].code === 'grammar') await expect(page.locator('.atlas-skill-detail')).toContainText(bootstrap.competencies[index].description);
        expect((await page.locator('.atlas-skill-detail').innerText()).trim().length).toBeGreaterThan(70);
    }
    for (let index = 0; index < bootstrap.levels.length; index++) {
        const configured = bootstrap.levels[index];
        await levels.nth(index).click();
        await expect(levels.nth(index)).toHaveAttribute('aria-pressed', 'true');
        expect(await levels.evaluateAll(elements => elements.filter(element => element.getAttribute('aria-pressed') === 'true').length)).toBe(1);
        await expect(page.locator('.atlas-level-detail')).toContainText(configured.code);
        await expect(page.locator('.atlas-level-detail')).toContainText(configured.name);
        if (configured.code === 'B2') await expect(page.locator('.atlas-level-detail')).toContainText(configured.description);
    }
    await levels.last().focus();
    await page.keyboard.press('Home');
    await expect(levels.first()).toBeFocused();
    await expect(levels.first()).toHaveAttribute('aria-pressed', 'true');
    await page.keyboard.press('ArrowRight');
    await expect(levels.nth(1)).toBeFocused();
    await expect(levels.nth(1)).toHaveAttribute('aria-pressed', 'true');
    await skills.first().focus();
    await page.keyboard.press('End');
    await expect(skills.last()).toBeFocused();
    await expect(skills.last()).toHaveAttribute('aria-pressed', 'true');
    const englishHeadline = await page.locator('.lp-hero-title').innerText();
    await page.locator('.public-header .language-button').click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'id');
    await expect(page.locator('.lp-hero-title')).not.toHaveText(englishHeadline, {useInnerText: true});
    await skills.first().click();
    await expect(page.locator('.atlas-skill-detail')).toContainText(bootstrap.competencies[0].name);
    await page.locator('.public-header .language-button').click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.locator('.lp-hero-title')).toHaveText(englishHeadline, {useInnerText: true});
    await page.screenshot({path: 'tmp/qa/landing-language-atlas-light.png', fullPage: true});
});

test('premium navigation stays usable with enlarged text, dark theme, reduced motion and narrow screens', async ({page}) => {
    test.setTimeout(120000);
    await page.addInitScript(() => {
        localStorage.setItem('inbaf-text-size', 'extra_large');
        localStorage.setItem('inbaf-theme', 'dark');
    });
    await page.emulateMedia({reducedMotion: 'reduce'});
    const header = page.locator('.public-header');
    for (const width of [1440, 768, 390, 320]) {
        await page.setViewportSize({width, height: 1000});
        await page.goto('/');
        await expect(page.locator('.landing-redesign')).toBeVisible();
        await expect(page.locator('html')).toHaveClass(/dark/);
        await expect(page.locator('html')).toHaveAttribute('data-text-size', 'extra_large');
        expect(await page.locator('html').evaluate(element => parseFloat(getComputedStyle(element).fontSize))).toBe(20);
        await expect(header.getByRole('link', {name: 'Sign in', exact: true})).toBeInViewport();
        await noHorizontalOverflow(page);
        if (width === 1440) {
            await header.locator('.language-button').click();
            await expect(page.locator('html')).toHaveAttribute('lang', 'id');
            await expect(header.getByRole('link', {name: 'Masuk', exact: true})).toBeInViewport();
            const bounds = await header.locator('a,button').evaluateAll(elements => elements.map(element => {
                const rect = element.getBoundingClientRect();
                const style = getComputedStyle(element);
                return {left: rect.left, right: rect.right, visible: rect.width > 0 && rect.height > 0 && rect.bottom > 0 && rect.top < innerHeight && style.visibility !== 'hidden'};
            }).filter(rect => rect.visible));
            expect(bounds.every(rect => rect.left >= -1 && rect.right <= width + 1)).toBe(true);
            await noHorizontalOverflow(page);
            await page.screenshot({path: 'tmp/qa/landing-redesign-dark-xl-id-desktop.png', fullPage: false});
            await header.locator('.language-button').click();
            await expect(page.locator('html')).toHaveAttribute('lang', 'en');
        }

        const mobile = header.locator('.public-menu-toggle');
        if (await mobile.isVisible()) {
            await mobile.focus();
            await page.keyboard.press('Enter');
            await expect(mobile).toHaveAttribute('aria-expanded', 'true');
            await expect(header.getByRole('link', {name: 'About INBAF', exact: true})).toBeVisible();
            await page.keyboard.press('Escape');
            await expect(mobile).toHaveAttribute('aria-expanded', 'false');
            await expect(mobile).toBeFocused();
            await mobile.click();
            await page.mouse.click(8, 980);
            await expect(mobile).toHaveAttribute('aria-expanded', 'false');
        }

        await openNavigation(page);
        const assessments = header.getByRole('button', {name: 'Assessments', exact: true});
        await assessments.focus();
        await page.keyboard.press('ArrowDown');
        const menu = header.getByRole('menu', {name: 'Assessment types', exact: true});
        await expect(menu).toBeVisible();
        await expect(menu.getByRole('menuitem').first()).toBeFocused();
        await page.keyboard.press('End');
        await expect(menu.getByRole('menuitem').last()).toBeFocused();
        await page.keyboard.press('Home');
        await expect(menu.getByRole('menuitem').first()).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(menu).not.toBeVisible();
        await expect(assessments).toBeFocused();
        await assessments.click();
        await menu.getByRole('menuitem').filter({hasText: 'Simulation Test'}).click();
        await expectAnchorClear(page, 'simulation');
        await expect(page.locator('.lp-assessment-tab').first()).toHaveAttribute('aria-pressed', 'true');
        await expect(page.locator('.lp-assessment-panel')).toContainText('Simulation Test');
        if (await mobile.isVisible()) await expect(mobile).toHaveAttribute('aria-expanded', 'false');
        for (const top of [1600, 500]) {
            await page.evaluate(value => window.scrollTo({top: value, behavior: 'instant'}), top);
            await expect.poll(() => header.evaluate(element => element.getBoundingClientRect().top)).toBeCloseTo(0, 0);
            await expect(header.getByRole('link', {name: 'Sign in', exact: true})).toBeInViewport();
        }
        await openNavigation(page);
        await header.getByRole('link', {name: 'Proficiency Levels', exact: true}).click();
        await expectAnchorClear(page, 'proficiency');
        await noHorizontalOverflow(page);
        if (width === 320) {
            await page.evaluate(() => window.scrollTo({top: 0, behavior: 'instant'}));
            await page.screenshot({path: 'tmp/qa/landing-redesign-dark-xl-320.png', fullPage: true});
        }
    }
    const motion = await page.locator('.landing-redesign').evaluate(element => [element, ...element.querySelectorAll('*')].map(node => {
        const style = getComputedStyle(node);
        return {animation: style.animationDuration.split(',').map(value => parseFloat(value) || 0), transition: style.transitionDuration.split(',').map(value => parseFloat(value) || 0)};
    }));
    expect(motion.every(style => [...style.animation, ...style.transition].every(duration => duration <= .01))).toBe(true);
    await openNavigation(page);
    await header.getByRole('link', {name: 'Verify Certificate', exact: true}).click();
    await expect(page).toHaveURL(/\/verify$/);
    await expect(page.getByRole('textbox', {name: 'Certificate token', exact: true})).toBeVisible();
    await noHorizontalOverflow(page);
});
