import {defineConfig} from 'vite'
import react, {reactCompilerPreset} from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import babel from '@rolldown/plugin-babel'
import lingui, {linguiTransformerBabelPreset} from '@lingui/vite-plugin'

// The SPA calls the backend with RELATIVE `/api/...` URLs (no
// `import.meta.env.VITE_API_*` anywhere in src/) — so the ONLY way a served
// bundle reaches the backend is the Vite proxy. Vite 8 does fall back to
// `server.proxy` when `preview.proxy` is unset (`resolvePreviewOptions`:
// `proxy: preview?.proxy ?? server.proxy`, node.js:34998) — MEASURED, not
// assumed: a preview server on the unpatched config proxied /api fine. The
// proxy is therefore set EXPLICITLY for preview anyway, so the contract does
// not silently depend on that upstream default (it has changed across Vite
// majors before, and a preview-served E2E run without a proxy would get the
// SPA fallback (HTML) instead of JSON on every /api call).
const apiProxy = {
    '/api': {
        target: process.env.VITE_API_PROXY || 'http://localhost:8000',
        changeOrigin: true,
    },
};

// `VITE_API_PROXY` is read HERE, at config-load time, i.e. when the dev/preview
// process starts. It is never baked into the bundle (nothing in src/ reads
// import.meta.env), so the env var has to be present for the *serving* step,
// not for `pnpm build`.
export default defineConfig({
    plugins: [
        react(),
        tailwindcss(),
        lingui(),
        babel({presets: [reactCompilerPreset(), linguiTransformerBabelPreset()]}),
    ],
    build: {
        chunkSizeWarningLimit: 1024,
    },
    server: {
        host: '0.0.0.0',
        port: 5173,
        // Same reason as `preview` below: fail loudly instead of silently
        // listening on 5174, which would leave Playwright (baseURL
        // http://localhost:5173) talking to nothing. Both serving modes pin the
        // port, so a dev→preview switch never moves the origin under the suite.
        strictPort: true,
        proxy: apiProxy,
    },
    preview: {
        // Same origin as the dev server: playwright.config.ts pins
        // `baseURL: http://localhost:5173`, and keeping one origin means the
        // E2E gate (which serves the production bundle) needs no config change.
        port: 5173,
        // Fail loudly instead of silently listening on 4173 (vite's default),
        // which would leave Playwright talking to nothing.
        strictPort: true,
        proxy: apiProxy,
    },
})
