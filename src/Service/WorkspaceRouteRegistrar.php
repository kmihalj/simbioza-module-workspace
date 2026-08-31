<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use AaiEduHr\SimbiozaModuleWorkspace\Controller\WorkspaceController;
use AaiEduHr\SimbiozaModuleWorkspace\Controller\WorkspaceShortsController;
use HeartPhrame\Routing\Routes;

use function ltrim;
use function str_starts_with;
use function strtok;
use function strtolower;
use function trim;

final readonly class WorkspaceRouteRegistrar
{
    /**
     * HR: Prima router i konfiguraciju za kasnu registraciju javnih Workspace URL-ova.
     * EN: Receives the router and configuration for late public Workspace URL registration.
     */
    public function __construct(
        private WorkspaceConfig $config,
        private Routes $routes,
    ) {
    }

    /**
     * HR: Registrira područje i stranicu tek nakon svih fiksnih ruta drugih modula.
     * EN: Registers workspace and page routes only after all fixed routes from other modules.
     */
    public function register(): void
    {
        $rootPath = $this->config->rootPath();
        if ($rootPath === '' || $this->pathConflicts($rootPath)) {
            return;
        }

        $this->routes->addRoute(
            'GET',
            '/' . $rootPath . '/{workspaceSlug}',
            WorkspaceController::class . '@show',
            'workspace.show',
            [],
        );
        $this->routes->addRoute(
            'GET',
            '/' . $rootPath . '/{workspaceSlug}/shorts',
            WorkspaceShortsController::class . '@index',
            'workspace.shorts',
            [],
        );
        $this->routes->addRoute(
            'GET',
            '/' . $rootPath . '/{workspaceSlug}/{nodeSlug}',
            WorkspaceController::class . '@showNode',
            'workspace.node.show',
            [],
        );
    }

    /**
     * HR: Provjerava zauzima li drugi GET endpoint isti prvi segment.
     * EN: Checks whether another GET endpoint occupies the same first segment.
     */
    private function pathConflicts(string $rootPath): bool
    {
        foreach ($this->routes->getNamedRoutes() as $name => $route) {
            if (str_starts_with((string)$name, 'workspace.')) {
                continue;
            }

            if ((string)($route['method'] ?? '') !== 'GET') {
                continue;
            }

            if ($this->firstRouteSegment((string)($route['path'] ?? '')) === $rootPath) {
                return true;
            }
        }

        return false;
    }

    /**
     * HR: Izdvaja prvi statički segment iz route predloška.
     * EN: Extracts the first static segment from a route template.
     */
    private function firstRouteSegment(string $path): string
    {
        $path = trim($path, '/');
        if ($path === '' || str_starts_with($path, '{')) {
            return '';
        }

        return strtolower(ltrim(strtok($path, '/') ?: '', '/'));
    }
}
