import { defineConfig } from 'vite'
import Symfony from '@symfony/reprise/vite'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
    build: {
        rollupOptions: {
            input: {
                app: './assets/app.js',
            },
        },
    },
    plugins: [
        tailwindcss(),
        Symfony({
            stimulus: './assets/controllers.json',
        }),
    ],
})
