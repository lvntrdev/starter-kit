/// <reference types="vitest" />
import { defineConfig } from 'vitest/config';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import i18n from 'laravel-vue-i18n/vite';
import AutoImport from 'unplugin-auto-import/vite';
import Components from 'unplugin-vue-components/vite';
import { PrimeVueResolver } from '@primevue/auto-import-resolver';
import path from 'path';

export default defineConfig({
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
            '@lvntr': path.resolve(__dirname, 'vendor/lvntr/starter-kit/resources/js'),
        },
    },

    plugins: [
        wayfinder(),
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            ssr: 'resources/js/ssr.ts',
            refresh: true,
        }),

        vue(),
        tailwindcss(),
        i18n(),

        AutoImport({
            imports: ['vue', '@vueuse/core'],
            dts: 'auto-imports.d.ts',
            vueTemplate: true,
        }),

        Components({
            dirs: [
                'resources/js/components',
                'vendor/lvntr/starter-kit/resources/js/components',
            ],
            dts: 'components.d.ts',
            resolvers: [PrimeVueResolver()],
        }),
    ],

    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (id.includes('node_modules')) {
                        if (id.includes('primevue') || id.includes('@primevue') || id.includes('primeicons')) {
                            return 'vendor-primevue';
                        }
                        if (id.includes('vue') || id.includes('@vue')) {
                            return 'vendor-vue';
                        }
                        return 'vendor';
                    }
                },
            },
        },
    },

    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },

    test: {
        environment: 'jsdom',
        globals: true,
    },
});
