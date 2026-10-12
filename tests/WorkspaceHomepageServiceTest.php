<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceAccessService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfig;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceHomepageRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceHomepageService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceWorkflowService;
use HeartPhrame\Authn\AuthnHandlerInterface;
use HeartPhrame\Config\Config;
use HeartPhrame\Container\Container;
use HeartPhrame\Helper\Helper;
use HeartPhrame\Localization\TranslatorInterface;
use HeartPhrame\Routing\Routes;
use HeartPhrame\Routing\UrlGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkspaceHomepageService::class)]
#[CoversClass(WorkspaceHomepageRepository::class)]
#[UsesClass(WorkspaceRepository::class)]
#[UsesClass(\AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceDataDirectory::class)]
#[UsesClass(WorkspaceAccessService::class)]
#[UsesClass(WorkspaceWorkflowService::class)]
#[UsesClass(WorkspaceConfig::class)]
#[UsesClass(\AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfigurationStore::class)]
#[UsesClass(WorkspaceValue::class)]
final class WorkspaceHomepageServiceTest extends TestCase
{
    private WorkspaceRepository $repository;

    private WorkspaceHomepageRepository $homepages;

    private WorkspaceHomepageService $service;

    private AuthnHandlerInterface $authn;

    private string $configurationDirectory;

    private string|false $originalConfigPath;

    private string|false $originalOverridePaths;

    /**
     * EN: Prepares published public pages and the real ACL resolver on SQLite.
     *
     * HR: Priprema objavljene javne stranice i stvarni ACL resolver na SQLiteu.
     */
    protected function setUp(): void
    {
        $this->configurationDirectory = sys_get_temp_dir() . '/workspace_homepage_' . bin2hex(random_bytes(6));
        mkdir($this->configurationDirectory . '/workspace', 0770, true);
        $this->originalConfigPath = getenv('HPH_CONFIG_PATH');
        $this->originalOverridePaths = getenv('HPH_CONFIG_OVERRIDE_PATHS');
        putenv('HPH_CONFIG_PATH=' . $this->configurationDirectory);
        putenv('HPH_CONFIG_OVERRIDE_PATHS');
        $helper = new Helper();
        $config = new Config($helper, [
            'database' => [
                'connections' => [
                    'default' => [
                        'driver' => 'sqlite',
                        'database' => ':memory:',
                    ],
                ],
            ],
            'app' => [
                'localization' => [
                    'locale' => 'en',
                    'fallback_locale' => 'hr',
                    'supported_locales' => ['hr', 'en'],
                ],
            ],
        ]);
        $database = new Database($config, $helper);
        $database->schema()->create('auth_users', static function (Blueprint $table): void {
            $table->id();
            $table->string('login_identifier', 190)->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_admin')->default(false)->index();
            $table->timestamps();
        });
        $database->table('auth_users')->insert([
            'id' => 1,
            'login_identifier' => 'admin@example.test',
            'is_active' => true,
            'is_admin' => true,
            'created_at' => '2026-08-03 09:00:00',
            'updated_at' => '2026-08-03 09:00:00',
        ]);
        $migration = require dirname(__DIR__) . '/resources/migrations/initial_workspace_schema.php';
        $this->assertInstanceOf(ReversibleMigrationInterface::class, $migration);
        $migration->up($database);

        $this->repository = new WorkspaceRepository($database);
        $this->authn = $this->authnHandler();
        $workspaceConfig = new WorkspaceConfig($config, dirname(__DIR__));
        $this->homepages = new WorkspaceHomepageRepository($database, $workspaceConfig);
        $workflow = new WorkspaceWorkflowService($this->repository);
        $access = new WorkspaceAccessService(
            $this->repository,
            $this->authn,
            $workspaceConfig,
            $workflow,
        );
        $routes = new Routes();
        $routes->setBasePath('');
        $routes->addRoute(
            'GET',
            '/workspace/{workspaceSlug}/{nodeSlug}',
            'test-handler',
            'workspace.node.show',
        );
        $routes->addRoute(
            'GET',
            '/workspace/{workspaceSlug}/shorts',
            'test-handler',
            'workspace.shorts',
        );
        $translator = new class implements TranslatorInterface {
            /**
             * EN: The test does not translate text and only exposes the active locale.
             *
             * HR: Test ne prevodi tekst, nego samo izlaže aktivni jezik.
             *
             * @param array<string, string|int|float> $replace
             */
            public function trans(string $key, array $replace = [], ?string $locale = null): string
            {
                return $key;
            }

            /**
             * EN: Returns English as the active test locale.
             *
             * HR: Vraća engleski kao aktivni testni jezik.
             */
            public function getLocale(): string
            {
                return 'en';
            }

            /**
             * EN: The test locale is immutable.
             *
             * HR: Testni jezik je nepromjenjiv.
             */
            public function setLocale(string $locale): void
            {
            }
        };
        $this->service = new WorkspaceHomepageService(
            $this->repository,
            $this->homepages,
            $access,
            $workflow,
            $workspaceConfig,
            new UrlGenerator($routes, new Container()),
            $translator,
            $config,
        );
    }

