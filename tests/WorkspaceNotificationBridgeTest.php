<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceAccessService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfig;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceNotificationBridge;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceWorkflowService;
use HeartPhrame\Authn\AuthnHandlerInterface;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use HeartPhrame\Routing\UrlGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

#[CoversClass(WorkspaceNotificationBridge::class)]
#[UsesClass(WorkspaceAccessService::class)]
#[UsesClass(WorkspaceConfig::class)]
#[UsesClass(\AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceDataDirectory::class)]
#[UsesClass(\AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfigurationStore::class)]
#[UsesClass(WorkspaceRepository::class)]
#[UsesClass(\AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue::class)]
#[UsesClass(WorkspaceWorkflowService::class)]
final class WorkspaceNotificationBridgeTest extends TestCase
{
    /**
     * EN: Proves that review submission notifies effective publishers while
     *     excluding the action author.
     *
     * HR: Dokazuje da slanje nacrta na pregled obavještava efektivne
     *     objavljivače, ali ne i samog autora radnje.
     */
    public function testPageSubmissionNotifiesOtherPublishers(): void
    {
        require_once __DIR__ . '/Fixtures/OptionalNotificationService.php';

        [$repository, $access, $workspaceConfig] = $this->workspaceServices();
        $workspace = $repository->saveWorkspace([
            'name' => 'Team',
            'slug' => 'team',
            'visibility' => 'restricted',
        ], 3);
        $node = $repository->saveNode((int)$workspace['id'], [
            'title' => 'Roadmap',
            'slug' => 'roadmap',
            'node_type' => 'document',
            'document_key' => 'roadmap',
        ], 3);
        $repository->replaceWorkspaceAcl((int)$workspace['id'], [
            WorkspaceRepository::SUBJECT_USER => [
                7 => [
                    'can_view' => true,
                    'can_add' => false,
                    'can_edit' => true,
                    'can_publish' => true,
                    'can_delete' => false,
                    'can_manage' => false,
                ],
                11 => [
                    'can_view' => true,
                    'can_add' => false,
                    'can_edit' => false,
                    'can_publish' => true,
                    'can_delete' => false,
                    'can_manage' => false,
                ],
            ],
        ]);
        $urlGenerator = $this->createMock(UrlGenerator::class);
        $urlGenerator->method('namedRouteExists')->willReturn(false);
        $urlGenerator->method('getBasePath')->willReturn('/example-app');

        $recorder = new class {
            /**
             * @var list<array<string,mixed>>
             */
            public array $calls = [];

            /**
             * EN: Records a batch message instead of writing to the real inbox.
             *
             * HR: Pamti grupnu poruku umjesto stvarnog upisa u inbox.
             *
             * @param list<int> $userIds
             * @param array<string,mixed> $data
             */
            public function notifyUsers(
                array $userIds,
                string $key,
                string $title,
                string $message,
                string $link = '',
                string $sourceModule = '',
                string $sourceReference = '',
                string $dedupKey = '',
                array $data = [],
                bool $sendEmail = true,
            ): void {
                $this->calls[] = [
                    'user_ids' => $userIds,
                    'key' => $key,
                    'title' => $title,
                    'message' => $message,
                    'link' => $link,
                    'source_module' => $sourceModule,
                    'source_reference' => $sourceReference,
                    'dedup_key' => $dedupKey,
                    'data' => $data,
                    'send_email' => $sendEmail,
                ];
            }
        };
        $container = new class ($recorder) implements ContainerInterface {
            /**
             * EN: Receives the test Notification recorder.
             *
             * HR: Prima testni Notification zapisivač.
             */
            public function __construct(private readonly object $recorder)
            {
            }

            /**
             * EN: Returns the only optional service available in the test.
             *
             * HR: Vraća jedini opcionalni servis dostupan u testu.
             */
            public function get(string $id): object
            {
                if ($id !== \AaiEduHr\HeartPhrameModuleNotification\Service\NotificationService::class) {
                    throw new RuntimeException('Unexpected service: ' . $id);
                }

                return $this->recorder;
            }

            /**
             * EN: Confirms availability of only the test Notification service.
             *
             * HR: Potvrđuje dostupnost samo testnog Notification servisa.
             */
            public function has(string $id): bool
            {
                return $id === \AaiEduHr\HeartPhrameModuleNotification\Service\NotificationService::class;
            }
        };

        $bridge = new WorkspaceNotificationBridge(
            $container,
            $access,
            $urlGenerator,
            $repository,
            $workspaceConfig,
        );
        $bridge->pageSubmitted(
            $workspace,
            $node,
            'hr',
            4,
            7,
        );

        $this->assertCount(1, $recorder->calls);
        $call = $recorder->calls[0];
        $this->assertSame([11], $call['user_ids']);
        $this->assertSame('workspace.review_requested', $call['key']);
        $this->assertSame('/example-app/workspace/team/roadmap?lang=hr&draft=preview', $call['link']);
        $this->assertSame(
            'workspace:review:' . (int)$node['id'] . ':hr:4',
            $call['dedup_key'],
        );
        $this->assertTrue($call['send_email']);
    }

