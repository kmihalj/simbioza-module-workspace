<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleWorkspace\Tests;

use AaiEduHr\HeartPhrameModuleAuth\Middleware\RequireAuthenticatedUserMiddleware;
use AaiEduHr\HeartPhrameModuleWorkspace\Controller\WorkspaceController;
use AaiEduHr\HeartPhrameModuleWorkspace\Controller\WorkspaceExportController;
use AaiEduHr\HeartPhrameModuleWorkspace\Controller\WorkspaceHomepageController;
use AaiEduHr\HeartPhrameModuleWorkspace\Controller\WorkspaceMenuController;
use AaiEduHr\HeartPhrameModuleWorkspace\Controller\WorkspaceSettingsController;
use AaiEduHr\HeartPhrameModuleWorkspace\Controller\WorkspaceThemeController;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class WorkspaceManifestTest extends TestCase
{
    /**
     * HR: Svaka dokumentirana nadogradnja Workspace sheme mora biti dostupna
     *     kao izravna CLI naredba host aplikaciji.
     * EN: Every documented Workspace schema upgrade must be exposed as a
     *     direct CLI command to the host application.
     */
    public function testManifestRegistersHomepageViewOptionsMigrationCommand(): void
    {
        $manifest = require dirname(__DIR__) . '/heartphrame-manifest.php';
        $commandNames = array_map(
            static fn(object $command): string => $command->getName(),
            $manifest->getCommands(),
        );

        $this->assertContains('workspace:install-homepage-view-options-migration', $commandNames);
        $this->assertContains('workspace:install-node-properties-migration', $commandNames);
    }

    /**
     * HR: Provjerava javni ugovor početne rute bez pokretanja cijele aplikacije.
     * EN: Verifies the initial route contract without booting the complete application.
     */
    public function testManifestRegistersWorkspaceRouteContract(): void
    {
        $manifest = require dirname(__DIR__) . '/heartphrame-manifest.php';
        $routes = $manifest->getBaseRoutes();

        $routesByName = [];
        foreach ($routes as $route) {
            $routesByName[$route[3]] = $route;
        }

        $this->assertCount(42, $routesByName);
        $this->assertSame(
            ['GET', '/workspaces', WorkspaceController::class . '@index', 'workspace.index', []],
            $routesByName['workspace.index'],
        );
        $this->assertSame(
            WorkspaceSettingsController::class . '@index',
            $routesByName['workspace.settings'][2],
        );
        $this->assertSame(
            WorkspaceSettingsController::class . '@maintenance',
            $routesByName['workspace.settings.maintenance'][2],
        );
        $this->assertSame(
            WorkspaceSettingsController::class . '@runMaintenance',
            $routesByName['workspace.settings.maintenance.run'][2],
        );
        $this->assertContains(
            RequireAuthenticatedUserMiddleware::class,
            $routesByName['workspace.settings.maintenance.run'][4],
        );
        $this->assertSame(
            WorkspaceSettingsController::class . '@permanentlyDelete',
            $routesByName['workspace.settings.purge'][2],
        );
        $this->assertContains(
            RequireAuthenticatedUserMiddleware::class,
            $routesByName['workspace.settings.purge'][4],
        );
        $this->assertContains(
            RequireAuthenticatedUserMiddleware::class,
            $routesByName['workspace.manage'][4],
        );
        $this->assertSame(
            WorkspaceController::class . '@searchAclSubjects',
            $routesByName['workspace.acl.subjects'][2],
        );
        $this->assertContains(
            RequireAuthenticatedUserMiddleware::class,
            $routesByName['workspace.acl.subjects'][4],
        );
        $this->assertContains(
            RequireAuthenticatedUserMiddleware::class,
            $routesByName['workspace.node.save'][4],
        );
        $this->assertSame(
            WorkspaceController::class . '@nodeDialog',
            $routesByName['workspace.node.dialog'][2],
        );
        $this->assertContains(
            RequireAuthenticatedUserMiddleware::class,
            $routesByName['workspace.node.dialog'][4],
        );
        $this->assertSame(
            WorkspaceController::class . '@transitionWorkflow',
            $routesByName['workspace.workflow.transition'][2],
        );
        $this->assertContains(
            RequireAuthenticatedUserMiddleware::class,
            $routesByName['workspace.workflow.transition'][4],
        );
        $this->assertSame(
            WorkspaceController::class . '@saveTreeOrder',
            $routesByName['workspace.tree.order.save'][2],
        );
        $this->assertContains(
            RequireAuthenticatedUserMiddleware::class,
            $routesByName['workspace.tree.order.save'][4],
        );
        $this->assertSame(
            WorkspaceController::class . '@createPage',
            $routesByName['workspace.page.create'][2],
        );
        $this->assertContains(
            RequireAuthenticatedUserMiddleware::class,
            $routesByName['workspace.page.create'][4],
        );
        $this->assertSame(
            WorkspaceController::class . '@scripts',
            $routesByName['workspace.assets.js'][2],
        );
        $this->assertContains(
            RequireAuthenticatedUserMiddleware::class,
            $routesByName['workspace.settings'][4],
        );
        $this->assertSame(
            WorkspaceHomepageController::class . '@settings',
            $routesByName['workspace.settings.homepage'][2],
        );
        $this->assertSame(
            WorkspaceHomepageController::class . '@savePreference',
            $routesByName['workspace.homepage.preference.save'][2],
        );
        $this->assertContains(
            RequireAuthenticatedUserMiddleware::class,
            $routesByName['workspace.homepage.preference.save'][4],
        );
        $this->assertSame(
            WorkspaceExportController::class . '@form',
            $routesByName['workspace.export'][2],
        );
        $this->assertSame(
            WorkspaceExportController::class . '@download',
            $routesByName['workspace.export.download'][2],
        );
        $this->assertContains(
            RequireAuthenticatedUserMiddleware::class,
            $routesByName['workspace.export.download'][4],
        );
        $this->assertSame(
            WorkspaceThemeController::class . '@index',
            $routesByName['workspace.theme'][2],
        );
        $this->assertSame(
            WorkspaceThemeController::class . '@save',
            $routesByName['workspace.theme.save'][2],
        );
        $this->assertSame(
            WorkspaceThemeController::class . '@export',
            $routesByName['workspace.theme.export'][2],
        );
        $this->assertContains(
            RequireAuthenticatedUserMiddleware::class,
            $routesByName['workspace.theme.export'][4],
        );
        $this->assertSame(
            WorkspaceThemeController::class . '@asset',
            $routesByName['workspace.theme.asset'][2],
        );
        $this->assertSame([], $routesByName['workspace.theme.asset'][4]);
        $this->assertSame(WorkspaceMenuController::class . '@index', $routesByName['workspace.menu'][2]);
        $this->assertSame(WorkspaceMenuController::class . '@save', $routesByName['workspace.menu.save'][2]);
        $this->assertContains(
            RequireAuthenticatedUserMiddleware::class,
            $routesByName['workspace.menu.save'][4],
        );
    }
}
