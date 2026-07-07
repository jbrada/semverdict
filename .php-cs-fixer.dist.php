<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->append([__FILE__, __DIR__ . '/bin/semverdict', __DIR__ . '/bin/analyze-pair'])
    ->exclude('fixtures');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        '@Symfony' => true,
        'concat_space' => ['spacing' => 'one'],
        'declare_strict_types' => true,
        'global_namespace_import' => ['import_classes' => null, 'import_constants' => false, 'import_functions' => false],
        'native_function_invocation' => false,
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_to_comment' => false,
        'yoda_style' => false,
    ])
    ->setFinder($finder);
