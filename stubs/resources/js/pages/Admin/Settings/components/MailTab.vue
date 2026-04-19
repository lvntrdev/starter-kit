<script setup lang="ts">
    import { FB } from '@lvntr/components/FormBuilder/core';
    import SkForm from '@lvntr/components/FormBuilder/SkForm.vue';
    import adminSettings from '@/routes/settings';

    interface Props {
        settings: {
            mailer: string;
            host: string | null;
            port: number | null;
            username: string | null;
            password: null;
            password_is_set: boolean;
            encryption: string | null;
            from_address: string;
            from_name: string;
        };
    }

    const props = defineProps<Props>();

    const mailerOptions = [{ label: 'SMTP', value: 'smtp' }];

    const encryptionOptions = [
        { label: 'sk-setting.mail.encryption_none', value: 'none' },
        { label: 'TLS', value: 'tls' },
        { label: 'SSL', value: 'ssl' },
    ];

    const isSmtp = (values: Record<string, unknown>) => values.mailer === 'smtp';

    const passwordPlaceholder = computed(() => (props.settings.password_is_set ? '••••••••' : ''));

    const formConfig = computed(() =>
        FB.form()
            .layout('vertical')
            .cols(2)
            .cardTitle('sk-setting.mail.title')
            .cardSubtitle('sk-setting.mail.subtitle')
            .initialData({
                ...props.settings,
                host: props.settings.host ?? '',
                username: props.settings.username ?? '',
                // Never prefill the stored password — the backend preserves it
                // when this field is submitted empty.
                password: '',
                encryption: props.settings.encryption ?? 'none',
            })
            .submit({
                url: adminSettings.update.mail.url(),
                method: 'put',
                preserveScroll: true,
            })
            .addFields(
                FB.select().key('mailer').options(mailerOptions).class('col-span-full'),
                FB.inputText().key('host').placeholder('smtp.example.com').visible(isSmtp),
                FB.inputNumber().key('port').visible(isSmtp).useGrouping(false),
                FB.inputText().key('username').visible(isSmtp),
                FB.password().key('password').toggleMask().visible(isSmtp).placeholder(passwordPlaceholder.value),
                FB.select().key('encryption').options(encryptionOptions).default('none').visible(isSmtp),
                FB.inputText().key('from_address').inputType('email').placeholder('noreply@example.com'),
                FB.inputText().key('from_name'),
            )
            .build(),
    );

    /* ── Test Mail (separate small form) ── */
    const testMailRef = ref<InstanceType<typeof SkForm> | null>(null);

    const testMailConfig = computed(() =>
        FB.form()
            .layout('vertical')
            .cols(1)
            .cardTitle('sk-setting.mail.test_title')
            .cardSubtitle('sk-setting.mail.test_subtitle')
            .initialData({ test_email: '' })
            .submit({
                url: adminSettings.testMail.url(),
                method: 'post',
                preserveScroll: true,
            })
            .actionLabels({ submit: 'sk-setting.mail.test_send', submitIcon: 'pi pi-send' })
            .hideCancel()
            .addFields(FB.inputText().key('test_email').label(false).inputType('email').placeholder('test@example.com'))
            .build(),
    );

    function onTestMailSuccess() {
        testMailRef.value?.reset();
    }
</script>

<template>
    <div class="space-y-6">
        <SkForm :config="formConfig" />
        <SkForm ref="testMailRef" :config="testMailConfig" @success="onTestMailSuccess" />
    </div>
</template>
