<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleWorkspace\Tests;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceAccessService;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceConfig;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceHomepageRepository;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceHomepageService;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceValue;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceWorkflowService;
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
#[UsesClass(WorkspaceAccessService::class)]
#[UsesClass(WorkspaceWorkflowService::class)]
#[UsesClass(WorkspaceConfig::class)]
#[UsesClass(WorkspaceValue::class)]
final class WorkspaceHomepageServiceTest extends TestCase
{
    private WorkspaceRepository $repository;

    private WorkspaceHomepageRepository $homepages;

    private WorkspaceHomepageService $service;

    private AuthnHandlerInterface $authn;

    /**
     * HR: Priprema objavljene javne stranice i stvarni ACL resolver na SQLiteu.
     * EN: Prepares published public pages and the real ACL resolver on SQLite.
     */
    protected function setUp(): void
    {
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
        $this->homepages = new WorkspaceHomepageRepository($database);
        $this->authn = $this->authnHandler();
        $workspaceConfig = new WorkspaceConfig($config, dirname(__DIR__));
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
             * HR: Test ne prevodi tekst, nego samo izlaže aktivni jezik.
             * EN: The test does not translate text and only exposes the active locale.
             *
             * @param array<string, string|int|float> $replace
             */
            public function trans(string $key, array $replace = [], ?string $locale = null): string
            {
                return $key;
            }

            /**
             * HR: Vraća engleski kao aktivni testni jezik.
             * EN: Returns English as the active test locale.
             */
            public function getLocale(): string
            {
                return 'en';
            }

            /**
             * HR: Testni jezik je nepromjenjiv.
             * EN: The test locale is immutable.
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
     * HR: Dokazuje prioritet osobne, prijavljene i javne naslovnice te siguran fallback.
     * EN: Proves personal, authenticated, and public precedence plus safe fallback.
     */
    public function testHomepagePrecedenceAndUnavailablePersonalFallback(): void
    {
        [$publicNodeId, $authenticatedNodeId, $personalNodeId] = $this->publishedPages();

        $this->authn->login(['id' => 1, 'is_admin' => true]);
        $this->service->saveSettings([
            'public_node_id' => $publicNodeId,
            'authenticated_node_id' => $authenticatedNodeId,
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
     * HR: Dokazuje da isključivanje osobnog izbora uklanja Workspace sekciju profila.
     * EN: Proves that disabling personal selection removes the Workspace profile section.
     */
    public function testDisabledPersonalSelectionHidesAccountData(): void
    {
        [$publicNodeId, $authenticatedNodeId] = $this->publishedPages();
        $this->authn->login(['id' => 1, 'is_admin' => true]);
        $this->service->saveSettings([
            'public_node_id' => $publicNodeId,
            'authenticated_node_id' => $authenticatedNodeId,
        ], 1);

        $this->authn->login(['id' => 2, 'is_admin' => false]);
        $this->assertNull($this->service->accountData(2));
        $this->assertSame('/workspace/portal/prijavljeni?lang=en', $this->service->resolvePath());
    }

    /**
     * HR: Shorts naslovnica sprema vlastite postavke prikaza bez slobodnog URL polja.
     * EN: A Shorts homepage stores its own display settings without a free-form URL field.
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
     * HR: Kreira tri javno čitljive stranice s objavljenim engleskim verzijama.
     * EN: Creates three publicly readable pages with published English versions.
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
     * HR: Vraća promjenjivi testni Auth kontekst.
     * EN: Returns a mutable test Auth context.
     */
    private function authnHandler(): AuthnHandlerInterface
    {
        return new class implements AuthnHandlerInterface {
            /** @var array<string, mixed>|null */
            private ?array $user = null;

            /**
             * HR: Postavlja testnog korisnika.
             * EN: Sets the test user.
             *
             * @return array<string, mixed>|null
             */
            public function login(mixed $credentials): ?array
            {
                $this->user = is_array($credentials) ? $credentials : null;

                return $this->user;
            }

            /**
             * HR: Odjavljuje testnog korisnika.
             * EN: Signs out the test user.
             */
            public function logout(): void
            {
                $this->user = null;
            }

            /**
             * HR: Vraća stanje prijave.
             * EN: Returns the sign-in state.
             */
            public function check(): bool
            {
                return $this->user !== null;
            }

            /**
             * HR: Vraća testnog korisnika.
             * EN: Returns the test user.
             *
             * @return array<string, mixed>|null
             */
            public function user(): ?array
            {
                return $this->user;
            }

            /**
             * HR: Vraća session podatke testnog korisnika.
             * EN: Returns the test user's session data.
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
