<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleWorkspace\Tests;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceAccessService;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceConfig;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceEditorBridge;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceShortsService;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceValue;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceWorkflowService;
use HeartPhrame\Authn\AuthnHandlerInterface;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use HeartPhrame\Routing\UrlGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[CoversClass(WorkspaceShortsService::class)]
#[UsesClass(WorkspaceAccessService::class)]
#[UsesClass(WorkspaceConfig::class)]
#[UsesClass(WorkspaceEditorBridge::class)]
#[UsesClass(WorkspaceRepository::class)]
#[UsesClass(WorkspaceValue::class)]
#[UsesClass(WorkspaceWorkflowService::class)]
final class WorkspaceShortsServiceTest extends TestCase
{
    private Database $database;

    private WorkspaceRepository $repository;

    private AuthnHandlerInterface $authn;

    private WorkspaceShortsService $shorts;

    private object $editor;

    /**
     * HR: Priprema stvarno SQLite stablo, workflow i opcionalni batch Editor most.
     * EN: Prepares a real SQLite tree, workflow, and optional batch Editor bridge.
     */
    protected function setUp(): void
    {
        require_once __DIR__ . '/Fixtures/OptionalEditorBatchService.php';

        $helper = new Helper();
        $config = new Config($helper, [
            'database' => [
                'connections' => [
                    'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]);
        $this->database = new Database($config, $helper);
        $this->createAuthSchema();
        $migration = require dirname(__DIR__) . '/resources/migrations/initial_workspace_schema.php';
        $this->assertInstanceOf(ReversibleMigrationInterface::class, $migration);
        $migration->up($this->database);

        $this->repository = new WorkspaceRepository($this->database);
        $this->authn = $this->authnHandler();
        $workspaceConfig = new WorkspaceConfig($config, dirname(__DIR__));
        $workflow = new WorkspaceWorkflowService($this->repository);
        $access = new WorkspaceAccessService(
            $this->repository,
            $this->authn,
            $workspaceConfig,
            $workflow,
        );

        $editor = new \AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorService();
        $editor->documents = [
            'root-doc' => $this->editorDocument('Korijen', 'Korijenski sadržaj'),
            'child-doc' => $this->editorDocument('Dijete', 'Sadržaj druge razine'),
            'grandchild-doc' => $this->editorDocument('Unuk', 'Sadržaj treće razine'),
            'deep-doc' => $this->editorDocument('Duboko', 'Sadržaj četvrte razine'),
            'restricted-doc' => $this->editorDocument('Ograničeno', 'Skriveni sadržaj'),
            'draft-doc' => $this->editorDocument('Nacrt', 'Neobjavljeni sadržaj'),
        ];
        $this->editor = $editor;

        $container = new class ($editor) implements ContainerInterface {
            public function __construct(private readonly object $editor)
            {
            }

            public function get(string $id): mixed
            {
                return $this->editor;
            }

            public function has(string $id): bool
            {
                return true;
            }
        };
        $composer = new class extends ComposerBridge {
            public function isInstalled(string $packageName): bool
            {
                return true;
            }
        };
        $urlGenerator = $this->createMock(UrlGenerator::class);
        $urlGenerator->method('namedRouteExists')->willReturn(true);
        $urlGenerator->method('getPathFor')->willReturnCallback(
            static function (string $name, array $parameters = [], array $query = []): string {
                $path = $name === 'workspace.shorts'
                ? '/workspace/' . $parameters['workspaceSlug'] . '/shorts'
                : '/workspace/' . $parameters['workspaceSlug'] . '/' . ($parameters['nodeSlug'] ?? '');

                return $path . ($query !== [] ? '?' . http_build_query($query) : '');
            },
        );

        $this->shorts = new WorkspaceShortsService(
            $this->repository,
            $access,
            $workflow,
            new WorkspaceEditorBridge($container, $composer, $urlGenerator),
            $workspaceConfig,
            $urlGenerator,
        );
    }

    /**
     * HR: Razine, datum, objavljeno stanje i nasljedni ACL zajedno određuju Sažetke.
     * EN: Depth, date, publication state, and inherited ACL jointly determine Shorts.
     */
    public function testShortsRespectDepthOrderingPublicationAndInheritedAcl(): void
    {
        $workspace = $this->repository->saveWorkspace([
            'name' => 'Sažetci',
            'slug' => 'sazetci',
        ], 1);
        $workspaceId = (int)$workspace['id'];
        $this->repository->replaceWorkspaceAcl($workspaceId, [
            'user' => [
                2 => ['can_view' => true],
                3 => ['can_view' => true],
            ],
        ]);

        $root = $this->node($workspaceId, 'Korijen', 'root-doc');
        $child = $this->node($workspaceId, 'Dijete', 'child-doc', (int)$root['id']);
        $grandchild = $this->node(
            $workspaceId,
            'Unuk',
            'grandchild-doc',
            (int)$child['id'],
        );
        $deep = $this->node($workspaceId, 'Duboko', 'deep-doc', (int)$grandchild['id']);
        $restricted = $this->node($workspaceId, 'Ograničeno', 'restricted-doc');
        $draft = $this->node($workspaceId, 'Nacrt', 'draft-doc');

        $this->publish((int)$root['id'], '2026-01-01 10:00:00');
        $this->publish((int)$child['id'], '2026-01-03 10:00:00');
        $this->publish((int)$grandchild['id'], '2026-01-02 10:00:00');
        $this->publish((int)$deep['id'], '2026-01-04 10:00:00');
        $this->publish((int)$restricted['id'], '2025-12-31 10:00:00');
        $this->repository->saveNodeWorkflow((int)$draft['id'], 'hr', [
            'status' => 'draft',
            'current_version_number' => 1,
        ], 1);
        $this->repository->replaceNodeAcl($workspaceId, (int)$restricted['id'], [
            'user' => [2 => ['can_view' => true]],
        ]);

        $this->authn->login(['id' => 2, 'is_admin' => false]);
        $newest = $this->shorts->viewModel($workspace, 'hr', [
            'depth' => 3,
            'limit' => 'all',
            'order' => 'newest',
        ]);
        $this->assertSame(['Dijete', 'Unuk', 'Korijen', 'Ograničeno'], $this->titles($newest));
        $this->assertSame('all', $newest['limit']);
        $this->assertTrue((bool)$newest['all_available']);
        $this->assertStringContainsString('Sadržaj druge razine', (string)$newest['articles'][0]['html']);
        $this->assertSame('/workspace/sazetci/child-doc?lang=hr', $newest['articles'][0]['href']);

        $this->accessCacheReset();
        $this->authn->login(['id' => 3, 'is_admin' => false]);
        $explicitActor = $this->shorts->viewModel(
            $workspace,
            'hr',
            ['depth' => 1, 'limit' => 'all'],
            'hr',
            ['id' => 2, 'is_admin' => false],
        );
        $this->assertContains('Ograničeno', $this->titles($explicitActor));

        $hierarchy = $this->shorts->viewModel($workspace, 'hr', [
            'depth' => 2,
            'limit' => 10,
            'order' => 'hierarchy',
        ]);
        $this->assertSame(['Korijen', 'Dijete', 'Ograničeno'], $this->titles($hierarchy));
        $this->assertNotContains('Nacrt', $this->titles($hierarchy));
        $this->assertNotContains('Duboko', $this->titles($hierarchy));

        $this->accessCacheReset();
        $this->authn->login(['id' => 1, 'is_admin' => true]);
        $administrator = $this->shorts->viewModel($workspace, 'hr', [
            'depth' => 3,
            'limit' => 'all',
        ]);
        $this->assertNotContains('Nacrt', $this->titles($administrator));
    }

    /**
     * HR: Serverska validacija odbija `all` čim postoji 100 dostupnih članaka,
     *     neovisno o ručno sastavljenom query stringu.
     *
     * EN: Server-side validation rejects `all` as soon as 100 articles are
     *     available, regardless of a hand-crafted query string.
     */
    public function testAllOptionIsRejectedAtOneHundredArticles(): void
    {
        $workspace = $this->repository->saveWorkspace([
            'name' => 'Veliki sažetci',
            'slug' => 'veliki-sazetci',
        ], 1);
        $workspaceId = (int)$workspace['id'];
        $this->repository->replaceWorkspaceAcl($workspaceId, [
            'user' => [2 => ['can_view' => true]],
        ]);
        for ($index = 1; $index <= 100; ++$index) {
            $documentKey = 'article-' . $index;
            $node = $this->node($workspaceId, 'Članak ' . $index, $documentKey);
            $this->publish((int)$node['id'], '2026-01-01 10:00:00');
            $this->editor->documents[$documentKey] = $this->editorDocument(
                'Članak ' . $index,
                'Sadržaj ' . $index,
            );
        }

        $this->authn->login(['id' => 2, 'is_admin' => false]);

        $model = $this->shorts->viewModel($workspace, 'hr', [
            'depth' => 1,
            'limit' => 'all',
            'order' => 'hierarchy',
        ]);

        $this->assertSame(100, $model['total']);
        $this->assertFalse((bool)$model['all_available']);
        $this->assertSame('10', $model['limit']);
        $this->assertCount(10, WorkspaceValue::rows($model['articles'] ?? null));
    }

    /**
     * HR: Nedostajući aktivni jezik koristi samo objavljenu inačicu zadanog jezika sitea.
     * EN: A missing active locale falls back only to the published site-default locale.
     */
    public function testMissingRequestedLanguageFallsBackToPublishedSiteDefaultLanguage(): void
    {
        $workspace = $this->repository->saveWorkspace([
            'name' => 'Višejezični sažetci',
            'slug' => 'multilingual-summaries',
            'visibility' => 'public',
        ], 1);
        $node = $this->node((int)$workspace['id'], 'Default language', 'root-doc');
        $this->repository->saveNodeWorkflow((int)$node['id'], 'en', [
            'status' => 'published',
            'current_version_number' => 1,
            'published_version_number' => 1,
            'published_by_user_id' => 1,
            'published_at' => '2026-08-03 10:00:00',
        ], 1);

        $model = $this->shorts->viewModel($workspace, 'hr', [], 'en');

        $this->assertSame(2, $model['depth']);
        $this->assertSame('10', $model['limit']);
        $this->assertSame('newest', $model['order']);
        $this->assertSame('en', $model['articles'][0]['language']);
        $this->assertSame(
            '/workspace/multilingual-summaries/root-doc?lang=en',
            $model['articles'][0]['href'],
        );
    }

    /**
     * @return array{title:string,html:string,createdAt:string}
     */
    private function editorDocument(string $title, string $content): array
    {
        return [
            'title' => $title,
            'html' => '<p>' . $content . '</p>',
            'createdAt' => '2026-01-01 10:00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function node(int $workspaceId, string $title, string $documentKey, int $parentId = 0): array
    {
        return $this->repository->saveNode($workspaceId, [
            'title' => $title,
            'slug' => $documentKey,
            'node_type' => 'document',
            'document_key' => $documentKey,
            'parent_id' => $parentId > 0 ? $parentId : null,
        ], 1);
    }

    private function publish(int $nodeId, string $publishedAt): void
    {
        $this->repository->saveNodeWorkflow($nodeId, 'hr', [
            'status' => 'published',
            'current_version_number' => 1,
            'published_version_number' => 1,
            'published_by_user_id' => 1,
            'published_at' => $publishedAt,
        ], 1);
    }

    /**
     * @param array<string, mixed> $model
     * @return list<string>
     */
    private function titles(array $model): array
    {
        return array_map(
            static fn(array $article): string => (string)($article['title'] ?? ''),
            WorkspaceValue::rows($model['articles'] ?? null),
        );
    }

    private function accessCacheReset(): void
    {
        $reflection = new \ReflectionProperty($this->shorts, 'access');
        $access = $reflection->getValue($this->shorts);
        $this->assertInstanceOf(WorkspaceAccessService::class, $access);
        $access->clearRequestCache();
    }

    private function authnHandler(): AuthnHandlerInterface
    {
        return new class implements AuthnHandlerInterface {
            /** @var array<string, mixed>|null */
            private ?array $user = null;

            public function login(mixed $credentials): ?array
            {
                $this->user = is_array($credentials) ? $credentials : null;

                return $this->user;
            }

            public function logout(): void
            {
                $this->user = null;
            }

            public function check(): bool
            {
                return $this->user !== null;
            }

            public function user(): ?array
            {
                return $this->user;
            }

            public function userData(): ?array
            {
                return $this->user;
            }
        };
    }

    private function createAuthSchema(): void
    {
        $schema = $this->database->schema();
        $schema->create('auth_users', static function (Blueprint $table): void {
            $table->id();
            $table->string('login_identifier');
            $table->boolean('is_active')->default(true);
        });
        $schema->create('auth_groups', static function (Blueprint $table): void {
            $table->id();
            $table->string('group_name');
            $table->boolean('is_enabled')->default(true);
        });
        $schema->create('auth_user_groups', static function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id')->unsigned();
            $table->bigInteger('group_id')->unsigned();
        });
        $schema->create('auth_user_attribute_values', static function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id')->unsigned();
            $table->string('field_key');
            $table->text('value_text')->nullable();
        });
        foreach ([1, 2, 3] as $userId) {
            $this->database->table('auth_users')->insert([
                'id' => $userId,
                'login_identifier' => 'user' . $userId,
                'is_active' => true,
            ]);
        }
    }
}
