<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Discovery;

use FilesystemIterator;
use Qcodr\Restate\Sdk\Service\Attribute\Service;
use Qcodr\Restate\Sdk\Service\Attribute\VirtualObject;
use Qcodr\Restate\Sdk\Service\Attribute\Workflow;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Discovers Restate handler classes on disk so users do not have to hand-list every
 * service in config.
 *
 * Given a directory and the PSR-4 namespace it maps to, {@see scan} returns the
 * fully-qualified names of the classes carrying a `#[Service]`, `#[VirtualObject]`, or
 * `#[Workflow]` attribute — exactly the building block {@see \Qcodr\Restate\Laravel\RestateManager}
 * binds when a `discover` config key points at a directory (e.g. `app/Restate`).
 *
 * The file→FQCN mapping is purely structural (PSR-4): no file is parsed or executed beyond
 * the autoloader resolving the class, and the result is sorted so discovery order is stable
 * across filesystems and deploys.
 */
final class ServiceScanner
{
    /**
     * The class-level attributes that mark a class as a bindable Restate handler.
     *
     * @var list<class-string>
     */
    private const HANDLER_ATTRIBUTES = [
        Service::class,
        VirtualObject::class,
        Workflow::class,
    ];

    /**
     * Scans $directory recursively for PHP classes attributed as Restate services.
     *
     * A PSR-4 root maps $directory to $namespace, so each file's path relative to that root
     * becomes the trailing namespace segments. Only classes whose own attribute set includes
     * a Restate marker are returned; abstract classes are skipped (they cannot be bound). A
     * missing directory yields an empty list rather than an error, so an unconfigured
     * `discover` path is a no-op.
     *
     * @param string $directory the absolute directory to scan (the PSR-4 root)
     * @param string $namespace the namespace that $directory maps to
     *
     * @return list<class-string> the attributed class names, sorted ascending
     */
    public function scan(string $directory, string $namespace): array
    {
        if (!\is_dir($directory)) {
            return [];
        }

        /** @var list<class-string> $found */
        $found = [];
        foreach ($this->phpFiles($directory) as $file) {
            $class = $this->classFor($file, $directory, $namespace);
            if ($class !== null && $this->isHandlerClass($class)) {
                $found[] = $class;
            }
        }

        \sort($found, \SORT_STRING);

        return $found;
    }

    /**
     * Yields every `.php` file beneath $directory, recursively.
     *
     * @return iterable<SplFileInfo>
     */
    private function phpFiles(string $directory): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && \strtolower($file->getExtension()) === 'php') {
                yield $file;
            }
        }
    }

    /**
     * Resolves a file to its fully-qualified class name from the PSR-4 mapping, or null when
     * the path cannot be resolved or the expected class is not actually declared in the file.
     *
     * Requiring the class to exist (the autoloader loads it) means a file whose contents do
     * not match its PSR-4 path is silently skipped rather than poisoning discovery.
     *
     * @return class-string|null
     */
    private function classFor(SplFileInfo $file, string $directory, string $namespace): ?string
    {
        $realDirectory = \realpath($directory);
        $realFile = $file->getRealPath();
        if ($realDirectory === false || $realFile === false) {
            return null;
        }

        $relativePath = \substr($realFile, \strlen($realDirectory) + 1);
        $relativeClass = \str_replace(['/', '\\'], '\\', \substr($relativePath, 0, -\strlen('.php')));
        $class = \trim($namespace, '\\') . '\\' . $relativeClass;

        return \class_exists($class) ? $class : null;
    }

    /**
     * Whether $class is a concrete class carrying one of the Restate handler attributes.
     *
     * @param class-string $class
     */
    private function isHandlerClass(string $class): bool
    {
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract()) {
            return false;
        }

        foreach (self::HANDLER_ATTRIBUTES as $attribute) {
            if ($reflection->getAttributes($attribute) !== []) {
                return true;
            }
        }

        return false;
    }
}
