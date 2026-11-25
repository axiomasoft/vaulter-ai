import { readFileSync } from 'node:fs';
import { createServer as createHttpServer } from 'node:http';
import { createRequire } from 'node:module';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [, , workbookPath, sheetsRepo, nodeId] = process.argv;
if (!workbookPath || !sheetsRepo || !nodeId) {
    console.error('Usage: node reopen_check.mjs <workbook.json> <vaulter-sheets-dir> <node-id>');
    process.exit(2);
}

// Chrome's singleton socket lives under TMPDIR and must fit in sockaddr_un (108 bytes
// including NUL). The orchestrator TMPDIR is deeper than that, so Chrome aborts or
// sits in launch until the PHP process timeout. Keep the profile on a short path.
const chromeSocketSuffix = '/com.google.Chrome.XXXXXX/SingletonSocket';
const inheritedTmp = process.env.TMPDIR || process.env.TEMP || '/tmp';
const browserEnv = { ...process.env };
if (Buffer.byteLength(inheritedTmp + chromeSocketSuffix) > 107) {
    browserEnv.TMPDIR = '/tmp';
    browserEnv.TEMP = '/tmp';
    browserEnv.TMP = '/tmp';
}
const viteCacheDir = '/tmp/vaulter-ai-sheets-reopen-vite';

const require = createRequire(path.join(sheetsRepo, 'package.json'));
const playwrightDir = path.dirname(require.resolve('@playwright/test/package.json'));
const viteDir = path.dirname(require.resolve('vite/package.json'));
const { chromium } = await import(pathToFileURL(path.join(playwrightDir, 'index.mjs')).href);
const { createServer: createViteServer } = await import(pathToFileURL(path.join(viteDir, 'dist/node/index.js')).href);

const workbook = JSON.parse(readFileSync(workbookPath, 'utf8'));

// Serve a minimal standalone mount harness using @vaulter/sheets-vue from sibling vaulter-sheets build.
const virtualMountId = path.join(sheetsRepo, '__reopen_virtual_mount.js');
const mountModule = `
import { createApp, h, ref } from 'vue';
import { VaulterSpreadsheet } from ${JSON.stringify(path.join(sheetsRepo, 'packages/vue/dist/vaulter-sheets-vue.js'))};
import ${JSON.stringify(path.join(sheetsRepo, 'packages/vue/dist/vaulter-sheets-vue.css'))};

const params = new URLSearchParams(location.search);
const id = params.get('node');
const visible = ref(true);

createApp({
    setup() {
        return () => h('div', [
            h('button', { id: 'toggle-wb', onClick: () => { visible.value = !visible.value; } }, visible.value ? 'Close workbook' : 'Open workbook'),
            visible.value ? h(VaulterSpreadsheet, { node: { id, abilities: { edit: true } } }) : null,
        ]);
    },
}).mount('#app');
`;

const apiServer = createHttpServer((req, res) => {
    const reqUrl = new URL(req.url ?? '/', 'http://127.0.0.1');
    if (req.method === 'GET' && reqUrl.pathname === `/api/vaulter-sheets/v1/workbooks/${encodeURIComponent(nodeId)}`) {
        const body = JSON.stringify({
            data: {
                marker: { generation: 1, position: 13 },
                etag: `"${nodeId}.1.13"`,
                profile: workbook.profile,
                workbook,
                commands: ['set_number', 'set_text', 'set_formula', 'insert_row_before'],
            },
        });
        res.writeHead(200, { 'Content-Type': 'application/json', 'ETag': `"${nodeId}.1.13"` });
        res.end(body);
        return;
    }
    res.writeHead(404);
    res.end('not found');
});

await new Promise(resolve => apiServer.listen(0, '127.0.0.1', resolve));
const apiPort = apiServer.address().port;

const vite = await createViteServer({
    root: sheetsRepo,
    logLevel: 'silent',
    // Do not reuse or delete the sibling repo's node_modules/.vite cache, and do not
    // crawl its bench/e2e HTML (those pull Univer in and blow the PHP process timeout).
    cacheDir: viteCacheDir,
    optimizeDeps: {
        entries: [],
        noDiscovery: true,
        include: ['vue'],
        holdUntilCrawlEnd: false,
    },
    server: {
        port: 0,
        host: '127.0.0.1',
        watch: null,
        fs: { allow: [sheetsRepo] },
        proxy: {
            '/api': `http://127.0.0.1:${apiPort}`,
        },
    },
    plugins: [
        {
            name: 'vaulter-ai-sheets-reopen-entry',
            resolveId(source) {
                if (source === '/@reopen-mount.js' || source === virtualMountId) {
                    return virtualMountId;
                }
                return null;
            },
            load(id) {
                if (id === virtualMountId) {
                    return mountModule;
                }
                return null;
            },
            configureServer(server) {
                server.middlewares.use(async (req, res, next) => {
                    const reqUrl = (req.url ?? '').split('?')[0];
                    if (reqUrl === '/' || reqUrl === '/index.html') {
                        const transformed = await server.transformIndexHtml(
                            req.url ?? '/',
                            `<!doctype html><html lang="ru"><head><meta charset="utf-8"><link rel="icon" href="data:,"><title>Workbook Reopen</title></head><body><main id="app"></main><script type="module" src="/@reopen-mount.js"></script></body></html>`,
                        );
                        res.setHeader('Content-Type', 'text/html; charset=utf-8');
                        res.end(transformed);
                        return;
                    }
                    next();
                });
            },
        },
    ],
});