    /**
     * EN: Prepares the real portable Workspace schema and ACL service without a web session.
     *
     * HR: Priprema stvarnu prijenosnu Workspace shemu i ACL servis bez web sesije.
     *
     * @return array{WorkspaceRepository,WorkspaceAccessService,WorkspaceConfig}
     */
    private function workspaceServices(): array
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
        ]);
        $database = new Database($config, $helper);
        $this->createAuthSchema($database);
        $migration = require dirname(__DIR__) . '/resources/migrations/initial_workspace_schema.php';
        $this->assertInstanceOf(ReversibleMigrationInterface::class, $migration);
        $migration->up($database);

        $repository = new WorkspaceRepository($database);
        $workspaceConfig = new WorkspaceConfig($config, dirname(__DIR__));
        $access = new WorkspaceAccessService(
            $repository,
            $this->authnHandler(),
            $workspaceConfig,
            new WorkspaceWorkflowService($repository),
        );

        return [$repository, $access, $workspaceConfig];
    }

    /**
     * EN: Creates minimal Auth tables and three active users for ACL calculation.
     *
     * HR: Kreira minimalne Auth tablice i tri aktivna korisnika za ACL izračun.
     */
    private function createAuthSchema(Database $database): void
    {
        $schema = $database->schema();
        $schema->create('auth_users', static function (Blueprint $table): void {
            $table->id();
            $table->string('login_identifier');
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_active')->default(true);
        });
        $schema->create('auth_groups', static function (Blueprint $table): void {
            $table->id();
            $table->string('group_name');
            $table->boolean('is_enabled')->default(true);
        });
        $schema->create('auth_user_groups', static function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id')->unsigned()->index();
            $table->bigInteger('group_id')->unsigned()->index();
        });
        $schema->create('auth_user_attribute_values', static function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id')->unsigned()->index();
            $table->string('field_key');
            $table->text('value_text')->nullable();
        });

        foreach ([3, 7, 11] as $userId) {
            $database->table('auth_users')->insert([
                'id' => $userId,
                'login_identifier' => 'workspace-user-' . $userId,
                'is_admin' => false,
                'is_active' => true,
            ]);
        }
    }

    /**
     * EN: Returns an empty Auth handler because the test supplies permissions directly.
     *
     * HR: Vraća prazan Auth handler jer test prava uvijek prosljeđuje izravno.
     */
    private function authnHandler(): AuthnHandlerInterface
    {
        return new class implements AuthnHandlerInterface {
            /**
             * EN: The test performs no login.
             *
             * HR: Test ne izvodi prijavu.
             *
             * @return mixed[]|null
             */
            public function login(mixed $credentials): ?array
            {
                return null;
            }

            /**
             * EN: The test has no session to log out.
             *
             * HR: Test nema sesiju za odjavu.
             */
            public function logout(): void
            {
            }

            /**
             * EN: The isolated handler has no authenticated user.
             *
             * HR: Izolirani handler nema prijavljenog korisnika.
             */
            public function check(): bool
            {
                return false;
            }

            /**
             * EN: The isolated handler returns no user.
             *
             * HR: Izolirani handler ne vraća korisnika.
             *
             * @return mixed[]|null
             */
            public function user(): ?array
            {
                return null;
            }

            /**
             * EN: The isolated handler returns no user data.
             *
             * HR: Izolirani handler ne vraća podatke korisnika.
             *
             * @return mixed[]|null
             */
            public function userData(): ?array
            {
                return null;
            }
        };
    }
}
