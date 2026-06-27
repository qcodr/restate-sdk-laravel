<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Filesystem\Filesystem;
use Qcodr\Restate\Laravel\Codegen\ClientWriter;
use Qcodr\Restate\Laravel\RestateManager;
use Throwable;

/**
 * Generates typed, IDE-autocompletable client classes for the services bound to the
 * endpoint — one `{ServiceName}Client` per configured #[Service]/#[VirtualObject]/#[Workflow].
 *
 * The clients turn the stringly-typed `$ctx->serviceCall('Greeter', 'greet', 'world')` into
 * `GreeterClient::fromContext($ctx)->greet('world')`, so caller code gets autocomplete and
 * type checking. Generation itself is the SDK's job (Qcodr\Restate\Sdk\Codegen\ClientGenerator,
 * called via {@see ClientWriter}); this command only resolves *which* services to generate
 * (from {@see RestateManager::serviceClasses()}) and *where* to write them (option > config >
 * the app's `app/Restate/Clients` default), then reports each file written.
 */
final class CodegenCommand extends Command
{
    /** Default namespace and (relative) directory the generated clients land in. */
    private const DEFAULT_NAMESPACE = 'App\\Restate\\Clients';

    /** @var string */
    protected $signature = 'restate:codegen
        {--output= : Directory the generated clients are written to (default app/Restate/Clients)}
        {--namespace= : PHP namespace for the generated clients (default App\\Restate\\Clients)}';

    /** @var string */
    protected $description = 'Generate typed, IDE-autocompletable clients for the bound Restate services';

    public function handle(RestateManager $manager, Config $config, Filesystem $files): int
    {
        $classes = $manager->serviceClasses();
        if ($classes === []) {
            $this->warn('No services configured. Add classes to config/restate.php (services).');

            return self::SUCCESS;
        }

        $namespace = $this->resolveNamespace($config);
        $outputDir = $this->resolveOutputDir($config);
        $writer = ClientWriter::forNamespace($namespace, $files);

        $this->info(\sprintf('Generating Restate clients in %s (namespace %s):', $outputDir, $namespace));

        // Generate each service independently: one bad class (not autoloadable, or missing
        // its service attribute) is reported and the rest still generate, but the command
        // exits non-zero so CI/scripts notice the partial failure.
        $failed = false;
        foreach ($classes as $class) {
            try {
                $this->line('  • ' . $writer->write($class, $outputDir));
            } catch (Throwable $e) {
                $failed = true;
                $this->error(\sprintf('  ✗ %s: %s', $class, $e->getMessage()));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Resolves the target namespace: the `--namespace` option wins, then the optional
     * `restate.codegen.namespace` config key, then the built-in default. Leading/trailing
     * backslashes are trimmed so the value composes cleanly into a `namespace` statement.
     */
    private function resolveNamespace(Config $config): string
    {
        $option = $this->option('namespace');
        if (\is_string($option) && $option !== '') {
            return \trim($option, '\\');
        }

        $configured = $config->get('restate.codegen.namespace');
        if (\is_string($configured) && $configured !== '') {
            return \trim($configured, '\\');
        }

        return self::DEFAULT_NAMESPACE;
    }

    /**
     * Resolves the output directory: the `--output` option wins, then the optional
     * `restate.codegen.output` config key, then the app's `app/Restate/Clients`. A relative
     * value is anchored to the application base path so the result is deterministic.
     */
    private function resolveOutputDir(Config $config): string
    {
        $option = $this->option('output');
        if (\is_string($option) && $option !== '') {
            return $this->absolute($option);
        }

        $configured = $config->get('restate.codegen.output');
        if (\is_string($configured) && $configured !== '') {
            return $this->absolute($configured);
        }

        return app_path('Restate/Clients');
    }

    /**
     * Returns $path unchanged when it is already absolute, otherwise anchors it to the
     * application base path so `--output=app/Restate/Clients` behaves the same regardless of
     * the process working directory.
     */
    private function absolute(string $path): string
    {
        return $this->isAbsolute($path) ? $path : base_path($path);
    }

    /** True for a Unix root path or a Windows drive-qualified path. */
    private function isAbsolute(string $path): bool
    {
        return \str_starts_with($path, '/') || \preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }
}
