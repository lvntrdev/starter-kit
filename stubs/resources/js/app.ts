import '../css/app.css';
import 'primeicons/primeicons.css';
import { createSSRApp, h, type DefineComponent } from 'vue';
import { createInertiaApp, usePage } from '@inertiajs/vue3';
import { i18nVue } from 'laravel-vue-i18n';
import PrimeVue from 'primevue/config';
import ConfirmationService from 'primevue/confirmationservice';
import ToastService from 'primevue/toastservice';
import AppPreset from '@/theme/preset';
import { PermissionPlugin } from '@/plugins/permission';

type PageModule = { default: DefineComponent };

createInertiaApp({
    progress: {
        delay: 250,
        color: '#29d',
        includeCSS: true,
        showSpinner: false,
    },
    title: (title) => {
        const appName = (usePage().props.appName as string) || 'Laravel';
        return title ? `${title} - ${appName}` : appName;
    },
    resolve: (name) => {
        const pages = import.meta.glob<PageModule>('./pages/**/*.vue', {
            eager: true,
        });
        const page = pages[`./pages/${name}.vue`];

        if (!page) {
            throw new Error(`Page not found: ${name}`);
        }

        return page.default;
    },

    setup({ el, App, props, plugin }) {
        createSSRApp({ render: () => h(App, props) })
            .use(plugin)
            .use(i18nVue, {
                resolve: async (lang: string) => {
                    const langs = import.meta.glob<Record<string, string>>('../../lang/*.json');
                    return await langs[`../../lang/php_${lang}.json`]();
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
            .use(PermissionPlugin)
            .mount(el);
    },
});
