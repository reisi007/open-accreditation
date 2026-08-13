import {defineConfig} from 'vite'
import react, {reactCompilerPreset} from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import babel from '@rolldown/plugin-babel'
import lingui, {linguiTransformerBabelPreset} from '@lingui/vite-plugin'

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
        proxy: {
            '/api': {
                target: process.env.VITE_API_PROXY || 'http://localhost:8000',
                changeOrigin: true,
            },
        },
    },
})
