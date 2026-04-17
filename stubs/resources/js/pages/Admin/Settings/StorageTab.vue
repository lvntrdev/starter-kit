<script setup lang="ts">
    import { FB } from '@lvntr/components/FormBuilder/core';
    import SkForm from '@lvntr/components/FormBuilder/SkForm.vue';
    import adminSettings from '@/routes/settings';

    interface Props {
        settings: {
            media_disk: string;
            spaces_key: string | null;
            spaces_secret: string | null;
            spaces_region: string | null;
            spaces_bucket: string | null;
            spaces_endpoint: string | null;
            spaces_url: string | null;
            aws_key: string | null;
            aws_secret: string | null;
            aws_region: string | null;
            aws_bucket: string | null;
            aws_url: string | null;
            aws_endpoint: string | null;
        };
    }

    const props = defineProps<Props>();

    const diskOptions = [
        { label: 'Local', value: 'local' },
        { label: 'DigitalOcean Spaces', value: 'do' },
        { label: 'Amazon S3', value: 's3' },
    ];

    const doRegionOptions = [
        { label: 'NYC1', value: 'nyc1' },
        { label: 'NYC3', value: 'nyc3' },
        { label: 'AMS3', value: 'ams3' },
        { label: 'SFO2', value: 'sfo2' },
        { label: 'SFO3', value: 'sfo3' },
        { label: 'SGP1', value: 'sgp1' },
        { label: 'LON1', value: 'lon1' },
        { label: 'FRA1', value: 'fra1' },
        { label: 'TOR1', value: 'tor1' },
        { label: 'BLR1', value: 'blr1' },
        { label: 'SYD1', value: 'syd1' },
    ];

    const awsRegionOptions = [
        { label: 'US East (N. Virginia)', value: 'us-east-1' },
        { label: 'US East (Ohio)', value: 'us-east-2' },
        { label: 'US West (N. California)', value: 'us-west-1' },
        { label: 'US West (Oregon)', value: 'us-west-2' },
        { label: 'EU (Ireland)', value: 'eu-west-1' },
        { label: 'EU (London)', value: 'eu-west-2' },
        { label: 'EU (Frankfurt)', value: 'eu-central-1' },
        { label: 'EU (Paris)', value: 'eu-west-3' },
        { label: 'EU (Stockholm)', value: 'eu-north-1' },
        { label: 'Asia Pacific (Singapore)', value: 'ap-southeast-1' },
        { label: 'Asia Pacific (Tokyo)', value: 'ap-northeast-1' },
        { label: 'Asia Pacific (Sydney)', value: 'ap-southeast-2' },
        { label: 'Asia Pacific (Mumbai)', value: 'ap-south-1' },
        { label: 'South America (São Paulo)', value: 'sa-east-1' },
    ];

    const isDo = (values: Record<string, unknown>) => values.media_disk === 'do';
    const isS3 = (values: Record<string, unknown>) => values.media_disk === 's3';

    const formConfig = computed(() =>
        FB.form()
            .layout('vertical')
            .cols(2)
            .cardTitle('sk-setting.storage.title')
            .cardSubtitle('sk-setting.storage.subtitle')
            .initialData({
                media_disk: props.settings.media_disk ?? 'local',
                spaces_key: props.settings.spaces_key ?? '',
                spaces_secret: props.settings.spaces_secret ?? '',
                spaces_region: props.settings.spaces_region ?? '',
                spaces_bucket: props.settings.spaces_bucket ?? '',
                spaces_endpoint: props.settings.spaces_endpoint ?? '',
                spaces_url: props.settings.spaces_url ?? '',
                aws_key: props.settings.aws_key ?? '',
                aws_secret: props.settings.aws_secret ?? '',
                aws_region: props.settings.aws_region ?? '',
                aws_bucket: props.settings.aws_bucket ?? '',
                aws_url: props.settings.aws_url ?? '',
                aws_endpoint: props.settings.aws_endpoint ?? '',
            })
            .submit({
                url: adminSettings.update.storage.url(),
                method: 'put',
                preserveScroll: true,
            })
            .addFields(
                FB.select().key('media_disk').options(diskOptions).class('col-span-full'),

                // ── DO Spaces ──
                FB.title('sk-setting.storage.spaces_title').class('col-span-full').visible(isDo),
                FB.inputText().key('spaces_key').visible(isDo).optional(),
                FB.password().key('spaces_secret').toggleMask().visible(isDo).optional(),
                FB.select().key('spaces_region').options(doRegionOptions).visible(isDo).optional(),
                FB.inputText().key('spaces_bucket').visible(isDo).optional(),
                FB.inputText()
                    .key('spaces_endpoint')
                    .placeholder('https://fra1.digitaloceanspaces.com')
                    .visible(isDo)
                    .optional(),
                FB.inputText()
                    .key('spaces_url')
                    .placeholder('https://bucket.fra1.cdn.digitaloceanspaces.com')
                    .visible(isDo)
                    .optional(),

                // ── AWS S3 ──
                FB.title('sk-setting.storage.s3_title').class('col-span-full').visible(isS3),
                FB.inputText().key('aws_key').visible(isS3).optional(),
                FB.password().key('aws_secret').toggleMask().visible(isS3).optional(),
                FB.select().key('aws_region').options(awsRegionOptions).visible(isS3).optional(),
                FB.inputText().key('aws_bucket').visible(isS3).optional(),
                FB.inputText().key('aws_endpoint').placeholder('https://s3.amazonaws.com').visible(isS3).optional(),
                FB.inputText().key('aws_url').placeholder('https://bucket.s3.amazonaws.com').visible(isS3).optional(),
            )
            .build(),
    );
</script>

<template>
    <SkForm :config="formConfig" />
</template>
