<?php

declare(strict_types=1);

$packageRoot = dirname(__DIR__);
$monorepoRoot = dirname($packageRoot, 3);

$loader = require $monorepoRoot.'/vendor/autoload.php';

$composer = json_decode(
    (string) file_get_contents($packageRoot.'/composer.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);

foreach ($composer['autoload-dev']['psr-4'] ?? [] as $namespace => $path) {
    $loader->addPsr4($namespace, $packageRoot.'/'.trim($path, '/').'/', true);
}

require $monorepoRoot.'/tests/bootstrap.php';
