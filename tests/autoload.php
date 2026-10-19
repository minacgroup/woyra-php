<?php

declare(strict_types=1);

// Composer's autoloader when `composer install` has run, otherwise a minimal
// PSR-4 loader so the tests run with nothing but PHP.
$vendor = __DIR__ . '/../vendor/autoload.php';
if (is_file($vendor)) {
    require $vendor;
} else {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'Woyra\\')) {
            $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 6)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}
