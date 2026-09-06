<script setup lang="ts">
    import SkCard from '@lvntr/components/ui/SkCard.vue';
    import { FB } from '@lvntr/components/FormBuilder/core';
    import SkForm from '@lvntr/components/FormBuilder/SkForm.vue';
    import SkFormInput from '@lvntr/components/FormBuilder/SkFormInput.vue';
    import { trans } from 'laravel-vue-i18n';

    // Showcase-only literal labels: this is a developer-facing (system_admin) page
    // whose purpose is demonstrating the FB builder, so field labels stay literal
    // (`.trans(false)`) rather than each carrying its own translation key. The page
    // chrome — title, subtitle, intro, section headings — is fully translated.

    // ── Demo models (v-model mode — no submit) ───────────────────────────────
    // Each form is bound to its own seed object so fields render populated, like
    // the design mock. External (v-model) mode means the parent owns the state,
    // so defaults are seeded here rather than via .default().

    const textModel = ref<Record<string, unknown>>({
        name: 'Ayşe Yıldız',
        phone: '(532) 014 88 20',
        capacity: 24,
        price: 1250,
        password: 'Aa12345!',
        otp: '482',
        start_date: new Date(2026, 5, 6),
        description: 'Bilişsel gelişim ve sınav hazırlık atölyeleri için kısa tanıtım metni.',
        content: '<p><b>Atölye programı</b> — haftalık 2 oturum, küçük gruplar hâlinde yürütülür.</p>',
    });

    const choiceModel = ref<Record<string, unknown>>({
        role: 'editor',
        tags: ['math', 'geometry', 'logic'],
        plan: 'monthly',
        channels: ['email', 'sms'],
        view: 'card',
    });

    const boolModel = ref<Record<string, unknown>>({
        terms: true,
        published: true,
        featured: true,
    });

    const specialModel = ref<Record<string, unknown>>({ theme: 'indigo-800' });

    const i18nModel = ref<Record<string, unknown>>({
        i18n_title: { tr: 'Yaz Atölyesi', en: 'Summer Workshop' },
        i18n_summary: { tr: 'Öğrenciler için yaratıcı düşünme temelli yaz programı.', en: 'A creative-thinking summer program for students.' },
    });

    const cardModel = ref<Record<string, unknown>>({ name: '', email: '', phone: '', role: 'editor', active: true });
    const asideModel = ref<Record<string, unknown>>({
        org_name: 'LVNTR.DEV',
        admin_email: 'admin@lvntr.dev',
        org_phone: '+90 850 000 00 00',
        locale: 'tr',
        currency: 'try',
    });
    const dividedModel = ref<Record<string, unknown>>({ name: '', email: '', phone: '', role: 'editor', active: true });
    const filterModel = ref<Record<string, unknown>>({ q: '', role: 'all', status: 'all' });

    // ── Option lists ─────────────────────────────────────────────────────────
    const roleOptions = [
        { label: 'Yönetici', value: 'admin' },
        { label: 'Editör', value: 'editor' },
        { label: 'İzleyici', value: 'viewer' },
    ];
    const tagOptions = [
        { label: 'Matematik', value: 'math' },
        { label: 'Geometri', value: 'geometry' },
        { label: 'Mantık', value: 'logic' },
        { label: 'Fizik', value: 'physics' },
    ];
    const planOptions = [
        { label: 'Aylık', value: 'monthly', description: 'Her ay otomatik yenilenir' },
        { label: 'Yıllık', value: 'yearly', description: '2 ay ücretsiz — %16 indirim' },
    ];
    const channelOptions = [
        { label: 'E-posta', value: 'email' },
        { label: 'SMS', value: 'sms' },
        { label: 'Push bildirim', value: 'push' },
    ];
    const viewOptions = [
        { label: 'Kart', value: 'card' },
        { label: 'Liste', value: 'list' },
        { label: 'Tablo', value: 'table' },
    ];
    const localeOptions = [
        { label: 'Türkçe (TR)', value: 'tr' },
        { label: 'English (EN)', value: 'en' },
    ];
    const currencyOptions = [
        { label: '₺ Türk Lirası', value: 'try' },
        { label: '$ ABD Doları', value: 'usd' },
        { label: '€ Euro', value: 'eur' },
    ];
    const filterRoleOptions = [{ label: 'Tümü', value: 'all' }, ...roleOptions];
    const filterStatusOptions = [
        { label: 'Tümü', value: 'all' },
        { label: 'Aktif', value: 'active' },
        { label: 'Pasif', value: 'passive' },
    ];

    // ── Form configs ─────────────────────────────────────────────────────────

    const textConfig = computed(() =>
        FB.form()
            .layout('vertical')
            .cols(2)
            .isCard(false)
            .addFields(
                FB.inputText().key('name').label('Ad Soyad').trans(false).required().icon('pi pi-user').placeholder('Örn. Ayşe Yıldız'),
                FB.inputMask().key('phone').label('Telefon').trans(false).optional().mask('(599) 999 99 99').icon('pi pi-phone').hint('Maske: (599) 999 99 99'),
                FB.inputNumber().key('capacity').label('Kontenjan').trans(false).optional().min(0).hint('.min(0) · tam sayı'),
                FB.inputNumber().key('price').label('Ücret').trans(false).optional().prefix('₺').suffix(' / ay').fractionDigits(2, 2),
                FB.password().key('password').label('Parola').trans(false).required().toggleMask().feedback().icon('pi pi-lock'),
                FB.inputOtp().key('otp').label('Doğrulama kodu').trans(false).optional().length(6).hint('6 haneli tek-kullanımlık kod'),
                FB.datePicker().key('start_date').label('Başlangıç tarihi').trans(false).optional(),
                FB.textarea().key('description').label('Açıklama').trans(false).optional().rows(3).colSpan(2).placeholder('Birkaç satır açıklama yazın…'),
                FB.editor().key('content').label('İçerik (zengin metin)').trans(false).optional().toolbar('minimal').colSpan(2),
            )
            .build(),
    );

    const choiceConfig = computed(() =>
        FB.form()
            .layout('vertical')
            .cols(2)
            .isCard(false)
            .addFields(
                FB.select().key('role').label('Rol').trans(false).optional().options(roleOptions).icon('pi pi-shield'),
                FB.multiselect().key('tags').label('Etiketler').trans(false).optional().options(tagOptions),
                FB.radio().key('plan').label('Plan').trans(false).optional().options(planOptions).radioLayout('vertical'),
                FB.checkboxGroup().key('channels').label('Bildirim kanalları').trans(false).optional().options(channelOptions),
                FB.selectButton().key('view').label('Görünüm').trans(false).optional().options(viewOptions).colSpan(2),
            )
            .build(),
    );

    // ── Boolean / toggle — showcase-only hand-rendered chrome ──────────────────
    // Unlike the other sections, each control is presented with its FB.* factory
    // as a code badge and its own caption, matching the design mock. Controls render
    // through SkFormInput directly (not SkForm) so this demo-only chrome never leaks
    // into the shipped builder/SkForm.
    const boolDemo = computed(() => [
        {
            code: 'FB.checkbox()',
            label: 'Onay',
            caption: 'Kullanım koşullarını kabul ediyorum',
            field: FB.checkbox().key('terms').trans(false).build(),
        },
        {
            code: 'FB.toggleSwitch()',
            label: 'Yayın durumu',
            caption: 'Yayında',
            field: FB.toggleSwitch().key('published').trans(false).build(),
        },
        {
            code: 'FB.toggleButton()',
            label: 'Öne çıkar',
            caption: '',
            field: FB.toggleButton().key('featured').trans(false)
                .onLabel('Öne çıkıyor').offLabel('Öne çıkar')
                .onIcon('pi pi-star-fill').offIcon('pi pi-star')
                .props({ class: 'w-full' })
                .build(),
        },
    ]);

    const specialConfig = computed(() =>
        FB.form()
            .layout('vertical')
            .cols(2)
            .isCard(false)
            .addFields(
                FB.fileUpload().key('cover').label('Kapak görseli').trans(false).optional().accept('image/*'),
                FB.colorSelector().key('theme').label('Tema rengi').trans(false).optional().format('name-tone'),
            )
            .build(),
    );

    const i18nConfig = computed(() =>
        FB.form()
            .layout('vertical')
            .cols(2)
            .isCard(false)
            .addFields(
                FB.translatableText().key('i18n_title').label('Başlık').trans(false).required(),
                FB.translatableTextarea().key('i18n_summary').label('Özet').trans(false).optional().rows(3),
            )
            .build(),
    );

    // ── Layout compositions ─────────────────────────────────────────────────

    const cardConfig = computed(() =>
        FB.form()
            .layout('vertical')
            .cols(2)
            .cardTitle('sk-component.form.layouts.card.title')
            .cardSubtitle('sk-component.form.layouts.card.desc')
            .addFields(
                FB.inputText().key('name').label('Ad Soyad').trans(false).required().icon('pi pi-user').colSpan(2).placeholder('Örn. Mehmet Demir'),
                FB.inputText().key('email').label('E-posta').trans(false).required().inputType('email').icon('pi pi-envelope').placeholder('ad@lvntr.dev'),
                FB.inputMask().key('phone').label('Telefon').trans(false).optional().mask('(599) 999 99 99').icon('pi pi-phone'),
                FB.select().key('role').label('Rol').trans(false).optional().options(roleOptions),
                FB.toggleSwitch().key('active').label('Durum').trans(false).optional().controlPosition('right'),
            )
            .build(),
    );

    const asideConfig = computed(() =>
        FB.form()
            .layout('vertical')
            .cols(2)
            .isCard(false)
            .addFields(
                FB.section('Kurum Kimliği')
                    .trans(false)
                    .subtitle('Temel kimlik bilgileri sekmelerde ve arama sonuçlarında görünür.')
                    .aside()
                    .cols(2)
                    .addFields(
                        FB.inputText().key('org_name').label('Kurum Adı').trans(false).required().icon('pi pi-building').colSpan(2),
                        FB.inputText().key('admin_email').label('Yönetici E-posta').trans(false).optional().inputType('email').icon('pi pi-envelope'),
                        FB.inputMask().key('org_phone').label('Telefon').trans(false).optional().mask('+99 999 999 99 99').icon('pi pi-phone'),
                    ),
                FB.section('Bölgesel Ayarlar')
                    .trans(false)
                    .subtitle('Dil ve para birimi kullanıcılara gösterilen verileri biçimlendirir.')
                    .aside()
                    .cols(2)
                    .addFields(
                        FB.select().key('locale').label('Dil').trans(false).optional().options(localeOptions).icon('pi pi-language'),
                        FB.select().key('currency').label('Para Birimi').trans(false).optional().options(currencyOptions).icon('pi pi-wallet'),
                    ),
            )
            .build(),
    );

    const dividedConfig = computed(() =>
        FB.form()
            .layout('horizontal')
            .cols(1)
            .dividers()
            .isCard(false)
            .addFields(
                FB.inputText().key('name').label('Ad Soyad').trans(false).required().icon('pi pi-user').placeholder('Örn. Mehmet Demir'),
                FB.inputText().key('email').label('E-posta').trans(false).required().inputType('email').icon('pi pi-envelope').placeholder('ad@lvntr.dev'),
                FB.inputMask().key('phone').label('Telefon').trans(false).optional().mask('(599) 999 99 99').icon('pi pi-phone'),
                FB.select().key('role').label('Rol').trans(false).optional().options(roleOptions),
                FB.toggleSwitch().key('active').label('Durum').trans(false).optional().controlPosition('right'),
            )
            .build(),
    );

    const filterConfig = computed(() =>
        FB.form()
            .layout('vertical')
            .cols(4)
            .isCard(false)
            .addFields(
                FB.inputText().key('q').label('Ara').trans(false).optional().icon('pi pi-search').colSpan(2).placeholder('İsim, e-posta…'),
                FB.select().key('role').label('Rol').trans(false).optional().options(filterRoleOptions),
                FB.select().key('status').label('Durum').trans(false).optional().options(filterStatusOptions),
            )
            .build(),
    );
