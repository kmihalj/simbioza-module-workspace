<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleAuth\Backup\AuthBackupIdentityResolver;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleWorkspace\Account\WorkspaceHomepageAccountSectionProvider;
use AaiEduHr\SimbiozaModuleWorkspace\Api\WorkspaceApiExtension;
use AaiEduHr\SimbiozaModuleWorkspace\Api\WorkspaceApiService;
use AaiEduHr\SimbiozaModuleWorkspace\Api\WorkspaceResourceController;
use AaiEduHr\SimbiozaModuleWorkspace\Backup\WorkspacePageBackupProvider;
use AaiEduHr\SimbiozaModuleWorkspace\Backup\WorkspaceScopedBackupProvider;
use AaiEduHr\SimbiozaModuleWorkspace\Controller\WorkspaceBackupController;
use AaiEduHr\SimbiozaModuleWorkspace\Controller\WorkspaceController;
use AaiEduHr\SimbiozaModuleWorkspace\Controller\WorkspaceExportController;
use AaiEduHr\SimbiozaModuleWorkspace\Controller\WorkspaceHomepageController;
use AaiEduHr\SimbiozaModuleWorkspace\Controller\WorkspaceLookupController;
use AaiEduHr\SimbiozaModuleWorkspace\Controller\WorkspaceMenuController;
use AaiEduHr\SimbiozaModuleWorkspace\Controller\WorkspacePageTransferController;
use AaiEduHr\SimbiozaModuleWorkspace\Controller\WorkspaceSettingsController;
use AaiEduHr\SimbiozaModuleWorkspace\Controller\WorkspaceShortsController;
use AaiEduHr\SimbiozaModuleWorkspace\Controller\WorkspaceThemeController;
use AaiEduHr\SimbiozaModuleWorkspace\Listener\SynchronizeWorkspaceBacklinks;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceAccessService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceAdministrationListService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceBacklinkIndexer;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceBacklinkService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceBreadcrumbService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfig;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceContentChangeBatch;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceDynamicContentService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceEditorAccess;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceEditorBridge;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceExportEditorBridge;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceExportService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceExternalReferenceRegistry;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceHomepageRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceHomepageService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceIndexService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceLinkExtractor;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceLookupService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceMaintenanceBridge;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceMaintenanceService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceMenuIntegration;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceMenuNavigationTargetProvider;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceMenuService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceModuleViewRenderer;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceNotificationBridge;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspacePresentationRegistry;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepositoryRequestCache;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRouteRegistrar;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceSettingsService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceShortsService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceThemeArchiveService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceThemeAssetLibrary;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceThemeBridge;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceThemeRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceThemeService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceWorkflowService;
use HeartPhrame\Alert\AlertHandler;
use HeartPhrame\Authn\AuthnHandlerInterface;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\ConfigInterface;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\Localization\TranslatorInterface;
use HeartPhrame\Routing\Routes;
use HeartPhrame\Routing\UrlGenerator;
use HeartPhrame\Session\SessionInterface;
use HeartPhrame\View\View;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

