<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleWorkspace\Tests;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\HeartPhrameModuleWorkspace\Api\WorkspaceApiException;
use AaiEduHr\HeartPhrameModuleWorkspace\Api\WorkspaceApiService;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceAccessService;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceConfig;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceValue;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceWorkflowService;
use HeartPhrame\Authn\AuthnHandlerInterface;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * HR: Provjerava API-neutralni Workspace ugovor na prijenosnoj ORM shemi.
 * EN: Verifies the API-neutral Workspace contract on a portable ORM schema.
 */
#[CoversClass(WorkspaceApiService::class)]
#[CoversClass(WorkspaceApiException::class)]
#[UsesClass(WorkspaceAccessService::class)]
#[UsesClass(WorkspaceConfig::class)]
#[UsesClass(WorkspaceRepository::class)]
#[UsesClass(WorkspaceValue::class)]
#[UsesClass(WorkspaceWorkflowService::class)]
final class WorkspaceApiServiceTest extends TestCase
{
    private Database $database;

    private WorkspaceRepository $repository;

    private WorkspaceApiService $service;

    /**
     * HR: Priprema čistu SQLite bazu, minimalne Auth zapise i Workspace servis.
     * EN: Prepares a clean SQLite database, minimal Auth records, and the Workspace service.
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
        ]);
        $this->database = new Database($config, $helper);
        $this->createAuthSchema();

        $migration = require dirname(__DIR__) . '/resources/migrations/initial_workspace_schema.php';
        $this->assertInstanceOf(ReversibleMigrationInterface::class, $migration);
        $migration->up($this->database);

        $this->repository = new WorkspaceRepository($this->database);
        $workspaceConfig = new WorkspaceConfig($config, dirname(__DIR__));
        $access = new WorkspaceAccessService(
            $this->repository,
            $this->authnHandler(),
            $workspaceConfig,
            new WorkspaceWorkflowService($this->repository),
        );
        $this->service = new WorkspaceApiService(
            $this->repository,
            $access,
            $workspaceConfig,
        );
    }

    /**
     * HR: Prolazi administratorski CRUD, ACL, stablo, brisanje i vraćanje područja.
     * EN: Exercises administrator CRUD, ACL, tree, deletion, and Workspace restoration.
     */
    public function testAdministratorCanManageCompleteWorkspaceLifecycle(): void
    {
        $admin = $this->admin();
        $workspace = $this->service->createWorkspace([
            'name' => 'API područje',
            'slug' => 'api-podrucje',
            'description' => 'Workspace API test',
            'tree_visibility' => 'hidden',
            'contents_visibility' => 'shown',
        ], $admin);

        $this->assertSame('api-podrucje', $workspace['slug']);
        $this->assertSame('hidden', $workspace['tree_visibility']);
        $this->assertSame('shown', $workspace['contents_visibility']);
        $this->assertTrue((bool)($workspace['permissions']['can_manage'] ?? false));
        $this->assertSame('API područje', $this->service->getWorkspace('api-podrucje', $admin)['name']);

        $acl = $this->service->replaceWorkspaceAcl('api-podrucje', [
            'subjects' => [[
                'type' => WorkspaceRepository::SUBJECT_USER,
                'id' => 2,
                'permissions' => [
                    'can_view' => true,
                    'can_add' => false,
                    'can_edit' => false,
                    'can_publish' => false,
                    'can_delete' => false,
                    'can_manage' => false,
                ],
            ]],
        ], $admin);
        $this->assertSame('Ana Horvat', $acl[0]['label'] ?? null);

        $document = $this->repository->saveNode((int)$workspace['id'], [
            'title' => 'Dokument roditelj',
            'slug' => 'dokument-roditelj',
            'node_type' => 'document',
            'document_key' => 'workspace-api-parent',
            'sort_order' => 10,
        ], 1);
        $first = $this->service->createLinkNode('api-podrucje', [
            'title' => 'Vanjska dokumentacija',
            'slug' => 'vanjska-dokumentacija',
            'node_type' => 'external_link',
            'target_url' => 'https://example.org/docs',
            'parent_id' => $document['id'],
            'sort_order' => 20,
        ], $admin);
        $second = $this->service->createLinkNode('api-podrucje', [
            'title' => 'Lokalni kalendar',
            'slug' => 'lokalni-kalendar',
            'node_type' => 'internal_link',
            'target_url' => '/calendars',
            'sort_order' => 10,
        ], $admin);

        $updatedNode = $this->service->updateNode(
            'api-podrucje',
            (int)$first['id'],
            ['title' => 'Javna dokumentacija', 'contents_visibility' => 'hidden'],
            $admin,
        );
        $this->assertSame('Javna dokumentacija', $updatedNode['title']);
        $this->assertSame('hidden', $updatedNode['contents_visibility']);

        $this->service->reorderTree('api-podrucje', [
            ['id' => $document['id'], 'parent_id' => null, 'sort_order' => 10],
            ['id' => $first['id'], 'parent_id' => $document['id'], 'sort_order' => 10],
            ['id' => $second['id'], 'parent_id' => null, 'sort_order' => 20],
        ], $admin);
        $tree = $this->service->getTree('api-podrucje', $admin, 'hr');
        $this->assertSame((int)$document['id'], $tree[0]['id'] ?? null);
        $this->assertSame((int)$first['id'], $tree[0]['children'][0]['id'] ?? null);
        $this->assertSame((int)$second['id'], $tree[1]['id'] ?? null);

        $nodeAcl = $this->service->replaceNodeAcl(
            'api-podrucje',
            (int)$first['id'],
            [
                'subjects' => [[
                    'type' => WorkspaceRepository::SUBJECT_USER,
                    'id' => 2,
                    'permissions' => ['can_view' => false],
                ]],
            ],
            $admin,
        );
        $this->assertSame('Ana Horvat', $nodeAcl[0]['label'] ?? null);
        $this->assertFalse((bool)($nodeAcl[0]['permissions']['can_view'] ?? true));

        $subjects = $this->service->searchAclSubjects(
            'api-podrucje',
            WorkspaceRepository::SUBJECT_USER,
            'Ana',
            $admin,
        );
        $this->assertSame('Ana Horvat', $subjects[0]['label'] ?? null);

        $updatedWorkspace = $this->service->updateWorkspace(
            'api-podrucje',
            ['name' => 'Promijenjeno API područje'],
            $admin,
        );
        $this->assertSame('Promijenjeno API područje', $updatedWorkspace['name']);

        $this->service->deleteLinkNode('api-podrucje', (int)$second['id'], $admin);
        $this->assertCount(1, $this->service->getTree('api-podrucje', $admin, 'hr'));

        $workspaceId = (int)$workspace['id'];
        $this->service->deleteWorkspace('api-podrucje', $admin);
        $deleted = $this->service->listDeletedWorkspaces($admin);
        $this->assertSame($workspaceId, $deleted[0]['id'] ?? null);

        $restored = $this->service->restoreWorkspace(
            $workspaceId,
            'api-podrucje-vraceno',
            $admin,
        );
        $this->assertSame('api-podrucje-vraceno', $restored['slug']);
        $this->assertFalse((bool)$restored['is_deleted']);
    }

