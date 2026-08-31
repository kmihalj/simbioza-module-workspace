<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\QueryExecuted;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceAccessService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceConfig;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceWorkflowService;
use HeartPhrame\Authn\AuthnHandlerInterface;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(WorkspaceAccessService::class)]
#[UsesClass(WorkspaceRepository::class)]
#[UsesClass(WorkspaceConfig::class)]
#[UsesClass(WorkspaceValue::class)]
#[UsesClass(WorkspaceWorkflowService::class)]
final class WorkspaceAccessServiceTest extends TestCase
{
    private Database $database;

    private WorkspaceRepository $repository;

    private AuthnHandlerInterface $authn;

    private WorkspaceAccessService $access;

    /**
     * HR: Priprema prijenosnu SQLite shemu, minimalne Auth subjekte i ACL servis za svaki test.
     * EN: Prepares a portable SQLite schema, minimal Auth subjects, and the ACL service for each test.
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
        $this->authn = new class implements AuthnHandlerInterface {
            /**
             * @var mixed[]|null
             */
            private ?array $user = null;

            /**
             * HR: Sprema testnog korisnika bez izvođenja stvarne autentikacije.
             * EN: Stores the test user without performing real authentication.
             *
             * @return mixed[]|null
             */
            public function login(mixed $credentials): ?array
            {
                $this->user = is_array($credentials) ? $credentials : null;
                return $this->user;
            }

            /**
             * HR: Uklanja aktivnog testnog korisnika.
             * EN: Removes the active test user.
             */
            public function logout(): void
            {
                $this->user = null;
            }

            /**
             * HR: Vraća postoji li aktivni testni korisnik.
             * EN: Reports whether an active test user exists.
             */
            public function check(): bool
            {
                return $this->user !== null;
            }

            /**
             * HR: Vraća aktivnog testnog korisnika.
             * EN: Returns the active test user.
             *
             * @return mixed[]|null
             */
            public function user(): ?array
            {
                return $this->user;
            }