$services = [
    // EN: The dependency-free content registry is auto-wired once, never reset by deferred module order.
    //
    // HR: Registar sadržaja bez ovisnosti automatski se povezuje jednom, bez poništavanja redoslijedom modula.
    WorkspaceAdministrationListService::class =>
        static fn(ContainerInterface $container): WorkspaceAdministrationListService =>
            new WorkspaceAdministrationListService($container->get(TranslatorInterface::class)),

    WorkspacePresentationRegistry::class =>
        static fn(ContainerInterface $container): WorkspacePresentationRegistry =>
            new WorkspacePresentationRegistry(
                $container->get(TranslatorInterface::class),
                $container->get(WorkspaceRepository::class),
                $container->get(WorkspaceConfig::class),
            ),

    WorkspaceIndexService::class => static fn(): WorkspaceIndexService => new WorkspaceIndexService(),

    WorkspaceLookupService::class => static fn(ContainerInterface $container): WorkspaceLookupService =>
        new WorkspaceLookupService(
            $container->get(WorkspaceRepository::class),
            $container->get(WorkspaceAccessService::class),
            $container->get(WorkspacePresentationRegistry::class),
            $container->get(WorkspaceWorkflowService::class),
            $container->get(TranslatorInterface::class),
            $container->get(\AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceContentRegistry::class),
        ),

    WorkspaceLookupController::class => static fn(ContainerInterface $container): WorkspaceLookupController =>
        new WorkspaceLookupController(
            $container->get(ResponseFactory::class),
            $container->get(WorkspaceLookupService::class),
            $container->get(WorkspaceAccessService::class),
        ),

    WorkspaceConfig::class => static fn(ContainerInterface $container): WorkspaceConfig =>
        new WorkspaceConfig($container->get(ConfigInterface::class), dirname(__DIR__), $container->get(Database::class)),

    WorkspaceContentChangeBatch::class =>
        static fn(ContainerInterface $container): WorkspaceContentChangeBatch =>
            new WorkspaceContentChangeBatch(
                $container->get(\Psr\EventDispatcher\EventDispatcherInterface::class),
                $container->get(LoggerInterface::class),
            ),

    WorkspaceRepositoryRequestCache::class =>
        static fn(): WorkspaceRepositoryRequestCache => new WorkspaceRepositoryRequestCache(),

    WorkspaceRepository::class => static fn(ContainerInterface $container): WorkspaceRepository =>
        new WorkspaceRepository(
            $container->get(Database::class),
            $container->get(\Psr\EventDispatcher\EventDispatcherInterface::class),
            $container->get(LoggerInterface::class),
            $container->get(WorkspaceContentChangeBatch::class),
            $container->get(WorkspaceRepositoryRequestCache::class),
            $container->get(\AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceContentRegistry::class),
        ),

    WorkspaceThemeRepository::class => static fn(ContainerInterface $container): WorkspaceThemeRepository =>
        new WorkspaceThemeRepository($container->get(Database::class)),

    WorkspaceThemeAssetLibrary::class =>
        static fn(ContainerInterface $container): WorkspaceThemeAssetLibrary =>
            new WorkspaceThemeAssetLibrary($container->get(WorkspaceConfig::class)),

    WorkspaceThemeArchiveService::class =>
        static fn(ContainerInterface $container): WorkspaceThemeArchiveService =>
            new WorkspaceThemeArchiveService(
                $container,
                $container->get(WorkspaceThemeRepository::class),
                $container->get(WorkspaceThemeAssetLibrary::class),
                $container->get(WorkspaceConfig::class),
            ),

    WorkspaceThemeService::class => static fn(ContainerInterface $container): WorkspaceThemeService =>
        new WorkspaceThemeService(
            $container,
            $container->get(ComposerBridge::class),
            $container->get(WorkspaceThemeRepository::class),
            $container->get(WorkspaceThemeAssetLibrary::class),
            $container->get(WorkspaceConfig::class),
            $container->get(UrlGenerator::class),
        ),

    WorkspaceHomepageRepository::class =>
        static fn(ContainerInterface $container): WorkspaceHomepageRepository =>
            new WorkspaceHomepageRepository(
                $container->get(Database::class),
                $container->get(WorkspaceConfig::class),
            ),

    WorkspaceWorkflowService::class => static fn(ContainerInterface $container): WorkspaceWorkflowService =>
        new WorkspaceWorkflowService($container->get(WorkspaceRepository::class)),

    WorkspaceAccessService::class => static fn(ContainerInterface $container): WorkspaceAccessService =>
        new WorkspaceAccessService(
            $container->get(WorkspaceRepository::class),
            $container->get(AuthnHandlerInterface::class),
            $container->get(WorkspaceConfig::class),
            $container->get(WorkspaceWorkflowService::class),
            $container->get(\AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceContentRegistry::class),
        ),

    WorkspaceLinkExtractor::class => static fn(ContainerInterface $container): WorkspaceLinkExtractor =>
        new WorkspaceLinkExtractor($container->get(WorkspaceConfig::class)),

    WorkspaceBacklinkIndexer::class => static fn(ContainerInterface $container): WorkspaceBacklinkIndexer =>
        new WorkspaceBacklinkIndexer(
            $container->get(Database::class),
            $container->get(WorkspaceRepository::class),
            $container->get(WorkspaceWorkflowService::class),
            $container->get(WorkspaceEditorBridge::class),
            $container->get(WorkspaceLinkExtractor::class),
        ),

    WorkspaceBacklinkService::class => static fn(ContainerInterface $container): WorkspaceBacklinkService =>
        new WorkspaceBacklinkService(
            $container->get(Database::class),
            $container->get(WorkspaceRepository::class),
            $container->get(WorkspaceAccessService::class),
            $container->get(WorkspaceWorkflowService::class),
            $container->get(WorkspaceBacklinkIndexer::class),
            $container->get(WorkspaceConfig::class),
            $container->get(UrlGenerator::class),
            $container->get(WorkspaceEditorBridge::class),
        ),

    WorkspaceBreadcrumbService::class => static fn(ContainerInterface $container): WorkspaceBreadcrumbService =>
        new WorkspaceBreadcrumbService(
            $container->get(WorkspaceRepository::class),
            $container->get(WorkspaceConfig::class),
            $container->get(UrlGenerator::class),
        ),

    SynchronizeWorkspaceBacklinks::class =>
        static fn(ContainerInterface $container): SynchronizeWorkspaceBacklinks =>
            new SynchronizeWorkspaceBacklinks(
                $container->get(WorkspaceBacklinkIndexer::class),
                $container->get(LoggerInterface::class),
            ),

    WorkspaceApiService::class => static fn(ContainerInterface $container): WorkspaceApiService =>
        new WorkspaceApiService(
            $container->get(WorkspaceRepository::class),
            $container->get(WorkspaceAccessService::class),
            $container->get(WorkspaceConfig::class),
            $container->get(WorkspaceShortsService::class),
            $container->get(WorkspaceExportService::class),
            $container->get(WorkspaceThemeService::class),
            $container->get(WorkspaceThemeArchiveService::class),
            $container->get(WorkspaceHomepageService::class),
        ),

    WorkspaceHomepageService::class => static fn(ContainerInterface $container): WorkspaceHomepageService =>
        new WorkspaceHomepageService(
            $container->get(WorkspaceRepository::class),
            $container->get(WorkspaceHomepageRepository::class),
            $container->get(WorkspaceAccessService::class),
            $container->get(WorkspaceWorkflowService::class),
            $container->get(WorkspaceConfig::class),
            $container->get(UrlGenerator::class),
            $container->get(TranslatorInterface::class),
            $container->get(ConfigInterface::class),
        ),

    // EN: The host application uses a neutral service ID and therefore does not depend on a Workspace class.
    //
    // HR: Host aplikacija koristi neutralni servisni ID i zato ne ovisi o Workspace klasi.
    'heartphrame.application_homepage_resolver' =>
        static fn(ContainerInterface $container): WorkspaceHomepageService =>
            $container->get(WorkspaceHomepageService::class),

    WorkspaceEditorBridge::class => static fn(ContainerInterface $container): WorkspaceEditorBridge =>
        new WorkspaceEditorBridge(
            $container,
            $container->get(ComposerBridge::class),
            $container->get(UrlGenerator::class),
        ),

    WorkspaceDynamicContentService::class =>
        static fn(ContainerInterface $container): WorkspaceDynamicContentService =>
            new WorkspaceDynamicContentService(
                $container,
                $container->get(WorkspaceRepository::class),
                $container->get(WorkspaceWorkflowService::class),
                $container->get(WorkspaceEditorBridge::class),
                $container->get(WorkspaceConfig::class),
                $container->get(UrlGenerator::class),
                $container->get(WorkspaceExternalReferenceRegistry::class),
            ),

    WorkspaceEditorAccess::class => static fn(ContainerInterface $container): WorkspaceEditorAccess =>
        new WorkspaceEditorAccess(
            $container->get(WorkspaceRepository::class),
            $container->get(WorkspaceAccessService::class),
            $container->get(WorkspaceConfig::class),
            $container->get(UrlGenerator::class),
            $container->get(WorkspaceWorkflowService::class),
            $container->get(WorkspaceNotificationBridge::class),
            $container->get(WorkspaceDynamicContentService::class),
            $container->get(WorkspacePresentationRegistry::class),
            $container->get(WorkspaceThemeService::class),
        ),

    WorkspaceExportEditorBridge::class =>
        static fn(ContainerInterface $container): WorkspaceExportEditorBridge =>
            new WorkspaceExportEditorBridge(
                $container,
                $container->get(ComposerBridge::class),
            ),

    WorkspaceThemeBridge::class => static fn(ContainerInterface $container): WorkspaceThemeBridge =>
        new WorkspaceThemeBridge(
            $container,
            $container->get(ComposerBridge::class),
            $container->get(TranslatorInterface::class),
        ),

    WorkspaceExportService::class => static fn(ContainerInterface $container): WorkspaceExportService =>
        new WorkspaceExportService(
            $container->get(WorkspaceRepository::class),
            $container->get(WorkspaceAccessService::class),
            $container->get(WorkspaceWorkflowService::class),
            $container->get(WorkspaceExportEditorBridge::class),
            $container->get(WorkspaceThemeBridge::class),
            $container->get(WorkspaceConfig::class),
            $container->get(TranslatorInterface::class),
        ),

    WorkspaceNotificationBridge::class =>
        static fn(ContainerInterface $container): WorkspaceNotificationBridge =>
            new WorkspaceNotificationBridge(
                $container,
                $container->get(WorkspaceAccessService::class),
                $container->get(UrlGenerator::class),
                $container->get(WorkspaceRepository::class),
                $container->get(WorkspaceConfig::class),
            ),

    WorkspaceSettingsService::class => static fn(ContainerInterface $container): WorkspaceSettingsService =>
        new WorkspaceSettingsService(
            $container->get(WorkspaceConfig::class),
            $container->get(Routes::class),
            $container->get(WorkspaceRepository::class),
        ),

    WorkspaceMaintenanceBridge::class => static fn(ContainerInterface $container): WorkspaceMaintenanceBridge =>
        new WorkspaceMaintenanceBridge($container),

    WorkspaceMaintenanceService::class => static fn(ContainerInterface $container): WorkspaceMaintenanceService =>
        new WorkspaceMaintenanceService(
            $container->get(Database::class),
            $container->get(WorkspaceRepository::class),
            $container->get(WorkspaceMaintenanceBridge::class),
            $container->get(WorkspaceMenuService::class),
            $container->get(WorkspaceThemeAssetLibrary::class),
            $container->get(\Psr\EventDispatcher\EventDispatcherInterface::class),
        ),

    WorkspaceShortsService::class => static fn(ContainerInterface $container): WorkspaceShortsService =>
        new WorkspaceShortsService(
            $container->get(WorkspaceRepository::class),
            $container->get(WorkspaceAccessService::class),
            $container->get(WorkspaceWorkflowService::class),
            $container->get(WorkspaceEditorBridge::class),
            $container->get(WorkspaceConfig::class),
            $container->get(UrlGenerator::class),
        ),

    WorkspaceRouteRegistrar::class => static fn(ContainerInterface $container): WorkspaceRouteRegistrar =>
        new WorkspaceRouteRegistrar(
            $container->get(WorkspaceConfig::class),
            $container->get(Routes::class),
        ),

    WorkspaceMenuIntegration::class => static fn(ContainerInterface $container): WorkspaceMenuIntegration =>
        new WorkspaceMenuIntegration($container, $container->get(WorkspaceConfig::class)),

    WorkspaceMenuService::class => static fn(ContainerInterface $container): WorkspaceMenuService =>
        new WorkspaceMenuService(
            $container,
            $container->get(ComposerBridge::class),
            $container->get(WorkspaceConfig::class),
            $container->get(WorkspaceRepository::class),
            $container->get(UrlGenerator::class),
        ),

    WorkspaceMenuNavigationTargetProvider::class =>
        static fn(ContainerInterface $container): WorkspaceMenuNavigationTargetProvider =>
            new WorkspaceMenuNavigationTargetProvider(
                $container->get(WorkspaceRepository::class),
                $container->get(WorkspaceConfig::class),
                $container->get(UrlGenerator::class),
                $container->get(\AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceContentRegistry::class),
            ),

    WorkspaceModuleViewRenderer::class => static fn(ContainerInterface $container): WorkspaceModuleViewRenderer =>
        new WorkspaceModuleViewRenderer(
            $container->get(ResponseFactory::class),
            $container->get(ConfigInterface::class),
            $container->get(View::class),
        ),

    WorkspaceController::class => static fn(ContainerInterface $container): WorkspaceController =>
        new WorkspaceController(
            $container->get(ResponseFactory::class),
            $container->get(WorkspaceModuleViewRenderer::class),
            $container->get(WorkspaceRepository::class),
            $container->get(WorkspaceAccessService::class),
            $container->get(WorkspaceEditorBridge::class),
            $container->get(WorkspaceConfig::class),
            $container->get(UrlGenerator::class),
            $container->get(AlertHandler::class),
            $container->get(WorkspaceWorkflowService::class),
            $container->get(WorkspaceNotificationBridge::class),
            $container->get(TranslatorInterface::class),
            $container->get(WorkspaceThemeService::class),
            $container->get(WorkspaceMenuService::class),
            $container->get(WorkspaceBreadcrumbService::class),
            $container->get(WorkspaceBacklinkService::class),
            $container->get(WorkspacePresentationRegistry::class),
            $container->get(WorkspaceIndexService::class),
            $container->get(\Psr\EventDispatcher\EventDispatcherInterface::class),
            $container->get(LoggerInterface::class),
            $container->get(\AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceContentRegistry::class),
        ),

    WorkspaceExportController::class => static fn(ContainerInterface $container): WorkspaceExportController =>
        new WorkspaceExportController(
            $container->get(ResponseFactory::class),
            $container->get(WorkspaceModuleViewRenderer::class),
            $container->get(WorkspaceRepository::class),
            $container->get(WorkspaceAccessService::class),
            $container->get(WorkspaceExportService::class),
            $container->get(WorkspaceExportEditorBridge::class),
            $container->get(UrlGenerator::class),
            $container->get(AlertHandler::class),
            $container->get(WorkspaceThemeService::class),
        ),

    WorkspaceSettingsController::class => static fn(ContainerInterface $container): WorkspaceSettingsController =>
        new WorkspaceSettingsController(
            $container->get(ResponseFactory::class),
            $container->get(WorkspaceModuleViewRenderer::class),
            $container->get(WorkspaceRepository::class),
            $container->get(WorkspaceAccessService::class),
            $container->get(WorkspaceSettingsService::class),
            $container->get(WorkspaceMaintenanceService::class),
            $container->get(WorkspaceAdministrationListService::class),
            $container->get(WorkspacePresentationRegistry::class),
            $container->get(WorkspaceConfig::class),
            $container->get(UrlGenerator::class),
            $container->get(AlertHandler::class),
            $container->get(SessionInterface::class),
        ),

    WorkspaceShortsController::class => static fn(ContainerInterface $container): WorkspaceShortsController =>
        new WorkspaceShortsController(
            $container->get(WorkspaceModuleViewRenderer::class),
            $container->get(WorkspaceRepository::class),
            $container->get(WorkspaceAccessService::class),
            $container->get(WorkspaceBreadcrumbService::class),
            $container->get(WorkspaceShortsService::class),
            $container->get(WorkspaceConfig::class),
            $container->get(UrlGenerator::class),
            $container->get(TranslatorInterface::class),
            $container->get(WorkspaceThemeService::class),
            $container->get(WorkspacePresentationRegistry::class),
        ),

    WorkspaceThemeController::class => static fn(ContainerInterface $container): WorkspaceThemeController =>
        new WorkspaceThemeController(
            $container->get(ResponseFactory::class),
            $container->get(WorkspaceRepository::class),
            $container->get(WorkspaceAccessService::class),
            $container->get(WorkspaceThemeService::class),
            $container->get(WorkspaceThemeArchiveService::class),
            $container->get(WorkspaceThemeAssetLibrary::class),
            $container->get(UrlGenerator::class),
            $container->get(AlertHandler::class),
        ),

    WorkspaceMenuController::class => static fn(ContainerInterface $container): WorkspaceMenuController =>
        new WorkspaceMenuController(
            $container->get(ResponseFactory::class),
            $container->get(WorkspaceModuleViewRenderer::class),
            $container->get(WorkspaceRepository::class),
            $container->get(WorkspaceAccessService::class),
            $container->get(WorkspaceMenuService::class),
            $container->get(WorkspaceThemeService::class),
            $container->get(UrlGenerator::class),
            $container->get(AlertHandler::class),
        ),

    WorkspaceHomepageController::class =>
        static fn(ContainerInterface $container): WorkspaceHomepageController =>
            new WorkspaceHomepageController(
                $container->get(ResponseFactory::class),
                $container->get(WorkspaceModuleViewRenderer::class),
                $container->get(WorkspaceAccessService::class),
                $container->get(WorkspaceHomepageService::class),
                $container->get(UrlGenerator::class),
                $container->get(AlertHandler::class),
            ),

    WorkspaceHomepageAccountSectionProvider::class =>
        static fn(ContainerInterface $container): WorkspaceHomepageAccountSectionProvider =>
            new WorkspaceHomepageAccountSectionProvider(
                $container->get(WorkspaceHomepageService::class),
                $container->get(UrlGenerator::class),
            ),
];