    /**
     * HR: Dokazuje da Workspace scope ne zaobilazi stvarna prava običnog korisnika.
     * EN: Proves that a Workspace scope does not bypass a regular user's actual rights.
     */
    public function testRegularUserCanReadGrantedWorkspaceButCannotManageIt(): void
    {
        $admin = $this->admin();
        $this->service->createWorkspace([
            'name' => 'Ograničeno područje',
            'slug' => 'ograniceno-podrucje',
        ], $admin);
        $this->service->replaceWorkspaceAcl('ograniceno-podrucje', [
            'subjects' => [[
                'type' => WorkspaceRepository::SUBJECT_USER,
                'id' => 2,
                'permissions' => ['can_view' => true],
            ]],
        ], $admin);
        $this->service->createLinkNode('ograniceno-podrucje', [
            'title' => 'Vidljivi link',
            'node_type' => 'internal_link',
            'target_url' => '/about',
        ], $admin);

        $reader = ['id' => 2, 'is_admin' => false];
        $this->assertCount(1, $this->service->listWorkspaces($reader));
        $this->assertCount(1, $this->service->getTree('ograniceno-podrucje', $reader, 'hr'));

        try {
            $this->service->createLinkNode('ograniceno-podrucje', [
                'title' => 'Nedopušteni link',
                'node_type' => 'internal_link',
                'target_url' => '/forbidden',
            ], $reader);
            $this->fail('A read-only user must not manage Workspace nodes.');
        } catch (WorkspaceApiException $workspaceApiException) {
            $this->assertSame(403, $workspaceApiException->status);
            $this->assertSame('workspace_access_denied', $workspaceApiException->errorCode);
        }

        try {
            $this->service->createWorkspace([
                'name' => 'Nedopušteno područje',
            ], $reader);
            $this->fail('A regular user must follow the Workspace creation policy.');
        } catch (WorkspaceApiException $workspaceApiException) {
            $this->assertSame(403, $workspaceApiException->status);
        }
    }

