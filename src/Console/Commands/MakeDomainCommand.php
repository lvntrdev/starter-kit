<?php

namespace Lvntr\StarterKit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Scaffold a new domain with all layers following the DDD-inspired architecture.
 *
 * Interactive wizard that prompts for:
 *   1. Domain (model) name
 *   2. Fields with types (one by one)
 *   3. Admin layer (Controller + Routes)
 *   4. API layer (Controller + Routes)
 *   5. Events & Listeners
 *
 * All prompts can be bypassed with arguments/options for CI or scripting:
 *   php artisan make:sk-domain Student --fields="name:string,email:string" --admin --api --events
 *   php artisan make:sk-domain Student --fields="name:string" --no-api --no-events
 */
class MakeDomainCommand extends Command
{
    protected $signature = 'make:sk-domain
        {name? : The domain name (e.g. Student, Product, Category)}
        {--fields= : Comma-separated fields with types (e.g. name:string,age:integer)}
        {--id-type= : ID type: id, uuid, or ulid (default: id)}
        {--api : Generate API controller and routes}
        {--no-api : Skip API controller and routes}
        {--admin : Generate Admin controller and routes}
        {--no-admin : Skip Admin controller and routes}
        {--events : Generate events and listeners}
        {--no-events : Skip events and listeners}
        {--soft-deletes : Enable soft deletes on the model and migration}
        {--no-soft-deletes : Disable soft deletes on the model and migration}
        {--vue= : Vue page generation: none, empty, or full}
        {--vue-fields : Include model fields in DataTable columns and FormBuilder}
        {--no-vue-fields : Skip model fields in Vue components (only id)}
        {--from-migration= : Parse fields from existing migration file (e.g. 2026_03_21_create_products_table.php)}';

    protected $description = 'Scaffold a complete domain interactively (Model, DTO, Actions, Events, Controllers, Routes)';

    protected $aliases = ['make:domain', 'sk-make:domain'];

    /** Domain name variants */
    private string $dn;         // StudlyCase  e.g. Student

    private string $dnPlural;   // StudlyCase  e.g. Students

    private string $dnSnake;    // snake_case  e.g. student or school_student

    private string $dnPSnake;   // snake_case  e.g. students

    /** @var list<string> */
    private array $domainSegments = [];

    private string $domainPath; // slash path e.g. Student or School/Student

    private string $domainNamespace; // namespace path e.g. Student or School\Student

    /** @var array<int, array{name: string, type: string}> */
    private array $fields = [];

    /** ID type: 'id' (auto-increment), 'uuid', or 'ulid' */
    private string $idType = 'id';

    /** Layer flags resolved from options or interactive prompts */
    private bool $withApi = false;

    private bool $withAdmin = false;

    private bool $withEvents = false;

    private bool $withSoftDeletes = false;

    /** Vue page generation: 'none', 'empty', or 'full' */
    private string $vueMode = 'none';

    /** Whether to include model fields in Vue components */
    private bool $withVueFields = false;

    /** Whether domain is being created from an existing migration */
    private bool $fromMigration = false;

    /** Available field types for the interactive prompt */
    private const FIELD_TYPES = [
        'string',
        'integer',
        'bigInteger',
        'unsignedBigInteger',
        'float',
        'decimal',
        'boolean',
        'text',
        'longText',
        'json',
        'date',
        'dateTime',
        'timestamp',
    ];

    public function handle(): int
    {
        if (! $this->askDomainName()) {
            return self::FAILURE;
        }

        // --from-migration: parse fields, id-type, soft-deletes from existing migration
        $migrationOption = $this->option('from-migration');

        if ($migrationOption) {
            if (! $this->parseFieldsFromMigration($migrationOption)) {
                return self::FAILURE;
            }

            $this->fromMigration = true;
        } else {
            $this->askFields();
            $this->askIdType();
        }

        $this->askLayers();

        if (! $this->showSummaryAndConfirm()) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("🏗  Creating domain: {$this->domainPath}");
        $this->newLine();

        $this->createModel();

        if (! $this->fromMigration) {
            $this->populateMigration();
        }
        $this->createDTO();
        $this->createActions();

        if ($this->withEvents) {
            $this->createEvents();
            $this->createListeners();
        }

        if ($this->withAdmin) {
            $this->createFormRequests('Admin');
            $this->createAdminController();
        }

        if ($this->withApi) {
            $this->createFormRequests('Api');
            $this->createApiController();
        }

        $this->registerInServiceProvider();

        if ($this->withAdmin) {
            $this->appendAdminRoutes();
        }

        if ($this->withApi) {
            $this->appendApiRoutes();
        }

        if ($this->vueMode !== 'none' && $this->withAdmin) {
            $this->createTypeDefinition();
            $this->createVuePages();

            if ($this->vueMode === 'full') {
                $this->createDatatableQuery();
                $this->createAdminResource();
            }
        }

        $this->newLine();
        $this->info("✅ Domain '{$this->domainPath}' created successfully!");
        $this->newLine();
        $this->table(['Layer', 'Files'], $this->getFileSummaryTable());
        $this->newLine();
        $this->warn('📌 TODO:');
        $step = 1;

        if (! $this->fromMigration) {
            $this->line("   {$step}. Check migration content: database/migrations/");
            $step++;
        }

        $this->line("   {$step}. Check FormRequest validation rules");
        $step++;
        $this->line("   {$step}. Run: php artisan migrate");
        $step++;

        if ($this->withAdmin && $this->vueMode === 'none') {
            $this->line("   {$step}. Create Inertia pages: resources/js/pages/{$this->inertiaPagePath('')}");
        }

        return self::SUCCESS;
    }

    // ══════════════════════════════════════════════════════════════════════
    // INTERACTIVE WIZARD
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Step 1: Ask for the domain (model) name.
     */
    private function askDomainName(): bool
    {
        $raw = $this->argument('name');

        if (! $raw) {
            $raw = $this->ask('📦 Domain (Model) name? (e.g.: Student, Product, Category)');
        }

        if (! $raw) {
            $this->error('Domain name is required.');

            return false;
        }

        if (! $this->isValidDomainName($raw)) {
            $this->error('Invalid domain name. Use only letters, numbers, hyphens and underscores in segments.');

            return false;
        }

        $this->domainSegments = collect(preg_split('/[\/\\\\]+/', $raw) ?: [])
            ->filter()
            ->map(fn (string $segment): string => Str::studly($segment))
            ->values()
            ->all();

        $this->domainPath = implode('/', $this->domainSegments);
        $this->domainNamespace = implode('\\', $this->domainSegments);
        $this->dn = (string) last($this->domainSegments);
        $this->dnPlural = Str::plural($this->dn);
        $this->dnSnake = collect($this->domainSegments)->map(fn (string $segment): string => Str::snake($segment))->implode('_');
        $this->dnPSnake = Str::plural($this->dnSnake);

        return true;
    }

    /**
     * Step 2: Ask for fields interactively or parse from --fields option.
     */
    private function askFields(): void
    {
        $raw = $this->option('fields');

        // If --fields option was given, parse it directly
        if ($raw) {
            $this->fields = collect(explode(',', $raw))
                ->map(function (string $f) {
                    $parts = explode(':', trim($f));

                    return ['name' => trim($parts[0]), 'type' => trim($parts[1] ?? 'string')];
                })
                ->all();

            return;
        }

        $this->newLine();
        $this->info('📝 Field definition — leave empty to finish');
        $this->line('   Available types: '.implode(', ', self::FIELD_TYPES));
        $this->newLine();

        $index = 1;

        while (true) {
            $fieldName = $this->ask("   Field {$index} — name (empty = finish)");

            if (! $fieldName || trim($fieldName) === '') {
                break;
            }

            $fieldName = Str::snake(trim($fieldName));

            $fieldType = $this->choice(
                "   Field {$index} — \"{$fieldName}\" type",
                self::FIELD_TYPES,
                0 // default: string
            );

            $this->fields[] = ['name' => $fieldName, 'type' => $fieldType];
            $index++;
        }

        if (empty($this->fields)) {
            $this->fields = [['name' => 'name', 'type' => 'string']];
            $this->warn('   Default field added: name:string');
        }

        $this->newLine();
        $this->table(
            ['#', 'Field Name', 'Type'],
            collect($this->fields)->map(fn ($f, $i) => [$i + 1, $f['name'], $f['type']])->all()
        );
    }

