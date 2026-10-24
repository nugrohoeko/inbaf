import fs from 'node:fs';
import {expect} from '@playwright/test';

const localEnv = fs.readFileSync(new URL('../../.env', import.meta.url), 'utf8');

export function environmentValue(key, fallback = '') {
    if (process.env[key]) return process.env[key];
    const line = localEnv.split(/\r?\n/).find(value => value.startsWith(key + '='));
    if (!line) return fallback;
    const raw = line.slice(key.length + 1).trim();
    return /^(["']).*\1$/.test(raw) ? raw.slice(1, -1) : raw;
}

export async function login(page, role = 'participant') {
    await page.goto('/login');
    await page.getByLabel('Email address', {exact: true}).fill(environmentValue(role === 'superadmin' ? 'INBAF_ADMIN_EMAIL' : 'INBAF_PARTICIPANT_EMAIL', role === 'superadmin' ? 'admin@inbaf.test' : 'participant@inbaf.test'));
    const password = environmentValue(role === 'superadmin' ? 'INBAF_ADMIN_PASSWORD' : 'INBAF_PARTICIPANT_PASSWORD');
    if (!password) throw new Error('Set local INBAF demo credentials in .env before browser tests.');
    await page.getByLabel('Password', {exact: true}).fill(password);
    const response = page.waitForResponse(response => response.url().endsWith('/api/login') && response.request().method() === 'POST');
    await page.getByRole('button', {name: 'Sign in', exact: true}).click();
    expect((await response).status()).toBe(200);
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.locator('.main-content')).toBeVisible();
}

export async function noHorizontalOverflow(page) {
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);
}
