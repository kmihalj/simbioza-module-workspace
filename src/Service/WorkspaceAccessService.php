<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use AaiEduHr\SimbiozaModuleWorkspace\Contract\WorkspaceContentProviderInterface;
use HeartPhrame\Authn\AuthnHandlerInterface;

use function array_key_exists;
use function is_array;
use function is_numeric;

final class WorkspaceAccessService
{
    private const PERMISSION_KEYS = [
        'can_view',
        'can_add',
        'can_edit',
        'can_publish',
        'can_delete',
        'can_manage',
    ];

    /**
     * EN: Caches group identifiers per user during one request.
     *
     * HR: Pamti grupne identifikatore po korisniku tijekom jednog zahtjeva.
     *
     * @var array<int, list<int>>
     */
    private array $groupIdsCache = [];

    /**
     * EN: Caches Workspace ACL rows during one request.
     *
     * HR: Pamti ACL retke područja tijekom jednog zahtjeva.
     *
     * @var array<int, list<array<string, mixed>>>
     */
    private array $workspaceAclCache = [];

    /**
     * EN: Caches already calculated Workspace permissions per user.
     *
     * HR: Pamti već izračunata prava područja po korisniku.
     *
     * @var array<string, array<string, bool>>
     */
    private array $workspacePermissionCache = [];

    /**
     * EN: Caches Workspace nodes during one request.
     *
     * HR: Pamti čvorove područja tijekom jednog zahtjeva.
     *
     * @var array<int, list<array<string, mixed>>>
     */
    private array $workspaceNodesCache = [];

    /**
     * EN: Caches batched inherited ACL calculation results.
     *
     * HR: Pamti paketni rezultat nasljednog ACL izračuna.
     *
     * @var array<string, array<int, array<string, bool>>>
     */
    private array $nodePermissionCache = [];

    /**
     * EN: Caches Workspaces with at least one directly granted page per user.
     *
     * HR: Pamti područja s barem jednom izravno dopuštenom stranicom po korisniku.
     *
     * @var array<string, array<int, bool>>
     */
    private array $directWorkspaceAccessCache = [];

    /**
     * EN: Caches directly granted Workspace nodes per user.
     *
     * HR: Pamti izravno dopuštene čvorove područja po korisniku.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private array $directNodesCache = [];

    /**
     * EN: Receives the repository, auth context, and configuration required for one ACL calculation.
     *
     * HR: Prima repozitorij, auth kontekst i konfiguraciju potrebnu za jedinstveni ACL izračun.
     */
    public function __construct(
        private readonly WorkspaceRepository $repository,
        private readonly AuthnHandlerInterface $authnHandler,
        private readonly WorkspaceConfig $config,
        private readonly WorkspaceWorkflowService $workflow,
        private readonly ?WorkspaceContentRegistry $contentTypes = null,
    ) {
    }

    /**
     * EN: Returns the normalized current-user session payload or null for a guest.
     *
     * HR: Vraća normalizirani session payload trenutnog korisnika ili null za gosta.
     *
     * @return array<string, mixed>|null
     */
    public function currentUser(): ?array
    {
        $user = $this->authnHandler->userData();

        return is_array($user) ? $this->stringKeyArray($user) : null;
    }

    /**
     * EN: Checks administrator status for the current or supplied user.
     *
     * HR: Provjerava administratorski status trenutnog ili proslijeđenog korisnika.
     *
     * @param array<string, mixed>|null $user
     */
    public function isAdministrator(?array $user = null): bool
    {
        $user ??= $this->currentUser();

        return is_array($user) && (bool)($user['is_admin'] ?? false);
    }

