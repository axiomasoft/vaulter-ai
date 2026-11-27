#!/usr/bin/env node
import assert from 'node:assert/strict'
import { execFileSync, spawn } from 'node:child_process'
import fs from 'node:fs'
import net from 'node:net'
import os from 'node:os'
import path from 'node:path'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const repoRoot = path.resolve(__dirname, '../../../..')

function loadPlaywright() {
  const candidates = [
    process.env.VAULTER_PLAYWRIGHT_PATH,
    'playwright',
    '@playwright/test',
  ].filter((value) => typeof value === 'string' && value.trim() !== '')

  const req = createRequire(import.meta.url)
  for (const candidate of candidates) {
    try {
      const mod = req(candidate)
      if (mod?.chromium) {
        return mod
      }
    } catch {
      // Try next resolution candidate.
    }
  }
  throw new Error('Playwright module with chromium launcher could not be resolved. Set VAULTER_PLAYWRIGHT_PATH.')
}

async function allocatePort() {
  return await new Promise((resolve, reject) => {
    const srv = net.createServer()
    srv.unref()
    srv.on('error', reject)
    srv.listen(0, '127.0.0.1', () => {
      const addr = srv.address()
      const port = typeof addr === 'object' && addr ? addr.port : 0
      srv.close(() => resolve(port))
    })
  })
}

async function waitForReady(baseUrl, timeoutMs = 15000) {
  const deadline = Date.now() + timeoutMs
  while (Date.now() < deadline) {
    try {
      const res = await fetch(`${baseUrl}/_fixture/readyz`)
      if (res.ok) {
        const body = await res.json()
        if (body?.status === 'ready') {
          return
        }
      }
    } catch {
      // Server still booting.
    }
    await new Promise((r) => setTimeout(r, 150))
  }
  throw new Error(`Stand server at ${baseUrl} did not become ready within ${timeoutMs}ms.`)
}

function buildPackagesManifestPhp(profile) {
  const lines = [
    '<?php return [',
    "  'axiomasoft/packman' => ['providers' => [\\Packman\\PackmanServiceProvider::class]],",
    "  'axiomasoft/packman-plugins' => ['providers' => [\\Packman\\Plugins\\Laravel\\PluginsServiceProvider::class]],",
    "  'axiomasoft/packman-schema' => ['providers' => [\\Packman\\Schema\\Laravel\\SchemaServiceProvider::class]],",
    "  'axiomasoft/vaulter-core' => ['providers' => [\\Vaulter\\VaulterServiceProvider::class]],",
    "  'axiomasoft/vaulter-drive' => ['providers' => [\\Vaulter\\Drive\\VaulterDriveServiceProvider::class], 'aliases' => ['Vaulter' => \\Vaulter\\Drive\\Facades\\Vaulter::class]],",
    "  'axiomasoft/vaulter-media' => ['providers' => [\\Vaulter\\Media\\VaulterMediaServiceProvider::class]],",
    "  'axiomasoft/vaulter-documents' => ['providers' => [\\Vaulter\\Documents\\VaulterDocumentsServiceProvider::class]],",
    "  'axiomasoft/vaulter-ai-core' => ['providers' => [\\Vaulter\\Ai\\Core\\OfficeServiceProvider::class]],",
    "  'axiomasoft/vaulter-ai-documents' => ['providers' => [\\Vaulter\\Ai\\Documents\\DocumentsAiServiceProvider::class]],",
    "  'nesbot/carbon' => ['providers' => [\\Carbon\\Laravel\\ServiceProvider::class]],",
  ]
  if (profile === 'ai-agentools') {
    lines.push("  'axiomasoft/vaulter-ai-agentools' => ['providers' => [\\Vaulter\\Ai\\AgenTools\\AgenToolsServiceProvider::class]],")
  } else {
    lines.push("  'axiomasoft/vaulter-ai-laravel-ai' => ['providers' => [\\Vaulter\\Ai\\LaravelAi\\LaravelAiServiceProvider::class]],")
    lines.push("  'laravel/ai' => ['providers' => [\\Laravel\\Ai\\AiServiceProvider::class]],")
  }
  lines.push('];')
  return `${lines.join('\n')}\n`
}

