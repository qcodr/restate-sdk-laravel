<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Codegen;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Qcodr\Restate\Sdk\Codegen\ClientGenerator;

/**
 * Thin Laravel-facing adapter over the SDK's {@see ClientGenerator}.
 *
 * The SDK generator produces the *source* of a typed client; this adapter owns the
 * filesystem concern — naming the file from the generated client class, ensuring the target
 * directory exists, and persisting the file. Keeping the I/O here (rather than inside the
 * Artisan command) is deliberate: the command stays a thin orchestrator that only resolves
 * options and reports results, while the write step becomes independently unit-testable and
 * reusable from other call sites without an Artisan context.
 */
final class ClientWriter
{
    public function __construct(
        private readonly ClientGenerator $generator,
        private readonly Filesystem $files,
    ) {
    }

    /**
     * Builds a writer that emits clients under the given PHP namespace.
     *
     * The namespace is fixed for the writer's lifetime because every client produced by one
     * `restate:codegen` run shares a single target namespace (and output directory); binding
     * it once here keeps the per-service {@see write} call free of cross-cutting state.
     */
    public static function forNamespace(string $namespace, Filesystem $files): self
    {
        return new self(new ClientGenerator($namespace), $files);
    }

    /**
     * Generates the typed client for $serviceClass and writes it into $outputDir, returning
     * the absolute path written.
     *
     * Source generation (reflecting the service's #[Service]/#[VirtualObject]/#[Workflow]
     * attributes and handlers) is delegated to the SDK generator; this method only names and
     * persists the file. The directory is created when missing so a first run needs no setup.
     *
     * @param string $serviceClass fully-qualified, autoloadable service class name
     * @param string $outputDir    directory the `{ServiceName}Client.php` file is written to
     *
     * @throws InvalidArgumentException when $serviceClass is not autoloadable
     * @throws \Qcodr\Restate\Sdk\Service\ServiceDefinitionException when it is not a Restate service
     */
    public function write(string $serviceClass, string $outputDir): string
    {
        $source = $this->generator->generate($serviceClass);
        $className = $this->generator->clientClassName($serviceClass);

        $this->files->ensureDirectoryExists($outputDir);

        $path = \rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . $className . '.php';
        $this->files->put($path, $source);

        return $path;
    }
}
