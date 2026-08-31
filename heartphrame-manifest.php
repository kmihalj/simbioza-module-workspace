<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleAuth\Account\AuthAccountSectionRegistry;
use AaiEduHr\HeartPhrameModuleAuth\Middleware\RequireAuthenticatedUserMiddleware;
use AaiEduHr\HeartPhrameModuleAuth\ModuleAuth;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleWorkspace\Account\WorkspaceHomepageAccountSectionProvider;
use AaiEduHr\SimbiozaModuleWorkspace\Contract\WorkspaceIntegrationRegistrarInterface;
use AaiEduHr\SimbiozaModuleWorkspace\Controller\WorkspaceBackupController;
use AaiEduHr\SimbiozaModuleWorkspace\Controller\WorkspaceController;
use AaiEduHr\SimbiozaModuleWorkspace\Controller\WorkspaceExportController;
use AaiEduHr\SimbiozaModuleWorkspace\Controller\WorkspaceHomepageController;
use AaiEduHr\SimbiozaModuleWorkspace\Controller\WorkspaceMenuController;
use AaiEduHr\SimbiozaModuleWorkspace\Controller\WorkspaceSettingsController;
use AaiEduHr\SimbiozaModuleWorkspace\Controller\WorkspaceThemeController;
use AaiEduHr\SimbiozaModuleWorkspace\Event\WorkspaceContentChanged;
use AaiEduHr\SimbiozaModuleWorkspace\Listener\SynchronizeWorkspaceBacklinks;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceMenuIntegration;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRouteRegistrar;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Command\CommandDefinition;
use HeartPhrame\Config\ConfigInterface;
use HeartPhrame\Event\EventListener;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