    /**
     * Parse fields, ID type, and soft deletes from an existing migration file.
     */
    private function parseFieldsFromMigration(string $filename): bool
    {
        $path = database_path('migrations/'.$filename);

        if (! file_exists($path)) {
            // Try glob match (partial filename)
            $matches = glob(database_path('migrations/*'.$filename.'*'));

            if (empty($matches)) {
                $this->error("Migration file not found: {$filename}");

                return false;
            }

            $path = end($matches);
        }

        $content = file_get_contents($path);

        $this->info('📄 Parsing migration: '.basename($path));

        // Detect ID type
        if (preg_match('/\$table->uuid\s*\(\s*[\'"]id[\'"]\s*\)/', $content)) {
            $this->idType = 'uuid';
        } elseif (preg_match('/\$table->ulid\s*\(\s*[\'"]id[\'"]\s*\)/', $content)) {
            $this->idType = 'ulid';
        } else {
            $this->idType = 'id';
        }

        // Detect soft deletes
        if (preg_match('/\$table->softDeletes\s*\(/', $content)) {
            $this->withSoftDeletes = true;
        }

        // Extract columns — skip system columns
        $skipColumns = ['id', 'created_at', 'updated_at', 'deleted_at'];

        // Match patterns like: $table->string('name'), $table->integer('age')->nullable(), etc.
        preg_match_all(
            '/\$table->(\w+)\s*\(\s*[\'"](\w+)[\'"]/',
            $content,
            $matches,
            PREG_SET_ORDER,
        );

        // Also skip non-column methods
        $skipMethods = [
            'id', 'uuid', 'ulid', 'timestamps', 'softDeletes',
            'primary', 'unique', 'index', 'foreign', 'dropColumn',
            'dropForeign', 'dropIndex', 'dropUnique', 'dropPrimary',
            'rememberToken', 'morphs', 'nullableMorphs',
        ];

        foreach ($matches as $match) {
            $method = $match[1];
            $column = $match[2];

            if (in_array($column, $skipColumns) || in_array($method, $skipMethods)) {
                continue;
            }

            // Map Blueprint method to field type
            $type = $this->blueprintMethodToFieldType($method);

            if ($type !== null) {
                $this->fields[] = ['name' => $column, 'type' => $type];
            }
        }

        // Handle foreignId separately — detect $table->foreignId('xxx')
        preg_match_all(
            '/\$table->foreignId\s*\(\s*[\'"](\w+)[\'"]/',
            $content,
            $foreignMatches,
        );

        foreach ($foreignMatches[1] ?? [] as $foreignColumn) {
            if (! collect($this->fields)->contains('name', $foreignColumn)) {
                $this->fields[] = ['name' => $foreignColumn, 'type' => 'unsignedBigInteger'];
            }
        }

        if (empty($this->fields)) {
            $this->warn('  No fields found in migration.');
            $this->fields = [['name' => 'name', 'type' => 'string']];
        }

        $this->newLine();
        $this->components->twoColumnDetail('ID Type', $this->idType);
        $this->components->twoColumnDetail('Soft Deletes', $this->withSoftDeletes ? 'Yes' : 'No');
        $this->newLine();
        $this->table(
            ['#', 'Field Name', 'Type'],
            collect($this->fields)->map(fn ($f, $i) => [$i + 1, $f['name'], $f['type']])->all()
        );

        return true;
    }

    /**
     * Map a Blueprint column method to a field type string.
     *
     * @return string|null null if the method is not a recognized column type
     */
    private function blueprintMethodToFieldType(string $method): ?string
    {
        return match ($method) {
            'string', 'char' => 'string',
            'integer', 'tinyInteger', 'smallInteger', 'mediumInteger' => 'integer',
            'bigInteger' => 'bigInteger',
            'unsignedInteger', 'unsignedTinyInteger', 'unsignedSmallInteger', 'unsignedMediumInteger' => 'integer',
            'unsignedBigInteger' => 'unsignedBigInteger',
            'float' => 'float',
            'double' => 'float',
            'decimal', 'unsignedDecimal' => 'decimal',
            'boolean' => 'boolean',
            'text', 'mediumText' => 'text',
            'longText' => 'longText',
            'json', 'jsonb' => 'json',
            'date' => 'date',
            'dateTime', 'dateTimeTz' => 'dateTime',
            'timestamp', 'timestampTz' => 'timestamp',
            'enum' => 'string',
            'year' => 'integer',
            'binary' => 'text',
            default => null,
        };
    }

    /**
     * Step 3: Ask the ID type for the model and migration.
     */
    private function askIdType(): void
    {
        $opt = $this->option('id-type');

        if ($opt && in_array($opt, ['id', 'uuid', 'ulid'])) {
            $this->idType = $opt;

            return;
        }

        $this->newLine();
        $this->info('🔑 ID type selection');

        $choices = [
            'uuid — UUID v4 string',
            'ulid — ULID string',
            'id — auto-increment bigint',
        ];

        $selected = $this->choice('   Model ID type?', $choices, 0);

        $this->idType = match (true) {
            str_starts_with($selected, 'uuid') => 'uuid',
            str_starts_with($selected, 'ulid') => 'ulid',
            default => 'id',
        };
    }

    /**
     * Step 4: Ask which layers to generate.
     */
    private function askLayers(): void
    {
        $this->newLine();
        $this->info('⚙️  Layer selection');

        $this->withAdmin = $this->resolveFlag('admin', '   Create Admin layer (Controller + Routes)?');
        $this->withApi = $this->resolveFlag('api', '   Create API layer (Controller + Routes)?');
        $this->withEvents = $this->resolveFlag('events', '   Create Event and Listener layer?');

        // Skip soft-deletes prompt when parsed from migration
        if (! $this->fromMigration) {
            $this->withSoftDeletes = $this->resolveFlag('soft-deletes', '   Add soft delete support?');
        }

        $this->askVueMode();
    }

    /**
     * Step 4: Show configuration summary and ask for confirmation.
     */
    private function showSummaryAndConfirm(): bool
    {
        $this->newLine();
        $this->info('┌─────────────────────────────────────┐');
        $this->info('│      📋 Configuration Summary      │');
        $this->info('└─────────────────────────────────────┘');

        $this->newLine();
        $this->table(['Setting', 'Value'], [
            ['Domain', $this->domainPath],
            ['Plural', $this->dnPlural],
            ['ID Type', $this->idType],
            ['Fields', collect($this->fields)->map(fn ($f) => "{$f['name']}:{$f['type']}")->implode(', ')],
            ['Admin Layer', $this->withAdmin ? '✅ Yes' : '❌ No'],
            ['API Layer', $this->withApi ? '✅ Yes' : '❌ No'],
            ['Event/Listener', $this->withEvents ? '✅ Yes' : '❌ No'],
            ['Soft Deletes', $this->withSoftDeletes ? '✅ Yes' : '❌ No'],
            ['Vue Pages', $this->vueMode === 'none' ? '❌ No' : '✅ '.ucfirst($this->vueMode)],
            ...($this->vueMode === 'full' ? [['Vue Fields', $this->withVueFields ? '✅ Yes' : '❌ No']] : []),
        ]);

        if (! $this->confirm('Proceed with this configuration?', true)) {
            $this->warn('Cancelled.');

            return false;
        }

        return true;
    }

    /**
     * Check --flag / --no-flag options; if neither is set, prompt interactively.
     */
    private function resolveFlag(string $flag, string $question): bool
    {
        if ($this->option($flag)) {
            return true;
        }

        if ($this->option("no-{$flag}")) {
            return false;
        }

        return $this->confirm($question, true);
    }

    /**
     * Ask which Vue page mode to use.
     */
    private function askVueMode(): void
    {
        if (! $this->withAdmin) {
            $this->vueMode = 'none';

            return;
        }

        $opt = $this->option('vue');

        if ($opt && in_array($opt, ['none', 'empty', 'full'])) {
            $this->vueMode = $opt;

            if ($this->vueMode === 'full') {
                $this->askVueFields();
            }

            return;
        }

        $choices = [
            'full — Index (DataTable), Create, Edit, Form (FormBuilder)',
            'empty — Empty Index page only',
            'none — Skip Vue page generation',
        ];

        $selected = $this->choice('   Vue page generation?', $choices, 0);

        $this->vueMode = match (true) {
            str_starts_with($selected, 'full') => 'full',
            str_starts_with($selected, 'empty') => 'empty',
            default => 'none',
        };

        if ($this->vueMode === 'full') {
            $this->askVueFields();
        }
    }

