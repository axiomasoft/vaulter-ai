<?php

declare(strict_types=1);

namespace Vaulter\Ai\Collaboration\Tests\Support;

use Vaulter\Ai\Collaboration\CollaborationAiServiceProvider;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Ai\Tests\Support\TestActor;
use Vaulter\Collaboration\VaulterCollaborationServiceProvider;

abstract class LiveJournalTestCase extends OfficeTestCase
{
    protected bool $strictProfile = true;

    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            VaulterCollaborationServiceProvider::class,
            CollaborationAiServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('auth.providers.users.model', TestActor::class);
        $app['config']->set('vaulter.modules.collaboration', true);
        $app['config']->set('vaulter-collaboration.strict_profile', $this->strictProfile);
        $app['config']->set('vaulter-collaboration.hub.jwt_keys', ['k1' => 'test-collab-jwt-secret-key-at-least-32-bytes']);
        $app['config']->set('vaulter-collaboration.hub.jwt_active_kid', 'k1');
        $app['config']->set('vaulter-collaboration.hub.webhook_secret', 'test-webhook-secret-different-value');
    }
}