</script>

<template>
    <SkCard>
        <template #title>{{ $t('sk-component.form.title') }}</template>
        <template #subtitle>{{ $t('sk-component.form.subtitle') }}</template>
        <template #actions>
            <a href="https://primevue.org/" target="_blank" rel="noopener noreferrer">
                <Button :label="$t('sk-component.form.docs')" icon="pi pi-book" outlined size="small" />
            </a>
        </template>
        <template #content>
        <Message severity="info" :closable="false" class="mb-6">
            <span class="text-[13.5px] leading-relaxed">{{ trans('sk-component.form.intro') }}</span>
        </Message>

        <div class="flex flex-col gap-5">
            <!-- 01 · Text / number -->
            <SkCard>
                <div class="mb-5 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">01</span>
                    <div>
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.form.sections.text.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.form.sections.text.desc') }}</p>
                    </div>
                </div>
                <SkForm v-model="textModel" :config="textConfig" />
            </SkCard>

            <!-- 02 · Choice -->
            <SkCard>
                <div class="mb-5 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">02</span>
                    <div>
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.form.sections.choice.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.form.sections.choice.desc') }}</p>
                    </div>
                </div>
                <SkForm v-model="choiceModel" :config="choiceConfig" />
            </SkCard>

            <!-- 03 · Boolean — showcase-only layout: per-field FB code badge + caption -->
            <SkCard>
                <div class="mb-5 flex items-center gap-2.5 border-b border-surface-200 pb-4 dark:border-surface-700">
                    <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.form.sections.boolean.title') }}</h3>
                    <span class="text-[12px] font-medium text-surface-400">{{ trans('sk-component.form.field_count', { count: String(boolDemo.length) }) }}</span>
                </div>

                <div class="grid grid-cols-1 gap-x-8 gap-y-6 sm:grid-cols-2">
                    <div v-for="item in boolDemo" :key="item.field.key">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-[13px] font-semibold text-surface-700 dark:text-surface-200">{{ item.label }}</span>
                            <span class="rounded bg-surface-100 px-2 py-0.5 font-mono text-[11px] text-surface-500 dark:bg-surface-800 dark:text-surface-400">{{ item.code }}</span>
                        </div>
                        <div class="mt-3 flex items-center gap-2.5">
                            <SkFormInput
                                :field="item.field"
                                :value="boolModel[item.field.key]"
                                @update="(v) => (boolModel[item.field.key] = v)"
                            />
                            <label v-if="item.caption" :for="item.field.key" class="text-[13px] text-surface-600 dark:text-surface-300">{{ item.caption }}</label>
                        </div>
                    </div>
                </div>
            </SkCard>

            <!-- 04 · Special -->
            <SkCard>
                <div class="mb-5 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">04</span>
                    <div>
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.form.sections.special.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.form.sections.special.desc') }}</p>
                    </div>
                </div>
                <SkForm v-model="specialModel" :config="specialConfig" />
            </SkCard>

            <!-- 05 · Translatable -->
            <SkCard>
                <div class="mb-5 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">05</span>
                    <div>
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.form.sections.i18n.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.form.sections.i18n.desc') }}</p>
                    </div>
                </div>
                <SkForm v-model="i18nModel" :config="i18nConfig" />
            </SkCard>

            <!-- 06 · Layout compositions -->
            <SkCard>
                <div class="mb-5 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">06</span>
                    <div>
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.form.sections.layouts.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.form.sections.layouts.desc') }}</p>
                    </div>
                </div>

                <div class="flex flex-col gap-8">
                    <!-- A · form card + action bar -->
                    <div>
                        <p class="mb-3 text-[12.5px] font-semibold text-surface-600 dark:text-surface-300">
                            {{ $t('sk-component.form.layouts.card.title') }}
                            <span class="ml-2 font-mono text-[11px] font-normal text-surface-400">cardTitle · #actions</span>
                        </p>
                        <SkForm v-model="cardModel" :config="cardConfig">
                            <template #actions>
                                <Button :label="$t('sk-button.cancel')" severity="secondary" outlined type="button" />
                                <Button :label="$t('sk-button.save')" icon="pi pi-check" type="button" />
                            </template>
                        </SkForm>
                    </div>

                    <!-- B · aside section -->
                    <div>
                        <p class="mb-3 text-[12.5px] font-semibold text-surface-600 dark:text-surface-300">
                            {{ $t('sk-component.form.layouts.aside.title') }}
                            <span class="ml-2 font-mono text-[11px] font-normal text-surface-400">FB.section().aside()</span>
                        </p>
                        <SkForm v-model="asideModel" :config="asideConfig" />
                    </div>

                    <!-- C · divided horizontal -->
                    <div>
                        <p class="mb-3 text-[12.5px] font-semibold text-surface-600 dark:text-surface-300">
                            {{ $t('sk-component.form.layouts.divided.title') }}
                            <span class="ml-2 font-mono text-[11px] font-normal text-surface-400">layout('horizontal').dividers()</span>
                        </p>
                        <SkForm v-model="dividedModel" :config="dividedConfig" />
                    </div>

                    <!-- D · inline filter bar -->
                    <div>
                        <p class="mb-3 text-[12.5px] font-semibold text-surface-600 dark:text-surface-300">
                            {{ $t('sk-component.form.layouts.filter.title') }}
                            <span class="ml-2 font-mono text-[11px] font-normal text-surface-400">cols(4) · compact</span>
                        </p>
                        <div class="flex flex-col gap-3">
                            <SkForm v-model="filterModel" :config="filterConfig" />
                            <div class="flex items-center gap-2.5">
                                <Button :label="$t('sk-button.filter')" icon="pi pi-filter" size="small" type="button" />
                                <Button :label="$t('sk-button.reset')" icon="pi pi-refresh" severity="secondary" outlined size="small" type="button" />
                            </div>
                        </div>
                    </div>
                </div>
            </SkCard>
        </div>
        </template>
    </SkCard>
</template>