    private function askVueFields(): void
    {
        if ($this->option('vue-fields')) {
            $this->withVueFields = true;

            return;
        }

        if ($this->option('no-vue-fields')) {
            $this->withVueFields = false;

            return;
        }

        if (empty($this->fields)) {
            return;
        }

        $this->withVueFields = $this->confirm('   Include model fields in DataTable & FormBuilder?', true);
    }

    // ══════════════════════════════════════════════════════════════════════
    // MODEL
    // ══════════════════════════════════════════════════════════════════════

    private function createModel(): void
    {
        $path = $this->modelPath();

        if (file_exists($path)) {
            $this->warn("  ⏭  Model already exists: {$this->domainPath}");

            return;
        }

        $this->callSilently('make:model', ['name' => $this->domainPath, '-f' => true]);

        if (! $this->fromMigration) {
            $this->callSilently('make:migration', ['name' => "create_{$this->dnPSnake}_table", '--create' => $this->dnPSnake]);
        }

        $fillable = collect($this->fields)->map(fn ($f) => "        '{$f['name']}',")->implode("\n");
        $tableProperty = $this->dnPSnake === Str::snake($this->dnPlural)
            ? ''
            : "\n    protected \$table = '{$this->dnPSnake}';\n";
        $softDeleteImport = $this->withSoftDeletes ? "\nuse Illuminate\\Database\\Eloquent\\SoftDeletes;" : '';
        $softDeleteTrait = $this->withSoftDeletes ? "\n    use SoftDeletes;" : '';

        [$idImport, $idTrait, $idMeta] = match ($this->idType) {
            'uuid' => [
                "\nuse Illuminate\\Database\\Eloquent\\Concerns\\HasUuids;",
                "\n    use HasUuids;\n",
                "\n    protected \$keyType = 'string';\n\n    public \$incrementing = false;\n",
            ],
            'ulid' => [
                "\nuse Illuminate\\Database\\Eloquent\\Concerns\\HasUlids;",
                "\n    use HasUlids;\n",
                "\n    protected \$keyType = 'string';\n\n    public \$incrementing = false;\n",
            ],
            default => ['', '', ''],
        };

        $stub = "<?php\n\nnamespace {$this->modelNamespace()};\n\n"
            ."use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\n"
            ."use Illuminate\\Database\\Eloquent\\Model;{$idImport}{$softDeleteImport}\n\n"
            ."class {$this->dn} extends Model\n{\n"
            ."    /** @use HasFactory<\\Database\\Factories\\{$this->domainNamespace}Factory> */\n"
            ."    use HasFactory;{$idTrait}{$softDeleteTrait}{$idMeta}\n"
            ."{$tableProperty}"
            ."    /**\n"
            ."     * @var list<string>\n"
            ."     */\n"
            ."    protected \$fillable = [\n"
            ."{$fillable}\n"
            ."    ];\n"
            ."}\n";

        file_put_contents($path, $stub);
        $this->line("  ✓ Model: <info>app/Models/{$this->domainPath}.php</info>");
        $this->line($this->fromMigration ? '  ✓ Factory created (migration already exists)' : '  ✓ Migration + Factory created');
    }

    // ══════════════════════════════════════════════════════════════════════
    // MIGRATION
    // ══════════════════════════════════════════════════════════════════════

    private function populateMigration(): void
    {
        $pattern = database_path('migrations/*_create_'.$this->dnPSnake.'_table.php');
        $files = glob($pattern);

        if (empty($files)) {
            $this->warn('  ⏭  Migration file not found, create manually.');

            return;
        }

        $path = end($files);

        $idColumn = match ($this->idType) {
            'uuid' => "            \$table->uuid('id')->primary();",
            'ulid' => "            \$table->ulid('id')->primary();",
            default => '            $table->id();',
        };

        $fieldColumns = collect($this->fields)
            ->map(fn ($f) => $this->buildMigrationColumn($f))
            ->implode("\n");

        $tableName = $this->dnPSnake;
        $softDeleteColumn = $this->withSoftDeletes ? "            \$table->softDeletes();\n" : '';

        $content = "<?php\n\n"
            ."use Illuminate\\Database\\Migrations\\Migration;\n"
            ."use Illuminate\\Database\\Schema\\Blueprint;\n"
            ."use Illuminate\\Support\\Facades\\Schema;\n\n"
            ."return new class extends Migration\n"
            ."{\n"
            ."    public function up(): void\n"
            ."    {\n"
            ."        Schema::create('{$tableName}', function (Blueprint \$table) {\n"
            ."{$idColumn}\n"
            ."{$fieldColumns}\n"
            ."            \$table->timestamps();\n"
            ."{$softDeleteColumn}"
            ."        });\n"
            ."    }\n\n"
            ."    public function down(): void\n"
            ."    {\n"
            ."        Schema::dropIfExists('{$tableName}');\n"
            ."    }\n"
            ."};\n";

        file_put_contents($path, $content);
        $this->line('  ✓ Migration dolduruldu: <info>'.basename($path).'</info>');
    }

    private function buildMigrationColumn(array $field): string
    {
        $name = $field['name'];

        $col = match ($field['type']) {
            'string' => "\$table->string('{$name}')",
            'integer' => "\$table->integer('{$name}')",
            'bigInteger' => "\$table->bigInteger('{$name}')",
            'unsignedBigInteger' => "\$table->unsignedBigInteger('{$name}')",
            'float' => "\$table->float('{$name}')",
            'decimal' => "\$table->decimal('{$name}', 10, 2)",
            'boolean' => "\$table->boolean('{$name}')",
            'text' => "\$table->text('{$name}')",
            'longText' => "\$table->longText('{$name}')",
            'json' => "\$table->json('{$name}')",
            'date' => "\$table->date('{$name}')",
            'dateTime' => "\$table->dateTime('{$name}')",
            'timestamp' => "\$table->timestamp('{$name}')",
            default => "\$table->string('{$name}')",
        };

        return "            {$col};";
    }

    // ══════════════════════════════════════════════════════════════════════
    // DTO
    // ══════════════════════════════════════════════════════════════════════

    private function createDTO(): void
    {
        $path = "{$this->domainBasePath()}/DTOs/{$this->dn}DTO.php";
        $namespace = $this->domainClassNamespace('DTOs');

        $params = collect($this->fields)->map(fn ($f) => "        public {$this->phpType($f['type'])} \${$f['name']},")->implode("\n");
        $from = collect($this->fields)->map(fn ($f) => "            {$f['name']}: \$data['{$f['name']}'],")->implode("\n");
        $to = collect($this->fields)->map(fn ($f) => "            '{$f['name']}' => \$this->{$f['name']},")->implode("\n");

        $stub = <<<PHP
<?php

namespace {$namespace};

use App\Domain\Shared\DTOs\BaseDTO;

/**
 * Data Transfer Object for {$this->dn}.
 */
readonly class {$this->dn}DTO extends BaseDTO
{
    public function __construct(
{$params}
    ) {}

    /**
     * @param  array<string, mixed>  \$data
     */
    public static function fromArray(array \$data): static
    {
        return new static(
{$from}
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
{$to}
        ];
    }
}
PHP;

        $this->putFile($path, $stub);
        $this->line("  ✓ DTO: <info>app/Domain/{$this->domainPath}/DTOs/{$this->dn}DTO.php</info>");
    }

    // ══════════════════════════════════════════════════════════════════════
    // ACTIONS
    // ══════════════════════════════════════════════════════════════════════

