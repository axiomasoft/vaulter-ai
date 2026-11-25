<?php

declare(strict_types=1);

use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\Foundation\Application as Testbench;
use Vaulter\Ai\AgenTools\AgenToolsOfficeExecutor;
use Vaulter\Ai\AgenTools\AgenToolsServiceProvider;
use Vaulter\Ai\Collaboration\CollaborationAiServiceProvider;
use Vaulter\Ai\Core\OfficeServiceProvider;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Documents\DocumentsAiServiceProvider;
use Vaulter\Ai\LaravelAi\LaravelAiServiceProvider;
use Vaulter\Ai\LaravelAi\LaravelOfficeExecutor;
use Vaulter\Ai\Sheets\SheetsAiServiceProvider;
use Vaulter\Collaboration\VaulterCollaborationServiceProvider;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Sheets\VaulterSheetsServiceProvider;
use Vaulter\VaulterServiceProvider;

/*
 * A fresh host runs `php artisan key:generate` before it has an APP_KEY, and Larastan boots the
 * packages the same way. Booting every provider must therefore not build anything that needs the
 * encrypter; executors are only constructed when a run asks for them.
 */
test('every vaulter-ai provider boots before the host has an application key', function (): void {
    $previous = getenv('APP_KEY');
    $setKey = static function (string|false $value): void {
        putenv($value === false ? 'APP_KEY' : 'APP_KEY='.$value);
        if ($value === false) {
            unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);
        } else {
            $_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = $value;
        }
    };

    $setKey('');

    try {
        $app = Testbench::create(options: ['extra' => [
            'dont-discover' => ['*'],
            'providers' => [
                VaulterServiceProvider::class,
                VaulterDriveServiceProvider::class,
                VaulterMediaServiceProvider::class,
                VaulterDocumentsServiceProvider::class,
                VaulterSheetsServiceProvider::class,
                VaulterCollaborationServiceProvider::class,
                OfficeServiceProvider::class,
                DocumentsAiServiceProvider::class,
                SheetsAiServiceProvider::class,
                CollaborationAiServiceProvider::class,
                AiServiceProvider::class,
                LaravelAiServiceProvider::class,
                AgenToolsServiceProvider::class,
            ],
        ]]);

        expect($app->isBooted())->toBeTrue()
            ->and($app['config']->get('app.key'))->toBe('');

        $registry = $app->make(OfficeRegistry::class);

        expect($registry->isFrozen())->toBeTrue()
            ->and(static fn () => $registry->executor(AgenToolsOfficeExecutor::KEY))->toThrow(MissingAppKeyException::class)
            ->and(static fn () => $registry->executor(LaravelOfficeExecutor::KEY))->toThrow(MissingAppKeyException::class);

        $app->flush();
    } finally {
        // The standalone application installs Laravel's error handlers; PHPUnit flags a test that leaves them behind.
        HandleExceptions::flushState($this);
        $setKey($previous);
    }
});
