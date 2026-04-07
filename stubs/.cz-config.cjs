module.exports = {
    types: [
        { value: 'feat', name: 'feat:     🎸 Yeni bir özellik eklendi' },
        {
            value: 'wip',
            name: 'wip:      🚧  Yarım kalan iş (squash edilecek)',
        },
        {
            value: 'db',
            name: 'db:       🗃️  Veritabanı değişiklikleri (migration, seeder)',
        },
        { value: 'fix', name: 'fix:      🐛 Bir hata düzeltildi' },
        {
            value: 'hotfix',
            name: 'hotfix:   🚑 Acil production düzeltmesi',
        },
        {
            value: 'i18n',
            name: 'i18n:     🌐 Çeviri ve dil dosyası değişiklikleri',
        },
        {
            value: 'perf',
            name: 'perf:     ⚡ Performansı artıran kod değişikliği',
        },
        {
            value: 'docs',
            name: 'docs:     ✏️  Sadece dokümantasyon değişiklikleri',
        },
        {
            value: 'style',
            name: 'style:    💄 Kod anlamını etkilemeyen değişiklikler (boşluk, biçimlendirme vb.)',
        },
        {
            value: 'refactor',
            name: 'refactor: 💡 Hata düzeltmeyen ve özellik eklemeyen kod değişikliği',
        },
        {
            value: 'test',
            name: 'test:     💍 Eksik testlerin eklenmesi veya mevcut testlerin düzeltilmesi',
        },
        {
            value: 'build',
            name: 'build:    🏗️  Derleme sistemini veya dış bağımlılıkları etkileyen değişiklikler',
        },
        {
            value: 'ci',
            name: 'ci:       🎡 CI yapılandırma dosyaları ve betiklerindeki değişiklikler',
        },
        {
            value: 'chore',
            name: 'chore:    🤖 Derleme süreci veya yardımcı araç değişiklikleri',
        },
        {
            value: 'revert',
            name: "revert:   ⏪ Önceki bir commit'in geri alınması",
        },
    ],

    scopes: [],

    allowCustomScopes: true,
    allowBreakingChanges: ['feat', 'fix', 'hotfix'],
    skipQuestions: [],

    messages: {
        type: 'Commit türünü seçin:',
        scope: 'Değişikliğin kapsamını belirtin (isteğe bağlı):',
        customScope: 'Özel kapsam yazın:',
        subject: 'Kısa bir açıklama yazın:\n',
        body: 'Detaylı bir açıklama yazın (isteğe bağlı). Birden fazla satır için "|" kullanın:\n',
        breaking: 'Kırılma değişikliklerini (BREAKING CHANGES) listeleyin (isteğe bağlı):\n',
        footer: 'Bu commit ile kapatılacak issue numaralarını yazın (isteğe bağlı). Örn: #31, #34:\n',
        confirmCommit: 'Yukarıdaki commit mesajıyla devam etmek istiyor musunuz?',
    },

    subjectLimit: 72,
    subjectSeparator: ': ',
    typePrefix: '',
    typeSuffix: '',
    skipEmptyScopes: true,
};
