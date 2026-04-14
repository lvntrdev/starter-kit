import '../css/app.css';
import 'primeicons/primeicons.css';
import { createInertiaApp, usePage } from '@inertiajs/vue3';
import { i18nVue } from 'laravel-vue-i18n';
import PrimeVue from 'primevue/config';
import ConfirmationService from 'primevue/confirmationservice';
import ToastService from 'primevue/toastservice';
import Tooltip from 'primevue/tooltip';
import AppPreset from '@/theme/preset';
import { PermissionPlugin } from '@/plugins/permission';

createInertiaApp({
    pages: {
        path: './pages',
        lazy: false,
    },
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
    withApp(app, { ssr }) {
        app.use(i18nVue, {
            resolve: ssr
                ? (lang: string) => {
                      const langs = import.meta.glob<Record<string, string>>('../../lang/*.json', { eager: true });
                      return langs[`../../lang/php_${lang}.json`];
                  }
                : async (lang: string) => {
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
            .directive('tooltip', Tooltip);
    },
});
