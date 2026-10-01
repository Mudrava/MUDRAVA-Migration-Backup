import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import * as fs from 'node:fs';
import { writeFileSync, readFileSync } from 'node:fs';
import * as os from 'node:os';
import * as path from 'node:path';

// Full migration: source -> .mudrava -> target, through the real admin UI.
// Verifies: export completes, archive downloads, restore completes,
// content/serialized option/binary file land byte-correct on target.

const SOURCE = process.env.SOURCE_URL || 'http://localhost:8081';
const TARGET = process.env.TARGET_URL || 'http://localhost:8082';
const PASS = process.env.SOURCE_ADMIN_PASSWORD || 'test-only-password';

function wp(svc: string, cmd: string): string {
    return execFileSync('docker', ['compose', 'exec', '-T', svc, 'wp', ...cmd.split(' '), '--allow-root'], {
        encoding: 'utf8',
        maxBuffer: 64 * 1024 * 1024,
    }).trim();
}

async function login(page: import('@playwright/test').Page, url: string) {
    await page.goto(url + '/wp-login.php');
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', PASS);
    await page.click('#wp-submit');
    await page.waitForURL(/wp-admin/);
}

// Download one archive part through the REST endpoint (cookies+nonce),
// returning it as a Buffer, or null when the part does not exist (404).
async function downloadPart(page: import('@playwright/test').Page, url: string, id: string, part: number) {
    const nonce = await page.evaluate(() => (window as any).mudravaAdmin.nonce);
    const u = `${url}/wp-json/mudrava/v1/archives/${id}/download?part=${part}&_wpnonce=${nonce}`;
    const b64 = await page.evaluate(async (href) => {
        const r = await fetch(href);
        if (r.status === 404) return null;
        if (!r.ok) throw new Error('download failed ' + r.status);
        const buf = await r.arrayBuffer();
        let s = '';
        const bytes = new Uint8Array(buf);
        for (let i = 0; i < bytes.length; i += 8192) {
            s += String.fromCharCode(...bytes.subarray(i, i + 8192));
        }
        return btoa(s);
    }, u);
    return b64 === null ? null : Buffer.from(b64, 'base64');
}

// Read the archive id out of the status line ("Done: <id>").
async function archiveIdFrom(page: import('@playwright/test').Page) {
    const status = await page.locator('#mudrava-status').textContent();
    return (status || '').replace('Done:', '').trim();
}

