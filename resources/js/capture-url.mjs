#!/usr/bin/env node
/**
 * Single-URL screenshot runner.
 *
 * Reads a JSON manifest from stdin, optionally logs into a generic HTML
 * form (selectors default to WordPress wp-login.php), then captures
 * the same URL across one or more viewports — login happens once and
 * the session is reused for every shot. Driven by the PHP Artisan
 * command `screenshot:url` — never invoke directly unless debugging.
 *
 * Manifest shape:
 *   {
 *     "url": "https://example.com/wp-admin/",
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
 *     } | null,
 *     "shots": [
 *         { "viewport": { "name": "desktop", "width": 1280, "height": 800 },
 *           "output": "/abs/path/to/desktop.png" },
 *         ...
 *     ]
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

async function captureShot(context, manifest, shot) {
    const page = await context.newPage();
    try {
        await page.setViewportSize({
            width: shot.viewport.width,
            height: shot.viewport.height,
        });
        await page.goto(manifest.url, { waitUntil: 'domcontentloaded' });
        if (manifest.waitMs > 0) {
            await page.waitForTimeout(manifest.waitMs);
        }

        await mkdir(dirname(shot.output), { recursive: true });
        await page.screenshot({ path: shot.output, fullPage: !!manifest.fullPage });
        console.error(`captured ${shot.viewport.name} → ${shot.output}`);
    } finally {
        await page.close();
    }
}

(async () => {
    const manifest = JSON.parse(await readStdin());

    const browser = await chromium.launch({
        ignoreHTTPSErrors: !!manifest.ignoreHttpsErrors,
    });
    const context = await browser.newContext({
        ignoreHTTPSErrors: !!manifest.ignoreHttpsErrors,
    });

    try {
        if (manifest.login) {
            const loginPage = await context.newPage();
            try {
                await login(loginPage, manifest.login);
            } finally {
                await loginPage.close();
            }
        }

        for (const shot of manifest.shots) {
            await captureShot(context, manifest, shot);
        }
    } finally {
        await context.close();
        await browser.close();
    }
})().catch((err) => {
    console.error(err.stack || err.message || err);
    process.exit(1);
});
