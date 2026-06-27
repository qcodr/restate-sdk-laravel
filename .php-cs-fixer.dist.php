<?php

declare(strict_types=1);

/**
 * Strict coding-standard ruleset for the Restate Laravel integration — identical to the
 * Restate PHP SDK's: PSR-12 + the current PHP migration set, then risky rules enforcing
 * declared strict types, strict comparisons, strict params, and namespaced native calls.
 */
$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/config'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PHP82Migration' => true,
        'declare_strict_types' => true,
        'strict_param' => true,
        'strict_comparison' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha', 'imports_order' => ['class', 'function', 'const']],
        'no_unused_imports' => true,
        'global_namespace_import' => ['import_classes' => true, 'import_functions' => false, 'import_constants' => false],
        // @internal (not @all) so native PHP calls are namespaced for strictness, while
        // Laravel global helpers (app(), env(), config(), …) stay unqualified — Larastan's
        // dynamic-return extensions only type the unqualified helper calls.
        'native_function_invocation' => ['include' => ['@internal'], 'scope' => 'all', 'strict' => true],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'no_superfluous_phpdoc_tags' => false,
        'phpdoc_to_comment' => false,
    ])
    ->setFinder($finder);