test.describe('migration lab', () => {
    test('export on source, restore on target, verify semantics', async ({ page, browserName }) => {
        test.setTimeout(600_000);

        // Honest run: the target must start pristine, never on top of a
        // previous restore (fresh DB, no leftover uploads or storage).
        execFileSync('bash', ['bin/lab-reset-target.sh'], { stdio: 'inherit' });

        // ---- Source: export through the admin UI ----
        await login(page, SOURCE);
        await page.goto(SOURCE + '/wp-admin/admin.php?page=mudrava');
        // Environment checks live on their own tab now (viewed once, not
        // crowding the primary action). Open it, confirm green, go back.
        await page.click('.mudrava-tab[data-tab="environment"]');
        await expect(page.locator('#mudrava-preflight .ok').first()).toBeVisible();
        await page.click('.mudrava-tab[data-tab="backup"]');

        await page.click('#mudrava-start-export');
        await expect(page.locator('#mudrava-status')).toContainText('Done:', { timeout: 300_000 });

        const status = await page.locator('#mudrava-status').textContent();
        const archiveId = (status || '').replace('Done:', '').trim();
        expect(archiveId).toMatch(/^[a-z0-9\-]+$/i);

        // Download the archive through the REST download endpoint (cookies+nonce).
        const buf = await downloadPart(page, SOURCE, archiveId, 1);
        expect(buf).not.toBeNull();

        const archivePath = path.join(os.tmpdir(), `${archiveId}.mudrava`);
        writeFileSync(archivePath, buf!);
        const magic = readFileSync(archivePath).subarray(0, 8).toString('binary');
        expect(magic).toBe('MUDRAVA\0');

        // ---- Target: restore through the admin UI ----
        const ctx2 = await page.context().browser()!.newContext();
        const p2 = await ctx2.newPage();
        await login(p2, TARGET);
        await p2.goto(TARGET + '/wp-admin/admin.php?page=mudrava');
        await p2.click('.mudrava-tab[data-tab="restore"]');
        await p2.setInputFiles('#mudrava-file', archivePath);
        // Plaintext archive: no password field, no URL inputs - the
        // engine derives source -> this site from the archive itself.
        await expect(p2.locator('#mudrava-import-pw')).toBeHidden();
        await expect(p2.locator('#mudrava-rewrite-from')).toHaveCount(0);
        p2.on('dialog', (d) => d.accept());
        await p2.click('#mudrava-start-restore');
        // The job runs inside the blocking modal; status is its child.
        await expect(p2.locator('#mudrava-progress')).toBeVisible();
        await expect(p2.locator('#mudrava-status')).toContainText('Done:', { timeout: 300_000 });

        // ---- Verify on target via wp-cli ----
        const posts = parseInt(wp('target', 'post list --post_type=post --post_status=publish --format=count'), 10);
        expect(posts).toBeGreaterThanOrEqual(5);

        const serialized = wp('target', 'option get mudrava_serialized_test --format=json');
        expect(serialized).toContain('localhost:8082');
        expect(serialized).not.toContain('localhost:8081');

        const home = wp('target', 'option get home');
        expect(home).toBe(TARGET);

        const srcHash = execFileSync('docker',
            ['compose', 'exec', '-T', 'source', 'md5sum', 'wp-content/uploads/2026/01/blob.bin'],
            { encoding: 'utf8' }).trim().split(' ')[0];
        const dstHash = execFileSync('docker',
            ['compose', 'exec', '-T', 'target', 'md5sum', 'wp-content/uploads/2026/01/blob.bin'],
            { encoding: 'utf8' }).trim().split(' ')[0];
        expect(dstHash).toBe(srcHash);

        // Front page renders on target.
        await p2.goto(TARGET + '/');
        await expect(p2).toHaveTitle(/MUDRAVA/);

        await ctx2.close();
        void browserName;
    });

    test('encrypted archive: export with password, restore with password', async ({ page }) => {
        test.setTimeout(600_000);
        const PASSWORD = 'e2e-secret-pw-123';

        execFileSync('bash', ['bin/lab-reset-target.sh'], { stdio: 'inherit' });

        // ---- Source: encrypted export ----
        await login(page, SOURCE);
        await page.goto(SOURCE + '/wp-admin/admin.php?page=mudrava');
        // Encryption is opt-in: the password fields only exist while the
        // checkbox is on.
        await page.click('#mudrava-encrypt-on');
        await page.fill('#mudrava-export-password', PASSWORD);
        await page.fill('#mudrava-export-password2', PASSWORD);
        await page.click('#mudrava-start-export');
        await expect(page.locator('#mudrava-status')).toContainText('Done:', { timeout: 300_000 });

        const archiveId = await archiveIdFrom(page);
        expect(archiveId).toMatch(/^[a-z0-9\-]+$/i);

        const buf = await downloadPart(page, SOURCE, archiveId, 1);
        expect(buf).not.toBeNull();
        const archivePath = path.join(os.tmpdir(), `${archiveId}.enc.mudrava`);
        writeFileSync(archivePath, buf!);
        // Encrypted archives keep the same container magic; the payload is
        // sealed, which the wrong-password restore below proves.
        expect(readFileSync(archivePath).subarray(0, 8).toString('binary')).toBe('MUDRAVA\0');

        // ---- Target: restore without password must fail ----
        const ctx2 = await page.context().browser()!.newContext();
        const p2 = await ctx2.newPage();
        p2.on('dialog', (d) => d.accept());
        await login(p2, TARGET);
        await p2.goto(TARGET + '/wp-admin/admin.php?page=mudrava');
        await p2.click('.mudrava-tab[data-tab="restore"]');
        await p2.setInputFiles('#mudrava-file', archivePath);
        // Encrypted archive: the header sniff reveals the password field
        // the moment the file is picked, before any upload.
        await expect(p2.locator('#mudrava-import-pw')).toBeVisible();
        await p2.click('#mudrava-start-restore');
        await expect(p2.locator('#mudrava-status')).toContainText('Failed:', { timeout: 300_000 });

        // ---- Target: restore with the right password succeeds ----
        await p2.goto(TARGET + '/wp-admin/admin.php?page=mudrava');
        await p2.click('.mudrava-tab[data-tab="restore"]');
        await p2.setInputFiles('#mudrava-file', archivePath);
        await expect(p2.locator('#mudrava-import-pw')).toBeVisible();
        await p2.fill('#mudrava-import-password', PASSWORD);
        await p2.click('#mudrava-start-restore');
        await expect(p2.locator('#mudrava-status')).toContainText('Done:', { timeout: 300_000 });

        // ---- Verify content on target ----
        const posts = parseInt(wp('target', 'post list --post_type=post --post_status=publish --format=count'), 10);
        expect(posts).toBeGreaterThanOrEqual(5);
        const home = wp('target', 'option get home');
        expect(home).toBe(TARGET);
        const srcHash = execFileSync('docker',
            ['compose', 'exec', '-T', 'source', 'md5sum', 'wp-content/uploads/2026/01/blob.bin'],
            { encoding: 'utf8' }).trim().split(' ')[0];
        const dstHash = execFileSync('docker',
            ['compose', 'exec', '-T', 'target', 'md5sum', 'wp-content/uploads/2026/01/blob.bin'],
            { encoding: 'utf8' }).trim().split(' ')[0];
        expect(dstHash).toBe(srcHash);

        await ctx2.close();
    });

    test('split archive: multi-part export, multi-file upload, restore', async ({ page }) => {
        test.setTimeout(900_000);

        execFileSync('bash', ['bin/lab-reset-target.sh'], { stdio: 'inherit' });

        // ---- Source: split export, 2 MiB parts over a ~6 MiB blob ----
        // Split control: Auto/Off/2GB/4GB/Custom chips; the MB input
        // only appears for Custom, so the test picks it explicitly.
        await login(page, SOURCE);
        await page.goto(SOURCE + '/wp-admin/admin.php?page=mudrava');
        await page.click('.mudrava-chip[data-split="custom"]');
        await page.fill('#mudrava-split-size', '2');
        await page.click('#mudrava-start-export');
        await expect(page.locator('#mudrava-status')).toContainText('Done:', { timeout: 300_000 });

        const archiveId = await archiveIdFrom(page);
        expect(archiveId).toMatch(/^[a-z0-9\-]+$/i);

        // Download every part until a 404; must be a genuine multi-part set.
        const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'mudrava-split-'));
        const partPaths: string[] = [];
        for (let p = 1; p <= 64; p++) {
            const buf = await downloadPart(page, SOURCE, archiveId, p);
            if (buf === null) break;
            const fp = p === 1
                ? path.join(dir, `${archiveId}.mudrava`)
                : path.join(dir, `${archiveId}.mudrava.part${String(p).padStart(4, '0')}`);
            writeFileSync(fp, buf);
            partPaths.push(fp);
        }
        expect(partPaths.length).toBeGreaterThanOrEqual(3);
        expect(readFileSync(partPaths[0]).subarray(0, 8).toString('binary')).toBe('MUDRAVA\0');
        // Continuation parts carry their own container magic.
        expect(readFileSync(partPaths[1]).subarray(0, 8).toString('binary')).toBe('MUDRAVAP');

        // ---- Target: select all parts at once, restore ----
        const ctx2 = await page.context().browser()!.newContext();
        const p2 = await ctx2.newPage();
        await login(p2, TARGET);
        await p2.goto(TARGET + '/wp-admin/admin.php?page=mudrava');
        await p2.click('.mudrava-tab[data-tab="restore"]');
        await p2.setInputFiles('#mudrava-file', partPaths);
        p2.on('dialog', (d) => d.accept());
        await p2.click('#mudrava-start-restore');
        await expect(p2.locator('#mudrava-status')).toContainText('Done:', { timeout: 600_000 });

        // ---- Verify on target ----
        const posts = parseInt(wp('target', 'post list --post_type=post --post_status=publish --format=count'), 10);
        expect(posts).toBeGreaterThanOrEqual(5);
        const home = wp('target', 'option get home');
        expect(home).toBe(TARGET);
        const srcHash = execFileSync('docker',
            ['compose', 'exec', '-T', 'source', 'md5sum', 'wp-content/uploads/2026/01/blob.bin'],
            { encoding: 'utf8' }).trim().split(' ')[0];
        const dstHash = execFileSync('docker',
            ['compose', 'exec', '-T', 'target', 'md5sum', 'wp-content/uploads/2026/01/blob.bin'],
            { encoding: 'utf8' }).trim().split(' ')[0];
        expect(dstHash).toBe(srcHash);

        await ctx2.close();
    });
});