await vite.listen();
const browserPort = vite.httpServer.address().port;
const baseUrl = `http://127.0.0.1:${browserPort}`;

let browser;
let page;
const errors = [];
try {
    browser = await chromium.launch({
        headless: true,
        timeout: 20_000,
        env: browserEnv,
        // Snap Chromium and a restricted orchestrator sandbox do not come up under the
        // default sandbox; without this, launch waits until the PHP timeout kills it.
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
        ...(process.env.SHEETS_CHROMIUM_EXECUTABLE
            ? { executablePath: process.env.SHEETS_CHROMIUM_EXECUTABLE }
            : { channel: 'chromium' }),
    });
    page = await browser.newPage();
    page.setDefaultTimeout(15_000);
    page.on('pageerror', err => errors.push(`pageerror: ${err.message}`));
    page.on('response', res => {
        if (res.status() >= 400) errors.push(`http ${res.status()}: ${res.url()}`);
    });
    page.on('console', msg => {
        if (msg.type() === 'error') errors.push(`console.error: ${msg.text()}`);
    });

    const loadedPromise = page.waitForResponse(
        res => res.url().includes(`/api/vaulter-sheets/v1/workbooks/${encodeURIComponent(nodeId)}`) && res.status() === 200,
        { timeout: 15_000 },
    );
    await page.goto(`${baseUrl}/?node=${encodeURIComponent(nodeId)}`, { waitUntil: 'domcontentloaded' });
    const loadedResp = await loadedPromise;
    const loadedJson = await loadedResp.json();

    const canvas = page.locator('canvas[id^=univer-sheet-main-canvas_]').first();
    await canvas.waitFor({ state: 'visible', timeout: 20_000 });
    const box = await canvas.boundingBox();
    if (!box || box.width < 100 || box.height < 100) {
        throw new Error(`Unexpected canvas boundingBox: ${JSON.stringify(box)}`);
    }

    // Confirm no alert error message and sheet name is present in DOM.
    const alertCount = await page.locator('[role="alert"]').count();
    if (alertCount > 0) {
        throw new Error(`Unexpected alert: ${await page.locator('[role="alert"]').innerText()}`);
    }
    const bodyText = await page.locator('body').innerText();
    if (!bodyText.includes('Расходы')) {
        throw new Error(`Expected sheet tab "Расходы" in reopened UI, got: ${bodyText}`);
    }

    // Cold close + reopen cycle confirms clean mount/unmount and identical persisted cells.
    await page.click('#toggle-wb');
    await page.locator('canvas[id^=univer-sheet-main-canvas_]').waitFor({ state: 'detached', timeout: 10_000 });
    const reopenedPromise = page.waitForResponse(
        res => res.url().includes(`/api/vaulter-sheets/v1/workbooks/${encodeURIComponent(nodeId)}`) && res.status() === 200,
        { timeout: 15_000 },
    );
    await page.click('#toggle-wb');
    await reopenedPromise;
    await canvas.waitFor({ state: 'visible', timeout: 20_000 });

    const cells = loadedJson.data.workbook.native.sheets.expenses.cellData;
    const d2 = cells['1']['3'];
    const d3 = cells['2']?.['3'] ?? null;
    const d4 = cells['3']['3'];
    const d5 = cells['4']['3'];

    if (d2?.f !== '=C2-B2' || d2?.v !== 20 || d3 !== null || d4?.f !== '=C4-B4' || d4?.v !== -20 || d5?.f !== undefined || d5?.v !== 77) {
        throw new Error(`Unexpected reopened cellData: ${JSON.stringify({ d2, d3, d4, d5 })}`);
    }
    if (errors.length > 0) {
        throw new Error(`Browser errors during reopen: ${errors.join(' | ')}`);
    }
} catch (error) {
    // A bare locator timeout says nothing about why the grid never rendered; the page
    // errors, failed requests and visible text collected so far usually do.
    const bodyText = page ? await page.locator('body').innerText({ timeout: 2_000 }).catch(() => '') : '';
    console.error(`Browser errors: ${errors.length > 0 ? errors.join(' | ') : '(none)'}`);
    console.error(`Visible text: ${bodyText.slice(0, 2_000) || '(empty)'}`);
    throw error;
} finally {
    await browser?.close();
    await vite.close();
    await new Promise(resolve => apiServer.close(resolve));
}