// EN: Workspace registers its own ACL-aware API routes with the API core.
//
// HR: Workspace sam registrira svoje ACL-aware API rute uz API jezgru.
if (interface_exists(\AaiEduHr\HeartPhrameModuleApi\Contract\ApiExtensionInterface::class)) {
    $services[WorkspaceApiExtension::class] =
    static fn(): WorkspaceApiExtension => new WorkspaceApiExtension();
    $services[WorkspaceResourceController::class] =
    static fn(ContainerInterface $container): WorkspaceResourceController =>
            new WorkspaceResourceController(
                $container->get(\AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory::class),
                $container->get(ResponseFactory::class),
                $container->get(WorkspaceApiService::class),
                $container->get(ConfigInterface::class),
                $container->get(\AaiEduHr\HeartPhrameModuleApi\Service\ApiCursorPaginator::class),
                $container->get(\AaiEduHr\HeartPhrameModuleApi\Service\ApiEntityTagService::class),
            );
}

if (class_exists(\AaiEduHr\HeartPhrameModuleBackup\Service\DatabaseTableBackupProvider::class)) {
    $services['heartphrame.backup.provider.workspace'] =
    static fn(ContainerInterface $container): \AaiEduHr\HeartPhrameModuleBackup\Service\DatabaseTableBackupProvider =>
            new \AaiEduHr\SimbiozaModuleWorkspace\Backup\WorkspaceDatabaseBackupProvider(
                $container->get(Database::class),
                new \AaiEduHr\HeartPhrameModuleBackup\Value\BackupProviderMetadata(
                    'workspace',
                    \AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace::PACKAGE_NAME,
                    3,
                    ['hr' => 'Područja, stabla i ovlasti', 'en' => 'Workspaces, trees, and permissions'],
                    ['auth', 'editor-html'],
                    [\AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope::SITE, \AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope::COMPONENT],
                    true,
                    true,
                    componentGroups: [\AaiEduHr\HeartPhrameModuleBackup\Value\BackupComponentGroup::WORKSPACES],
                ),
                [
                    ['dataset' => 'workspaces', 'table' => \AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace::TABLE_WORKSPACES, 'primary_key' => 'id', 'conflict_keys' => ['uuid'], 'preserve_primary_key' => false, 'identity_namespace' => 'workspace.workspace', 'foreign_keys' => [
                        ['column' => 'created_by_user_id', 'namespace' => 'auth.user', 'nullable' => true],
                        ['column' => 'updated_by_user_id', 'namespace' => 'auth.user', 'nullable' => true],
                        ['column' => 'deleted_by_user_id', 'namespace' => 'auth.user', 'nullable' => true],
                    ]],
                    ['dataset' => 'workspace-acl', 'table' => \AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace::TABLE_WORKSPACE_ACL, 'primary_key' => 'id', 'conflict_keys' => ['workspace_id', 'subject_type', 'subject_id'], 'preserve_primary_key' => false, 'foreign_keys' => [
                        ['column' => 'workspace_id', 'namespace' => 'workspace.workspace'],
                    ], 'polymorphic_foreign_keys' => [[
                        'column' => 'subject_id', 'type_column' => 'subject_type',
                        'namespaces' => ['user' => 'auth.user', 'group' => 'auth.group'],
                        'passthrough' => ['public', 'authenticated'],
                    ]]],
                    ['dataset' => 'nodes', 'table' => \AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace::TABLE_WORKSPACE_NODES, 'primary_key' => 'id', 'conflict_keys' => ['uuid'], 'preserve_primary_key' => false, 'identity_namespace' => 'workspace.node', 'foreign_keys' => [
                        ['column' => 'workspace_id', 'namespace' => 'workspace.workspace'],
                        ['column' => 'parent_id', 'namespace' => 'workspace.node', 'nullable' => true, 'defer' => true],
                        ['column' => 'created_by_user_id', 'namespace' => 'auth.user', 'nullable' => true],
                        ['column' => 'updated_by_user_id', 'namespace' => 'auth.user', 'nullable' => true],
                    ]],
                    ['dataset' => 'node-acl', 'table' => \AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace::TABLE_WORKSPACE_NODE_ACL, 'primary_key' => 'id', 'conflict_keys' => ['node_id', 'subject_type', 'subject_id'], 'preserve_primary_key' => false, 'foreign_keys' => [
                        ['column' => 'node_id', 'namespace' => 'workspace.node'],
                    ], 'polymorphic_foreign_keys' => [[
                        'column' => 'subject_id', 'type_column' => 'subject_type',
                        'namespaces' => ['user' => 'auth.user', 'group' => 'auth.group'],
                        'passthrough' => ['public', 'authenticated'],
                    ]]],
                    ['dataset' => 'node-direct-permissions', 'table' => \AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace::TABLE_WORKSPACE_NODE_DIRECT_PERMISSIONS, 'primary_key' => 'id', 'conflict_keys' => ['node_id', 'user_id'], 'preserve_primary_key' => false, 'foreign_keys' => [
                        ['column' => 'node_id', 'namespace' => 'workspace.node'],
                        ['column' => 'user_id', 'namespace' => 'auth.user'],
                    ]],
                    ['dataset' => 'node-subject-permissions', 'table' => \AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace::TABLE_WORKSPACE_NODE_SUBJECT_PERMISSIONS, 'primary_key' => 'id', 'conflict_keys' => ['node_id', 'subject_type', 'subject_id'], 'preserve_primary_key' => false, 'foreign_keys' => [
                        ['column' => 'node_id', 'namespace' => 'workspace.node'],
                    ], 'polymorphic_foreign_keys' => [[
                        'column' => 'subject_id', 'type_column' => 'subject_type',
                        'namespaces' => ['group' => 'auth.group'], 'passthrough' => ['public', 'authenticated'],
                    ]]],
                    ['dataset' => 'node-workflows', 'table' => \AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS, 'primary_key' => 'id', 'conflict_keys' => ['node_id', 'language_code'], 'preserve_primary_key' => false, 'foreign_keys' => [
                        ['column' => 'node_id', 'namespace' => 'workspace.node'],
                        ['column' => 'submitted_by_user_id', 'namespace' => 'auth.user', 'nullable' => true],
                        ['column' => 'published_by_user_id', 'namespace' => 'auth.user', 'nullable' => true],
                        ['column' => 'archived_by_user_id', 'namespace' => 'auth.user', 'nullable' => true],
                        ['column' => 'updated_by_user_id', 'namespace' => 'auth.user', 'nullable' => true],
                    ]],
                    ['dataset' => 'user-homepages', 'table' => \AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace::TABLE_WORKSPACE_USER_HOMEPAGES, 'primary_key' => 'id', 'conflict_keys' => ['user_id'], 'preserve_primary_key' => false, 'foreign_keys' => [
                        ['column' => 'user_id', 'namespace' => 'auth.user'],
                        ['column' => 'node_id', 'namespace' => 'workspace.node', 'defer' => true],
                        ['column' => 'workspace_id', 'namespace' => 'workspace.workspace', 'nullable' => true, 'defer' => true],
                    ]],
                    ['dataset' => 'workspace-themes', 'table' => \AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace::TABLE_WORKSPACE_THEMES, 'primary_key' => 'id', 'conflict_keys' => ['workspace_id'], 'preserve_primary_key' => false, 'foreign_keys' => [
                        // EN: Workspaces have already been imported before
                        // private themes and workspace_id is required. Direct
                        // mapping avoids a temporary NULL correctly rejected
                        // by SQLite/PostgreSQL/MySQL before deferred finalizing.
                        //
                        // HR: Područja su već uvezena prije privatnih tema, a
                        // workspace_id je obvezan. Izravno mapiranje sprječava
                        // privremeni NULL koji bi SQLite/PostgreSQL/MySQL
                        // ispravno odbili prije odgođene završne faze.
                        ['column' => 'workspace_id', 'namespace' => 'workspace.workspace'],
                        ['column' => 'updated_by_user_id', 'namespace' => 'auth.user', 'nullable' => true],
                    ]],
                ],
                $container->get(WorkspaceConfig::class),
                new \AaiEduHr\SimbiozaModuleWorkspace\Backup\WorkspaceContentBackup(
                    $container->get(\AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceContentRegistry::class),
                ),
            );
}

