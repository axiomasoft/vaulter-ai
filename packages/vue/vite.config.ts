import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import dts from 'vite-plugin-dts'

export default defineConfig({
  plugins: [
    vue(),
    dts({ tsconfigPath: './tsconfig.json', include: ['src'], exclude: ['tests'] }),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  build: {
    lib: {
      entry: fileURLToPath(new URL('./src/index.ts', import.meta.url)),
      name: 'VaulterAiVue',
      fileName: 'vaulter-ai-vue',
      cssFileName: 'style',
    },
    rollupOptions: {
      external: ['vue', 'yjs', /^@tiptap\//],
      output: {
        exports: 'named',
        globals(id: string): string {
          if (id === 'vue') return 'Vue'
          if (id === 'yjs') return 'Y'
          if (id.startsWith('@tiptap/')) {
            return id
              .replace(/^@tiptap\//, 'Tiptap_')
              .replace(/[^a-zA-Z0-9_]/g, '_')
          }
          return id
        },
      },
    },
  },
  test: {
    environment: 'happy-dom',
  },
})