            /**
             * HR: Vraća podatke aktivnog testnog korisnika.
             * EN: Returns the active test user's data.
             *
             * @return mixed[]|null
             */
            public function userData(): ?array
            {
                return $this->user;
            }
        };
        $this->access = new WorkspaceAccessService(
            $this->repository,
            $this->authn,
            new WorkspaceConfig($config, dirname(__DIR__)),
            new WorkspaceWorkflowService($this->repository),
        );
    }

    /**
     * HR: Kreator novog područja dobiva upravljanje preko običnog korisničkog ACL-a.
     * EN: A new Workspace creator receives management through a regular user ACL entry.
     */
    public function testWorkspaceCreatorReceivesManagePermissionThroughAcl(): void
    {
        $workspace = $this->repository->saveWorkspace([
            'name' => 'Kreator',
            'slug' => 'kreator',
            'visibility' => 'restricted',
        ], 2);
        $this->authn->login(['id' => 2, 'is_admin' => false]);

        $permissions = $this->access->workspacePermissions($workspace);

        $this->assertTrue($permissions['can_view']);
        $this->assertTrue($permissions['can_manage']);
    }

    /**
     * HR: Sistemski izvršitelj ostaje u auditu, dok zasebni početni upravitelj
     *     dobiva svih šest prava kroz jedan korisnički ACL redak.
     * EN: The system actor remains in the audit trail while a separate initial
     *     manager receives all six permissions through one user ACL row.
     */
    public function testWorkspaceMayGrantInitialManagementToAnotherUser(): void
    {
        $workspace = $this->repository->saveWorkspace([
            'name' => 'Osobno područje',
            'slug' => 'osobno-podrucje',
            'visibility' => 'restricted',
        ], 1, 3);
        $this->authn->login(['id' => 3, 'is_admin' => false]);

        $this->assertSame(1, (int)$workspace['created_by_user_id']);
        $this->assertSame([
            'can_view' => true,
            'can_add' => true,
            'can_edit' => true,
            'can_publish' => true,
            'can_delete' => true,
            'can_manage' => true,
        ], $this->access->workspacePermissions($workspace));

        $acl = $this->repository->workspaceAclRows((int)$workspace['id']);
        $this->assertCount(1, $acl);
        $this->assertSame(3, (int)$acl[0]['subject_id']);
    }

    /**
     * HR: Kreiranje područja dopušta administratoru te konfiguriranim korisnicima i grupama.
     * EN: Workspace creation allows administrators and configured users or groups.
     */
    public function testWorkspaceCreationUsesConfiguredUsersAndGroups(): void
    {
        $appRoot = sys_get_temp_dir() . '/workspace_creators_' . uniqid();
        mkdir($appRoot . '/config', 0777, true);
        file_put_contents(
            $appRoot . '/config/workspace.php',
            "<?php return ['creation' => ['users' => [3], 'groups' => [10]]];",
        );
        $config = new class (new Helper(), [], $appRoot) extends Config {
            public function __construct(Helper $helper, array $data, private readonly string $appRoot)
            {
                parent::__construct($helper, $data);
            }

            public function getAppRootDir(): string
            {
                return $this->appRoot;
            }
        };
        $access = new WorkspaceAccessService(
            $this->repository,
            $this->authn,
            new WorkspaceConfig($config, dirname(__DIR__)),
            new WorkspaceWorkflowService($this->repository),
        );

        $this->assertTrue($access->canCreateWorkspace(['id' => 3, 'is_admin' => false]));
        $this->assertTrue($access->canCreateWorkspace(['id' => 2, 'is_admin' => false]));
        $this->assertFalse($access->canCreateWorkspace(['id' => 4, 'is_admin' => false]));
        $this->assertTrue($access->canCreateWorkspace(['id' => 4, 'is_admin' => true]));

        unlink($appRoot . '/config/workspace.php');
        rmdir($appRoot . '/config');
        rmdir($appRoot);
    }

    /**
     * HR: Dokazuje da se prava korisnika i grupa zbrajaju, a ograničenje roditelja sužava prava potomka.
     * EN: Proves that user and group grants are combined while a parent restriction narrows descendants.
     */
    public function testUserAndGroupRightsAreCombinedAndNodeRestrictionsAreInherited(): void
    {
        $workspace = $this->repository->saveWorkspace([
            'name' => 'Projekt',
            'slug' => 'projekt',
            'visibility' => 'restricted',
        ], 1);
        $workspaceId = (int)$workspace['id'];

        $this->repository->replaceWorkspaceAcl($workspaceId, [
            'user' => [
                2 => ['can_view' => true, 'can_edit' => true],
                4 => ['can_view' => true],
            ],
            'group' => [
                10 => ['can_view' => true, 'can_add' => true],
            ],
        ]);

        $root = $this->repository->saveNode($workspaceId, [
            'title' => 'Korijen',
            'slug' => 'korijen',
            'node_type' => 'document',
            'document_key' => 'root-document',
        ], 1);
        $child = $this->repository->saveNode($workspaceId, [
            'title' => 'Potomak',
            'slug' => 'potomak',
            'node_type' => 'document',
            'document_key' => 'child-document',
            'parent_id' => $root['id'],
        ], 1);

        $this->authn->login(['id' => 2, 'is_admin' => false]);
        $base = $this->access->workspacePermissions($workspace);
        $this->assertTrue($base['can_view']);
        $this->assertTrue($base['can_add']);
        $this->assertTrue($base['can_edit']);
        $this->assertFalse($base['can_delete']);
        $this->assertFalse($base['can_manage']);

        $this->repository->replaceNodeAcl($workspaceId, (int)$root['id'], [
            'user' => [
                2 => ['can_view' => true],
                3 => ['can_view' => true, 'can_edit' => true],
            ],
        ]);
        $storedRestrictions = $this->repository->nodeAclRows((int)$root['id']);
        $this->assertCount(1, $storedRestrictions);
        $this->assertSame(WorkspaceRepository::SUBJECT_USER, $storedRestrictions[0]['subject_type']);
        $this->assertSame(2, (int)$storedRestrictions[0]['subject_id']);

        $restricted = $this->access->nodePermissions($workspace, $child);
        $this->assertTrue($restricted['can_view']);
        $this->assertFalse($restricted['can_add']);
        $this->assertFalse($restricted['can_edit']);
        $this->assertFalse($restricted['can_delete']);
        $this->assertFalse($restricted['can_manage']);
        $this->assertTrue($this->access->canUseDocument('child-document', 'can_view'));
        $this->assertFalse($this->access->canUseDocument('child-document', 'can_edit'));

        $this->authn->login(['id' => 3, 'is_admin' => false]);
        $this->assertFalse($this->access->workspacePermissions($workspace)['can_view']);
        $this->assertFalse($this->access->canUseDocument('child-document', 'can_view'));

        $this->authn->login(['id' => 4, 'is_admin' => false]);
        $this->assertTrue($this->access->workspacePermissions($workspace)['can_view']);
        $this->assertCount(1, $this->access->visibleTree($workspace));
        $this->assertTrue($this->access->nodePermissions($workspace, $child)['can_view']);

        $this->authn->login(['id' => 1, 'is_admin' => false]);
        $this->assertFalse($this->access->nodePermissions($workspace, $child)['can_manage']);
    }

    /**
     * HR: Dokazuje da paketni ACL izračun zadržava nasljeđivanje roditeljskih
     *     ograničenja za više čvorova bez zasebnog izračuna svakoga čvora.
     * EN: Proves that batched ACL calculation preserves inherited parent
     *     restrictions for multiple nodes without calculating each node separately.
     */
    public function testBatchedNodePermissionsPreserveInheritedRestrictions(): void
    {
        $workspace = $this->repository->saveWorkspace([
            'name' => 'Paketna prava',
            'slug' => 'paketna-prava',
            'visibility' => 'restricted',
        ], 1);
        $workspaceId = (int)$workspace['id'];
        $this->repository->replaceWorkspaceAcl($workspaceId, [
            'user' => [
                2 => ['can_view' => true, 'can_add' => true, 'can_edit' => true],
            ],
        ]);
        $root = $this->repository->saveNode($workspaceId, [
            'title' => 'Korijen',
            'node_type' => 'document',
            'document_key' => 'batch-root',
        ], 1);
        $child = $this->repository->saveNode($workspaceId, [
            'title' => 'Potomak',
            'node_type' => 'document',
            'document_key' => 'batch-child',
            'parent_id' => $root['id'],
        ], 1);
        $this->repository->replaceNodeAcl($workspaceId, (int)$root['id'], [
            'user' => [
                2 => ['can_view' => true],
            ],
        ]);
        $this->authn->login(['id' => 2, 'is_admin' => false]);

        $permissions = $this->access->nodePermissionsForNodes(
            $workspace,
            $this->repository->nodesForWorkspace($workspaceId),
        );
        $rootPermissions = $permissions[(int)$root['id']] ?? [];
        $childPermissions = $permissions[(int)$child['id']] ?? [];

        $this->assertTrue((bool)($rootPermissions['can_view'] ?? false));
        $this->assertFalse((bool)($rootPermissions['can_edit'] ?? true));
        $this->assertSame($rootPermissions, $childPermissions);
    }

    /**
     * HR: Stari grupni red ograničenja više ne može oduzeti prava članovima grupe.
     * EN: A legacy group-restriction row can no longer remove rights from group members.
     */
    public function testLegacyGroupRestrictionsAreIgnored(): void
    {
        $workspace = $this->repository->saveWorkspace([
            'name' => 'Korisnička ograničenja',
            'slug' => 'korisnicka-ogranicenja',
            'visibility' => 'restricted',
        ], 1);
        $workspaceId = (int)$workspace['id'];
        $this->repository->replaceWorkspaceAcl($workspaceId, [
            'group' => [10 => ['can_view' => true, 'can_edit' => true]],
        ]);
        $node = $this->repository->saveNode($workspaceId, [
            'title' => 'Stranica',
            'node_type' => 'document',
            'document_key' => 'legacy-group-restriction',
        ], 1);
        $this->database->table('workspace_node_acl')->insert([
            'node_id' => $node['id'],
            'subject_type' => WorkspaceRepository::SUBJECT_GROUP,
            'subject_id' => 10,
            'can_view' => true,
            'can_add' => false,
            'can_edit' => false,
            'can_publish' => false,
            'can_delete' => false,
            'can_manage' => false,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        $this->authn->login(['id' => 2, 'is_admin' => false]);

        $permissions = $this->access->nodePermissions($workspace, $node);

        $this->assertTrue($permissions['can_view']);
        $this->assertTrue($permissions['can_edit']);
    }

    /**
     * HR: Picker ograničenja vraća korisnika s ujedinjenim izravnim i grupnim
     *     pravima te s već primijenjenim ograničenjima predaka.
     * EN: The restriction picker returns a user with merged direct and group
     *     permissions and with ancestor restrictions already applied.
     */
    public function testNodeEditorSubjectsSeparateInheritedAndDirectRestrictions(): void
    {
        $workspace = $this->repository->saveWorkspace([
            'name' => 'Nasljedna prava',
            'slug' => 'nasljedna-prava',
            'visibility' => 'restricted',
        ], 1);
        $workspaceId = (int)$workspace['id'];
        $this->repository->replaceWorkspaceAcl($workspaceId, [
            'user' => [
                2 => ['can_view' => true, 'can_edit' => true],
            ],
            'group' => [
                10 => ['can_view' => true, 'can_add' => true],
            ],
        ]);
        $root = $this->repository->saveNode($workspaceId, [
            'title' => 'Korijen',
            'node_type' => 'document',
            'document_key' => 'inherited-editor-root',
        ], 1);
        $child = $this->repository->saveNode($workspaceId, [
            'title' => 'Potomak',
            'node_type' => 'document',
            'document_key' => 'inherited-editor-child',
            'parent_id' => $root['id'],
        ], 1);
        $this->repository->replaceNodeAcl($workspaceId, (int)$root['id'], [
            'user' => [
                2 => ['can_view' => true],
            ],
        ]);
        $rootSubjects = $this->repository->restrictionUserSubjectsAtNode(
            $workspaceId,
            (int)$root['id'],
            [2],
        );
        $rootUser = $rootSubjects[0];
        $this->assertTrue((bool)$rootUser['can_add']);
        $this->assertTrue((bool)$rootUser['can_edit']);

        $childSubjects = $this->repository->restrictionUserSubjectsAtNode(
            $workspaceId,
            (int)$child['id'],
            [2],
        );
        $childUser = $childSubjects[0];
        $this->assertTrue((bool)$childUser['can_view']);
        $this->assertFalse((bool)$childUser['can_add']);
        $this->assertFalse((bool)$childUser['can_edit']);

        $search = $this->repository->searchRestrictionUsers(
            $workspaceId,
            (int)$child['id'],
            'Ana',
        );
        $this->assertSame('Ana Horvat', $search[0]['label'] ?? null);
        $this->assertTrue((bool)($search[0]['can_view'] ?? false));
        $this->assertSame([], $this->repository->searchRestrictionUsers(
            $workspaceId,
            (int)$child['id'],
            'user3',
        ));
    }

    /**
     * HR: Izravno pravo otvara samo ciljnu stranicu i njezino područje, bez
     *     otkrivanja roditelja ili nasljeđivanja prava na potomke.
     * EN: A direct grant opens only its target page and Workspace without
     *     revealing parents or inheriting the grant to descendants.
     */
    public function testDirectPermissionExposesOnlyTheGrantedPageAndWorkspace(): void
    {
        $workspace = $this->repository->saveWorkspace([
            'name' => 'Izravni pristup',
            'slug' => 'izravni-pristup',
            'visibility' => 'restricted',
        ], 1);
        $workspaceId = (int)$workspace['id'];
        $root = $this->repository->saveNode($workspaceId, [
            'title' => 'Skriveni roditelj',
            'slug' => 'skriveni-roditelj',
            'node_type' => 'document',
            'document_key' => 'direct-hidden-parent',
        ], 1);
        $page = $this->repository->saveNode($workspaceId, [
            'title' => 'Dopuštena stranica',
            'slug' => 'dopustena-stranica',
            'node_type' => 'document',
            'document_key' => 'direct-granted-page',
            'parent_id' => $root['id'],
        ], 1);
        $child = $this->repository->saveNode($workspaceId, [
            'title' => 'Nedopušteni potomak',
            'slug' => 'nedopusteni-potomak',
            'node_type' => 'document',
            'document_key' => 'direct-hidden-child',
            'parent_id' => $page['id'],
        ], 1);
        $this->repository->replaceNodeDirectPermissions($workspaceId, (int)$page['id'], [
            3 => ['can_edit' => true],
        ]);
        $this->authn->login(['id' => 3, 'is_admin' => false]);

        $this->assertFalse($this->access->workspacePermissions($workspace)['can_view']);
        $this->assertTrue($this->access->canAccessWorkspace($workspace));
        $this->assertCount(1, $this->access->visibleWorkspaces());
        $this->assertFalse($this->access->nodePermissions($workspace, $root)['can_view']);
        $pagePermissions = $this->access->nodePermissions($workspace, $page);
        $this->assertTrue($pagePermissions['can_view']);
        $this->assertTrue($pagePermissions['can_edit']);
        $this->assertFalse($pagePermissions['can_publish']);
        $this->assertFalse($pagePermissions['can_manage']);
        $this->assertFalse($this->access->nodePermissions($workspace, $child)['can_view']);

        $tree = $this->access->visibleTreeWindowForLanguages($workspace, null, []);
        $this->assertCount(1, $tree);
        $this->assertSame((int)$page['id'], (int)$tree[0]['id']);
        $this->assertNull($tree[0]['parent_id']);
        $this->assertSame([], $tree[0]['children']);
    }

    /**
     * HR: Ograničenja sužavaju naslijeđena prava, ali ne mijenjaju zasebno
     *     izravno pravo na ciljnoj stranici.
     * EN: Restrictions narrow inherited permissions but do not alter a separate
     *     direct grant on the target page.
     */
    public function testInheritedRestrictionDoesNotConsumeDirectPermission(): void
    {
        $workspace = $this->repository->saveWorkspace([
            'name' => 'Odvojena prava',
            'slug' => 'odvojena-prava',
            'visibility' => 'restricted',
        ], 1);
        $workspaceId = (int)$workspace['id'];
        $this->repository->replaceWorkspaceAcl($workspaceId, [
            'user' => [2 => ['can_view' => true, 'can_edit' => true]],
        ]);
        $root = $this->repository->saveNode($workspaceId, [
            'title' => 'Ograničeni roditelj',
            'node_type' => 'document',
            'document_key' => 'restricted-direct-root',
        ], 1);
        $page = $this->repository->saveNode($workspaceId, [
            'title' => 'Izravno uređivanje',
            'node_type' => 'document',
            'document_key' => 'restricted-direct-page',
            'parent_id' => $root['id'],
        ], 1);
        $child = $this->repository->saveNode($workspaceId, [
            'title' => 'Naslijeđeni potomak',
            'node_type' => 'document',
            'document_key' => 'restricted-direct-child',
            'parent_id' => $page['id'],
        ], 1);
        $this->repository->replaceNodeAcl($workspaceId, (int)$root['id'], [
            'user' => [2 => ['can_view' => true]],
        ]);
        $this->repository->replaceNodeDirectPermissions($workspaceId, (int)$page['id'], [
            2 => ['can_edit' => true],
        ]);
        $this->authn->login(['id' => 2, 'is_admin' => false]);

        $this->assertFalse($this->access->nodePermissions($workspace, $root)['can_edit']);
        $this->assertTrue($this->access->nodePermissions($workspace, $page)['can_edit']);
        $this->assertFalse($this->access->nodePermissions($workspace, $child)['can_edit']);
    }

    /**
     * HR: Dokazuje da se kratkotrajni ACL cache može isprazniti nakon izmjene
     *     prava te sljedeći izračun čita novo stanje.
     * EN: Proves that the short-lived ACL cache can be cleared after a
     *     permission change so the next calculation reads the new state.
     */
    public function testRequestCacheIsClearedAfterPermissionChanges(): void
    {
        $workspace = $this->repository->saveWorkspace([
            'name' => 'Promjena prava',
            'slug' => 'promjena-prava',
            'visibility' => 'restricted',
        ], 1);
        $workspaceId = (int)$workspace['id'];
        $this->repository->replaceWorkspaceAcl($workspaceId, [
            'user' => [
                2 => ['can_view' => true],
            ],
        ]);
        $this->authn->login(['id' => 2, 'is_admin' => false]);

        $this->assertTrue($this->access->workspacePermissions($workspace)['can_view']);

        $this->repository->replaceWorkspaceAcl($workspaceId, []);
        $this->access->clearRequestCache();

        $this->assertFalse($this->access->workspacePermissions($workspace)['can_view']);
    }

    /**
     * HR: Dokazuje da običan korisnik učitava ACL cijelog popisa jednim upitom,
     *     dok administrator koristi brzi put bez nepotrebnog ACL čitanja.
     * EN: Proves that a regular user loads listing ACL in one query while an
     *     administrator uses the fast path without an unnecessary ACL read.
     */
    public function testVisibleWorkspaceListingBatchesAclRowsAndSkipsThemForAdministrator(): void
    {
        for ($index = 1; $index <= 3; ++$index) {
            $workspace = $this->repository->saveWorkspace([
                'name' => 'Paketno područje ' . $index,
                'slug' => 'paketno-podrucje-' . $index,
                'visibility' => 'restricted',
            ], 1);
            $this->repository->replaceWorkspaceAcl((int)$workspace['id'], [
                'user' => [2 => ['can_view' => true]],
            ]);
        }

        $aclQueries = [];
        $directQueries = [];
        $this->database->listen(static function (QueryExecuted $query) use (&$aclQueries, &$directQueries): void {
            if (str_contains($query->sql, 'FROM "workspace_acl"')) {
                $aclQueries[] = $query->sql;
            }

            if (str_contains($query->sql, 'workspace_node_direct_permissions')) {
                $directQueries[] = $query->sql;
            }
        });
        $this->authn->login(['id' => 2, 'is_admin' => false]);

        $this->assertCount(3, $this->access->visibleWorkspaces());
        $this->assertCount(1, $aclQueries);
        $this->assertCount(1, $directQueries);
        $this->assertStringContainsString('"workspace_id" IN (?, ?, ?)', $aclQueries[0]);

        $this->database->forgetQueryListeners();
        $this->access->clearRequestCache();
        $administratorAclQueries = [];
        $administratorDirectQueries = [];
        $this->database->listen(static function (QueryExecuted $query) use (
            &$administratorAclQueries,
            &$administratorDirectQueries,
        ): void {
            if (str_contains($query->sql, 'FROM "workspace_acl"')) {
                $administratorAclQueries[] = $query->sql;
            }

            if (str_contains($query->sql, 'workspace_node_direct_permissions')) {
                $administratorDirectQueries[] = $query->sql;
            }
        });
        $this->authn->login(['id' => 3, 'is_admin' => true]);

        $this->assertCount(3, $this->access->visibleWorkspaces());
        $this->assertSame([], $administratorAclQueries);
        $this->assertSame([], $administratorDirectQueries);
    }

    /**
     * HR: Paketni izračun stabla čita izravna prava svih stranica jednim upitom.
     * EN: Batched tree permission calculation reads all direct page grants in one query.
     */
    public function testBatchedNodePermissionsReadDirectGrantsOnce(): void
    {
        $workspace = $this->repository->saveWorkspace([
            'name' => 'Paketne izravne dozvole',
            'slug' => 'paketne-izravne-dozvole',
            'visibility' => 'restricted',
        ], 1);
        $workspaceId = (int)$workspace['id'];
        $this->repository->replaceWorkspaceAcl($workspaceId, [
            'user' => [2 => ['can_view' => true]],
        ]);
        for ($index = 1; $index <= 3; ++$index) {
            $this->repository->saveNode($workspaceId, [
                'title' => 'Stranica ' . $index,
                'node_type' => 'document',
                'document_key' => 'batch-direct-' . $index,
            ], 1);
        }

        $directQueries = [];
        $this->database->listen(static function (QueryExecuted $query) use (&$directQueries): void {
            if (str_contains($query->sql, 'FROM "workspace_node_direct_permissions"')) {
                $directQueries[] = $query->sql;
            }
        });
        $this->authn->login(['id' => 2, 'is_admin' => false]);

        $permissions = $this->access->nodePermissionsForNodes(
            $workspace,
            $this->repository->nodesForWorkspace($workspaceId),
        );

        $this->assertCount(3, $permissions);
        $this->assertCount(1, $directQueries);
    }

    /**
     * HR: Dokazuje da arhiva ostavlja pregled i upravljanje postavkama, ali zaključava sadržaj manageru i adminu.
     * EN: Proves that archive keeps viewing and settings management while locking content for a manager and admin.
     */
    public function testArchivedWorkspaceIsReadOnlyForManagerAndAdministrator(): void
    {
        $workspace = $this->repository->saveWorkspace([
            'name' => 'Arhiva',
            'slug' => 'arhiva',
            'visibility' => 'restricted',
            'is_archived' => true,
        ], 1);
        $node = $this->repository->saveNode((int)$workspace['id'], [
            'title' => 'Dokument',
            'node_type' => 'document',
            'document_key' => 'archived-document',
        ], 1);

        foreach (
            [
                ['id' => 1, 'is_admin' => false],
                ['id' => 2, 'is_admin' => true],
            ] as $user
        ) {
            $this->authn->login($user);
            $permissions = $this->access->nodePermissions($workspace, $node);

            $this->assertTrue($permissions['can_view']);
            $this->assertFalse($permissions['can_add']);
            $this->assertFalse($permissions['can_edit']);
            $this->assertFalse($permissions['can_delete']);
            $this->assertTrue($permissions['can_manage']);
        }
    }

    /**
     * HR: Dokazuje da ugrađena publika Javno ima samo pregled, Svi prijavljeni
     *     može dobiti šira prava te picker pretražuje ograničen Auth imenik.
     * EN: Proves that the built-in Public audience is view-only, All signed-in
     *     users may receive broader rights, and the picker searches a bounded Auth directory.
     */
    public function testBuiltInAudiencesAndDirectorySearch(): void
    {
        $workspace = $this->repository->saveWorkspace([
            'name' => 'Publike',
            'slug' => 'publike',
            'visibility' => 'restricted',
        ], 1);
        $workspaceId = (int)$workspace['id'];
        $this->repository->replaceWorkspaceAcl($workspaceId, [
            WorkspaceRepository::SUBJECT_PUBLIC => [
                WorkspaceRepository::BUILT_IN_SUBJECT_ID => ['can_manage' => true],
            ],
            WorkspaceRepository::SUBJECT_AUTHENTICATED => [
                WorkspaceRepository::BUILT_IN_SUBJECT_ID => ['can_add' => true],
            ],
        ]);

        $savedWorkspace = $this->repository->findWorkspaceById($workspaceId);
        $this->assertIsArray($savedWorkspace);
        $this->assertSame('public', $savedWorkspace['visibility'] ?? null);

        $guestPermissions = $this->access->workspacePermissions($savedWorkspace);
        $this->assertTrue($guestPermissions['can_view']);
        $this->assertFalse($guestPermissions['can_add']);
        $this->assertFalse($guestPermissions['can_manage']);

        $this->authn->login(['id' => 3, 'is_admin' => false]);
        $authenticatedPermissions = $this->access->workspacePermissions($savedWorkspace);
        $this->assertTrue($authenticatedPermissions['can_view']);
        $this->assertTrue($authenticatedPermissions['can_add']);
        $this->assertFalse($authenticatedPermissions['can_edit']);

        $subjects = $this->repository->workspaceAclSubjects($workspaceId);
        $public = array_values(array_filter(
            $subjects,
            static fn(array $subject): bool =>
                ($subject['subject_type'] ?? '') === WorkspaceRepository::SUBJECT_PUBLIC,
        ));
        $this->assertCount(1, $public);
        $this->assertTrue((bool)($public[0]['can_view'] ?? false));
        $this->assertFalse((bool)($public[0]['can_add'] ?? true));
        $this->assertTrue((bool)($public[0]['is_read_only'] ?? false));

        $users = $this->repository->searchDirectorySubjects(
            WorkspaceRepository::SUBJECT_USER,
            'Ana',
        );
        $this->assertSame('Ana Horvat', $users[0]['label'] ?? null);
        $this->assertArrayNotHasKey('password_hash', $users[0]);
        $this->assertSame(
            ['id', 'label', 'type', 'category', 'is_builtin', 'is_read_only'],
            array_keys($users[0]),
        );
        $sortedUsers = $this->repository->searchDirectorySubjects(
            WorkspaceRepository::SUBJECT_USER,
            'user',
        );
        $this->assertSame(
            ['Sara Babic', 'Ana Horvat', 'Borna Kovac', 'Alen Zec'],
            array_column($sortedUsers, 'label'),
        );

        $groups = $this->repository->searchDirectorySubjects(
            WorkspaceRepository::SUBJECT_GROUP,
            'Jav',
        );
        $this->assertSame(WorkspaceRepository::SUBJECT_PUBLIC, $groups[0]['type'] ?? null);
        $this->assertTrue((bool)($groups[0]['is_read_only'] ?? false));
    }

    /**
     * HR: Provjerava da interni link prihvaća samo lokalnu putanju, a vanjski URL mora koristiti vanjski tip čvora.
     * EN: Verifies that an internal link accepts only a local path while an external URL requires an external node.
     */
    public function testInternalLinkRejectsAnExternalTarget(): void
    {
        $workspace = $this->repository->saveWorkspace([
            'name' => 'Linkovi',
        ], 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Interna putanja nije valjana.');
        $this->repository->saveNode((int)$workspace['id'], [
            'title' => 'Pogrešan interni link',
            'node_type' => 'internal_link',
            'target_url' => 'https://example.org',
        ], 1);
    }

    /**
     * HR: Provjerava valjanu lokalnu putanju, jedinstveno vlasništvo dokumenta i zabranu ciklusa u hijerarhiji.
     * EN: Verifies a valid local path, unique document ownership, and cycle prevention in the hierarchy.
     */
    public function testNodeOwnershipAndHierarchyAreValidated(): void
    {
        $workspace = $this->repository->saveWorkspace([
            'name' => 'Stablo',
        ], 1);
        $workspaceId = (int)$workspace['id'];
        $internalLink = $this->repository->saveNode($workspaceId, [
            'title' => 'Kalendar',
            'node_type' => 'internal_link',
            'target_url' => '/calendars?mode=month',
        ], 1);
        $this->assertSame('/calendars?mode=month', $internalLink['target_url']);

        $root = $this->repository->saveNode($workspaceId, [
            'title' => 'Korijen',
            'node_type' => 'document',
            'document_key' => 'owned-document',
        ], 1);
        $this->assertSame('korijen', $root['slug']);
        try {
            $this->repository->saveNode($workspaceId, [
                'title' => 'Duplikat',
                'node_type' => 'document',
                'document_key' => 'owned-document',
            ], 1);
            $this->fail('A document key must not belong to two active nodes.');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame(
                'HTML dokument već pripada drugoj stranici područja.',
                $runtimeException->getMessage(),
            );
        }

        $child = $this->repository->saveNode($workspaceId, [
            'title' => 'Potomak',
            'node_type' => 'document',
            'document_key' => 'child-owned-document',
            'parent_id' => $root['id'],
        ], 1);
        try {
            $this->repository->saveNode($workspaceId, [
                ...$root,
                'parent_id' => $child['id'],
            ], 1);
            $this->fail('A node must not be moved into its own subtree.');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame(
                'Stranicu nije moguće premjestiti u vlastitu podgranu.',
                $runtimeException->getMessage(),
            );
        }
    }

    /**
     * HR: Aktivna stranica ostaje na spremljenoj poziciji iako je prozor stabla
     *     dohvaća ranije kako bi otvorio njezinu granu.
     * EN: The active page keeps its persisted position even when the tree window
     *     fetches it earlier so that its branch can be expanded.
     */
    public function testActiveTreeNodeDoesNotChangePersistedSiblingOrder(): void
    {
        $workspace = $this->repository->saveWorkspace([
            'name' => 'Stabilni poredak',
            'visibility' => 'public',
        ], 1);
        $workspaceId = (int)$workspace['id'];
        $root = $this->repository->saveNode($workspaceId, [
            'title' => 'Korijen',
            'node_type' => 'document',
            'document_key' => 'stable-tree-root',
        ], 1);
        $first = $this->repository->saveNode($workspaceId, [
            'title' => 'Prva',
            'node_type' => 'document',
            'document_key' => 'stable-tree-first',
            'parent_id' => $root['id'],
        ], 1);
        $second = $this->repository->saveNode($workspaceId, [
            'title' => 'Druga',
            'node_type' => 'document',
            'document_key' => 'stable-tree-second',
            'parent_id' => $root['id'],
        ], 1);
        $active = $this->repository->saveNode($workspaceId, [
            'title' => 'Aktualno',
            'node_type' => 'document',
            'document_key' => 'stable-tree-active',
            'parent_id' => $root['id'],
        ], 1);
        $this->repository->reorderNodes($workspaceId, [
            ['id' => $root['id'], 'parent_id' => null, 'sort_order' => 10],
            ['id' => $first['id'], 'parent_id' => $root['id'], 'sort_order' => 10],
            ['id' => $second['id'], 'parent_id' => $root['id'], 'sort_order' => 10],
            ['id' => $active['id'], 'parent_id' => $root['id'], 'sort_order' => 10],
        ], 1);

        $this->authn->login(['id' => 1, 'is_admin' => false]);
        $tree = $this->access->visibleTreeWindowForLanguages(
            $workspace,
            null,
            [],
            (int)$active['id'],
        );

        $this->assertCount(1, $tree);
        $this->assertSame(
            ['Prva', 'Druga', 'Aktualno'],
            array_column($tree[0]['children'] ?? [], 'title'),
        );
    }

    /**
     * HR: Dokazuje da se cijeli raspored stabla sprema u jednoj transakciji te
     *     da ciklički raspored ne mijenja prethodno valjano stanje.
     * EN: Proves that the complete tree arrangement is saved in one transaction
     *     and that a cyclic arrangement does not change the previous valid state.
     */
    public function testTreeArrangementIsValidatedAndSavedAtomically(): void
    {
        $workspace = $this->repository->saveWorkspace([
            'name' => 'Organizator',
        ], 1);
        $workspaceId = (int)$workspace['id'];
        $first = $this->repository->saveNode($workspaceId, [
            'title' => 'Prva',
            'node_type' => 'document',
            'document_key' => 'tree-first',
        ], 1);
        $second = $this->repository->saveNode($workspaceId, [
            'title' => 'Druga',
            'node_type' => 'document',
            'document_key' => 'tree-second',
        ], 1);
        $child = $this->repository->saveNode($workspaceId, [
            'title' => 'Dijete',
            'node_type' => 'document',
            'document_key' => 'tree-child',
            'parent_id' => $first['id'],
        ], 1);

        $this->repository->reorderNodes($workspaceId, [
            ['id' => $second['id'], 'parent_id' => null, 'sort_order' => 10],
            ['id' => $first['id'], 'parent_id' => $second['id'], 'sort_order' => 10],
            ['id' => $child['id'], 'parent_id' => $first['id'], 'sort_order' => 10],
        ], 1);

        $savedFirst = $this->repository->findNodeById((int)$first['id']);
        $savedSecond = $this->repository->findNodeById((int)$second['id']);
        $this->assertSame((int)$second['id'], (int)($savedFirst['parent_id'] ?? 0));
        $this->assertNull($savedSecond['parent_id'] ?? null);

        try {
            $this->repository->reorderNodes($workspaceId, [
                ['id' => $second['id'], 'parent_id' => $child['id'], 'sort_order' => 10],
                ['id' => $first['id'], 'parent_id' => $second['id'], 'sort_order' => 10],
                ['id' => $child['id'], 'parent_id' => $first['id'], 'sort_order' => 10],
            ], 1);
            $this->fail('A cyclic tree arrangement must be rejected.');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame(
                'Stranicu nije moguće premjestiti u vlastitu podgranu.',
                $runtimeException->getMessage(),
            );
        }

        $unchangedSecond = $this->repository->findNodeById((int)$second['id']);
        $this->assertNull($unchangedSecond['parent_id'] ?? null);
    }

    /**
     * HR: Kreira samo Auth stupce koje Workspace repository koristi za provjeru korisnika, grupa i članstva.
     * EN: Creates only the Auth columns used by the Workspace repository for users, groups, and membership.
     */
    private function createAuthSchema(): void
    {
        $schema = $this->database->schema();
        $schema->create('auth_users', static function (Blueprint $table): void {
            $table->id();
            $table->string('login_identifier');
            $table->string('password_hash')->nullable();
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

        foreach ([1, 2, 3, 4] as $userId) {
            $this->database->table('auth_users')->insert([
                'id' => $userId,
                'login_identifier' => 'user' . $userId,
                'password_hash' => 'must-never-leave-the-repository',
                'is_active' => true,
            ]);
        }

        $this->database->table('auth_groups')->insert([
            'id' => 10,
            'group_name' => 'Urednici',
            'is_enabled' => true,
        ]);
        $this->database->table('auth_user_groups')->insert([
            'user_id' => 2,
            'group_id' => 10,
        ]);
        foreach (
            [
                1 => ['Alen Zec', 'Alen', 'Zec'],
                2 => ['Ana Horvat', 'Ana', 'Horvat'],
                3 => ['Borna Kovac', 'Borna', 'Kovac'],
                4 => ['Sara Babic', 'Sara', 'Babic'],
            ] as $userId => [$displayName, $firstName, $lastName]
        ) {
            $nameAttributes = [
                'display_name' => $displayName,
                'first_name' => $firstName,
                'last_name' => $lastName,
            ];
            foreach ($nameAttributes as $fieldKey => $value) {
                $this->database->table('auth_user_attribute_values')->insert([
                    'user_id' => $userId,
                    'field_key' => $fieldKey,
                    'value_text' => $value,
                ]);
            }
        }
    }
}