function buildEnvContent({ dbPath, runtimeDir, fixtureToken, profile }) {
  return [
    'APP_ENV=testing',
    'APP_DEBUG=true',
    'APP_KEY=base64:tJ4mVwShl8LETWdRtTCwcoKS7MS2ipekchk9seHrIVc=',
    'DB_CONNECTION=sqlite',
    `DB_DATABASE=${dbPath}`,
    'CACHE_STORE=array',
    'QUEUE_CONNECTION=sync',
    'SESSION_DRIVER=array',
    `FIXTURE_TOKEN=${fixtureToken}`,
    `STAND_PROFILE=${profile}`,
    'VAULTER_AI_STAND_APPROVAL=human',
    'VAULTER_AI_DETERMINISTIC_SCRIPT=1',
    `APP_PACKAGES_CACHE=${path.join(runtimeDir, 'bootstrap/packages.php')}`,
    `APP_SERVICES_CACHE=${path.join(runtimeDir, 'bootstrap/services.php')}`,
    `VIEW_COMPILED_PATH=${path.join(runtimeDir, 'views')}`,
  ].join('\n') + '\n'
}

function buildBootstrapScript(runtimeDir, dbPath, profile, fixtureToken) {
  return `<?php
declare(strict_types=1);
require ${JSON.stringify(path.join(repoRoot, 'vendor/autoload.php'))};
if (! class_exists(\\App\\Models\\StandActor::class)) {
    require_once ${JSON.stringify(path.join(repoRoot, 'tests/consumers/office/host/app/Models/StandActor.php'))};
}
if (! class_exists(\\App\\Models\\StandOwner::class)) {
    require_once ${JSON.stringify(path.join(repoRoot, 'tests/consumers/office/host/app/Models/StandOwner.php'))};
}
if (! class_exists(\\App\\Providers\\StandServiceProvider::class)) {
    require_once ${JSON.stringify(path.join(repoRoot, 'tests/consumers/office/host/app/Providers/StandServiceProvider.php'))};
}

$envVars = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ${JSON.stringify(dbPath)},
    'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'FIXTURE_TOKEN' => ${JSON.stringify(fixtureToken)},
    'STAND_PROFILE' => ${JSON.stringify(profile)},
    'VAULTER_AI_STAND_APPROVAL' => 'human',
    'VAULTER_AI_DETERMINISTIC_SCRIPT' => '1',
    'APP_PACKAGES_CACHE' => ${JSON.stringify(path.join(runtimeDir, 'bootstrap/packages.php'))},
    'APP_SERVICES_CACHE' => ${JSON.stringify(path.join(runtimeDir, 'bootstrap/services.php'))},
    'VIEW_COMPILED_PATH' => ${JSON.stringify(path.join(runtimeDir, 'views'))},
];
foreach ($envVars as $k => $v) {
    putenv($k.'='.$v);
    $_ENV[$k] = $v;
}

$app = require ${JSON.stringify(path.join(repoRoot, 'tests/consumers/office/host/bootstrap/app.php'))};
$app->useEnvironmentPath(${JSON.stringify(runtimeDir)});
$app->useStoragePath(${JSON.stringify(path.join(runtimeDir, 'storage'))});

if (PHP_SAPI === 'cli' && ($GLOBALS['argv'][1] ?? null) === '--migrate') {
    $kernel = $app->make(\\Illuminate\\Contracts\\Console\\Kernel::class);
    $kernel->bootstrap();
    \\Illuminate\\Support\\Facades\\DB::connection()->statement('PRAGMA journal_mode=WAL;');
    \\Illuminate\\Support\\Facades\\DB::connection()->statement('PRAGMA busy_timeout=5000;');
    $exit = $kernel->call('migrate', ['--force' => true, '--no-interaction' => true]);
    exit((int) $exit);
}

$app['events']->listen(\\Illuminate\\Database\\Events\\ConnectionEstablished::class, static function ($event): void {
    if ($event->connection->getDriverName() === 'sqlite') {
        $event->connection->statement('PRAGMA busy_timeout=5000;');
    }
});

$app->handleRequest(\\Illuminate\\Http\\Request::capture());
`
}