    private function createActions(): void
    {
        $dir = "{$this->domainBasePath()}/Actions";
        $ev = $this->withEvents;
        $v = $this->dnSnake;
        $namespace = $this->domainClassNamespace('Actions');
        $dtoNamespace = $this->domainClassNamespace('DTOs');
        $eventsNamespace = $this->domainClassNamespace('Events');
        $modelClass = $this->modelClass();

        // ── CREATE ────────────────────────────────────────────────────
        $cimp = $ev ? "\nuse {$eventsNamespace}\\{$this->dn}Created;" : '';
        $cdisp = $ev ? "\n\n        {$this->dn}Created::dispatch(\$model, auth()->id());" : '';

        $this->putFile("{$dir}/Create{$this->dn}Action.php", <<<PHP
<?php

namespace {$namespace};

use App\Domain\Shared\Actions\BaseAction;
use {$dtoNamespace}\\{$this->dn}DTO;{$cimp}
use {$modelClass};

/**
 * Action: Create a new {$this->dnSnake}.
 */
class Create{$this->dn}Action extends BaseAction
{
    public function execute({$this->dn}DTO \$dto): {$this->dn}
    {
        \$model = {$this->dn}::create(\$dto->toArray());{$cdisp}

        return \$model;
    }
}
PHP);

        // ── UPDATE ────────────────────────────────────────────────────
        $uimp = $ev ? "\nuse {$eventsNamespace}\\{$this->dn}Updated;" : '';
        $utrack = $ev ? <<<'TRACK'

        $changedFields = array_keys(array_filter(
            $data,
            fn ($value, $key) => $model->getAttribute($key) !== $value,
            ARRAY_FILTER_USE_BOTH,
        ));
TRACK : '';
        $udisp = $ev ? "\n\n        if (! empty(\$changedFields)) {\n            {$this->dn}Updated::dispatch(\$model, \$changedFields, auth()->id());\n        }" : '';

        $this->putFile("{$dir}/Update{$this->dn}Action.php", <<<PHP
<?php

namespace {$namespace};

use App\Domain\Shared\Actions\BaseAction;
use {$dtoNamespace}\\{$this->dn}DTO;{$uimp}
use {$modelClass};

/**
 * Action: Update an existing {$this->dnSnake}.
 */
class Update{$this->dn}Action extends BaseAction
{
    public function execute({$this->dn} \$model, {$this->dn}DTO \$dto): {$this->dn}
    {
        \$data = \$dto->toArray();{$utrack}

        \$model->update(\$data);
        \$model->refresh();{$udisp}

        return \$model;
    }
}
PHP);

        // ── DELETE ────────────────────────────────────────────────────
        $dimp = $ev ? "\nuse {$eventsNamespace}\\{$this->dn}Deleted;" : '';

        if ($ev) {
            $emailField = $this->hasField('email') ? "\n        \${$v}Email = \${$v}->email;" : '';
            $deletedDispatchArgs = $this->hasField('email')
                ? "\${$v}Id, \${$v}Email, auth()->id()"
                : "\${$v}Id, auth()->id()";
            $dbody = <<<BODY
        \${$v}Id = \${$v}->id;{$emailField}

        \$result = (bool) \${$v}->delete();

        if (\$result) {
            {$this->dn}Deleted::dispatch({$deletedDispatchArgs});
        }

        return \$result;
BODY;
        } else {
            $dbody = "        return (bool) \${$v}->delete();";
        }

        $this->putFile("{$dir}/Delete{$this->dn}Action.php", <<<PHP
<?php

namespace {$namespace};

use App\Domain\Shared\Actions\BaseAction;{$dimp}
use {$modelClass};

/**
 * Action: Delete a {$this->dnSnake}.
 */
class Delete{$this->dn}Action extends BaseAction
{
    public function execute({$this->dn} \${$v}): bool
    {
{$dbody}
    }
}
PHP);

        $this->line('  ✓ Actions: <info>Create, Update, Delete</info>');
    }

    // ══════════════════════════════════════════════════════════════════════
    // EVENTS
    // ══════════════════════════════════════════════════════════════════════