return new class extends \HeartPhrame\Module\AbstractModuleManifest {
    private const AUTH_MODULE_PACKAGE = 'aaieduhr/heartphrame-module-auth';

    private const ORM_MODULE_PACKAGE = 'aaieduhr/heartphrame-module-orm';

    /**
     * HR: Provjerava jesu li auth i ORM instalirani i uključeni prije Workspace
     * modula. Područja će uvijek imati članove i prenosivi ACL model.
     *
     * EN: Verifies that auth and ORM are installed and enabled before the
     * Workspace module. Workspaces will always have members and a portable ACL model.
     */
    public function canLoad(ContainerInterface $container): bool
    {
        $composerBridge = $container->get(ComposerBridge::class);
        if (!($composerBridge instanceof ComposerBridge)) {
            throw new RuntimeException('Workspace module requires the HeartPhrame ComposerBridge service.');
        }

        if (!$composerBridge->isInstalled(self::AUTH_MODULE_PACKAGE) || !class_exists(ModuleAuth::class)) {
            throw new RuntimeException('Workspace module requires the installed auth module.');
        }

        if (!$composerBridge->isInstalled(self::ORM_MODULE_PACKAGE) || !class_exists(Database::class)) {
            throw new RuntimeException('Workspace module requires the installed ORM module.');
        }

        $config = $container->get(ConfigInterface::class);
        if (!($config instanceof ConfigInterface)) {
            throw new RuntimeException('Workspace module requires the HeartPhrame ConfigInterface service.');
        }

        $enabledModules = $config->getAsArrayWithValuesAsNonEmptyStrings('app.modules.enabled') ?? [];
        foreach ([self::AUTH_MODULE_PACKAGE, self::ORM_MODULE_PACKAGE] as $requiredModule) {
            if (!in_array($requiredModule, $enabledModules, true)) {
                throw new RuntimeException(
                    'Workspace module requires enabled module "' . $requiredModule . '" before "'
                    . ModuleWorkspace::PACKAGE_NAME . '".',
                );
            }
        }

        return true;
    }

    /**
     * HR: Odgađa učitavanje dok framework ne registrira obavezne auth i ORM module.
     * EN: Defers loading until the framework has registered the required auth and ORM modules.
     */
    public function requiresDeferredLoading(): bool
    {
        return true;
    }

    /**
     * HR: Učitava servisne definicije Workspace modula.
     * EN: Loads Workspace module service definitions.
     */
    public function getServices(): array
    {
        $services = require __DIR__ . '/config/services.php';
        if (!is_array($services)) {
            throw new RuntimeException('Workspace config/services.php must return an array.');
        }

        return $services;
    }

    /**
     * HR: Registrira fiksne akcijske i settings rute; javni slug URL-ovi dodaju se kasno.
     * EN: Registers fixed action and settings routes; public slug URLs are added late.
     */
    public function getBaseRoutes(): array
    {
        $routes = [
            ['GET', '/workspaces', WorkspaceController::class . '@index', 'workspace.index', []],
            [
                'GET',
                '/workspaces/manage',
                WorkspaceController::class . '@manage',
                'workspace.manage',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'POST',
                '/workspaces/save',
                WorkspaceController::class . '@saveWorkspace',
                'workspace.save',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'POST',
                '/workspaces/delete',
                WorkspaceController::class . '@deleteWorkspace',
                'workspace.delete',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'POST',
                '/workspaces/restore',
                WorkspaceController::class . '@restoreWorkspace',
                'workspace.restore',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'POST',
                '/workspaces/acl',
                WorkspaceController::class . '@saveWorkspaceAcl',
                'workspace.acl.save',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'GET',
                '/workspaces/acl/subjects',
                WorkspaceController::class . '@searchAclSubjects',
                'workspace.acl.subjects',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'POST',
                '/workspaces/node/save',
                WorkspaceController::class . '@saveNode',
                'workspace.node.save',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'GET',
                '/workspaces/node/dialog',
                WorkspaceController::class . '@nodeDialog',
                'workspace.node.dialog',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'GET',
                '/workspaces/tree/organizer',
                WorkspaceController::class . '@treeOrganizer',
                'workspace.tree.organizer',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'GET',
                '/workspaces/tree/branch',
                WorkspaceController::class . '@treeBranch',
                'workspace.tree.branch',
                [],
            ],
            [
                'POST',
                '/workspaces/tree/order',
                WorkspaceController::class . '@saveTreeOrder',
                'workspace.tree.order.save',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'POST',
                '/workspaces/page/create',
                WorkspaceController::class . '@createPage',
                'workspace.page.create',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'POST',
                '/workspaces/node/delete',
                WorkspaceController::class . '@deleteNode',
                'workspace.node.delete',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'POST',
                '/workspaces/node/acl',
                WorkspaceController::class . '@saveNodeAcl',
                'workspace.node.acl.save',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'POST',
                '/workspaces/node/direct-permissions',
                WorkspaceController::class . '@saveNodeDirectPermissions',
                'workspace.node.direct-permissions.save',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'POST',
                '/workspaces/workflow',
                WorkspaceController::class . '@transitionWorkflow',
                'workspace.workflow.transition',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'GET',
                '/workspaces/export',
                WorkspaceExportController::class . '@form',
                'workspace.export',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'POST',
                '/workspaces/export',
                WorkspaceExportController::class . '@download',
                'workspace.export.download',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'GET',
                '/workspaces/theme',
                WorkspaceThemeController::class . '@index',
                'workspace.theme',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'GET',
                '/workspaces/menu',
                WorkspaceMenuController::class . '@index',
                'workspace.menu',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'POST',
                '/workspaces/menu',
                WorkspaceMenuController::class . '@save',
                'workspace.menu.save',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'POST',
                '/workspaces/theme',
                WorkspaceThemeController::class . '@save',
                'workspace.theme.save',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'GET',
                '/workspaces/theme/export',
                WorkspaceThemeController::class . '@export',
                'workspace.theme.export',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'GET',
                '/workspaces/theme/assets/{workspace}/{file}',
                WorkspaceThemeController::class . '@asset',
                'workspace.theme.asset',
                [],
            ],
            ['GET', '/workspaces/assets.css', WorkspaceController::class . '@styles', 'workspace.assets.css', []],
            ['GET', '/workspaces/assets.js', WorkspaceController::class . '@scripts', 'workspace.assets.js', []],
            [
                'GET',
                '/settings/workspaces',
                WorkspaceSettingsController::class . '@index',
                'workspace.settings',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'POST',
                '/settings/workspaces',
                WorkspaceSettingsController::class . '@save',
                'workspace.settings.save',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'GET',
                '/settings/workspaces/all',
                WorkspaceSettingsController::class . '@all',
                'workspace.settings.all',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'GET',
                '/settings/workspaces/homepage',
                WorkspaceHomepageController::class . '@settings',
                'workspace.settings.homepage',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'POST',
                '/settings/workspaces/homepage',
                WorkspaceHomepageController::class . '@saveSettings',
                'workspace.settings.homepage.save',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'POST',
                '/workspaces/homepage/preference',
                WorkspaceHomepageController::class . '@savePreference',
                'workspace.homepage.preference.save',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'GET',
                '/settings/workspaces/deleted',
                WorkspaceSettingsController::class . '@deleted',
                'workspace.settings.deleted',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'GET',
                '/settings/workspaces/maintenance',
                WorkspaceSettingsController::class . '@maintenance',
                'workspace.settings.maintenance',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'POST',
                '/settings/workspaces/maintenance',
                WorkspaceSettingsController::class . '@runMaintenance',
                'workspace.settings.maintenance.run',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'POST',
                '/settings/workspaces/maintenance/images',
                WorkspaceSettingsController::class . '@optimizeImages',
                'workspace.settings.maintenance.images',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'GET',
                '/settings/workspaces/maintenance/images/status',
                WorkspaceSettingsController::class . '@imageOptimizationStatus',
                'workspace.settings.maintenance.images.status',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'POST',
                '/settings/workspaces/maintenance/images/step',
                WorkspaceSettingsController::class . '@stepImageOptimization',
                'workspace.settings.maintenance.images.step',
                [RequireAuthenticatedUserMiddleware::class],
            ],
            [
                'POST',
                '/settings/workspaces/purge',
                WorkspaceSettingsController::class . '@permanentlyDelete',
                'workspace.settings.purge',
                [RequireAuthenticatedUserMiddleware::class],
            ],
        ];

        // HR: Rute se uopće ne registriraju bez opcionalnog Backup modula.
        // EN: Routes are not registered at all without the optional Backup module.
        if (class_exists(\AaiEduHr\HeartPhrameModuleBackup\Service\BackupManager::class)) {
            $middleware = [RequireAuthenticatedUserMiddleware::class];
            $routes = [
                ...$routes,
                [
                    'GET', '/workspaces/backup', WorkspaceBackupController::class . '@index',
                    'workspace.backup', $middleware,
                ],
                [
                    'GET', '/workspaces/backup/csrf', WorkspaceBackupController::class . '@csrf',
                    'workspace.backup.csrf', $middleware,
                ],
                [
                    'POST', '/workspaces/backup/create', WorkspaceBackupController::class . '@create',
                    'workspace.backup.create', $middleware,
                ],
                [
                    'POST', '/workspaces/backup/upload/start', WorkspaceBackupController::class . '@uploadStart',
                    'workspace.backup.upload.start', $middleware,
                ],
                [
                    'POST', '/workspaces/backup/upload/chunk', WorkspaceBackupController::class . '@uploadChunk',
                    'workspace.backup.upload.chunk', $middleware,
                ],
                [
                    'POST', '/workspaces/backup/upload/finish', WorkspaceBackupController::class . '@uploadFinish',
                    'workspace.backup.upload.finish', $middleware,
                ],
                [
                    'POST', '/workspaces/backup/preflight', WorkspaceBackupController::class . '@preflight',
                    'workspace.backup.preflight', $middleware,
                ],
                [
                    'POST', '/workspaces/backup/restore', WorkspaceBackupController::class . '@restore',
                    'workspace.backup.restore', $middleware,
                ],
            ];
        }

        return $routes;
    }

    /**
     * HR: Registrira helper naredbu za kopiranje jedine početne migracije.
     * EN: Registers the helper command for copying the single initial migration.
     */
    public function getCommands(): array
    {
        return [
            new CommandDefinition(
                'workspace',
                'Workspace module helper command.',
                [\AaiEduHr\SimbiozaModuleWorkspace\Command\HpWorkspaceCommand::class, 'run'],
            ),
            new CommandDefinition(
                'workspace:install-migration',
                'Copy initial Workspace migration into the host application.',
                [\AaiEduHr\SimbiozaModuleWorkspace\Command\HpWorkspaceCommand::class, 'installMigration'],
            ),
            new CommandDefinition(
                'workspace:install-homepage-migration',
                'Copy the Workspace homepage upgrade migration into the host application.',
                [
                    \AaiEduHr\SimbiozaModuleWorkspace\Command\HpWorkspaceCommand::class,
                    'installHomepageMigration',
                ],
            ),
            new CommandDefinition(
                'workspace:install-homepage-view-options-migration',
                'Copy the structured Workspace homepage-options migration into the host application.',
                [
                    \AaiEduHr\SimbiozaModuleWorkspace\Command\HpWorkspaceCommand::class,
                    'installHomepageViewOptionsMigration',
                ],
            ),
            new CommandDefinition(
                'workspace:install-themes-migration',
                'Copy the Workspace private-theme upgrade migration into the host application.',
                [
                    \AaiEduHr\SimbiozaModuleWorkspace\Command\HpWorkspaceCommand::class,
                    'installThemesMigration',
                ],
            ),
            new CommandDefinition(
                'workspace:install-backlinks-migration',
                'Copy the Workspace backlinks upgrade migration into the host application.',
                [
                    \AaiEduHr\SimbiozaModuleWorkspace\Command\HpWorkspaceCommand::class,
                    'installBacklinksMigration',
                ],
            ),
            new CommandDefinition(
                'workspace:install-node-labels-migration',
                'Copy the Workspace page-label upgrade migration into the host application.',
                [
                    \AaiEduHr\SimbiozaModuleWorkspace\Command\HpWorkspaceCommand::class,
                    'installNodeLabelsMigration',
                ],
            ),
            new CommandDefinition(
                'workspace:install-node-properties-migration',
                'Copy the Workspace structured-page-properties migration into the host application.',
                [
                    \AaiEduHr\SimbiozaModuleWorkspace\Command\HpWorkspaceCommand::class,
                    'installNodePropertiesMigration',
                ],
            ),
            new CommandDefinition(
                'workspace:install-node-direct-permissions-migration',
                'Copy the Workspace direct-page-permissions migration into the host application.',
                [
                    \AaiEduHr\SimbiozaModuleWorkspace\Command\HpWorkspaceCommand::class,
                    'installNodeDirectPermissionsMigration',
                ],
            ),
            new CommandDefinition(
                'workspace:install-remove-owner-migration',
                'Copy the migration that removes the obsolete Workspace owner.',
                [
                    \AaiEduHr\SimbiozaModuleWorkspace\Command\HpWorkspaceCommand::class,
                    'installRemoveOwnerMigration',
                ],
            ),
        ];
    }

    /**
     * HR: Veže promjene objavljenog sadržaja na sinkronizaciju backlink indeksa.
     * EN: Binds published-content changes to backlink-index synchronization.
     *
     * @return EventListener[]
     */
    public function getEventListeners(): array
    {
        return [
            new EventListener(WorkspaceContentChanged::class, SynchronizeWorkspaceBacklinks::class),
        ];
    }

    /**
     * HR: Kasno registrira javne slug rute pa zatim menu stavke koje ih koriste.
     * EN: Registers public slug routes late, followed by menu entries that use them.
     *
     * @return mixed[]
     */
    public function getBootstrapCallables(): array
    {
        return [
            static function (ContainerInterface $container): void {
                $registrar = $container->get(WorkspaceRouteRegistrar::class);
                if ($registrar instanceof WorkspaceRouteRegistrar) {
                    $registrar->register();
                }
            },
            static function (ContainerInterface $container): void {
                $integration = $container->get(WorkspaceMenuIntegration::class);
                if ($integration instanceof WorkspaceMenuIntegration) {
                    $integration->registerMenuItems();
                    $integration->registerNavigationTargets();
                }
            },
            static function (ContainerInterface $container): void {
                $registry = $container->get(AuthAccountSectionRegistry::class);
                $provider = $container->get(WorkspaceHomepageAccountSectionProvider::class);
                if (
                    $registry instanceof AuthAccountSectionRegistry
                    && $provider instanceof WorkspaceHomepageAccountSectionProvider
                ) {
                    $registry->register($provider);
                }
            },
            static function (ContainerInterface $container): void {
                $registrarClass = 'AaiEduHr\\SimbiozaModuleUser\\Service\\SimbiozaUserIntegrationRegistrar';
                if (!class_exists($registrarClass)) {
                    return;
                }

                try {
                    $registrar = $container->get($registrarClass);
                    if ($registrar instanceof WorkspaceIntegrationRegistrarInterface) {
                        $registrar->register();
                    }
                } catch (ContainerExceptionInterface) {
                    // HR: Simbioza User će ponoviti registraciju ako se učitava nakon Workspacea.
                    // EN: Simbioza User retries the registration when it loads after Workspace.
                }
            },
        ];
    }

    /**
     * HR: Vraća direktorij s prikazima koji pripadaju Workspace modulu.
     * EN: Returns the directory containing views owned by the Workspace module.
     */
    public function getViewsPath(): string
    {
        return __DIR__ . '/views';
    }
};
