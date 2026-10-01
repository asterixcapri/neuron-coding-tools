<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS3x0' => true,
        '@PHP8x1Migration' => true,
        'binary_operator_spaces' => true,
        'braces_position' => true,
        'declare_strict_types' => true,
        'fully_qualified_strict_types' => ['import_symbols' => true],
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => true,
            'import_functions' => true,
        ],
        'method_argument_space' => true,
        'native_constant_invocation' => true,
        'native_function_invocation' => [
            'scope' => 'all',
            'include' => ['@internal', '@compiler_optimized'],
        ],
        'no_unused_imports' => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'ordered_imports' => [
            'imports_order' => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ],
        'whitespace_after_comma_in_array' => true,
    ])
    ->setFinder((new Finder())->in(__DIR__)->append([__FILE__]));