    private function createEvents(): void
    {
        $dir = "{$this->domainBasePath()}/Events";
        $v = $this->dnSnake;
        $namespace = $this->domainClassNamespace('Events');
        $modelClass = $this->modelClass();

        $this->putFile("{$dir}/{$this->dn}Created.php", <<<PHP
<?php

namespace {$namespace};

use {$modelClass};
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class {$this->dn}Created
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly {$this->dn} \${$v},
        public readonly int|string|null \$performedBy = null,
    ) {}
}
PHP);

        $this->putFile("{$dir}/{$this->dn}Updated.php", <<<PHP
<?php

namespace {$namespace};

use {$modelClass};
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class {$this->dn}Updated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly {$this->dn} \${$v},
        /** @var array<string> */
        public readonly array \$changedFields = [],
        public readonly int|string|null \$performedBy = null,
    ) {}
}
PHP);

        $idType = $this->idType === 'id' ? 'int' : 'int|string';
        $deletedEventArgs = $this->hasField('email')
            ? "        public readonly {$idType} \${$v}Id,\n        public readonly string \${$v}Email,\n        public readonly int|string|null \$performedBy = null,"
            : "        public readonly {$idType} \${$v}Id,\n        public readonly int|string|null \$performedBy = null,";

        $this->putFile("{$dir}/{$this->dn}Deleted.php", <<<PHP
<?php

namespace {$namespace};

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class {$this->dn}Deleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
{$deletedEventArgs}
    ) {}
}
PHP);

        $this->line('  ✓ Events: <info>Created, Updated, Deleted</info>');
    }

    // ══════════════════════════════════════════════════════════════════════
    // LISTENERS
    // ══════════════════════════════════════════════════════════════════════

    private function createListeners(): void
    {
        $dir = "{$this->domainBasePath()}/Listeners";
        $v = $this->dnSnake;
        $idProperty = "{$v}Id";
        $emailLogLine = $this->hasField('email') ? "\n            'email' => \$event->{$v}Email," : '';
        $namespace = $this->domainClassNamespace('Listeners');
        $eventsNamespace = $this->domainClassNamespace('Events');

        foreach (['Created', 'Updated', 'Deleted'] as $event) {
            $logData = match ($event) {
                'Created' => "[\n            '{$this->dnSnake}_id' => \$event->{$v}->id,{$emailLogLine}\n            'created_by' => \$event->performedBy,\n        ]",
                'Updated' => "[\n            '{$this->dnSnake}_id' => \$event->{$v}->id,{$emailLogLine}\n            'changed_fields' => \$event->changedFields,\n            'updated_by' => \$event->performedBy,\n        ]",
                'Deleted' => "[\n            '{$this->dnSnake}_id' => \$event->{$idProperty},{$emailLogLine}\n            'deleted_by' => \$event->performedBy,\n        ]",
            };

            $this->putFile("{$dir}/Log{$this->dn}{$event}.php", <<<PHP
<?php

namespace {$namespace};

use {$eventsNamespace}\\{$this->dn}{$event};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class Log{$this->dn}{$event} implements ShouldQueue
{
    public function handle({$this->dn}{$event} \$event): void
    {
        Log::channel('stack')->info('{$this->dn} {$event}', {$logData});
    }
}
PHP);
        }

        $this->line('  ✓ Listeners: <info>LogCreated, LogUpdated, LogDeleted</info>');
    }

    // ══════════════════════════════════════════════════════════════════════
    // FORM REQUESTS
    // ══════════════════════════════════════════════════════════════════════

    private function createFormRequests(string $layer): void
    {
        $dir = $this->requestDirectory($layer);
        $storeImports = $this->buildFormRequestImports(false);
        $updateImports = $this->buildFormRequestImports(true);
        $required = $this->buildRequestRules('required', false);
        $updateMode = $layer === 'Api' ? 'sometimes' : 'required';
        $updateRules = $this->buildRequestRules($updateMode, true);
        $attrs = collect($this->fields)->map(fn ($f) => "            '{$f['name']}' => '{$this->attributeLabel($f['name'])}',")->implode("\n");
        $namespace = $this->requestNamespace($layer);

        $this->putFile("{$dir}/Store{$this->dn}Request.php", <<<PHP
<?php

namespace {$namespace};

use Illuminate\Foundation\Http\FormRequest;{$storeImports}

class Store{$this->dn}Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
{$required}
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
{$attrs}
        ];
    }
}
PHP);

        $this->putFile("{$dir}/Update{$this->dn}Request.php", <<<PHP
<?php

namespace {$namespace};

use Illuminate\Foundation\Http\FormRequest;{$updateImports}

class Update{$this->dn}Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
{$updateRules}
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
{$attrs}
        ];
    }
}
PHP);

        $this->line("  ✓ {$layer} FormRequests: <info>Store, Update</info>");
    }

    // ══════════════════════════════════════════════════════════════════════
    // ADMIN CONTROLLER
    // ══════════════════════════════════════════════════════════════════════

    private function createAdminController(): void
    {
        $path = $this->controllerPath('Admin');
        $v = $this->dnSnake;
        $rn = $this->dnPSnake;
        $namespace = $this->controllerNamespace('Admin');
        $actionsNamespace = $this->domainClassNamespace('Actions');
        $dtoNamespace = $this->domainClassNamespace('DTOs');
        $requestNamespace = $this->requestNamespace('Admin');
        $modelClass = $this->modelClass();
        $indexPage = $this->inertiaPagePath('Index');
        $createPage = $this->inertiaPagePath('Create');
        $showPage = $this->inertiaPagePath('Show');
        $editPage = $this->inertiaPagePath('Edit');

        $dtApiImport = '';
        $dtApiMethod = '';
        $resourceImport = '';
        $dataBody = "return to_api(\${$v});";

        if ($this->vueMode === 'full') {
            $queriesNamespace = $this->domainClassNamespace('Queries');
            $resourceNs = $this->adminResourceNamespace();

            $dtApiImport = "\nuse {$queriesNamespace}\\{$this->dn}DatatableQuery;";
            $resourceImport = "\nuse {$resourceNs}\\{$this->dn}Resource;";

            $dtApiMethod = <<<METHOD

    public function dtApi({$this->dn}DatatableQuery \$query): ApiResponse
    {
        return \$query->response();
    }

METHOD;

            $dataBody = "return to_api(['{$v}' => new {$this->dn}Resource(\${$v})]);";
        }

        $this->putFile($path, <<<PHP
<?php

namespace {$namespace};

use {$actionsNamespace}\\Create{$this->dn}Action;
use {$actionsNamespace}\\Delete{$this->dn}Action;
use {$actionsNamespace}\\Update{$this->dn}Action;
use {$dtoNamespace}\\{$this->dn}DTO;
use App\Http\Controllers\Controller;
use {$requestNamespace}\\Store{$this->dn}Request;
use {$requestNamespace}\\Update{$this->dn}Request;
use App\Http\Responses\ApiResponse;{$dtApiImport}{$resourceImport}
use {$modelClass};
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class {$this->dn}Controller extends Controller
{
    public function index(): Response
    {
        return Inertia::render('{$indexPage}');
    }
{$dtApiMethod}
    public function create(): Response
    {
        return Inertia::render('{$createPage}');
    }

    public function store(Store{$this->dn}Request \$request, Create{$this->dn}Action \$action): RedirectResponse
    {
        \$dto = {$this->dn}DTO::fromArray(\$request->validated());
        \$action->execute(\$dto);

        return back()->with('success', 'Record created.');
    }

    public function data({$this->dn} \${$v}): ApiResponse
    {
        {$dataBody}
    }

    public function show({$this->dn} \${$v}): Response
    {
        return Inertia::render('{$showPage}', [
            '{$v}' => \${$v},
        ]);
    }

    public function edit({$this->dn} \${$v}): Response
    {
        return Inertia::render('{$editPage}', [
            '{$v}' => \${$v},
        ]);
    }

    public function update(Update{$this->dn}Request \$request, {$this->dn} \${$v}, Update{$this->dn}Action \$action): RedirectResponse
    {
        \$dto = {$this->dn}DTO::fromArray(\$request->validated());
        \$action->execute(\${$v}, \$dto);

        return redirect()
            ->route('{$rn}.index')
            ->with('success', 'Record updated.');
    }

    public function destroy({$this->dn} \${$v}, Delete{$this->dn}Action \$action): RedirectResponse
    {
        try {
            \$action->execute(\${$v});

            return redirect()
                ->route('{$rn}.index')
                ->with('success', 'Record deleted.');
        } catch (\LogicException \$e) {
            return redirect()
                ->route('{$rn}.index')
                ->with('error', \$e->getMessage());
        }
    }
}
PHP);

        $this->line('  ✓ Admin Controller: <info>'.str_replace(base_path().'/', '', $path).'</info>');
    }

    // ══════════════════════════════════════════════════════════════════════
    // API CONTROLLER
    // ══════════════════════════════════════════════════════════════════════

    private function createApiController(): void
    {
        $path = $this->controllerPath('Api');
        $v = $this->dnSnake;
        $rn = $this->dnPSnake;
        $fieldNames = collect($this->fields)->map(fn ($f) => "'{$f['name']}'")->implode(', ');
        $namespace = $this->controllerNamespace('Api');
        $actionsNamespace = $this->domainClassNamespace('Actions');
        $dtoNamespace = $this->domainClassNamespace('DTOs');
        $requestNamespace = $this->requestNamespace('Api');
        $modelClass = $this->modelClass();

        $this->putFile($path, <<<PHP
<?php

namespace {$namespace};

use {$actionsNamespace}\\Create{$this->dn}Action;
use {$actionsNamespace}\\Delete{$this->dn}Action;
use {$actionsNamespace}\\Update{$this->dn}Action;
use {$dtoNamespace}\\{$this->dn}DTO;
use App\Http\Controllers\Controller;
use {$requestNamespace}\\Store{$this->dn}Request;
use {$requestNamespace}\\Update{$this->dn}Request;
use App\Http\Responses\ApiResponse;
use {$modelClass};
use Illuminate\Http\JsonResponse;

class {$this->dn}Controller extends Controller
{
    public function index(): ApiResponse
    {
        return to_api({$this->dn}::paginate());
    }

    public function store(Store{$this->dn}Request \$request, Create{$this->dn}Action \$action): ApiResponse
    {
        \$dto = {$this->dn}DTO::fromArray(\$request->validated());
        \$model = \$action->execute(\$dto);

        return to_api(\$model, 'Record created.', 201);
    }

    public function show({$this->dn} \${$v}): ApiResponse
    {
        return to_api(\${$v});
    }

    public function update(Update{$this->dn}Request \$request, {$this->dn} \${$v}, Update{$this->dn}Action \$action): ApiResponse
    {
        \$merged = array_merge(
            \${$v}->only([{$fieldNames}]),
            \$request->validated(),
        );

        \$dto = {$this->dn}DTO::fromArray(\$merged);
        \$model = \$action->execute(\${$v}, \$dto);

        return to_api(\$model, 'Record updated.');
    }

    public function destroy({$this->dn} \${$v}, Delete{$this->dn}Action \$action): ApiResponse|JsonResponse
    {
        try {
            \$action->execute(\${$v});

            return to_api(status: 204);
        } catch (\LogicException \$e) {
            return to_api(null, \$e->getMessage(), 400);
        }
    }
}
PHP);

        $this->line('  ✓ API Controller: <info>'.str_replace(base_path().'/', '', $path).'</info>');
    }

    // ══════════════════════════════════════════════════════════════════════
    // SERVICE PROVIDER REGISTRATION
    // ══════════════════════════════════════════════════════════════════════

    private function registerInServiceProvider(): void
    {
        if (! $this->withEvents) {
            return;
        }

        $path = app_path('Providers/DomainServiceProvider.php');
        $eventsNamespace = $this->domainClassNamespace('Events');
        $listenersNamespace = $this->domainClassNamespace('Listeners');
        $createdEventAlias = $this->providerAlias('Created');
        $updatedEventAlias = $this->providerAlias('Updated');
        $deletedEventAlias = $this->providerAlias('Deleted');
        $logCreatedAlias = $this->providerAlias('LogCreated');
        $logUpdatedAlias = $this->providerAlias('LogUpdated');
        $logDeletedAlias = $this->providerAlias('LogDeleted');

        if (! file_exists($path)) {
            $this->warn('  ⏭  DomainServiceProvider not found');

            return;
        }

        $content = file_get_contents($path);
        $imports = [
            "use {$eventsNamespace}\\{$this->dn}Created as {$createdEventAlias};",
            "use {$eventsNamespace}\\{$this->dn}Deleted as {$deletedEventAlias};",
            "use {$eventsNamespace}\\{$this->dn}Updated as {$updatedEventAlias};",
            "use {$listenersNamespace}\\Log{$this->dn}Created as {$logCreatedAlias};",
            "use {$listenersNamespace}\\Log{$this->dn}Deleted as {$logDeletedAlias};",
            "use {$listenersNamespace}\\Log{$this->dn}Updated as {$logUpdatedAlias};",
        ];

        foreach ($imports as $import) {
            $content = $this->ensureUseImport($content, $import);
        }

        $eventLines = [
            "        // ── {$this->domainPath} Events ─────────────────────────────────────────────",
            "        Event::listen({$createdEventAlias}::class, {$logCreatedAlias}::class);",
            "        Event::listen({$updatedEventAlias}::class, {$logUpdatedAlias}::class);",
            "        Event::listen({$deletedEventAlias}::class, {$logDeletedAlias}::class);",
        ];

        $content = $this->ensureBootLines($content, $eventLines);

        file_put_contents($path, $content);
        $this->line('  ✓ DomainServiceProvider: <info>events registered</info>');
    }

    // ══════════════════════════════════════════════════════════════════════
    // ROUTES
    // ══════════════════════════════════════════════════════════════════════

    private function appendAdminRoutes(): void
    {
        $path = base_path("routes/web/{$this->dnPSnake}-route.php");
        $controllerNamespace = $this->controllerNamespace('Admin');

        $dtApiRoute = $this->vueMode === 'full'
            ? "\n    Route::get('dt', 'dtApi')->name('dtApi');"
            : '';

        $routeParam = '{'.$this->dnSnake.'}';

        $stub = <<<PHP
<?php

use {$controllerNamespace}\\{$this->dn}Controller;
use Illuminate\Support\Facades\Route;

Route::prefix('{$this->dnPSnake}')->name('{$this->dnPSnake}.')->controller({$this->dn}Controller::class)->group(function () {{$dtApiRoute}
    Route::get('{$routeParam}/data', 'data')->name('data');
});

Route::resource('{$this->dnPSnake}', {$this->dn}Controller::class);

PHP;

        $this->putFile($path, $stub);
        $this->line("  ✓ Admin routes: <info>routes/web/{$this->dnPSnake}-route.php</info>");
    }

    private function appendApiRoutes(): void
    {
        $path = base_path("routes/api/{$this->dnPSnake}-route.php");
        $controllerNamespace = $this->controllerNamespace('Api');

        $stub = <<<PHP
<?php

use {$controllerNamespace}\\{$this->dn}Controller;
use Illuminate\Support\Facades\Route;

Route::apiResource('{$this->dnPSnake}', {$this->dn}Controller::class);

PHP;

        $this->putFile($path, $stub);
        $this->line("  ✓ API routes: <info>routes/api/{$this->dnPSnake}-route.php</info>");
    }

    // ══════════════════════════════════════════════════════════════════════
    // TYPE DEFINITION
    // ══════════════════════════════════════════════════════════════════════

    private function createTypeDefinition(): void
    {
        $typePath = resource_path("js/types/{$this->dnSnake}.ts");
        $indexPath = resource_path('js/types/index.ts');

        $resourceFields = collect($this->fields)
            ->map(fn ($f) => "    {$f['name']}: {$this->fieldToTsType($f['type'])};")
            ->implode("\n");

        $stub = <<<TS
export interface {$this->dn} {
    id: string;
{$resourceFields}
    created_at: string;
    updated_at: string;
}

TS;

        $this->putFile($typePath, $stub);

        // Add re-export to index.ts
        $exportLine = "export type { {$this->dn} } from './{$this->dnSnake}';";

        if (file_exists($indexPath)) {
            $indexContent = file_get_contents($indexPath);

            if (! str_contains($indexContent, $exportLine)) {
                // Find the last export type line and add after it
                $lines = explode("\n", $indexContent);
                $lastExportIndex = -1;

                foreach ($lines as $i => $line) {
                    if (str_starts_with(trim($line), 'export type {')) {
                        $lastExportIndex = $i;
                    }
                }

                if ($lastExportIndex >= 0) {
                    array_splice($lines, $lastExportIndex + 1, 0, [$exportLine]);
                } else {
                    $lines[] = $exportLine;
                }

                file_put_contents($indexPath, implode("\n", $lines));
            }
        }

        $this->line("  ✓ Type definition: <info>resources/js/types/{$this->dnSnake}.ts</info>");
    }

    // ══════════════════════════════════════════════════════════════════════
    // VUE PAGES
    // ══════════════════════════════════════════════════════════════════════

    private function createVuePages(): void
    {
        $baseDir = resource_path("js/pages/{$this->inertiaPagePath('')}");

        if ($this->vueMode === 'empty') {
            $this->createEmptyVuePage($baseDir);

            return;
        }

        $this->createFullVuePages($baseDir);
    }

    private function createEmptyVuePage(string $baseDir): void
    {
        $pagePath = "{$baseDir}/Index.vue";
        $title = $this->dnPSnake;

        $stub = <<<VUE
<script setup lang="ts">
    import AdminLayout from '@/layouts/AdminLayout.vue';
</script>

<template>
    <AdminLayout title="{$title}">
        <div />
    </AdminLayout>
</template>

VUE;

        $this->putFile($pagePath, $stub);
        $this->line("  ✓ Vue (empty): <info>resources/js/pages/{$this->inertiaPagePath('Index.vue')}</info>");
    }

    private function createFullVuePages(string $baseDir): void
    {
        $v = $this->dnSnake;
        $rn = $this->dnPSnake;
        $dn = $this->dn;
        $dnCamel = Str::camel($this->dn);
        $routeImport = Str::camel($this->dnPSnake);
        $formComponent = "{$dn}Form";
        $formImportPath = "@/pages/{$this->inertiaPagePath("components/{$formComponent}.vue")}";
        $routeImportPath = "@/routes/{$rn}";
        $refreshKey = "{$rn}-table";
        $dtColumns = $this->buildDatatableColumns($dn);
        $formFields = $this->buildFormFields();

        // ── Index.vue ────────────────────────────────────────────────
        $this->putFile("{$baseDir}/Index.vue", <<<VUE
<script setup lang="ts">
    import { DB } from '@lvntr/components/DatatableBuilder/core';
    import { useConfirm } from '@/composables/useConfirm';
    import { useDialog } from '@/composables/useDialog';
    import { useRefreshBus } from '@/composables/useRefreshBus';
    import AdminLayout from '@/layouts/AdminLayout.vue';
    import type { {$dn} } from '@/types';
    import { router } from '@inertiajs/vue3';
    import { trans } from 'laravel-vue-i18n';

    import {$formComponent} from '{$formImportPath}';
    import {$routeImport} from '{$routeImportPath}';
    import { Button } from 'primevue';

    const { confirmDelete } = useConfirm();
    const dialog = useDialog();
    const bus = useRefreshBus();

    const REFRESH_KEY = '{$refreshKey}';

    function openCreateDialog() {
        dialog.open({$formComponent}, { inDialog: true }, trans('{$rn}.create'), {
            refreshKey: REFRESH_KEY,
        });
    }

    function openEditDialog({$dnCamel}Id: string) {
        dialog.open({$formComponent}, { {$dnCamel}Id, inDialog: true }, trans('{$rn}.edit'), {
            refreshKey: REFRESH_KEY,
        });
    }

    function deleteRecord({$dnCamel}: {$dn}) {
        confirmDelete(
            () => {
                router.delete({$routeImport}.destroy.url({$dnCamel}), {
                    onSuccess: () => bus.refresh(REFRESH_KEY),
                });
            },
        );
    }

    const tableConfig = DB.table<{$dn}>()
        .route({$routeImport}.dtApi.url())
        .sortable(true)
        .addColumns(
{$dtColumns}
        )
        .addActions(
            DB.action<{$dn}>()
                .icon('pi pi-pencil')
                .severity('warn')
                .label('button.edit')
                .handle(({$dnCamel}) => openEditDialog({$dnCamel}.id)),
        )
        .addMenuActions(
            DB.menuAction<{$dn}>()
                .label('button.edit')
                .icon('pi pi-pencil')
                .handle(({$dnCamel}) => openEditDialog({$dnCamel}.id)),
            DB.menuAction<{$dn}>()
                .label('button.edit_on_page')
                .icon('pi pi-external-link')
                .handle(({$dnCamel}) => router.visit({$routeImport}.edit.url({$dnCamel}))),
            DB.menuAction<{$dn}>()
                .label('button.delete')
                .icon('pi pi-trash')
                .separator()
                .handle(({$dnCamel}) => deleteRecord({$dnCamel})),
        )
        .build();
</script>

<template>
    <AdminLayout title="{$rn}">
        <template #page-actions>
            <Button label="Ekle" icon="pi pi-plus" @click="openCreateDialog" />
        </template>
        <SkDatatable :config="tableConfig" :refresh-key="REFRESH_KEY" />
    </AdminLayout>
</template>

VUE);

        // ── Create.vue ───────────────────────────────────────────────
        $this->putFile("{$baseDir}/Create.vue", <<<VUE
<script setup lang="ts">
    import AdminLayout from '@/layouts/AdminLayout.vue';
    import {$formComponent} from '{$formImportPath}';
</script>

<template>
    <AdminLayout title="{$rn}" :back-url="true">
        <{$formComponent} />
    </AdminLayout>
</template>

VUE);

        // ── Edit.vue ─────────────────────────────────────────────────
        $this->putFile("{$baseDir}/Edit.vue", <<<VUE
<script setup lang="ts">
    import AdminLayout from '@/layouts/AdminLayout.vue';
    import {$formComponent} from '{$formImportPath}';

    interface Props {
        {$dnCamel}Id: string;
    }

    defineProps<Props>();
</script>

<template>
    <AdminLayout title="{$rn}" :subtitle="{$dnCamel}Id" :back-url="true">
        <{$formComponent} :{$v}-id="{$dnCamel}Id" />
    </AdminLayout>
</template>

VUE);

        // ── components/Form.vue ──────────────────────────────────────
        $this->putFile("{$baseDir}/components/{$formComponent}.vue", <<<VUE
<script setup lang="ts">
    import { FB } from '@lvntr/components/FormBuilder/core';
    import SkForm from '@lvntr/components/FormBuilder/SkForm.vue';
    import {$routeImport} from '{$routeImportPath}';

    interface Props {
        {$dnCamel}Id?: string | null;
        inDialog?: boolean;
    }

    const props = withDefaults(defineProps<Props>(), {
        {$dnCamel}Id: null,
        inDialog: false,
    });

    const emit = defineEmits<{
        success: [];
        cancel: [];
    }>();

    const formRef = ref<InstanceType<typeof SkForm>>();
    const isEdit = computed(() => !!props.{$dnCamel}Id);

    const formConfig = computed(() => {
        const builder = FB.form()
            .layout('vertical')
            .cols(2)
            .submit({
                url: isEdit.value ? {$routeImport}.update.url(props.{$dnCamel}Id!) : {$routeImport}.store.url(),
                method: isEdit.value ? 'put' : 'post',
            })
            .inDialog(props.inDialog)
            .actionsPosition('bottom');

        if (isEdit.value) {
            builder.dataUrl({$routeImport}.data.url(props.{$dnCamel}Id!)).dataKey('{$v}');
        }

        return builder
            .addFields(
{$formFields}
            )
            .build();
    });

    defineExpose({ reset: () => formRef.value?.reset() });
</script>

<template>
    <SkForm ref="formRef" :config="formConfig" @success="emit('success')" @cancel="emit('cancel')" />
</template>

VUE);

        $this->line('  ✓ Vue (full): <info>Index, Create, Edit, '.$formComponent.'</info>');
    }

    // ══════════════════════════════════════════════════════════════════════
    // DATATABLE QUERY
    // ══════════════════════════════════════════════════════════════════════

    private function createDatatableQuery(): void
    {
        $dir = "{$this->domainBasePath()}/Queries";
        $namespace = $this->domainClassNamespace('Queries');
        $modelClass = $this->modelClass();
        $resourceNs = $this->adminResourceNamespace();

        $fieldNames = collect($this->fields)->map(fn ($f) => "'{$f['name']}'")->implode(', ');
        $sortableFields = collect($this->fields)->map(fn ($f) => "'{$f['name']}'")->implode(', ');

        $this->putFile("{$dir}/{$this->dn}DatatableQuery.php", <<<PHP
<?php

namespace {$namespace};

use {$resourceNs}\\{$this->dn}Resource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\DatatableQueryBuilder;
use {$modelClass};

class {$this->dn}DatatableQuery
{
    public function response(): ApiResponse
    {
        return DatatableQueryBuilder::for({$this->dn}::query())
            ->searchable(['id', {$fieldNames}])
            ->sortable(['id', {$sortableFields}, 'created_at'])
            ->filterable([])
            ->defaultSort('-created_at')
            ->resource({$this->dn}Resource::class)
            ->response();
    }
}
PHP);

        $this->line("  ✓ DatatableQuery: <info>app/Domain/{$this->domainPath}/Queries/{$this->dn}DatatableQuery.php</info>");
    }

    // ══════════════════════════════════════════════════════════════════════
    // ADMIN RESOURCE
    // ══════════════════════════════════════════════════════════════════════

    private function createAdminResource(): void
    {
        $namespace = $this->adminResourceNamespace();
        $dir = $this->adminResourceDirectory();
        $modelClass = $this->modelClass();

        $resourceFields = collect($this->fields)
            ->map(fn ($f) => "            '{$f['name']}' => \$this->{$f['name']},")
            ->implode("\n");

        $this->putFile("{$dir}/{$this->dn}Resource.php", <<<PHP
<?php

namespace {$namespace};

use {$modelClass};
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin {$this->dn}
 */
class {$this->dn}Resource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request \$request): array
    {
        return [
            'id' => \$this->id,
{$resourceFields}
            'created_at' => format_date(\$this->created_at),
            'updated_at' => format_date(\$this->updated_at),
        ];
    }
}
PHP);

        $this->line('  ✓ Admin Resource: <info>'.str_replace(base_path().'/', '', "{$dir}/{$this->dn}Resource.php").'</info>');
    }

    // ══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════

    private function modelPath(): string
    {
        return app_path("Models/{$this->domainPath}.php");
    }

    private function modelClass(): string
    {
        return "App\\Models\\{$this->domainNamespace}";
    }

    private function modelNamespace(): string
    {
        $segments = $this->modelNamespaceSegments();

        return implode('\\', $segments);
    }

    /**
     * @return list<string>
     */
    private function modelNamespaceSegments(): array
    {
        return array_merge(['App', 'Models'], array_slice($this->domainSegments, 0, -1));
    }

    private function domainBasePath(): string
    {
        return app_path("Domain/{$this->domainPath}");
    }

    private function domainClassNamespace(string $suffix = ''): string
    {
        return 'App\\Domain\\'.$this->domainNamespace.($suffix !== '' ? "\\{$suffix}" : '');
    }

    private function requestDirectory(string $layer): string
    {
        return app_path("Http/Requests/{$layer}/{$this->domainPath}");
    }

    private function requestNamespace(string $layer): string
    {
        return 'App\\Http\\Requests\\'.$layer.'\\'.$this->domainNamespace;
    }

    private function controllerDirectory(string $layer): string
    {
        $nestedPath = $this->domainDirectoryPath();

        return app_path(
            trim("Http/Controllers/{$layer}/{$nestedPath}", '/')
        );
    }

    private function controllerPath(string $layer): string
    {
        return $this->controllerDirectory($layer)."/{$this->dn}Controller.php";
    }

    private function controllerNamespace(string $layer): string
    {
        $namespace = "App\\Http\\Controllers\\{$layer}";
        $nestedNamespace = $this->domainDirectoryNamespace();

        return $nestedNamespace === '' ? $namespace : "{$namespace}\\{$nestedNamespace}";
    }

    private function providerAlias(string $suffix): string
    {
        return implode('', $this->domainSegments).$suffix;
    }

    private function adminResourceNamespace(): string
    {
        return 'App\\Http\\Resources\\Admin\\'.$this->domainNamespace;
    }

    private function adminResourceDirectory(): string
    {
        return app_path("Http/Resources/Admin/{$this->domainPath}");
    }

    /**
     * Generate DataTable column lines from fields.
     */
    private function buildDatatableColumns(string $dn): string
    {
        if (! $this->withVueFields || empty($this->fields)) {
            return "            DB.column<{$dn}>().label('common.id').key('id'),";
        }

        $lines = [];

        foreach ($this->fields as $field) {
            $key = $field['name'];
            $type = $field['type'];

            $col = "            DB.column<{$dn}>().key('{$key}')";

            if ($type === 'boolean') {
                $col .= '.enumTag()';
            }

            $lines[] = $col.',';
        }

        return implode("\n", $lines);
    }

    /**
     * Generate FormBuilder field lines from fields.
     */
    private function buildFormFields(): string
    {
        if (! $this->withVueFields || empty($this->fields)) {
            return '                // TODO: add form fields here';
        }

        $lines = [];

        foreach ($this->fields as $field) {
            $key = $field['name'];
            $type = $field['type'];

            $line = match ($type) {
                'integer', 'bigInteger', 'unsignedBigInteger', 'float', 'decimal' => "                FB.inputNumber().key('{$key}'),",
                'boolean' => "                FB.toggleSwitch().key('{$key}'),",
                'text', 'longText' => "                FB.textarea().key('{$key}'),",
                'date' => "                FB.inputText().key('{$key}').inputType('date'),",
                'dateTime', 'timestamp' => "                FB.inputText().key('{$key}').inputType('datetime-local'),",
                default => "                FB.inputText().key('{$key}'),",
            };

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Generate TypeScript type for a field.
     */
    private function fieldToTsType(string $type): string
    {
        return match ($type) {
            'integer', 'bigInteger', 'unsignedBigInteger', 'float', 'decimal' => 'number',
            'boolean' => 'boolean',
            default => 'string',
        };
    }

    private function inertiaPagePath(string $page): string
    {
        $segments = array_merge(
            ['Admin'],
            array_slice($this->domainSegments, 0, -1),
            [$this->dnPlural, $page]
        );

        return implode('/', $segments);
    }

    private function domainDirectoryPath(): string
    {
        return implode('/', array_slice($this->domainSegments, 0, -1));
    }

    private function domainDirectoryNamespace(): string
    {
        return implode('\\', array_slice($this->domainSegments, 0, -1));
    }

    private function putFile(string $path, string $content): void
    {
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_exists($path)) {
            $this->warn('  ⏭  Already exists: '.str_replace(base_path().'/', '', $path));

            return;
        }

        file_put_contents($path, $content);
    }

    private function ensureUseImport(string $content, string $import): string
    {
        if (str_contains($content, $import)) {
            return $content;
        }

        return preg_replace(
            '/(use Illuminate\\\\Support\\\\Facades\\\\Route;|use Illuminate\\\\Support\\\\Facades\\\\Event;)/',
            $import."\n$1",
            $content,
            1
        ) ?? $content;
    }

    /**
     * @param  list<string>  $lines
     */
    private function ensureBootLines(string $content, array $lines): string
    {
        $missingLines = array_values(array_filter($lines, fn (string $line): bool => ! str_contains($content, $line)));

        if ($missingLines === []) {
            return $content;
        }

        return preg_replace_callback(
            '/public function boot\(\): void\s*\{\n(?P<body>[\s\S]*?)^\s*\}/m',
            function (array $matches) use ($missingLines): string {
                $body = rtrim($matches['body'], "\n");
                $body .= ($body === '' ? '' : "\n").implode("\n", $missingLines)."\n";

                return "public function boot(): void\n    {\n{$body}    }";
            },
            $content,
            1
        ) ?? $content;
    }

    private function phpType(string $type): string
    {
        return match ($type) {
            'integer', 'int', 'bigInteger', 'unsignedBigInteger' => 'int',
            'float', 'double', 'decimal' => 'float',
            'boolean', 'bool' => 'bool',
            default => 'string',
        };
    }

    private function valType(string $type): string
    {
        return match ($type) {
            'integer', 'int', 'bigInteger', 'unsignedBigInteger' => 'integer',
            'float', 'double', 'decimal' => 'numeric',
            'boolean', 'bool' => 'boolean',
            default => 'string',
        };
    }

    private function isValidDomainName(string $raw): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_-]*(?:[\/\\\\][A-Za-z][A-Za-z0-9_-]*)*$/', $raw) === 1;
    }

    private function hasField(string $name): bool
    {
        return collect($this->fields)->contains(fn (array $field) => $field['name'] === $name);
    }

    private function buildFormRequestImports(bool $updating): string
    {
        $imports = [];

        if ($this->hasUniqueField()) {
            $imports[] = 'use Illuminate\Validation\Rule;';
        }

        if ($this->hasField('password')) {
            $imports[] = 'use Illuminate\Validation\Rules\Password;';
        }

        if ($imports === []) {
            return '';
        }

        return "\n".implode("\n", $imports);
    }

    private function hasUniqueField(): bool
    {
        return collect($this->fields)->contains(fn (array $field) => $this->shouldUseUniqueRule($field['name']));
    }

    private function shouldUseUniqueRule(string $fieldName): bool
    {
        return $fieldName === 'email' || str_ends_with($fieldName, '_number');
    }

    private function buildRequestRules(string $presence, bool $updating): string
    {
        return collect($this->fields)
            ->map(function (array $field) use ($presence, $updating): string {
                $rules = [$presence];

                if ($field['name'] === 'password') {
                    if ($updating) {
                        $rules = ['nullable', 'confirmed', 'Password::defaults()'];
                    } else {
                        $rules = ['required', 'confirmed', 'Password::defaults()'];
                    }

                    return $this->formatRuleLine($field['name'], $rules);
                }

                $rules[] = $this->valType($field['type']);

                if ($field['name'] === 'email') {
                    $rules[] = 'email';
                }

                if (in_array($field['type'], ['string', 'text', 'longText'], true)) {
                    $rules[] = 'max:255';
                }

                if ($this->shouldUseUniqueRule($field['name'])) {
                    $rules[] = $updating
                        ? "Rule::unique('{$this->dnPSnake}')->ignore(\$this->route('{$this->dnSnake}'))"
                        : "unique:{$this->dnPSnake}";
                }

                return $this->formatRuleLine($field['name'], $rules);
            })
            ->implode("\n");
    }

    /**
     * @param  list<string>  $rules
     */
    private function formatRuleLine(string $fieldName, array $rules): string
    {
        $formattedRules = collect($rules)
            ->map(fn (string $rule) => str_contains($rule, '::') || str_contains($rule, '(') ? $rule : "'{$rule}'")
            ->implode(', ');

        return "            '{$fieldName}' => [{$formattedRules}],";
    }

    private function attributeLabel(string $fieldName): string
    {
        return match ($fieldName) {
            'name' => 'Name',
            'email' => 'Email',
            'password' => 'Password',
            default => Str::title(str_replace('_', ' ', $fieldName)),
        };
    }

    /** @return array<int, array{string, string}> */
    private function getFileSummaryTable(): array
    {
        $rows = [
            ['Model', "app/Models/{$this->domainPath}.php"],
            ['Migration', "database/migrations/xxx_create_{$this->dnPSnake}_table.php"],
            ['Factory', "database/factories/{$this->domainPath}Factory.php"],
            ['DTO', "app/Domain/{$this->domainPath}/DTOs/{$this->dn}DTO.php"],
            ['Actions', "app/Domain/{$this->domainPath}/Actions/ (Create, Update, Delete)"],
        ];

        if ($this->withEvents) {
            $rows[] = ['Events', "app/Domain/{$this->domainPath}/Events/ (Created, Updated, Deleted)"];
            $rows[] = ['Listeners', "app/Domain/{$this->domainPath}/Listeners/ (Log*)"];
            $rows[] = ['Provider', 'app/Providers/DomainServiceProvider.php (updated)'];
        }

        if ($this->withAdmin) {
            $rows[] = ['Admin Controller', str_replace(base_path().'/', '', $this->controllerPath('Admin'))];
            $rows[] = ['Admin Requests', "app/Http/Requests/Admin/{$this->domainPath}/ (Store, Update)"];
            $rows[] = ['Admin Routes', "routes/web/{$this->dnPSnake}-route.php"];
        }

        if ($this->withApi) {
            $rows[] = ['API Controller', str_replace(base_path().'/', '', $this->controllerPath('Api'))];
            $rows[] = ['API Requests', "app/Http/Requests/Api/{$this->domainPath}/ (Store, Update)"];
            $rows[] = ['API Routes', "routes/api/{$this->dnPSnake}-route.php"];
        }

        if ($this->vueMode === 'empty') {
            $rows[] = ['Type Definition', "resources/js/types/{$this->dnSnake}.ts"];
            $rows[] = ['Vue Pages', "resources/js/pages/{$this->inertiaPagePath('Index.vue')}"];
        } elseif ($this->vueMode === 'full') {
            $rows[] = ['Type Definition', "resources/js/types/{$this->dnSnake}.ts"];
            $rows[] = ['Vue Pages', "resources/js/pages/{$this->inertiaPagePath('')} (Index, Create, Edit, Form)"];
            $rows[] = ['DatatableQuery', "app/Domain/{$this->domainPath}/Queries/{$this->dn}DatatableQuery.php"];
            $rows[] = ['Admin Resource', "app/Http/Resources/Admin/{$this->domainPath}/{$this->dn}Resource.php"];
        }

        return $rows;
    }
}
