<?php

declare(strict_types=1);
use Vaulter\Collaboration\Persistence\CommandJournal;

$collabSrc = dirname(__DIR__, 3).'/vaulter-collaboration/packages/collaboration/src';

if (is_dir($collabSrc)) {
    spl_autoload_register(static function (string $class) use ($collabSrc): void {
        $prefix = 'Vaulter\\Collaboration\\';
        if (! str_starts_with($class, $prefix)) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        $file = $collabSrc.'/'.$relative;
        if (is_file($file)) {
            require_once $file;
        }
    });
} elseif (! class_exists(CommandJournal::class)) {
    require_once __DIR__.'/stubs/vaulter-collaboration.php';
}
