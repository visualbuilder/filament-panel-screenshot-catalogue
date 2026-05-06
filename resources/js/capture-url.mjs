#!/usr/bin/env node
/**
 * Single-URL screenshot runner.
 *
 * Reads a JSON manifest from stdin, optionally logs into a generic HTML
 * form (selectors default to WordPress wp-login.php), navigates to the
 * target URL, and writes one PNG. Driven by the PHP Artisan command
 * `screenshot:url` — never invoke directly unless debugging.
 *
 * Manifest shape:
 *   {
 *     "url": "https://example.com/wp-admin/",
 *     "output": "/abs/path/to/shot.png",
 *     "viewport": { "name": "desktop", "width": 1280, "height": 800 },
 *     "fullPage": false,
 *     "waitMs": 1500,
 *     "ignoreHttpsErrors": false,
 *     "login": {
 *         "url": "https://example.com/wp-login.php",
 *         "username": "...",
 *         "password": "...",
 *         "usernameSelector": "#user_login",
 *         "passwordSelector": "#user_pass",
 *         "submitSelector": "#wp-submit"
 *     } | null
 *   }
 */

import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';
import { dirname } from 'node:path';
import { stdin } from 'node:process';

async function readStdin() {
    const chunks = [];
    for await (const chunk of stdin) chunks.push(chunk);
    return Buffer.concat(chunks).toString('utf8');
}

async function login(page, login) {
    await page.goto(login.url, { waitUntil: 'domcontentloaded' });
    await page.fill(login.usernameSelector, login.username);
    await page.fill(login.passwordSelector, login.password);

    const before = page.url();
    await Promise.all([
        page.waitForURL((url) => url.toString() !== before, { timeout: 60000 }),
        page.click(login.submitSelector),
    ]);
}

(async () => {
    const manifest = JSON.parse(await readStdin());

    const browser = await chromium.launch({
        ignoreHTTPSErrors: !!manifest.ignoreHttpsErrors,
    });
    const context = await browser.newContext({
        ignoreHTTPSErrors: !!manifest.ignoreHttpsErrors,
        viewport: { width: manifest.viewport.width, height: manifest.viewport.height },
    });

    try {
        const page = await context.newPage();

        if (manifest.login) {
            await login(page, manifest.login);
        }

        await page.goto(manifest.url, { waitUntil: 'domcontentloaded' });
        if (manifest.waitMs > 0) {
            await page.waitForTimeout(manifest.waitMs);
        }

        await mkdir(dirname(manifest.output), { recursive: true });
        await page.screenshot({ path: manifest.output, fullPage: !!manifest.fullPage });

        console.error(`captured ${manifest.url} → ${manifest.output}`);
    } finally {
        await context.close();
        await browser.close();
    }
})().catch((err) => {
    console.error(err.stack || err.message || err);
    process.exit(1);
});
