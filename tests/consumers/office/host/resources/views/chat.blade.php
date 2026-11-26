<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Vaulter AI — Чат с документом</title>
    <link rel="icon" href="data:,">
    <script type="importmap">
        {
            "imports": {
                "vue": "/_stand-assets/vue.esm-browser.prod.js",
                "@vaulter/ai-vue": "/_stand-assets/vaulter-ai-vue.js"
            }
        }
    </script>
</head>
<body>
<main class="vai-chat-stand" data-viewer="{{ $viewer->effectiveSubject }}">
    <header>
        <h1>AI-чат документа</h1>
        <p data-testid="chat-stand-viewer">Пользователь: <strong>{{ $viewer->effectiveSubject }}</strong></p>
    </header>

    <div id="vai-chat-mount"></div>
</main>
<script type="module">
    import { createApp, h } from 'vue';
    import { ChatPanel, createOfficeWorkspaceClient } from '@vaulter/ai-vue';

    const resourceId = @json($resourceId);
    const initialSessionId = @json($sessionId);
    const blockId = @json($blockId);
    const excerpt = @json($excerpt);
    const searchParams = new URLSearchParams(window.location.search);
    const sheetId = searchParams.get('sheet_id') || '';
    const tableId = searchParams.get('table_id') || '';

    const selection = resourceId && sheetId
        ? { kind: 'sheet_table', resource_id: resourceId, sheet_id: sheetId, ...(tableId ? { table_id: tableId } : {}) }
        : (resourceId && blockId
            ? { kind: 'text_block', resource_id: resourceId, block_id: blockId, ...(excerpt ? { excerpt } : {}) }
            : null);

    const client = createOfficeWorkspaceClient({
        baseUrl: window.location.origin,
        workspacePrefix: 'office',
        credentials: 'same-origin',
    });

    createApp({
        render() {
            return h(ChatPanel, {
                client,
                sessionId: initialSessionId || null,
                resourceIds: resourceId ? [resourceId] : [],
                selection,
                baseReconnectDelayMs: 50,
                onSent(payload) {
                    const url = new URL(window.location.href);
                    if (payload.sessionId) {
                        url.searchParams.set('session_id', payload.sessionId);
                    }
                    if (payload.runId) {
                        url.searchParams.set('run_id', payload.runId);
                    }
                    window.history.replaceState({}, '', url.toString());
                },
            });
        },
    }).mount('#vai-chat-mount');
</script>
</body>
</html>