async function waitForWorkspaceApprovalSettled(pageB, workspaceUrl, timeoutMs = 25000) {
  const deadline = Date.now() + timeoutMs
  while (Date.now() < deadline) {
    const bodyTextB = await pageB.locator('body').innerText()
    if (bodyTextB.includes('Принято человеком') && bodyTextB.includes('Сохранено владельцем')) {
      const articleTextB = await pageB.locator('article[data-proposal-id]').first().innerText()
      const receiptMatch = articleTextB.match(/Квитанция:\s*([0-9a-f-]{36})/i)
      if (receiptMatch && receiptMatch[1]) {
        return { bodyTextB, receiptId: receiptMatch[1] }
      }
    }
    await new Promise((r) => setTimeout(r, 400))
    await pageB.goto(workspaceUrl, { waitUntil: 'domcontentloaded' })
  }
  throw new Error(`Proposal approval did not settle via async queue:work within ${timeoutMs}ms at ${workspaceUrl}`)
}

async function runProfileE2E(playwright, profile, remoteConfig = null) {
  let runtimeDir = null
  let server = null
  const serverLogs = []
  let baseUrl = remoteConfig ? remoteConfig.baseUrl : ''
  let fixtureToken = remoteConfig ? remoteConfig.fixtureToken : 'pw-fixture-token-92'

  if (!remoteConfig) {
    runtimeDir = fs.mkdtempSync(path.join('/tmp', `vai-pw-${profile}-`))
    fs.mkdirSync(path.join(runtimeDir, 'bootstrap'), { recursive: true })
    fs.mkdirSync(path.join(runtimeDir, 'storage/framework/views'), { recursive: true })
    fs.mkdirSync(path.join(runtimeDir, 'storage/logs'), { recursive: true })
    fs.mkdirSync(path.join(runtimeDir, 'views'), { recursive: true })

    const dbPath = path.join(runtimeDir, 'stand.sqlite')
    fs.writeFileSync(dbPath, '')

    fs.writeFileSync(path.join(runtimeDir, 'bootstrap/packages.php'), buildPackagesManifestPhp(profile))
    fs.writeFileSync(path.join(runtimeDir, '.env'), buildEnvContent({ dbPath, runtimeDir, fixtureToken, profile }))

    const routerPath = path.join(runtimeDir, 'router.php')
    fs.writeFileSync(routerPath, buildBootstrapScript(runtimeDir, dbPath, profile, fixtureToken))

    execFileSync('php', [routerPath, '--migrate'], { cwd: repoRoot, stdio: 'pipe' })

    const port = await allocatePort()
    baseUrl = `http://127.0.0.1:${port}`
    server = spawn('php', ['-S', `127.0.0.1:${port}`, routerPath], {
      cwd: repoRoot,
      env: {
        ...process.env,
        PHP_CLI_SERVER_WORKERS: '6',
      },
      stdio: ['ignore', 'pipe', 'pipe'],
    })
    server.stdout.on('data', (chunk) => serverLogs.push(chunk.toString()))
    server.stderr.on('data', (chunk) => serverLogs.push(chunk.toString()))
  }

  const browser = await playwright.chromium.launch({
    executablePath: process.env.VAULTER_CHROMIUM_PATH || playwright.chromium.executablePath(),
    headless: true,
    args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'],
  })
  const consoleErrorsA = []
  try {
    await waitForReady(baseUrl)

    const seedRes = await fetch(`${baseUrl}/_fixture/seed`, {
      method: 'POST',
      headers: {
        'X-Fixture-Token': fixtureToken,
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify({ approval: 'human' }),
    })
    const seedRaw = await seedRes.text()
    assert.equal(seedRes.status, 200, `Seed failed: ${seedRaw}`)
    const seed = JSON.parse(seedRaw)
    const isSheetProfile = profile === 'ai-sheets' && Boolean(seed.sheet_resource_id)
    const docId = isSheetProfile ? seed.sheet_resource_id : seed.resource_id

    const contextA = await browser.newContext()
    const contextB = await browser.newContext()

    const cookieBase = {
      name: 'stand_token',
      domain: new URL(baseUrl).hostname,
      path: '/',
      httpOnly: false,
      sameSite: 'Lax',
    }
    await contextA.addCookies([{ ...cookieBase, value: seed.writer_token }])
    await contextB.addCookies([{ ...cookieBase, value: seed.reviewer_token }])

    // 1. Writer (Context A) opens /office/chat with bounded selection and observes SSE + waiting approval.
    const pageA = await contextA.newPage()
    pageA.on('pageerror', (err) => consoleErrorsA.push(`pageerror: ${err.message}`))
    pageA.on('console', (msg) => {
      if (msg.type() === 'error') {
        const loc = msg.location()?.url || ''
        consoleErrorsA.push(`console.error: ${msg.text()}${loc ? ` (${loc})` : ''}`)
      }
    })
    pageA.on('response', (res) => {
      if (res.status() >= 400) {
        consoleErrorsA.push(`http_${res.status()}: ${res.url()}`)
      }
    })
    pageA.on('requestfailed', (req) => consoleErrorsA.push(`requestfailed: ${req.url()} (${req.failure()?.errorText || 'unknown'})`))

    const chatUrl = isSheetProfile
      ? `${baseUrl}/office/chat?resource_id=${encodeURIComponent(docId)}&sheet_id=expenses&table_id=costs`
      : `${baseUrl}/office/chat?resource_id=${encodeURIComponent(docId)}&block_id=p42&excerpt=${encodeURIComponent('Pay within 15 working days.')}`
    await pageA.goto(chatUrl, { waitUntil: 'domcontentloaded' })

    await pageA.locator('[data-testid="office-chat-panel"]').waitFor({ state: 'visible' })
    await pageA.locator('[data-testid="chat-editor-selection"]').waitFor({ state: 'visible' })
    if (!isSheetProfile) {
      await pageA.locator('[data-testid="chat-selection-excerpt"]').waitFor({ state: 'visible' })
    }
    await pageA.waitForFunction(() => {
      const el = document.querySelector('[data-testid="chat-stream-status"]')
      return el && el.getAttribute('data-connected') === 'true'
    })

    const initialSessionId = await pageA.locator('[data-testid="office-chat-panel"]').getAttribute('data-session-id')
    assert.ok(initialSessionId && initialSessionId.length > 10, 'Expected ChatPanel to allocate session_id')

    const promptText = isSheetProfile
      ? 'Замени формулу delta в таблице costs'
      : 'Замени 15 на 30 в блоке p42'
    await pageA.locator('[data-testid="chat-input"]').fill(promptText)
    await pageA.locator('[data-action="send-chat"]').click()

    await pageA.locator('[data-testid="chat-bound-run-id"]').waitFor({ state: 'visible' })
    const boundRunId = await pageA.locator('[data-testid="office-chat-panel"]').getAttribute('data-run-id')
    assert.ok(boundRunId && boundRunId.length > 10, 'Expected bound run_id after sending message')

    await pageA.locator('[data-testid="chat-status-waiting-approval"]').waitFor({ state: 'visible', timeout: 25000 })
    await pageA.locator('[data-testid="chat-proposals"] [data-proposal-id]').waitFor({ state: 'visible', timeout: 25000 })

    const pageATextBeforeClose = await pageA.locator('body').innerText()
    assert.ok(!pageATextBeforeClose.includes('sk-'), 'API key prefix must never appear in chat DOM')
    assert.equal(consoleErrorsA.length, 0, `Unexpected page errors in Context A: ${consoleErrorsA.join('; ')}`)

    // Close Tab A completely before Reviewer approves.
    await pageA.close()

    // 2. Reviewer (Context B) opens /office/workspace and approves the proposal P.
    const pageB = await contextB.newPage()
    const workspaceUrl = `${baseUrl}/office/workspace?resource_id=${encodeURIComponent(docId)}`
    await pageB.goto(workspaceUrl, { waitUntil: 'domcontentloaded' })
    await pageB.locator('[data-action="approve"]').first().waitFor({ state: 'visible' })
    await Promise.all([
      pageB.waitForNavigation({ waitUntil: 'domcontentloaded' }),
      pageB.locator('[data-action="approve"]').first().click(),
    ])
    const { receiptId } = await waitForWorkspaceApprovalSettled(pageB, workspaceUrl)

    // 3. Reopen a brand new tab in Context A for the saved session -> observes committed, verified & assistant reply.
    const pageA2 = await contextA.newPage()
    await pageA2.goto(`${baseUrl}/office/chat?session_id=${encodeURIComponent(initialSessionId)}`, { waitUntil: 'domcontentloaded' })
    await pageA2.locator('[data-testid="chat-status-owner-committed"]').waitFor({ state: 'visible', timeout: 25000 })
    await pageA2.locator('[data-testid="chat-status-verified"]').waitFor({ state: 'visible', timeout: 25000 })
    await pageA2.waitForFunction(() => {
      const el = document.querySelector('[data-testid="chat-messages"]')
      const text = el ? el.innerText : ''
      return text.includes('Квитанция:') || text.includes('подтверждено')
    }, null, { timeout: 25000 })

    const reopenedRunId = await pageA2.locator('[data-testid="office-chat-panel"]').getAttribute('data-run-id')
    assert.equal(reopenedRunId, boundRunId, 'Reopened chat panel must stay bound to the same run_id')

    const reopenedText = await pageA2.locator('[data-testid="chat-messages"]').innerText()
    assert.ok(reopenedText.includes(promptText), 'Reopened chat must retain decrypted user prompt')
    assert.ok(reopenedText.includes('Квитанция:') || reopenedText.includes('подтверждено'), 'Reopened chat must display completed assistant response')
    if (receiptId !== '') {
      const fullText = await pageA2.locator('[data-testid="office-chat-panel"]').innerText()
      assert.ok(fullText.includes(receiptId), 'Expected owner receipt_id in reopened chat panel')
    }

    // 4. Revoke Writer's Drive grant -> reload Context A -> collapsed hidden state without leaked text or receipt.
    const revokeRes = await fetch(`${baseUrl}/_fixture/revoke`, {
      method: 'POST',
      headers: {
        'X-Fixture-Token': fixtureToken,
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify({
        owner_id: seed.owner_id,
        user_id: seed.writer_user_id,
      }),
    })
    const revokeRaw = await revokeRes.text()
    assert.equal(revokeRes.status, 200, `Revoke failed: ${revokeRaw}`)
    const revokeBody = JSON.parse(revokeRaw)
    assert.ok(revokeBody.revoked >= 1, 'Expected at least 1 grant revoked')

    await pageA2.reload({ waitUntil: 'domcontentloaded' })
    await pageA2.locator('[data-testid="chat-redacted-banner"]').waitFor({ state: 'visible' })
    await pageA2.locator('[data-testid="chat-hidden-fragment"]').first().waitFor({ state: 'visible' })

    const redactedDomText = await pageA2.locator('[data-testid="office-chat-panel"]').innerText()
    assert.ok(!redactedDomText.includes('Pay within 15 working days'), 'Revoked selection excerpt leaked into DOM')
    assert.ok(!redactedDomText.includes(promptText), 'Revoked prompt text leaked into DOM')
    if (receiptId !== '') {
      assert.ok(!redactedDomText.includes(receiptId), 'Revoked receipt_id leaked into DOM')
    }

    await contextA.close()
    await contextB.close()
    console.log(JSON.stringify({
      ok: true,
      mode: remoteConfig ? 'remote-pg-async' : 'local-sqlite-sync',
      profile,
      session_id: initialSessionId,
      run_id: boundRunId,
      revoked: revokeBody.revoked,
    }))
  } catch (err) {
    console.error(`Browser E2E failure [${profile}]:`, err)
    if (consoleErrorsA.length > 0) {
      console.error('Console/page errors:', consoleErrorsA.join('\n'))
    }
    if (serverLogs.length > 0) {
      console.error('Server logs:', serverLogs.join('\n'))
    }
    throw err
  } finally {
    await browser.close()
    if (server) {
      server.kill('SIGTERM')
    }
    if (runtimeDir) {
      fs.rmSync(runtimeDir, { recursive: true, force: true })
    }
  }
}

async function main() {
  const playwright = loadPlaywright()
  const ownerHttpUrl = (process.env.OWNER_HTTP_URL || '').trim().replace(/\/+$/, '')
  const fixtureToken = (process.env.FIXTURE_TOKEN || '').trim()
  if (ownerHttpUrl !== '') {
    if (fixtureToken === '') {
      throw new Error('FIXTURE_TOKEN is required when OWNER_HTTP_URL is set')
    }
    const profile = process.env.STAND_PROFILE || 'ai-text'
    await runProfileE2E(playwright, profile, { baseUrl: ownerHttpUrl, fixtureToken })
    return
  }

  const profiles = process.argv.slice(2)
  const targets = profiles.length > 0 ? profiles : ['ai-text', 'ai-agentools']
  for (const profile of targets) {
    await runProfileE2E(playwright, profile)
  }
}

main().catch((err) => {
  console.error(err)
  process.exit(1)
})
