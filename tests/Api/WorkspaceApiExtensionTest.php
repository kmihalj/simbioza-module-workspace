<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests\Api;

use AaiEduHr\HeartPhrameModuleApi\Contract\ApiRouteRegistry;
use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiAuthenticationMiddleware;
use AaiEduHr\SimbiozaModuleWorkspace\Api\WorkspaceApiExtension;
use HeartPhrame\Routing\Routes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * EN: Verifies the Workspace API declaration.
 *
 * HR: Provjerava Workspace API oglas.
 */
#[CoversClass(WorkspaceApiExtension::class)]
#[CoversClass(ApiRouteRegistry::class)]
final class WorkspaceApiExtensionTest extends TestCase
{
    /**
     * EN: Registers every protected Workspace route.
     *
     * HR: Registrira sve Workspace rute sa zaštitom.
     */
    public function testRegistersOwnedRoutes(): void
    {
        $routes = new Routes();
        (new WorkspaceApiExtension())->register(new ApiRouteRegistry($routes));
        $namedRoutes = $routes->getNamedRoutes();
        $registeredRoutes = $routes->getRoutes();

        $this->assertCount(30, $namedRoutes);
        $this->assertSame(
            '/api/v1/workspaces/{workspaceSlug}/theme/import',
            $namedRoutes['api.v1.workspaces.theme.import']['path'] ?? null,
        );
        $this->assertContains(
            ApiAuthenticationMiddleware::class,
            $registeredRoutes['GET']['/api/v1/workspaces']['middleware'],
        );
    }
}