    /**
     * EN: Returns whether the user may create a new workspace.
     *
     * HR: Vraća smije li korisnik kreirati novo područje.
     *
     * @param array<string, mixed>|null $user
     */
    public function canCreateWorkspace(?array $user = null): bool
    {
        $user ??= $this->currentUser();
        if (!is_array($user) || $this->userId($user) <= 0) {
            return false;
        }

        if ($this->isAdministrator($user)) {
            return true;
        }

        $userId = $this->userId($user);
        if (in_array($userId, $this->config->creatorUserIds(), true)) {
            return true;
        }

        $creatorGroupIds = $this->config->creatorGroupIds();
        if ($creatorGroupIds === []) {
            return false;
        }

        foreach ($this->groupIds($userId, $user) as $groupId) {
            if (in_array($groupId, $creatorGroupIds, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * EN: Filters workspaces the user may actually view.
     *
     * HR: Filtrira područja koja korisnik stvarno smije vidjeti.
     *
     * @param array<string, mixed>|null $user
     * @return list<array<string, mixed>>
     */
    public function visibleWorkspaces(?array $user = null): array
    {
        $user ??= $this->currentUser();
        $workspaces = $this->repository->activeWorkspaces();
        if (!$this->isAdministrator($user)) {
            $this->preloadWorkspaceAclRows($workspaces);
            $this->preloadDirectWorkspaceAccess(
                $workspaces,
                $this->userId($user),
                $this->groupIds($this->userId($user), $user),
            );
        }

        $visible = [];
        foreach ($workspaces as $workspace) {
            $permissions = $this->workspacePermissions($workspace, $user);
            if (
                !$permissions['can_view'] && !$this->hasDirectWorkspaceAccess($workspace, $user)
                && !$this->hasExtensionAccess($workspace, $user)
            ) {
                continue;
            }

            $workspace['permissions'] = $permissions;
            $visible[] = $workspace;
        }

        return $visible;
    }

    /**
     * EN: Checks whether a user may open a Workspace through base access or a direct page grant.
     *
     * HR: Provjerava može li korisnik otvoriti područje baznim pravom ili izravnim pravom na stranicu.
     *
     * @param array<string, mixed> $workspace
     * @param array<string, mixed>|null $user
     */
    public function canAccessWorkspace(array $workspace, ?array $user = null): bool
    {
        $user ??= $this->currentUser();

        return $this->workspacePermissions($workspace, $user)['can_view']
        || $this->hasDirectWorkspaceAccess($workspace, $user)
        || $this->hasExtensionAccess($workspace, $user);
    }

    /**
     * EN: Does not grant ordinary content rights through extensions.
     *
     * HR: Ne dodjeljuje prava običnog sadržaja kroz proširenja.
     * @param array<string,mixed> $workspace
     * @param array<string,mixed>|null $user
     */
    private function hasExtensionAccess(array $workspace, ?array $user): bool
    {
        foreach ($this->contentTypes?->all() ?? [] as $provider) {
            if ($provider->workspaceVisible($workspace, $user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * EN: Calculates base workspace permissions as the union of direct user and group ACL entries.
     *
     * HR: Računa bazna prava područja kao uniju direktnog korisničkog i grupnog ACL-a.
     *
     * @param array<string, mixed> $workspace
     * @param array<string, mixed>|null $user
     * @return array<string, bool>
     */
    public function workspacePermissions(array $workspace, ?array $user = null): array
    {
        $user ??= $this->currentUser();
        $userId = $this->userId($user);
        $cacheKey = $this->workspacePermissionCacheKey($workspace, $user);
        if (array_key_exists($cacheKey, $this->workspacePermissionCache)) {
            return $this->workspacePermissionCache[$cacheKey];
        }

        $workspaceId = WorkspaceValue::int($workspace['id'] ?? 0);
        if ($this->isAdministrator($user)) {
            return $this->workspacePermissionCache[$cacheKey] = $this->workspacePermissionsFromRows(
                $workspace,
                $user,
                [],
                [],
            );
        }

        $groupIds = $this->groupIds($userId, $user);

        return $this->workspacePermissionCache[$cacheKey] = $this->workspacePermissionsFromRows(
            $workspace,
            $user,
            $groupIds,
            $this->workspaceAclRows($workspaceId),
        );
    }

    /**
     * EN: Applies restrictions from root to node as an intersection of inherited permissions.
     *
     * HR: Primjenjuje ograničenja od korijena do čvora kao presjek naslijeđenih prava.
     *
     * @param array<string, mixed> $workspace
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $user
     * @return array<string, bool>
     */
    public function nodePermissions(array $workspace, array $node, ?array $user = null): array
    {
        $user ??= $this->currentUser();
        $nodeId = WorkspaceValue::int($node['id'] ?? 0);
        $nodes = $this->repository->ancestorNodes(
            WorkspaceValue::int($workspace['id'] ?? 0),
            $nodeId,
        );
        $permissions = $this->nodePermissionsForNodes($workspace, $nodes, $user);

        return $permissions[$nodeId] ?? $this->workspacePermissions($workspace, $user);
    }

    /**
     * EN: Calculates effective permissions for all supplied nodes in one batch
     *     from Workspace ACL, groups, and restrictions. Beginner note: parent
     *     chains are calculated in memory so a large tree does not query per row.
     *
     * HR: Grupno računa efektivna prava svih zadanih čvorova iz jednog skupa
     *     Workspace ACL-a, grupa i ograničenja. Početnička napomena: lanac
     *     roditelja računa se u memoriji pa veliko stablo ne izvodi upit po retku.
     *
     * @param array<string, mixed> $workspace
     * @param list<array<string, mixed>> $nodes
     * @param array<string, mixed>|null $user
     * @return array<int, array<string, bool>>
     */
    public function nodePermissionsForNodes(
        array $workspace,
        array $nodes,
        ?array $user = null,
    ): array {
        $user ??= $this->currentUser();
        $userId = $this->userId($user);
        $cacheKey = $this->nodePermissionCacheKey($workspace, $nodes, $user);
        if (array_key_exists($cacheKey, $this->nodePermissionCache)) {
            return $this->nodePermissionCache[$cacheKey];
        }

        $base = $this->workspacePermissions($workspace, $user);
        foreach ($this->contentTypes?->all() ?? [] as $provider) {
            $provider->prepare($workspace, $nodes);
        }

        $nodeIds = [];
        $nodesById = [];
        $parentIds = [];
        foreach ($nodes as $node) {
            $nodeId = WorkspaceValue::int($node['id'] ?? 0);
            if ($nodeId <= 0) {
                continue;
            }

            $nodeIds[] = $nodeId;
            $nodesById[$nodeId] = $node;
            $parentIds[$nodeId] = WorkspaceValue::int($node['parent_id'] ?? 0);
        }

        if ($nodeIds === []) {
            return [];
        }

        if ($this->isAdministrator($user)) {
            return $this->nodePermissionCache[$cacheKey] = array_fill_keys($nodeIds, $base);
        }

        $rowsByNode = [];
        foreach ($this->repository->nodeAclRowsForNodes($nodeIds) as $row) {
            $restrictionNodeId = WorkspaceValue::int($row['node_id'] ?? 0);
            if ($restrictionNodeId > 0) {
                $rowsByNode[$restrictionNodeId][] = $row;
            }
        }

        $groupIds = $this->groupIds($userId, $user);
        $directByNode = $this->directPermissionsByNode(
            $this->repository->nodeDirectPermissionRowsForNodes($nodeIds),
            $userId,
            $groupIds,
        );

        $permissions = [];
        foreach ($nodeIds as $nodeId) {
            $inherited = $this->restrictPermissionsFromRows(
                $this->contentTypes?->get(WorkspaceValue::string($nodesById[$nodeId]['node_type'] ?? ''))
                    ?->permissions($workspace, $nodesById[$nodeId], $user, $base) ?? $base,
                $this->ancestorIdsFromParentMap($nodeId, $parentIds),
                $rowsByNode,
                $userId,
                $groupIds,
            );
            $permissions[$nodeId] = $this->applyArchivedReadOnly(
                $workspace,
                $this->applyCreatorEditPermission(
                    $this->unionPermissions(
                        $inherited,
                        $directByNode[$nodeId] ?? $this->emptyPermissions(),
                    ),
                    $nodesById[$nodeId] ?? [],
                    $userId,
                ),
            );
        }

        return $this->nodePermissionCache[$cacheKey] = $permissions;
    }

    /**
     * EN: Returns active users who hold the requested effective permission on
     *     the selected node in one batch, including administrators.
     *
     * HR: Grupno vraća aktivne korisnike koji na odabranom čvoru imaju traženo
     *     efektivno pravo, uključujući administratore.
     *
     * @param array<string, mixed> $workspace
     * @param array<string, mixed> $node
     * @return list<int>
     */
    public function userIdsWithNodePermission(
        array $workspace,
        array $node,
        string $permission,
    ): array {
        if (!in_array($permission, self::PERMISSION_KEYS, true)) {
            return [];
        }

        $users = $this->repository->activeAclUsers();
        $userIds = [];
        foreach ($users as $user) {
            $userId = $this->userId($user);
            if ($userId > 0) {
                $userIds[] = $userId;
            }
        }

        $groupsByUser = $this->repository->groupIdsForUsers($userIds);
        $workspaceRows = $this->repository->workspaceAclRows(
            WorkspaceValue::int($workspace['id'] ?? 0),
        );
        $ancestorIds = $this->repository->ancestorNodeIds(
            WorkspaceValue::int($workspace['id'] ?? 0),
            WorkspaceValue::int($node['id'] ?? 0),
        );
        $rowsByNode = [];
        foreach ($this->repository->nodeAclRowsForNodes($ancestorIds) as $row) {
            $rowsByNode[WorkspaceValue::int($row['node_id'] ?? 0)][] = $row;
        }

        $directRows = $this->repository->nodeDirectPermissionRowsForNodes([WorkspaceValue::int($node['id'] ?? 0)]);

        $allowedUserIds = [];
        foreach ($users as $user) {
            $userId = $this->userId($user);
            if ($userId <= 0) {
                continue;
            }

            $permissions = $this->workspacePermissionsFromRows(
                $workspace,
                $user,
                $groupsByUser[$userId] ?? [],
                $workspaceRows,
            );
            if (!$this->isAdministrator($user)) {
                $permissions = $this->restrictPermissionsFromRows(
                    $permissions,
                    $ancestorIds,
                    $rowsByNode,
                    $userId,
                    $groupsByUser[$userId] ?? [],
                );
                $permissions = $this->applyArchivedReadOnly(
                    $workspace,
                    $this->applyCreatorEditPermission(
                        $this->unionPermissions(
                            $permissions,
                            ($this->directPermissionsByNode($directRows, $userId, $groupsByUser[$userId] ?? []))
                                [WorkspaceValue::int($node['id'] ?? 0)] ?? $this->emptyPermissions(),
                        ),
                        $node,
                        $userId,
                    ),
                );
            }

            if ($permissions[$permission]) {
                $allowedUserIds[] = $userId;
            }
        }

        return $allowedUserIds;
    }

    /**
     * EN: Returns the visible tree with effective permissions for every node.
     *
     * HR: Vraća vidljivo stablo s efektivnim pravima svakog čvora.
     *
     * @param array<string, mixed> $workspace
     * @param array<string, mixed>|null $user
     * @return list<array<string, mixed>>
     */
    public function visibleTree(
        array $workspace,
        ?array $user = null,
        string $language = '',
    ): array {
        return $this->visibleTreeForLanguages(
            $workspace,
            $user,
            $language !== '' ? [$language] : [],
        );
    }

    /**
     * EN: Returns the tree readable in the first published locale from the supplied priority.
     *
     * HR: Vraća stablo čitljivo u prvom objavljenom jeziku iz zadanog prioriteta.
     *
     * @param array<string, mixed> $workspace
     * @param array<string, mixed>|null $user
     * @param list<string> $languages
     * @return list<array<string, mixed>>
     */
    public function visibleTreeForLanguages(
        array $workspace,
        ?array $user,
        array $languages,
        bool $allowAnyLanguageFallback = false,
    ): array {
        $nodes = $this->nodesForWorkspace(WorkspaceValue::int($workspace['id'] ?? 0));
        $nodes = $this->navigationNodes($nodes);

        $visible = $this->visibleNodesForLanguages(
            $workspace,
            $user,
            $languages,
            $nodes,
            $allowAnyLanguageFallback,
        );

        return $this->buildTree($this->promoteVisibleOrphans($visible), null);
    }

    /**
     * EN: Returns the initial compact visible-tree window. Large Workspaces no
     *     longer calculate ACL and workflow for every hidden page on each view.
     *
     * HR: Vraća početni, mali prozor vidljivog stabla. Velika područja zato
     *     ne računaju ACL i workflow za svaku skrivenu stranicu pri svakom prikazu.
     *
     * @param array<string,mixed> $workspace
     * @param array<string,mixed>|null $user
     * @param list<string> $languages
     * @return list<array<string,mixed>>
     */
    public function visibleTreeWindowForLanguages(
        array $workspace,
        ?array $user,
        array $languages,
        int $activeNodeId = 0,
        bool $allowAnyLanguageFallback = false,
    ): array {
        $workspaceId = WorkspaceValue::int($workspace['id'] ?? 0);
        $nodes = $this->repository->treeWindowNodes(
            $workspaceId,
            $activeNodeId,
        );
        $directNodes = $this->markDirectNodeTreeVisibility(
            $workspaceId,
            $this->directNodesForWorkspace(
                $workspaceId,
                $this->userId($user ?? $this->currentUser()),
                $this->groupIds($this->userId($user ?? $this->currentUser()), $user ?? $this->currentUser()),
            ),
        );
        $nodes = $this->navigationNodes(
            $this->mergeNodesById($nodes, $directNodes),
            $activeNodeId,
        );
        $visible = $this->visibleNodesForLanguages(
            $workspace,
            $user,
            $languages,
            $nodes,
            $allowAnyLanguageFallback,
        );

        return $this->buildTree($this->promoteVisibleOrphans($visible), null);
    }

    /**
     * EN: Returns immediate ACL-filtered branch children for on-demand loading.
     *
     * HR: Vraća neposrednu ACL-filtriranu djecu grane za naknadno učitavanje.
     *
     * @param array<string,mixed> $workspace
     * @param array<string,mixed>|null $user
     * @param list<string> $languages
     * @return list<array<string,mixed>>
     */
    public function visibleTreeBranchForLanguages(
        array $workspace,
        ?array $user,
        array $languages,
        int $parentId,
        bool $allowAnyLanguageFallback = false,
    ): array {
        $nodes = $this->repository->treeBranchNodes(
            WorkspaceValue::int($workspace['id'] ?? 0),
            $parentId,
        );
        $nodes = $this->navigationNodes($nodes);

        $visible = $this->visibleNodesForLanguages(
            $workspace,
            $user,
            $languages,
            $nodes,
            $allowAnyLanguageFallback,
        );

        return array_values(array_filter(
            $visible,
            static fn(array $node): bool => WorkspaceValue::int($node['parent_id'] ?? 0) === $parentId,
        ));
    }

    /**
     * EN: Applies batched ACL and locale workflow checks to already targeted nodes.
     *
     * HR: Primjenjuje paketni ACL i jezični workflow na već ciljano dohvaćene čvorove.
     *
     * @param array<string,mixed> $workspace
     * @param array<string,mixed>|null $user
     * @param list<string> $languages
     * @param list<array<string,mixed>> $nodes
     * @return list<array<string,mixed>>
     */
    private function visibleNodesForLanguages(
        array $workspace,
        ?array $user,
        array $languages,
        array $nodes,
        bool $allowAnyLanguageFallback = false,
    ): array {
        $user ??= $this->currentUser();
        $languages = array_values(array_unique(array_filter(array_map(
            static fn(string $language): string => strtolower(trim($language)),
            $languages,
        ))));
        $permissionsByNode = $this->nodePermissionsForNodes($workspace, $nodes, $user);
        $workflowStates = $languages !== []
        ? $this->repository->nodeWorkflowsForNodesAllLanguages(
            array_values(array_filter(array_map(
                static fn(array $node): int =>
                    WorkspaceValue::string($node['node_type'] ?? '') === 'document'
                        ? WorkspaceValue::int($node['id'] ?? 0)
                        : 0,
                $nodes,
            ))),
        )
        : [];
        $visible = [];
        foreach ($nodes as $node) {
            $nodeId = WorkspaceValue::int($node['id'] ?? 0);
            $permissions = $permissionsByNode[$nodeId] ?? $this->emptyPermissions();
            if (!$permissions['can_view']) {
                continue;
            }

            $type = WorkspaceValue::string($node['node_type'] ?? 'document');
            if (
                !in_array($type, ['document', 'separator', 'internal_link', 'external_link'], true)
                && !($this->contentTypes?->get($type)?->visible($node, $permissions, $languages) ?? false)
            ) {
                continue;
            }

            if (
                $languages !== []
                && WorkspaceValue::string($node['node_type'] ?? '') === 'document'
                && !$permissions['can_edit']
                && !$permissions['can_publish']
                && !$permissions['can_manage']
                && !$this->hasReadableLanguage(
                    WorkspaceValue::rows($workflowStates[$nodeId] ?? null),
                    $languages,
                    $allowAnyLanguageFallback,
                )
            ) {
                continue;
            }

            $node['permissions'] = $permissions;
            $visible[] = $node;
        }

        return $visible;
    }

    /**
     * EN: Checks whether a readable publication exists in the allowed locale priority.
     *
     * HR: Provjerava postoji li čitljiva objava u dopuštenom jezičnom prioritetu.
     *
     * @param list<array<string, mixed>> $workflows
     * @param list<string> $languages
     */
    private function hasReadableLanguage(
        array $workflows,
        array $languages,
        bool $allowAnyLanguageFallback = false,
    ): bool {
        foreach ($workflows as $workflow) {
            if (
                in_array(
                    strtolower(WorkspaceValue::string($workflow['language_code'] ?? '')),
                    $languages,
                    true,
                )
                && $this->workflow->isReadableWorkflow($workflow)
            ) {
                return true;
            }
        }

        if ($allowAnyLanguageFallback) {
            foreach ($workflows as $workflow) {
                if ($this->workflow->isReadableWorkflow($workflow)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * EN: Clears short-lived ACL caches after permissions change in the same
     *     request. Beginner note: the next calculation then reads the database again.
     *
     * HR: Briše kratkotrajne ACL cacheve nakon promjene prava u istom zahtjevu.
     *     Početnička napomena: sljedeći izračun tada ponovno čita bazu.
     */
    public function clearRequestCache(): void
    {
        $this->groupIdsCache = [];
        $this->workspaceAclCache = [];
        $this->workspacePermissionCache = [];
        $this->workspaceNodesCache = [];
        $this->nodePermissionCache = [];
        $this->directWorkspaceAccessCache = [];
        $this->directNodesCache = [];
    }

    /**
     * EN: Calculates fresh batch visibility across Workspaces with the existing ACL rules.
     * No permission result is cached: later reads see mutations made in the same request.
     * Ancestors, direct page grants and imported restrictions retain normal semantics;
     * incomplete/cyclic paths deny access rather than omit an unknown ancestor ACL.
     *
     * HR: Računa svježu skupnu vidljivost kroz područja uz postojeća ACL pravila.
     * Rezultat prava ne cacheira se: kasniji dohvat vidi promjene iz istog zahtjeva.
     * Preci, izravna prava stranice i importna ograničenja zadržavaju semantiku;
     * nepotpun/cikličan put uskraćuje pristup, ne preskače nepoznati ACL pretka.
     *
     * @param list<array<string,mixed>> $workspaces
     * @param list<array<string,mixed>> $nodes
     * @param array<string,mixed>|null $user
     * @return array{workspaces:array<int,bool>,nodes:array<int,bool>}
     */
    public function viewPermissionsForTargets(array $workspaces, array $nodes, ?array $user): array
    {
        $userId = $this->userId($user);
        $groupIds = is_array($user['group_ids'] ?? null)
        ? $this->groupIds($userId, $user)
        : ($userId > 0 ? $this->repository->groupIdsForUser($userId) : []);
        $this->preloadDirectWorkspaceAccess(array_values($workspaces), $userId, $groupIds);
        $workspaceIds = array_map(static fn(array $row): int => WorkspaceValue::int($row['id'] ?? 0), $workspaces);
        $workspaceRows = [];
        foreach ($this->repository->workspaceAclRowsForWorkspaces($workspaceIds) as $row) {
            $workspaceRows[WorkspaceValue::int($row['workspace_id'] ?? 0)][] = $row;
        }

        $base = [];
        $byWorkspace = [];
        $visibleWorkspaces = [];
        foreach ($workspaces as $workspace) {
            $id = WorkspaceValue::int($workspace['id'] ?? 0);
            $byWorkspace[$id] = $workspace;
            $base[$id] = $this->workspacePermissionsFromRows($workspace, $user, $groupIds, $workspaceRows[$id] ?? []);
            $visibleWorkspaces[$id] = $base[$id]['can_view'] || $this->hasDirectWorkspaceAccess($workspace, $user)
            || $this->hasExtensionAccess($workspace, $user);
        }

        $byNode = [];
        foreach ($nodes as $node) {
            $byNode[WorkspaceValue::int($node['id'] ?? 0)] = $node;
        }

        $restrictions = [];
        $direct = [];
        foreach (array_chunk(array_keys($byNode), 500) as $ids) {
            foreach ($this->repository->nodeAclRowsForNodes($ids) as $row) {
                $restrictions[WorkspaceValue::int($row['node_id'] ?? 0)][] = $row;
            }

            $direct += $this->directPermissionsByNode(
                $this->repository->nodeDirectPermissionRowsForNodes($ids),
                $userId,
                $groupIds,
            );
        }

        $visibleNodes = [];
        foreach ($byNode as $id => $node) {
            $workspaceId = WorkspaceValue::int($node['workspace_id'] ?? 0);
            $visibleNodes[$id] = false;
            if (!isset($byWorkspace[$workspaceId])) {
                continue;
            }

            $path = [];
            $cursor = $id;
            while ($cursor > 0 && !isset($path[$cursor]) && count($path) < 256) {
                $parent = $byNode[$cursor] ?? null;
                if (!is_array($parent) || WorkspaceValue::int($parent['workspace_id'] ?? 0) !== $workspaceId) {
                    break;
                }

                $path[$cursor] = $cursor;
                $cursor = WorkspaceValue::int($parent['parent_id'] ?? 0);
            }

            if ($cursor > 0) {
                continue;
            }

            if ($this->isAdministrator($user)) {
                $visibleNodes[$id] = $base[$workspaceId]['can_view'];
                continue;
            }

            $permissions = $this->restrictPermissionsFromRows(
                $base[$workspaceId],
                array_reverse(array_values($path)),
                $restrictions,
                $userId,
                $groupIds,
            );
            $permissions = $this->unionPermissions($permissions, $direct[$id] ?? $this->emptyPermissions());
            $visibleNodes[$id] = $permissions['can_view'];
        }

        // EN: Apply content publication rules as well as ACL to extension targets.
        //
        // HR: Na dodatne ciljeve primjenjujemo i pravila objave sadržaja, uz ACL.
        $extensionRights = [];
        foreach ($byNode as $id => $node) {
            $type = WorkspaceValue::string($node['node_type'] ?? '');
            if (in_array($type, ['document', 'separator', 'internal_link', 'external_link'], true)) {
                continue;
            }

            $workspaceId = WorkspaceValue::int($node['workspace_id'] ?? 0);
            $provider = $this->contentTypes?->get($type);
            $visibleNodes[$id] = false;
            if (!$provider instanceof WorkspaceContentProviderInterface || !isset($byWorkspace[$workspaceId])) {
                continue;
            }

            if (!isset($extensionRights[$workspaceId])) {
                $siblings = array_values(array_filter($byNode, static fn(array $row): bool =>
                    WorkspaceValue::int($row['workspace_id'] ?? 0) === $workspaceId));
                $extensionRights[$workspaceId] = $this->nodePermissionsForNodes(
                    $byWorkspace[$workspaceId],
                    $siblings,
                    $user,
                );
            }

            $rights = $extensionRights[$workspaceId][$id] ?? $this->emptyPermissions();
            $visibleNodes[$id] = $rights['can_view'] && $provider->visible($node, $rights, []);
        }

        return ['workspaces' => $visibleWorkspaces, 'nodes' => $visibleNodes];
    }

    /**
     * EN: Batch-caches directly accessible Workspaces so the Workspace list does not query per row.
     *
     * HR: Paketno sprema područja s izravnim pravima kako popis područja ne bi radio upit po retku.
     *
     * @param list<array<string, mixed>> $workspaces
     * @param list<int> $groupIds
     */
    private function preloadDirectWorkspaceAccess(array $workspaces, int $userId, array $groupIds = []): void
    {
        $workspaceIds = array_values(array_filter(array_map(
            static fn(array $workspace): int => WorkspaceValue::int($workspace['id'] ?? 0),
            $workspaces,
        )));
        $cacheId = $userId . ":" . implode(",", $groupIds);
        $this->directWorkspaceAccessCache[$cacheId] ??= [];
        foreach ($workspaceIds as $workspaceId) {
            $this->directWorkspaceAccessCache[$cacheId][$workspaceId] = false;
        }

        $nodes = $this->repository->directPermissionNodesForWorkspaces($workspaceIds, $userId, $groupIds);
        $nodeIds = array_map(static fn(array $node): int => WorkspaceValue::int($node['id'] ?? 0), $nodes);
        $rights = $this->directPermissionsByNode(
            $this->repository->nodeDirectPermissionRowsForNodes($nodeIds),
            $userId,
            $groupIds,
        );
        $workflows = $this->repository->nodeWorkflowsForNodesAllLanguages($nodeIds);
        foreach ($workspaces as $workspace) {
            $wid = WorkspaceValue::int($workspace['id'] ?? 0);
            foreach ($this->contentTypes?->all() ?? [] as $provider) {
                $provider->prepare($workspace, $nodes);
            }

            foreach ($nodes as $node) {
                if (WorkspaceValue::int($node['workspace_id'] ?? 0) !== $wid) {
                    continue;
                }

                $id = WorkspaceValue::int($node['id'] ?? 0);
                $permissions = $rights[$id] ?? $this->emptyPermissions();
                if (!$permissions['can_view']) {
                    continue;
                }

                $type = WorkspaceValue::string($node['node_type'] ?? 'document');
                if (
                    !in_array($type, ['document', 'separator', 'internal_link', 'external_link'], true)
                    && !($this->contentTypes?->get($type)?->visible($node, $permissions, []) ?? false)
                ) {
                    continue;
                }

                if (
                    $type === 'document' && !$permissions['can_edit'] && !$permissions['can_publish']
                    && isset($workflows[$id])
                    && !$this->hasReadableLanguage(WorkspaceValue::rows($workflows[$id]), [], true)
                ) {
                    continue;
                }

                $this->directWorkspaceAccessCache[$cacheId][$wid] = true;
                break;
            }
        }
    }

    /**
     * EN: Checks whether the user has a direct permission on any Workspace page.
     *
     * HR: Provjerava ima li korisnik izravno pravo na neku stranicu područja.
     *
     * @param array<string, mixed> $workspace
     * @param array<string, mixed>|null $user
     */
    private function hasDirectWorkspaceAccess(array $workspace, ?array $user): bool
    {
        $userId = $this->userId($user);
        $workspaceId = WorkspaceValue::int($workspace['id'] ?? 0);
        if ($workspaceId <= 0) {
            return false;
        }

        $groupIds = $this->groupIds($userId, $user);
        $cacheId = $userId . ':' . implode(',', $groupIds);
        if (!isset($this->directWorkspaceAccessCache[$cacheId][$workspaceId])) {
            $this->preloadDirectWorkspaceAccess([$workspace], $userId, $groupIds);
        }

        return (bool)($this->directWorkspaceAccessCache[$cacheId][$workspaceId] ?? false);
    }

    /**
     * EN: Loads and caches Workspace pages on which the user has direct permissions.
     *
     * HR: Dohvaća i predmemorira stranice područja na kojima korisnik ima izravna prava.
     *
     * @param list<int> $groupIds
     * @return list<array<string, mixed>>
     */
    private function directNodesForWorkspace(int $workspaceId, int $userId, array $groupIds = []): array
    {
        if ($workspaceId <= 0) {
            return [];
        }

        $key = $workspaceId . '|' . $userId . '|' . implode(',', $groupIds);
        if (!array_key_exists($key, $this->directNodesCache)) {
            $this->directNodesCache[$key] = $this->repository->directPermissionNodesForUser(
                $workspaceId,
                $userId,
                $groupIds,
            );
        }

        return $this->directNodesCache[$key];
    }

    /**
     * EN: Merges targeted node sets without duplicates.
     *
     * HR: Spaja ciljane skupove čvorova bez duplikata.
     *
     * @param list<array<string, mixed>> $left
     * @param list<array<string, mixed>> $right
     * @return list<array<string, mixed>>
     */
    private function mergeNodesById(array $left, array $right): array
    {
        $nodes = [];
        foreach ([...$left, ...$right] as $node) {
            $nodeId = WorkspaceValue::int($node['id'] ?? 0);
            if ($nodeId > 0) {
                $nodes[$nodeId] = isset($nodes[$nodeId]) ? [...$node, ...$nodes[$nodeId]] : $node;
            }
        }

        return array_values($nodes);
    }

    /**
     * EN: Removes a marked item and its complete subtree from navigation. When
     *     a direct URL opens a page inside a hidden branch, only the required
     *     path is retained and marked for a dimmed presentation.
     *
     * HR: Iz navigacije uklanja označenu stavku i cijelu njezinu podgranu. Ako
     *     je izravnim URL-om otvorena stranica unutar skrivene grane, zadržava
     *     samo potreban put i označava ga za prigušeni prikaz.
     *
     * @param list<array<string,mixed>> $nodes
     * @return list<array<string,mixed>>
     */
    private function navigationNodes(array $nodes, int $activeNodeId = 0): array
    {
        $byId = [];
        foreach ($nodes as $node) {
            $nodeId = WorkspaceValue::int($node['id'] ?? 0);
            if ($nodeId > 0) {
                $byId[$nodeId] = $node;
            }
        }

        $activePath = [];
        $currentId = $activeNodeId;
        while ($currentId > 0 && isset($byId[$currentId]) && !isset($activePath[$currentId])) {
            $activePath[$currentId] = true;
            $currentId = WorkspaceValue::int($byId[$currentId]['parent_id'] ?? 0);
        }

        $hiddenByNode = [];
        $resolving = [];
        $isHidden = function (int $nodeId) use (&$isHidden, &$hiddenByNode, &$resolving, $byId): bool {
            if ($nodeId <= 0 || !isset($byId[$nodeId])) {
                return false;
            }

            if (array_key_exists($nodeId, $hiddenByNode)) {
                return $hiddenByNode[$nodeId];
            }

            if (isset($resolving[$nodeId])) {
                return (bool)($byId[$nodeId]['is_tree_hidden'] ?? false);
            }

            $node = $byId[$nodeId];
            $resolving[$nodeId] = true;
            $parentId = WorkspaceValue::int($node['parent_id'] ?? 0);
            $hiddenByNode[$nodeId] = (bool)($node['is_tree_hidden_effective'] ?? false)
            || (bool)($node['is_tree_hidden'] ?? false)
            || ($parentId > 0 && $isHidden($parentId));
            unset($resolving[$nodeId]);

            return $hiddenByNode[$nodeId];
        };

        $visible = [];
        foreach ($nodes as $node) {
            $nodeId = WorkspaceValue::int($node['id'] ?? 0);
            $hidden = $isHidden($nodeId);
            if ($hidden && !isset($activePath[$nodeId])) {
                continue;
            }

            $node['is_tree_hidden_effective'] = $hidden;
            $node['is_tree_temporarily_visible'] = $hidden;
            $visible[$nodeId] = $node;
        }

        $retainedParents = [];
        foreach ($visible as $node) {
            $parentId = WorkspaceValue::int($node['parent_id'] ?? 0);
            if ($parentId > 0) {
                $retainedParents[$parentId] = true;
            }
        }

        foreach ($visible as $nodeId => &$node) {
            if ((bool)($node['children_loaded'] ?? false)) {
                $node['has_children'] = isset($retainedParents[$nodeId]);
            } elseif (isset($retainedParents[$nodeId])) {
                $node['has_children'] = true;
            }

            if ((bool)($node['is_tree_temporarily_visible'] ?? false)) {
                $node['has_children'] = isset($retainedParents[$nodeId]);
                $node['children_loaded'] = true;
            }
        }

        unset($node);

        return array_values($visible);
    }

    /**
     * EN: A directly granted node may be loaded without its ancestors, so its
     *     inherited hidden-navigation state is carried separately.
     *
     * HR: Izravno dopušteni čvor može biti dohvaćen bez predaka; zato mu
     *     zasebno prenosimo naslijeđeno stanje skrivene navigacijske grane.
     *
     * @param list<array<string,mixed>> $nodes
     * @return list<array<string,mixed>>
     */
    private function markDirectNodeTreeVisibility(int $workspaceId, array $nodes): array
    {
        foreach ($nodes as &$node) {
            $nodeId = WorkspaceValue::int($node['id'] ?? 0);
            foreach ($this->repository->ancestorNodes($workspaceId, $nodeId) as $ancestor) {
                if ((bool)($ancestor['is_tree_hidden'] ?? false)) {
                    $node['is_tree_hidden_effective'] = true;
                    break;
                }
            }
        }

        unset($node);

        return $nodes;
    }

    /**
     * EN: Promotes a directly granted page with no visible parent to a root item
     *     without revealing inaccessible ancestor names or structure.
     *
     * HR: Izravno dopuštenu stranicu bez vidljivog roditelja prikazuje kao korijen,
     *     bez otkrivanja naziva ili strukture nedostupnih predaka.
     *
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    private function promoteVisibleOrphans(array $nodes): array
    {
        $visibleIds = [];
        foreach ($nodes as $node) {
            $visibleIds[WorkspaceValue::int($node['id'] ?? 0)] = true;
        }

        foreach ($nodes as &$node) {
            $parentId = WorkspaceValue::int($node['parent_id'] ?? 0);
            if ($parentId > 0 && !isset($visibleIds[$parentId])) {
                $node['parent_id'] = null;
            }
        }

        unset($node);

        return $nodes;
    }

    /**
     * EN: Builds an ancestor chain from a preloaded parent map and stops at a
     *     missing node or a cycle in malformed data.
     *
     * HR: Gradi lanac predaka iz već učitane mape roditelja i zaustavlja se
     *     na nedostajućem čvoru ili ciklusu neispravnih podataka.
     *
     * @param array<int, int> $parentIds
     * @return list<int>
     */
    private function ancestorIdsFromParentMap(int $nodeId, array $parentIds): array
    {
        $ancestorIds = [];
        $visited = [];
        $currentId = $nodeId;
        while ($currentId > 0 && isset($parentIds[$currentId]) && !isset($visited[$currentId])) {
            $visited[$currentId] = true;
            $ancestorIds[] = $currentId;
            $currentId = $parentIds[$currentId];
        }

        return array_reverse($ancestorIds);
    }

    /**
     * EN: Returns a user's groups from cache or loads them through the repository once.
     *
     * HR: Vraća korisnikove grupe iz cachea ili ih prvi put učitava kroz repozitorij.
     *
     * @param array<string,mixed>|null $user
     * @return list<int>
     */
    private function groupIds(int $userId, ?array $user = null): array
    {
        if ($userId <= 0) {
            return [];
        }

        if (
            is_array($user)
            && $this->userId($user) === $userId
            && array_key_exists('group_ids', $user)
            && is_array($user['group_ids'])
        ) {
            return array_values(array_unique(array_filter(array_map(
                static fn(mixed $groupId): int => is_numeric($groupId) ? (int)$groupId : 0,
                $user['group_ids'],
            ), static fn(int $groupId): bool => $groupId > 0)));
        }

        if (!array_key_exists($userId, $this->groupIdsCache)) {
            $this->groupIdsCache[$userId] = $this->repository->groupIdsForUser($userId);
        }

        return $this->groupIdsCache[$userId];
    }

    /**
     * EN: Returns Workspace ACL rows from cache or loads them once.
     *
     * HR: Vraća ACL retke područja iz cachea ili ih prvi put učitava.
     *
     * @return list<array<string, mixed>>
     */
    private function workspaceAclRows(int $workspaceId): array
    {
        if (!array_key_exists($workspaceId, $this->workspaceAclCache)) {
            $this->workspaceAclCache[$workspaceId] = $this->repository->workspaceAclRows($workspaceId);
        }

        return $this->workspaceAclCache[$workspaceId];
    }

    /**
     * EN: Preloads the request cache with ACL rows for all Workspaces in one query.
     *
     * HR: Unaprijed puni request cache ACL retcima svih područja jednim upitom.
     *
     * @param list<array<string, mixed>> $workspaces
     */
    private function preloadWorkspaceAclRows(array $workspaces): void
    {
        $workspaceIds = [];
        foreach ($workspaces as $workspace) {
            $workspaceId = WorkspaceValue::int($workspace['id'] ?? 0);
            if ($workspaceId > 0 && !array_key_exists($workspaceId, $this->workspaceAclCache)) {
                $workspaceIds[$workspaceId] = $workspaceId;
            }
        }

        if ($workspaceIds === []) {
            return;
        }

        foreach ($workspaceIds as $workspaceId) {
            $this->workspaceAclCache[$workspaceId] = [];
        }

        foreach ($this->repository->workspaceAclRowsForWorkspaces(array_values($workspaceIds)) as $row) {
            $workspaceId = WorkspaceValue::int($row['workspace_id'] ?? 0);
            if (array_key_exists($workspaceId, $this->workspaceAclCache)) {
                $this->workspaceAclCache[$workspaceId][] = $row;
            }
        }
    }

    /**
     * EN: Returns all Workspace nodes from cache or loads them once.
     *
     * HR: Vraća sve čvorove područja iz cachea ili ih prvi put učitava.
     *
     * @return list<array<string, mixed>>
     */
    private function nodesForWorkspace(int $workspaceId): array
    {
        if (!array_key_exists($workspaceId, $this->workspaceNodesCache)) {
            $this->workspaceNodesCache[$workspaceId] = $this->repository->nodesForWorkspace($workspaceId);
        }

        return $this->workspaceNodesCache[$workspaceId];
    }

    /**
     * EN: Builds a stable Workspace-permission key from the user and
     *     security-relevant Workspace properties.
     *
     * HR: Gradi stabilan ključ prava područja iz korisnika i sigurnosno bitnih
     *     svojstava područja.
     *
     * @param array<string, mixed> $workspace
     * @param array<string, mixed>|null $user
     */
    private function workspacePermissionCacheKey(array $workspace, ?array $user): string
    {
        return WorkspaceValue::int($workspace['id'] ?? 0)
        . '|'
        . $this->userId($user)
        . '|'
        . (int)$this->isAdministrator($user)
        . '|'
        . WorkspaceValue::string($workspace['visibility'] ?? '')
        . '|'
        . (int)(bool)($workspace['is_archived'] ?? false);
    }

    /**
     * EN: Builds a batched ACL result key from the Workspace, user, and supplied
     *     node structure.
     *
     * HR: Gradi ključ paketnog ACL rezultata iz područja, korisnika i strukture
     *     zadanih čvorova.
     *
     * @param array<string, mixed> $workspace
     * @param list<array<string, mixed>> $nodes
     * @param array<string, mixed>|null $user
     */
    private function nodePermissionCacheKey(array $workspace, array $nodes, ?array $user): string
    {
        $nodeSignature = '';
        foreach ($nodes as $node) {
            $nodeSignature .= WorkspaceValue::int($node['id'] ?? 0)
            . ':'
            . WorkspaceValue::int($node['parent_id'] ?? 0)
            . ';';
        }

        return $this->workspacePermissionCacheKey($workspace, $user) . '|' . $nodeSignature;
    }

    /**
     * EN: Checks an editor document through its Workspace node and inherited ACL.
     *
     * HR: Provjerava editorov dokument kroz njegov Workspace čvor i nasljedni ACL.
     */
    public function canUseDocument(string $documentKey, string $permission): bool
    {
        if (!in_array($permission, self::PERMISSION_KEYS, true)) {
            return false;
        }

        $node = $this->repository->findNodeByDocumentKey($documentKey);
        if (!is_array($node)) {
            return false;
        }

        $workspace = $this->repository->findWorkspaceById(WorkspaceValue::int($node['workspace_id'] ?? 0));
        if (!is_array($workspace)) {
            return false;
        }

        $permissions = $this->nodePermissions($workspace, $node);

        return $permissions[$permission];
    }

    /**
     * EN: Checks whether an ACL row belongs to guests, all authenticated users,
     *     the current user, or one of their real Auth groups.
     *
     * HR: Provjerava pripada li ACL red gostima, svim prijavljenima, trenutnom
     *     korisniku ili jednoj njegovoj stvarnoj Auth grupi.
     *
     * @param array<string, mixed> $row
     * @param list<int> $groupIds
     */
    private function subjectMatches(array $row, int $userId, array $groupIds): bool
    {
        $subjectType = WorkspaceValue::string($row['subject_type'] ?? '');
        $subjectId = WorkspaceValue::int($row['subject_id'] ?? null);

        return ($subjectType === WorkspaceRepository::SUBJECT_PUBLIC
            && $subjectId === WorkspaceRepository::BUILT_IN_SUBJECT_ID)
        || ($subjectType === WorkspaceRepository::SUBJECT_AUTHENTICATED
            && $subjectId === WorkspaceRepository::BUILT_IN_SUBJECT_ID
            && $userId > 0)
        || ($subjectType === WorkspaceRepository::SUBJECT_USER
            && $userId > 0
            && $subjectId === $userId)
        || ($subjectType === WorkspaceRepository::SUBJECT_GROUP
            && in_array($subjectId, $groupIds, true));
    }

    /**
     * EN: Calculates base permissions from preloaded Workspace ACL rows.
     *
     * HR: Računa bazna prava iz već učitanih Workspace ACL redaka.
     *
     * @param array<string, mixed> $workspace
     * @param array<string, mixed>|null $user
     * @param list<int> $groupIds
     * @param list<array<string, mixed>> $rows
     * @return array<string, bool>
     */
    private function workspacePermissionsFromRows(
        array $workspace,
        ?array $user,
        array $groupIds,
        array $rows,
    ): array {
        $permissions = $this->emptyPermissions();
        $userId = $this->userId($user);
        if ($this->isAdministrator($user)) {
            return $this->applyArchivedReadOnly($workspace, $this->allPermissions());
        }

        $visibility = WorkspaceValue::string($workspace['visibility'] ?? 'restricted');
        if ($visibility === 'public' || ($visibility === 'authenticated' && $userId > 0)) {
            $permissions['can_view'] = true;
        }

        foreach ($rows as $row) {
            if (!$this->subjectMatches($row, $userId, $groupIds)) {
                continue;
            }

            $permissions = $this->unionPermissions(
                $permissions,
                $this->permissionsFromRow($row),
            );
        }

        return $this->applyArchivedReadOnly($workspace, $permissions);
    }

    /**
     * EN: Applies preloaded restrictions from all ancestors to base permissions.
     *
     * HR: Primjenjuje već učitana ograničenja svih predaka na bazna prava.
     *
     * @param array<string, bool> $permissions
     * @param list<int> $ancestorIds
     * @param array<int, list<array<string, mixed>>> $rowsByNode
     * @param list<int> $groupIds
     * @return array<string, bool>
     */
    private function restrictPermissionsFromRows(
        array $permissions,
        array $ancestorIds,
        array $rowsByNode,
        int $userId,
        array $groupIds = [],
    ): array {
        foreach ($ancestorIds as $ancestorId) {
            $restrictionRows = $rowsByNode[$ancestorId] ?? [];
            if ($restrictionRows === []) {
                continue;
            }

            $hasImportedDefault = false;
            foreach ($restrictionRows as $row) {
                if (
                    WorkspaceValue::string($row['subject_type'] ?? '')
                        === WorkspaceRepository::SUBJECT_PUBLIC
                    && WorkspaceValue::int($row['subject_id'] ?? 0)
                        === WorkspaceRepository::BUILT_IN_SUBJECT_ID
                ) {
                    $hasImportedDefault = true;
                    break;
                }
            }

            if ($hasImportedDefault) {
                $allowedAtNode = $this->emptyPermissions();
                foreach ($restrictionRows as $row) {
                    $type = WorkspaceValue::string($row['subject_type'] ?? '');
                    $subjectId = WorkspaceValue::int($row['subject_id'] ?? 0);
                    $matches = ($type === WorkspaceRepository::SUBJECT_PUBLIC
                            && $subjectId === WorkspaceRepository::BUILT_IN_SUBJECT_ID)
                    || ($type === WorkspaceRepository::SUBJECT_USER && $subjectId === $userId)
                    || ($type === WorkspaceRepository::SUBJECT_GROUP && in_array($subjectId, $groupIds, true));
                    if ($matches) {
                        $allowedAtNode = $this->unionPermissions(
                            $allowedAtNode,
                            $this->permissionsFromRow($row),
                        );
                    }
                }

                foreach (self::PERMISSION_KEYS as $permission) {
                    $permissions[$permission] = $permissions[$permission] && $allowedAtNode[$permission];
                }

                continue;
            }

            $matchingRow = null;
            foreach ($restrictionRows as $row) {
                if (
                    WorkspaceValue::string($row['subject_type'] ?? '')
                        === WorkspaceRepository::SUBJECT_USER
                    && WorkspaceValue::int($row['subject_id'] ?? 0) === $userId
                ) {
                    $matchingRow = $row;
                    break;
                }
            }

            if (!is_array($matchingRow)) {
                continue;
            }

            $allowedAtNode = $this->permissionsFromRow($matchingRow);
            foreach (self::PERMISSION_KEYS as $key) {
                $permissions[$key] = $permissions[$key] && $allowedAtNode[$key];
            }
        }

        return $permissions;
    }

    /**
     * EN: Normalizes one database ACL row into a permission set.
     *
     * HR: Normalizira jedan DB ACL red u skup prava.
     *
     * @param array<string, mixed> $row
     * @return array<string, bool>
     */
    private function permissionsFromRow(array $row): array
    {
        $manage = (bool)($row['can_manage'] ?? false);
        $publish = $manage || (bool)($row['can_publish'] ?? false);
        $delete = $manage || (bool)($row['can_delete'] ?? false);
        $edit = $manage || (bool)($row['can_edit'] ?? false);
        $add = $manage || (bool)($row['can_add'] ?? false);
        $view = $add || $edit || $publish || (bool)($row['can_view'] ?? false);
        $view = $view || $delete || $manage;

        return [
            'can_view' => $view,
            'can_add' => $add,
            'can_edit' => $edit,
            'can_publish' => $publish,
            'can_delete' => $delete,
            'can_manage' => $manage,
        ];
    }

    /**
     * EN: Unions matching page-only grants; anonymous audiences are always read-only.
     *
     * HR: Spaja odgovarajuća prava samo za stranicu; anonimne publike uvijek imaju samo čitanje.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<int> $groupIds
     * @return array<int,array<string,bool>>
     */
    private function directPermissionsByNode(array $rows, int $userId, array $groupIds): array
    {
        $result = [];
        foreach ($rows as $row) {
            $type = WorkspaceValue::string($row['subject_type'] ?? 'user');
            $id = WorkspaceValue::int($row['subject_id'] ?? $row['user_id'] ?? 0);
            $matches = match ($type) {
                'public' => $id === 1,
                'authenticated' => $id === 1 && $userId > 0,
                'user' => $userId > 0 && $id === $userId,
                'group' => $userId > 0 && in_array($id, $groupIds, true),
                default => false,
            };
            if (!$matches) {
                continue;
            }

            if ($type === 'public') {
                $row['can_edit'] = $row['can_publish'] = false;
            }

            $nodeId = WorkspaceValue::int($row['node_id'] ?? 0);
            $result[$nodeId] = $this->unionPermissions(
                $result[$nodeId] ?? $this->emptyPermissions(),
                $this->permissionsFromDirectRow($row),
            );
        }

        return $result;
    }

    /**
     * EN: A direct grant may provide only viewing, editing, or publishing of this page.
     *
     * HR: Izravno pravo može dati samo pregled, uređivanje ili objavljivanje ove stranice.
     *
     * @param array<string, mixed> $row
     * @return array<string, bool>
     */
    private function permissionsFromDirectRow(array $row): array
    {
        $edit = (bool)($row['can_edit'] ?? false);
        $publish = (bool)($row['can_publish'] ?? false);

        return [
            'can_view' => $edit || $publish || (bool)($row['can_view'] ?? false),
            'can_add' => false,
            'can_edit' => $edit,
            'can_publish' => $publish,
            'can_delete' => false,
            'can_manage' => false,
        ];
    }

    /**
     * EN: Unions permissions from multiple user or group ACL rows.
     *
     * HR: Spaja prava iz više korisničkih ili grupnih ACL redaka.
     *
     * @param array<string, bool> $left
     * @param array<string, bool> $right
     * @return array<string, bool>
     */
    private function unionPermissions(array $left, array $right): array
    {
        foreach (self::PERMISSION_KEYS as $key) {
            $left[$key] = $left[$key] || $right[$key];
        }

        return $left;
    }

    /**
     * EN: The author of a new page with an effective add grant may continue
     *     editing that page without receiving edit access to other users' pages.
     *
     * HR: Autor nove stranice s važećim pravom dodavanja smije nastaviti
     *     uređivati upravo tu stranicu, bez dobivanja prava nad tuđim stranicama.
     *
     * @param array<string, bool> $permissions
     * @param array<string, mixed> $node
     * @return array<string, bool>
     */
    private function applyCreatorEditPermission(array $permissions, array $node, int $userId): array
    {
        if (
            $userId > 0
            && $permissions['can_add']
            && WorkspaceValue::int($node['created_by_user_id'] ?? 0) === $userId
        ) {
            $permissions['can_view'] = true;
            $permissions['can_edit'] = true;
        }

        return $permissions;
    }

    /**
     * EN: Returns an initial permission set with no grants.
     *
     * HR: Vraća početni skup bez prava.
     *
     * @return array<string, bool>
     */
    private function emptyPermissions(): array
    {
        return [
            'can_view' => false,
            'can_add' => false,
            'can_edit' => false,
            'can_publish' => false,
            'can_delete' => false,
            'can_manage' => false,
        ];
    }

    /**
     * EN: Returns the complete permission set for administrators.
     *
     * HR: Vraća puni skup prava za administratora.
     *
     * @return array<string, bool>
     */
    private function allPermissions(): array
    {
        return [
            'can_view' => true,
            'can_add' => true,
            'can_edit' => true,
            'can_publish' => true,
            'can_delete' => true,
            'can_manage' => true,
        ];
    }

    /**
     * EN: An archived workspace keeps viewing and settings management while disabling content changes for everyone.
     *
     * HR: Arhivirano područje ostavlja pregled i upravljanje postavkama, ali svima isključuje promjene sadržaja.
     *
     * @param array<string, mixed> $workspace
     * @param array<string, bool> $permissions
     * @return array<string, bool>
     */
    private function applyArchivedReadOnly(array $workspace, array $permissions): array
    {
        if (!(bool)($workspace['is_archived'] ?? false)) {
            return $permissions;
        }

        $permissions['can_add'] = false;
        $permissions['can_edit'] = false;
        $permissions['can_publish'] = false;
        $permissions['can_delete'] = false;

        return $permissions;
    }

    /**
     * EN: Recursively turns flat nodes into a tree using only their persisted
     *     ordering, regardless of the order in which the database returned them.
     *
     * HR: Rekurzivno slaže ravne čvorove u stablo koristeći isključivo spremljeni
     *     poredak, neovisno o redoslijedu kojim su dohvaćeni iz baze.
     *
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    private function buildTree(array $nodes, ?int $parentId): array
    {
        $branch = [];
        foreach ($nodes as $node) {
            $nodeParentId = is_numeric($node['parent_id'] ?? null) ? (int)$node['parent_id'] : null;
            if ($nodeParentId !== $parentId) {
                continue;
            }

            $node['children'] = $this->buildTree($nodes, WorkspaceValue::int($node['id'] ?? 0));
            $branch[] = $node;
        }

        // EN: The active node may be marked and expanded, but it must never gain a new position.
        //
        // HR: Aktivni čvor smije dobiti oznaku i otvorenu granu, ali nikada novu poziciju.
        usort($branch, $this->compareTreeNodes(...));

        return $branch;
    }

    /**
     * EN: Compares nodes by persisted position and durable ID. A title is not
     *     part of the order because renaming or selecting an active page could
     *     otherwise appear to move it without an explicit tree edit.
     *
     * HR: Uspoređuje čvorove prema spremljenoj poziciji i trajnom ID-u. Naslov
     *     nije dio poretka jer bi preimenovanje ili odabir aktivne stranice tada
     *     moglo prividno premjestiti stavku bez izričitog uređivanja stabla.
     *
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function compareTreeNodes(array $left, array $right): int
    {
        $order = WorkspaceValue::int($left['sort_order'] ?? 0)
        <=> WorkspaceValue::int($right['sort_order'] ?? 0);
        if ($order !== 0) {
            return $order;
        }

        return WorkspaceValue::int($left['id'] ?? 0)
        <=> WorkspaceValue::int($right['id'] ?? 0);
    }

    /**
     * EN: Reads a positive user ID from the authentication payload.
     *
     * HR: Čita pozitivan user ID iz auth payload-a.
     *
     * @param array<string, mixed>|null $user
     */
    private function userId(?array $user): int
    {
        return is_array($user) && is_numeric($user['id'] ?? null) ? (int)$user['id'] : 0;
    }

    /**
     * EN: Keeps only string keys from the authentication payload.
     *
     * HR: Zadržava samo string ključeve iz auth payload-a.
     *
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private function stringKeyArray(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