    /**
     * HR: Vraća administratorski identitet korišten u API servisnim testovima.
     * EN: Returns the administrator identity used by API service tests.
     *
     * @return array<string,mixed>
     */
    private function admin(): array
    {
        return ['id' => 1, 'is_admin' => true];
    }

    /**
     * HR: Vraća izolirani Auth handler jer servisni testovi prosljeđuju korisnika izričito.
     * EN: Returns an isolated Auth handler because service tests pass users explicitly.
     */
    private function authnHandler(): AuthnHandlerInterface
    {
        return new class implements AuthnHandlerInterface {
            /**
             * HR: Test ne izvodi prijavu i zato uvijek vraća null.
             * EN: The test performs no login and therefore always returns null.
             *
             * @return mixed[]|null
             */
            public function login(mixed $credentials): ?array
            {
                return null;
            }

            /**
             * HR: Test nema sesiju koju treba odjaviti.
             * EN: The test has no session to log out.
             */
            public function logout(): void
            {
            }

            /**
             * HR: Izolirani handler nema aktivnog korisnika.
             * EN: The isolated handler has no active user.
             */
            public function check(): bool
            {
                return false;
            }

            /**
             * HR: Izolirani handler ne vraća autentificiranog korisnika.
             * EN: The isolated handler returns no authenticated user.
             *
             * @return mixed[]|null
             */
            public function user(): ?array
            {
                return null;
            }

            /**
             * HR: Izolirani handler ne vraća korisničke podatke.
             * EN: The isolated handler returns no user data.
             *
             * @return mixed[]|null
             */
            public function userData(): ?array
            {
                return null;
            }
        };
    }

    /**
     * HR: Kreira minimalne Auth tablice koje Workspace koristi za vlasnike i ACL subjekte.
     * EN: Creates the minimal Auth tables used by Workspace for owners and ACL subjects.
     */
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
            $table->bigInteger('user_id')->unsigned()->index();
            $table->bigInteger('group_id')->unsigned()->index();
        });
        $schema->create('auth_user_attribute_values', static function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id')->unsigned()->index();
            $table->string('field_key');
            $table->text('value_text')->nullable();
        });

        foreach ([1, 2] as $userId) {
            $this->database->table('auth_users')->insert([
                'id' => $userId,
                'login_identifier' => 'api-user-' . $userId,
                'is_active' => true,
            ]);
        }

        $this->database->table('auth_user_attribute_values')->insert([
            'user_id' => 2,
            'field_key' => 'display_name',
            'value_text' => 'Ana Horvat',
        ]);
    }
}