    /**
     * EN: Removes only fixture JSON/lock and restores the environment.
     *
     * HR: Uklanja samo testni JSON/lock i vraća okolinu.
     */
    protected function tearDown(): void
    {
        foreach (['workspace.json', 'workspace.json.lock'] as $file) {
            $path = $this->configurationDirectory . '/workspace/' . $file;
            if (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($this->configurationDirectory . '/workspace');
        rmdir($this->configurationDirectory);
        putenv($this->originalConfigPath === false ? 'HPH_CONFIG_PATH'
            : 'HPH_CONFIG_PATH=' . $this->originalConfigPath);
        putenv($this->originalOverridePaths === false ? 'HPH_CONFIG_OVERRIDE_PATHS'
            : 'HPH_CONFIG_OVERRIDE_PATHS=' . $this->originalOverridePaths);
    }

    /**
     * EN: Proves personal, authenticated, and public precedence plus safe fallback.
     *
     * HR: Dokazuje prioritet osobne, prijavljene i javne naslovnice te siguran fallback.
     */
    public function testHomepagePrecedenceAndUnavailablePersonalFallback(): void
    {
        [$publicNodeId, $authenticatedNodeId, $personalNodeId] = $this->publishedPages();

        $this->authn->login(['id' => 1, 'is_admin' => true]);
        $this->service->saveSettings([
            'public_target' => 'page:' . $publicNodeId,
            'authenticated_target' => 'page:' . $authenticatedNodeId,
            'allow_user_selection' => '1',
        ], 1);

        $this->authn->logout();
        $this->assertSame('/workspace/portal/javno?lang=en', $this->service->resolvePath());

        $this->authn->login(['id' => 2, 'is_admin' => false]);
        $this->assertSame('/workspace/portal/prijavljeni?lang=en', $this->service->resolvePath());

        $this->service->saveUserSelection(2, $personalNodeId);
        $this->assertSame('/workspace/portal/osobno?lang=en', $this->service->resolvePath());
        $this->assertSame($personalNodeId, $this->homepages->userNodeId(2));

        $this->repository->disableNodeTree(1, $personalNodeId, 1);
        $this->assertSame('/workspace/portal/prijavljeni?lang=en', $this->service->resolvePath());
        $this->assertTrue((bool)($this->service->accountData(2)['selectionUnavailable'] ?? false));
    }

    /**
     * EN: Proves that disabling personal selection removes the Workspace profile section.
     *
     * HR: Dokazuje da isključivanje osobnog izbora uklanja Workspace sekciju profila.
     */
    public function testDisabledPersonalSelectionHidesAccountData(): void
    {
        [$publicNodeId, $authenticatedNodeId] = $this->publishedPages();
        $this->authn->login(['id' => 1, 'is_admin' => true]);
        $this->service->saveSettings([
            'public_target' => 'page:' . $publicNodeId,
            'authenticated_target' => 'page:' . $authenticatedNodeId,
        ], 1);

        $this->authn->login(['id' => 2, 'is_admin' => false]);
        $this->assertNull($this->service->accountData(2));
        $this->assertSame('/workspace/portal/prijavljeni?lang=en', $this->service->resolvePath());
    }

    /**
     * EN: A Shorts homepage stores its own display settings without a free-form URL field.
     *
     * HR: Shorts naslovnica sprema vlastite postavke prikaza bez slobodnog URL polja.
     */
    public function testStructuredShortsHomepagePreservesTreeAndDisplayOptions(): void
    {
        $this->publishedPages();
        $workspace = $this->repository->findWorkspaceBySlug('portal');
        $this->assertIsArray($workspace);
        $workspaceId = (int)$workspace['id'];

        $this->authn->login(['id' => 1, 'is_admin' => true]);
        $this->service->saveSettings([
            'public_target' => 'shorts:' . $workspaceId,
            'public_show_tree' => '0',
            'public_show_display_options' => '0',
            'authenticated_target' => 'default',
            'allow_user_selection' => '1',
        ], 1);

        $this->authn->logout();
        $this->assertSame(
            '/workspace/portal/shorts?lang=en&tree=0&options=0',
            $this->service->resolvePath(),
        );

        $this->authn->login(['id' => 2, 'is_admin' => false]);
        $this->service->saveUserSelection(2, [
            'target' => 'shorts:' . $workspaceId,
            'show_tree' => '1',
            'show_display_options' => '0',
        ]);
        $this->assertSame(
            '/workspace/portal/shorts?lang=en&tree=1&options=0',
            $this->service->resolvePath(),
        );
        $this->assertSame('shorts:' . $workspaceId, $this->service->accountData(2)['selectedTargetValue']);
    }

    /**
     * EN: Creates three publicly readable pages with published English versions.
     *
     * HR: Kreira tri javno čitljive stranice s objavljenim engleskim verzijama.
     *
     * @return array{int,int,int}
     */
    private function publishedPages(): array
    {
        $workspace = $this->repository->saveWorkspace([
            'name' => 'Portal',
            'slug' => 'portal',
            'visibility' => 'public',
        ], 1);
        $workspaceId = (int)$workspace['id'];
        $ids = [];
        foreach (
            [
                ['Javno', 'javno'],
                ['Prijavljeni', 'prijavljeni'],
                ['Osobno', 'osobno'],
            ] as $index => [$title, $slug]
        ) {
            $node = $this->repository->saveNode($workspaceId, [
                'title' => $title,
                'slug' => $slug,
                'node_type' => 'document',
                'document_key' => 'document-' . $slug,
            ], 1);
            $nodeId = (int)$node['id'];
            $ids[] = $nodeId;
            $this->repository->saveNodeWorkflow($nodeId, 'en', [
                'status' => WorkspaceWorkflowService::STATUS_PUBLISHED,
                'current_version_number' => $index + 1,
                'published_version_number' => $index + 1,
            ], 1);
        }

        return [$ids[0], $ids[1], $ids[2]];
    }

    /**
     * EN: Returns a mutable test Auth context.
     *
     * HR: Vraća promjenjivi testni Auth kontekst.
     */
    private function authnHandler(): AuthnHandlerInterface
    {
        return new class implements AuthnHandlerInterface {
            /** @var array<string, mixed>|null */
            private ?array $user = null;

            /**
             * EN: Sets the test user.
             *
             * HR: Postavlja testnog korisnika.
             *
             * @return array<string, mixed>|null
             */
            public function login(mixed $credentials): ?array
            {
                $this->user = is_array($credentials) ? $credentials : null;

                return $this->user;
            }

            /**
             * EN: Signs out the test user.
             *
             * HR: Odjavljuje testnog korisnika.
             */
            public function logout(): void
            {
                $this->user = null;
            }

            /**
             * EN: Returns the sign-in state.
             *
             * HR: Vraća stanje prijave.
             */
            public function check(): bool
            {
                return $this->user !== null;
            }

            /**
             * EN: Returns the test user.
             *
             * HR: Vraća testnog korisnika.
             *
             * @return array<string, mixed>|null
             */
            public function user(): ?array
            {
                return $this->user;
            }

            /**
             * EN: Returns the test user's session data.
             *
             * HR: Vraća session podatke testnog korisnika.
             *
             * @return array<string, mixed>|null
             */
            public function userData(): ?array
            {
                return $this->user;
            }
        };
    }
}