if (class_exists(\AaiEduHr\HeartPhrameModuleBackup\Service\FilesystemBackupProvider::class)) {
    $services['heartphrame.backup.provider.workspace-files'] =
    static fn(ContainerInterface $container): \AaiEduHr\SimbiozaModuleWorkspace\Backup\WorkspaceFilesBackupProvider =>
            new \AaiEduHr\SimbiozaModuleWorkspace\Backup\WorkspaceFilesBackupProvider(
                $container->get(Database::class),
                $container->get(WorkspaceConfig::class),
                $container->get(\AaiEduHr\HeartPhrameModuleBackup\Service\BackupFilesystem::class),
            );
}

if (interface_exists(\AaiEduHr\HeartPhrameModuleBackup\Contract\BackupProviderInterface::class)) {
    // EN: Selective workspace backup uses stable business identities.
    //
    // HR: Selektivni backup područja koristi stabilne poslovne identitete.
    $services['heartphrame.backup.provider.workspace-scope'] =
    static fn(ContainerInterface $container): WorkspaceScopedBackupProvider =>
            new WorkspaceScopedBackupProvider(
                $container->get(Database::class),
                $container->get(AuthBackupIdentityResolver::class),
                $container->get(WorkspaceConfig::class),
                $container->get(\AaiEduHr\HeartPhrameModuleBackup\Service\BackupFilesystem::class),
                $container->get(WorkspaceRepository::class),
                new \AaiEduHr\SimbiozaModuleWorkspace\Backup\WorkspaceContentBackup(
                    $container->get(\AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceContentRegistry::class),
                ),
            );

    // EN: A single page is a portable scope that includes Editor content,
    //     every language, history, and attachments.
    //
    // HR: Jedna stranica je zaseban prenosivi opseg koji uključuje Editorov
    //     sadržaj, sve jezike, povijest i privitke.
    $services['heartphrame.backup.provider.page-transfer'] =
    static fn(ContainerInterface $container): WorkspacePageBackupProvider =>
            new WorkspacePageBackupProvider(
                $container->get(Database::class),
                $container->get(AuthBackupIdentityResolver::class),
                $container->get(WorkspaceRepository::class),
                $container->get(WorkspaceConfig::class),
                new \AaiEduHr\SimbiozaModuleWorkspace\Backup\WorkspaceContentBackup(
                    $container->get(\AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceContentRegistry::class),
                ),
            );

    // EN: Workspace owns its backup-UI ACL rules, so the generic Backup module
    // does not need to know any Workspace class.
    //
    // HR: Workspace je vlasnik ACL pravila svojeg backup sučelja; generički
    // Backup modul zato ne mora poznavati ni jednu Workspace klasu.
    $services[WorkspaceBackupController::class] =
    static fn(ContainerInterface $container): WorkspaceBackupController =>
            new WorkspaceBackupController(
                $container->get(ResponseFactory::class),
                $container->get(WorkspaceModuleViewRenderer::class),
                $container->get(WorkspaceRepository::class),
                $container->get(WorkspaceAccessService::class),
                $container->get(\AaiEduHr\HeartPhrameModuleBackup\Service\BackupJobRepository::class),
                $container->get(\AaiEduHr\HeartPhrameModuleBackup\Service\BackupJobRunner::class),
                $container->get(\AaiEduHr\HeartPhrameModuleBackup\Service\BackupUploadService::class),
                $container->get(\AaiEduHr\HeartPhrameModuleBackup\Service\BackupManager::class),
                $container->get(\AaiEduHr\HeartPhrameModuleBackup\Service\BackupConfig::class),
                $container->get(UrlGenerator::class),
                $container->get(\HeartPhrame\Session\SessionInterface::class),
            );

    $services[WorkspacePageTransferController::class] =
    static fn(ContainerInterface $container): WorkspacePageTransferController =>
            new WorkspacePageTransferController(
                $container->get(ResponseFactory::class),
                $container->get(WorkspaceModuleViewRenderer::class),
                $container->get(WorkspaceRepository::class),
                $container->get(WorkspaceAccessService::class),
                $container->get(WorkspaceEditorBridge::class),
                $container->get(WorkspaceConfig::class),
                $container->get(\AaiEduHr\HeartPhrameModuleBackup\Service\BackupManager::class),
                $container->get(UrlGenerator::class),
                $container->get(AlertHandler::class),
                $container->get(TranslatorInterface::class),
            );
}

