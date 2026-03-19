import { createInertiaApp } from '@inertiajs/vue3';
import createServer from '@inertiajs/vue3/server';
import { renderToString } from 'vue/server-renderer';
import { createSSRApp, h, type DefineComponent } from 'vue';
import { i18nVue } from 'laravel-vue-i18n';
import PrimeVue from 'primevue/config';
import ConfirmationService from 'primevue/confirmationservice';
import ToastService from 'primevue/toastservice';
import AppPreset from '@/theme/preset';
import { PermissionPlugin } from '@/plugins/permission';

type PageModule = { default: DefineComponent };

createServer((page) =>
    createInertiaApp({
        page,
        render: renderToString,
        title: (title) => {
            const appName = (page.props.appName as string) || 'Laravel';
            return title ? `${title} - ${appName}` : appName;
        },

        resolve: (name) => {
            const pages = import.meta.glob<PageModule>('./pages/**/*.vue', {
                eager: true,
            });
            const pageModule = pages[`./pages/${name}.vue`];

            if (!pageModule) {
                throw new Error(`Page not found: ${name}`);
            }

            return pageModule.default;
        },

        setup({ App, props, plugin }) {
            return createSSRApp({ render: () => h(App, props) })
                .use(plugin)
                .use(i18nVue, {
                    resolve: (lang: string) => {
                        const langs = import.meta.glob<Record<string, string>>('../../lang/*.json', { eager: true });
                        return langs[`../../lang/php_${lang}.json`];
                    },
                })
                .use(PrimeVue, {
                    theme: {
                        preset: AppPreset,
                        options: {
                            darkModeSelector: '.dark',
                            cssLayer: {
                                name: 'primevue',
                                order: 'tailwind-base, primevue, tailwind-utilities',
                            },
                        },
                    },
                })
                .use(ConfirmationService)
                .use(ToastService)
                .use(PermissionPlugin);
        },
    }),
);
