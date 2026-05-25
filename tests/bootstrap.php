<?php
declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (! file_exists($autoload)) {
    fwrite(STDERR, "Composer autoload not found. Run composer install before tests.\n");
    exit(1);
}

require_once $autoload;
