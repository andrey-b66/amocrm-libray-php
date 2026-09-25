<?php

declare(strict_types=1);

// PSR-12 для кода и тестов; без кэша, чтобы в корне не появлялся .php-cs-fixer.cache.
$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests']);

return (new PhpCsFixer\Config())
    ->setRules(['@PSR12' => true])
    ->setUsingCache(false)
    ->setFinder($finder);
