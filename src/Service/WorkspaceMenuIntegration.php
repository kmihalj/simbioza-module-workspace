<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use Psr\Container\ContainerInterface;
use Throwable;

use function is_callable;
use function is_object;
use function method_exists;

final readonly class WorkspaceMenuIntegration
{
    private const MENU_PACKAGE = 'aaieduhr/heartphrame-module-menu';

    private const MENU_REPOSITORY = 'AaiEduHr\\HeartPhrameModuleMenu\\Service\\MenuConfigRepository';

    private const MENU_TARGET_REGISTRY =
    'AaiEduHr\\HeartPhrameModuleMenu\\Extension\\MenuNavigationTargetRegistry';

    /**
     * HR: Prima container i konfiguraciju za potpuno opcionalnu module-menu integraciju.
     * EN: Receives the container and configuration for fully optional module-menu integration.
     */
    public function __construct(
        private ContainerInterface $container,
        private WorkspaceConfig $config,
    ) {
    }

    /**
     * HR: Dodaje glavnu i administratorske Workspace stavke kada je menu modul dostupan.
     * EN: Adds main and administration Workspace entries when the menu module is available.
     */
    public function registerMenuItems(): void
    {
        if (
            !$this->config->isAppModuleEnabled(self::MENU_PACKAGE)
            || !class_exists(self::MENU_REPOSITORY)
        ) {
            return;
        }

        try {
            $repository = $this->container->get(self::MENU_REPOSITORY);
        } catch (Throwable) {
            return;
        }

        if (!is_object($repository) || !method_exists($repository, 'upsertItemsForSection')) {
            return;
        }

        if ($this->config->shouldAutoRegisterTopMenu()) {
            $this->upsertSection($repository, 'top', [[
                'id' => 'workspaces',
                'parent_id' => '',
                'label' => ['hr' => 'Područja', 'en' => 'Workspaces'],
                'route' => 'workspace.index',
                'url' => '',
                'query' => '',
                'order' => 45,
                'enabled' => true,
                'level' => 0,
            ]]);
        }

        if ($this->config->shouldAutoRegisterSettingsMenu()) {
            $this->upsertSection($repository, 'settings', [
                [
                    'id' => 'workspace.settings.group',
                    'parent_id' => '',
                    'label' => ['hr' => 'Područja', 'en' => 'Workspaces'],
                    'route' => '',
                    'url' => '',
                    'query' => '',
                    'order' => 55,
                    'enabled' => true,
                    'level' => 0,
                ],
                [
                    'id' => 'workspace.settings',
                    'parent_id' => 'workspace.settings.group',
                    'label' => ['hr' => 'Opće postavke', 'en' => 'General settings'],
                    'route' => 'workspace.settings',
                    'url' => '',
                    'query' => '',
                    'order' => 10,
                    'enabled' => true,
                    'level' => 1,
                ],
                [
                    'id' => 'workspace.settings.homepage',
                    'parent_id' => 'workspace.settings.group',
                    'label' => ['hr' => 'Naslovnica aplikacije', 'en' => 'Application homepage'],
                    'route' => 'workspace.settings.homepage',
                    'url' => '',
                    'query' => '',
                    'order' => 20,
                    'enabled' => true,
                    'level' => 1,
                ],
                [
                    'id' => 'workspace.settings.all',
                    'parent_id' => 'workspace.settings.group',
                    'label' => ['hr' => 'Sva područja', 'en' => 'All workspaces'],
                    'route' => 'workspace.settings.all',
                    'url' => '',
                    'query' => '',
                    'order' => 30,
                    'enabled' => true,
                    'level' => 1,
                ],
                [
                    'id' => 'workspace.settings.deleted',
                    'parent_id' => 'workspace.settings.group',
                    'label' => ['hr' => 'Obrisana područja', 'en' => 'Deleted workspaces'],
                    'route' => 'workspace.settings.deleted',
                    'url' => '',
                    'query' => '',
                    'order' => 40,
                    'enabled' => true,
                    'level' => 1,
                ],
                [
                    'id' => 'workspace.settings.maintenance',
                    'parent_id' => 'workspace.settings.group',
                    'label' => ['hr' => 'Održavanje', 'en' => 'Maintenance'],
                    'route' => 'workspace.settings.maintenance',
                    'url' => '',
                    'query' => '',
                    'order' => 50,
                    'enabled' => true,
                    'level' => 1,
                ],
            ]);
        }
    }

    /**
     * HR: Registrira lijeno dohvaćanje područja i stranica u zajednički Menu
     *     katalog. Closure sprječava čitanje baze tijekom bootstrapa aplikacije.
     * EN: Registers lazy workspace and page loading in the shared Menu catalog.
     *     The closure prevents database reads during application bootstrap.
     */
    public function registerNavigationTargets(): void
    {
        if (
            !$this->config->isAppModuleEnabled(self::MENU_PACKAGE)
            || !class_exists(self::MENU_TARGET_REGISTRY)
        ) {
            return;
        }

        try {
            $registry = $this->container->get(self::MENU_TARGET_REGISTRY);
            $provider = $this->container->get(WorkspaceMenuNavigationTargetProvider::class);
        } catch (Throwable) {
            return;
        }

        if (
            !is_object($registry)
            || !method_exists($registry, 'register')
            || !($provider instanceof WorkspaceMenuNavigationTargetProvider)
        ) {
            return;
        }

        $registry->register('workspace', static fn(): array => $provider->targets());
    }

    /**
     * HR: Upisuje ili osvježava skup stavki u jednoj menu sekciji.
     * EN: Inserts or refreshes a set of entries in one menu section.
     *
     * @param list<array<string, mixed>> $desired
     */
    private function upsertSection(object $repository, string $section, array $desired): void
    {
        $upsert = [$repository, 'upsertItemsForSection'];
        if (!is_callable($upsert)) {
            return;
        }

        try {
            $upsert($section, $desired);
        } catch (Throwable) {
            return;
        }
    }
}