if (class_exists(\AaiEduHr\HeartPhrameModuleBackup\Service\StructuredConfigBackupProvider::class)) {
    $services['heartphrame.backup.provider.workspace-configuration'] =
    static function (ContainerInterface $container): \AaiEduHr\HeartPhrameModuleBackup\Service\StructuredConfigBackupProvider {
        $config = $container->get(WorkspaceConfig::class);
        $store = $config->configurationStore();
        $keys = ['routing', 'defaults', 'creation', 'shorts', 'backlinks', 'menu', 'homepage'];
        $transform = new \AaiEduHr\SimbiozaModuleWorkspace\Backup\WorkspaceConfigurationIdentityTransform();
        return new \AaiEduHr\HeartPhrameModuleBackup\Service\StructuredConfigBackupProvider(
            new \AaiEduHr\HeartPhrameModuleBackup\Value\BackupProviderMetadata(
                'workspace-configuration',
                \AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace::PACKAGE_NAME,
                1,
                ['hr' => 'Globalne postavke područja', 'en' => 'Global Workspace settings'],
                ['workspace'],
                [\AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope::SITE, \AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope::COMPONENT],
                true,
                componentGroups: [\AaiEduHr\HeartPhrameModuleBackup\Value\BackupComponentGroup::SETTINGS],
            ),
            $container->get(\AaiEduHr\HeartPhrameModuleBackup\Service\BackupFilesystem::class),
            [[
                'key' => 'workspace-config', 'path' => $store->path(), 'read_paths' => $store->paths(),
                'include_keys' => $keys,
                'restore_targets' => [[
                    'path' => $store->path(), 'read_paths' => $store->paths(),
                    'key_map' => array_combine($keys, $keys), 'lock_path' => $store->path() . '.lock',
                    'transform' => $transform->transform(...),
                    'validate' => static function (array $values) use ($store): void {
                        $store->validate(WorkspaceValue::stringKeyArray(
                            \HeartPhrame\Config\ConfigArrayMerger::merge($store->read(), $values),
                        ));
                    },
                ]],
            ]],
        );
    };
}

if (interface_exists(\AaiEduHr\HeartPhrameModuleMenu\Service\MenuContextOwnerResolverInterface::class)) {
    $services[\AaiEduHr\HeartPhrameModuleMenu\Service\MenuContextOwnerResolverInterface::class] =
    static fn(ContainerInterface $container): \AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceMenuContextOwnerResolver =>
            new \AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceMenuContextOwnerResolver($container->get(Database::class));
}

return $services;
