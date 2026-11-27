import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

// Builds one script and one stylesheet with fixed names into the plugin:
// plugin/images/app.js and plugin/images/app.css. Shell.php adds a content hash as ?v=.
export default defineConfig(({ mode }) => ({
  plugins: [vue()],
  define: {
    __VUE_OPTIONS_API__: 'false',
    __VUE_PROD_DEVTOOLS__: 'false',
    __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false',
  },
  build: {
    outDir: fileURLToPath(new URL('../plugin/images', import.meta.url)),
    emptyOutDir: false,
    cssCodeSplit: false,
    sourcemap: mode === 'development',
    minify: mode !== 'development',
    target: 'es2020',
    rolldownOptions: {
      input: fileURLToPath(new URL('./src/main.ts', import.meta.url)),
      output: {
        entryFileNames: 'app.js',
        assetFileNames: 'app[extname]',
      },
    },
  },
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.test.ts'],
  },
}))
