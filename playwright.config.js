import {defineConfig} from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    globalSetup: './tests/Browser/setup.js',
    outputDir: './tmp/playwright-results',
    timeout: 60000,
    expect: {timeout: 15000},
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list'], ['json', {outputFile: 'tmp/playwright-results/report.json'}]],
    use: {
        baseURL: 'http://127.0.0.1:8001',
        browserName: 'chromium',
        headless: true,
        viewport: {width: 1440, height: 1000},
        actionTimeout: 15000,
        screenshot: 'only-on-failure',
        // Authentication inputs must never be captured in a trace artifact.
        trace: 'off',
        launchOptions: {executablePath: process.env.INBAF_CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe'},
    },
});
