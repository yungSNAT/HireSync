<?php

// Runs inside the EspoCRM container, whose autoloader already maps the installed module.
require '/var/www/html/vendor/autoload.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'PharmacyTests\\';

    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
