<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleWorkspace\Service;

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
     * HR: Pamti grupne identifikatore po korisniku tijekom jednog zahtjeva.
     * EN: Caches group identifiers per user during one request.
     *
     * @var array<int, list<int>>
     */
    private array $groupIdsCache = [];

    /**
     * HR: Pamti ACL retke područja tijekom jednog zahtjeva.
     * EN: Caches Workspace ACL rows during one request.
     *
     * @var array<int, list<array<string, mixed>>>
     */
    private array $workspaceAclCache = [];

    /**
     * HR: Pamti već izračunata prava područja po korisniku.
     * EN: Caches already calculated Workspace permissions per user.
     *
     * @var array<string, array<string, bool>>
     */
    private array $workspacePermissionCache = [];

    /**
     * HR: Pamti čvorove područja tijekom jednog zahtjeva.
     * EN: Caches Workspace nodes during one request.
     *
     * @var array<int, list<array<string, mixed>>>
     */
    private array $workspaceNodesCache = [];

    /**
     * HR: Pamti paketni rezultat nasljednog ACL izračuna.
     * EN: Caches batched inherited ACL calculation results.
     *
     * @var array<string, array<int, array<string, bool>>>
     */
    private array $nodePermissionCache = [];

    /**
     * HR: Prima repozitorij, auth kontekst i konfiguraciju potrebnu za jedinstveni ACL izračun.
     * EN: Receives the repository, auth context, and configuration required for one ACL calculation.
     */
    public function __construct(
        private readonly WorkspaceRepository $repository,
        private readonly AuthnHandlerInterface $authnHandler,
        private readonly WorkspaceConfig $config,
        private readonly WorkspaceWorkflowService $workflow,
    ) {
    }

    /**
     * HR: Vraća normalizirani session payload trenutnog korisnika ili null za gosta.
     * EN: Returns the normalized current-user session payload or null for a guest.
     *
     * @return array<string, mixed>|null
     */
    public function currentUser(): ?array
    {
        $user = $this->authnHandler->userData();

        return is_array($user) ? $this->stringKeyArray($user) : null;
    }

    /**
     * HR: Provjerava administratorski status trenutnog ili proslijeđenog korisnika.
     * EN: Checks administrator status for the current or supplied user.
     *
     * @param array<string, mixed>|null $user
     */
    public function isAdministrator(?array $user = null): bool
    {
        $user ??= $this->currentUser();

        return is_array($user) && (bool)($user['is_admin'] ?? false);
    }

    /**
     * HR: Vraća smije li korisnik kreirati novo područje.
     * EN: Returns whether the user may create a new workspace.
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

        return $this->config->authenticatedUsersMayCreate();
    }

    /**
     * HR: Filtrira područja koja korisnik stvarno smije vidjeti.
     * EN: Filters workspaces the user may actually view.
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
        }

        $visible = [];
        foreach ($workspaces as $workspace) {
            $permissions = $this->workspacePermissions($workspace, $user);
            if (!$permissions['can_view']) {
                continue;
            }

            $workspace['permissions'] = $permissions;
            $visible[] = $workspace;
        }

        return $visible;
    }

    /**
     * HR: Računa bazna prava područja kao uniju direktnog korisničkog i grupnog ACL-a.
     * EN: Calculates base workspace permissions as the union of direct user and group ACL entries.
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
        if (
            $this->isAdministrator($user)
            || ($userId > 0 && $userId === WorkspaceValue::int($workspace['owner_user_id'] ?? 0))
        ) {
            return $this->workspacePermissionCache[$cacheKey] = $this->workspacePermissionsFromRows(
                $workspace,
                $user,
                [],
                [],
            );
        }

        $groupIds = $this->groupIds($userId);

        return $this->workspacePermissionCache[$cacheKey] = $this->workspacePermissionsFromRows(
            $workspace,
            $user,
            $groupIds,
            $this->workspaceAclRows($workspaceId),
        );
    }

    /**
     * HR: Primjenjuje ograničenja od korijena do čvora kao presjek naslijeđenih prava.
     * EN: Applies restrictions from root to node as an intersection of inherited permissions.
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
     * HR: Grupno računa efektivna prava svih zadanih čvorova iz jednog skupa
     *     Workspace ACL-a, grupa i ograničenja. Početnička napomena: lanac
     *     roditelja računa se u memoriji pa veliko stablo ne izvodi upit po retku.
     *
     * EN: Calculates effective permissions for all supplied nodes in one batch
     *     from Workspace ACL, groups, and restrictions. Beginner note: parent
     *     chains are calculated in memory so a large tree does not query per row.
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

        $groupIds = $this->groupIds($userId);
        $base = $this->workspacePermissions($workspace, $user);

        $nodeIds = [];
        $parentIds = [];
        foreach ($nodes as $node) {
            $nodeId = WorkspaceValue::int($node['id'] ?? 0);
            if ($nodeId <= 0) {
                continue;
            }

            $nodeIds[] = $nodeId;
            $parentIds[$nodeId] = WorkspaceValue::int($node['parent_id'] ?? 0);
        }

        if ($nodeIds === []) {
            return [];
        }

        if (
            $this->isAdministrator($user)
            || ($userId > 0 && $userId === WorkspaceValue::int($workspace['owner_user_id'] ?? 0))
        ) {
            return $this->nodePermissionCache[$cacheKey] = array_fill_keys($nodeIds, $base);
        }

        $rowsByNode = [];
        foreach ($this->repository->nodeAclRowsForNodes($nodeIds) as $row) {
            $restrictionNodeId = WorkspaceValue::int($row['node_id'] ?? 0);
            if ($restrictionNodeId > 0) {
                $rowsByNode[$restrictionNodeId][] = $row;
            }
        }

        $permissions = [];
        foreach ($nodeIds as $nodeId) {
            $permissions[$nodeId] = $this->restrictPermissionsFromRows(
                $base,
                $this->ancestorIdsFromParentMap($nodeId, $parentIds),
                $rowsByNode,
                $userId,
                $groupIds,
            );
        }

        return $this->nodePermissionCache[$cacheKey] = $permissions;
    }

    /**
     * HR: Grupno vraća aktivne korisnike koji na odabranom čvoru imaju traženo
     *     efektivno pravo, uključujući vlasnika i administratore.
     * EN: Returns active users who hold the requested effective permission on
     *     the selected node in one batch, including the owner and administrators.
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
            if (
                !$this->isAdministrator($user)
                && $userId !== WorkspaceValue::int($workspace['owner_user_id'] ?? 0)
            ) {
                $permissions = $this->restrictPermissionsFromRows(
                    $permissions,
                    $ancestorIds,
                    $rowsByNode,
                    $userId,
                    $groupsByUser[$userId] ?? [],
                );
            }

            if ($permissions[$permission]) {
                $allowedUserIds[] = $userId;
            }
        }

        return $allowedUserIds;
    }

    /**
     * HR: Vraća vidljivo stablo s efektivnim pravima svakog čvora.
     * EN: Returns the visible tree with effective permissions for every node.
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
     * HR: Vraća stablo čitljivo u prvom objavljenom jeziku iz zadanog prioriteta.
     * EN: Returns the tree readable in the first published locale from the supplied priority.
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
    ): array {
        $nodes = $this->nodesForWorkspace(WorkspaceValue::int($workspace['id'] ?? 0));
        $visible = $this->visibleNodesForLanguages($workspace, $user, $languages, $nodes);

        return $this->buildTree($visible, null);
    }

    /**
     * HR: Vraća početni, mali prozor vidljivog stabla. Velika područja zato
     *     ne računaju ACL i workflow za svaku skrivenu stranicu pri svakom prikazu.
     * EN: Returns the initial compact visible-tree window. Large Workspaces no
     *     longer calculate ACL and workflow for every hidden page on each view.
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
    ): array {
        $nodes = $this->repository->treeWindowNodes(
            WorkspaceValue::int($workspace['id'] ?? 0),
            $activeNodeId,
        );
        $visible = $this->visibleNodesForLanguages($workspace, $user, $languages, $nodes);

        return $this->buildTree($visible, null);
    }

    /**
     * HR: Vraća neposrednu ACL-filtriranu djecu grane za naknadno učitavanje.
     * EN: Returns immediate ACL-filtered branch children for on-demand loading.
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
    ): array {
        $nodes = $this->repository->treeBranchNodes(
            WorkspaceValue::int($workspace['id'] ?? 0),
            $parentId,
        );
        $visible = $this->visibleNodesForLanguages($workspace, $user, $languages, $nodes);

        return array_values(array_filter(
            $visible,
            static fn(array $node): bool => WorkspaceValue::int($node['parent_id'] ?? 0) === $parentId,
        ));
    }

    /**
     * HR: Primjenjuje paketni ACL i jezični workflow na već ciljano dohvaćene čvorove.
     * EN: Applies batched ACL and locale workflow checks to already targeted nodes.
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

            if (
                $languages !== []
                && WorkspaceValue::string($node['node_type'] ?? '') === 'document'
                && !$permissions['can_edit']
                && !$permissions['can_publish']
                && !$permissions['can_manage']
                && !$this->hasReadableLanguage(
                    WorkspaceValue::rows($workflowStates[$nodeId] ?? null),
                    $languages,
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
     * HR: Provjerava postoji li čitljiva objava u dopuštenom jezičnom prioritetu.
     * EN: Checks whether a readable publication exists in the allowed locale priority.
     *
     * @param list<array<string, mixed>> $workflows
     * @param list<string> $languages
     */
    private function hasReadableLanguage(array $workflows, array $languages): bool
    {
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

        return false;
    }

    /**
     * HR: Briše kratkotrajne ACL cacheve nakon promjene prava u istom zahtjevu.
     *     Početnička napomena: sljedeći izračun tada ponovno čita bazu.
     * EN: Clears short-lived ACL caches after permissions change in the same
     *     request. Beginner note: the next calculation then reads the database again.
     */
    public function clearRequestCache(): void
    {
        $this->groupIdsCache = [];
        $this->workspaceAclCache = [];
        $this->workspacePermissionCache = [];
        $this->workspaceNodesCache = [];
        $this->nodePermissionCache = [];
    }

    /**
     * HR: Gradi lanac predaka iz već učitane mape roditelja i zaustavlja se
     *     na nedostajućem čvoru ili ciklusu neispravnih podataka.
     * EN: Builds an ancestor chain from a preloaded parent map and stops at a
     *     missing node or a cycle in malformed data.
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
     * HR: Vraća korisnikove grupe iz cachea ili ih prvi put učitava kroz repozitorij.
     * EN: Returns a user's groups from cache or loads them through the repository once.
     *
     * @return list<int>
     */
    private function groupIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        if (!array_key_exists($userId, $this->groupIdsCache)) {
            $this->groupIdsCache[$userId] = $this->repository->groupIdsForUser($userId);
        }

        return $this->groupIdsCache[$userId];
    }

    /**
     * HR: Vraća ACL retke područja iz cachea ili ih prvi put učitava.
     * EN: Returns Workspace ACL rows from cache or loads them once.
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
     * HR: Unaprijed puni request cache ACL retcima svih područja jednim upitom.
     * EN: Preloads the request cache with ACL rows for all Workspaces in one query.
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
     * HR: Vraća sve čvorove područja iz cachea ili ih prvi put učitava.
     * EN: Returns all Workspace nodes from cache or loads them once.
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
     * HR: Gradi stabilan ključ prava područja iz korisnika i sigurnosno bitnih
     *     svojstava područja.
     * EN: Builds a stable Workspace-permission key from the user and
     *     security-relevant Workspace properties.
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
        . WorkspaceValue::int($workspace['owner_user_id'] ?? 0)
        . '|'
        . WorkspaceValue::string($workspace['visibility'] ?? '')
        . '|'
        . (int)(bool)($workspace['is_archived'] ?? false);
    }

    /**
     * HR: Gradi ključ paketnog ACL rezultata iz područja, korisnika i strukture
     *     zadanih čvorova.
     * EN: Builds a batched ACL result key from the Workspace, user, and supplied
     *     node structure.
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
     * HR: Provjerava editorov dokument kroz njegov Workspace čvor i nasljedni ACL.
     * EN: Checks an editor document through its Workspace node and inherited ACL.
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
     * HR: Provjerava pripada li ACL red gostima, svim prijavljenima, trenutnom
     *     korisniku ili jednoj njegovoj stvarnoj Auth grupi.
     * EN: Checks whether an ACL row belongs to guests, all authenticated users,
     *     the current user, or one of their real Auth groups.
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
     * HR: Računa bazna prava iz već učitanih Workspace ACL redaka.
     * EN: Calculates base permissions from preloaded Workspace ACL rows.
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
        $isOwner = $userId > 0
        && $userId === WorkspaceValue::int($workspace['owner_user_id'] ?? 0);
        if ($this->isAdministrator($user) || $isOwner) {
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
     * HR: Primjenjuje već učitana ograničenja svih predaka na bazna prava.
     * EN: Applies preloaded restrictions from all ancestors to base permissions.
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
        array $groupIds,
    ): array {
        foreach ($ancestorIds as $ancestorId) {
            $restrictionRows = $rowsByNode[$ancestorId] ?? [];
            if ($restrictionRows === []) {
                continue;
            }

            $allowedAtNode = $this->emptyPermissions();
            foreach ($restrictionRows as $row) {
                if ($this->subjectMatches($row, $userId, $groupIds)) {
                    $allowedAtNode = $this->unionPermissions(
                        $allowedAtNode,
                        $this->permissionsFromRow($row),
                    );
                }
            }

            foreach (self::PERMISSION_KEYS as $key) {
                $permissions[$key] = $permissions[$key] && $allowedAtNode[$key];
            }
        }

        return $permissions;
    }

    /**
     * HR: Normalizira jedan DB ACL red u skup prava.
     * EN: Normalizes one database ACL row into a permission set.
     *
     * @param array<string, mixed> $row
     * @return array<string, bool>
     */
    private function permissionsFromRow(array $row): array
    {
        $manage = (bool)($row['can_manage'] ?? false);
        $publish = $manage || (bool)($row['can_publish'] ?? false);
        $delete = $manage || (bool)($row['can_delete'] ?? false);
        $edit = $delete || (bool)($row['can_edit'] ?? false);
        $add = $manage || (bool)($row['can_add'] ?? false);
        $view = $add || $edit || $publish || (bool)($row['can_view'] ?? false);

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
     * HR: Spaja prava iz više korisničkih ili grupnih ACL redaka.
     * EN: Unions permissions from multiple user or group ACL rows.
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
     * HR: Vraća početni skup bez prava.
     * EN: Returns an initial permission set with no grants.
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
     * HR: Vraća puni skup prava za administratora i vlasnika područja.
     * EN: Returns the complete permission set for administrators and workspace owners.
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
     * HR: Arhivirano područje ostavlja pregled i upravljanje postavkama, ali svima isključuje promjene sadržaja.
     * EN: An archived workspace keeps viewing and settings management while disabling content changes for everyone.
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
     * HR: Rekurzivno slaže ravne čvorove u stablo koristeći isključivo spremljeni
     *     poredak, neovisno o redoslijedu kojim su dohvaćeni iz baze.
     * EN: Recursively turns flat nodes into a tree using only their persisted
     *     ordering, regardless of the order in which the database returned them.
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

        // HR: Aktivni čvor smije dobiti oznaku i otvorenu granu, ali nikada novu poziciju.
        // EN: The active node may be marked and expanded, but it must never gain a new position.
        usort($branch, $this->compareTreeNodes(...));

        return $branch;
    }

    /**
     * HR: Uspoređuje čvorove prema spremljenoj poziciji i trajnom ID-u. Naslov
     *     nije dio poretka jer bi preimenovanje ili odabir aktivne stranice tada
     *     moglo prividno premjestiti stavku bez izričitog uređivanja stabla.
     * EN: Compares nodes by persisted position and durable ID. A title is not
     *     part of the order because renaming or selecting an active page could
     *     otherwise appear to move it without an explicit tree edit.
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
     * HR: Čita pozitivan user ID iz auth payload-a.
     * EN: Reads a positive user ID from the authentication payload.
     *
     * @param array<string, mixed>|null $user
     */
    private function userId(?array $user): int
    {
        return is_array($user) && is_numeric($user['id'] ?? null) ? (int)$user['id'] : 0;
    }

    /**
     * HR: Zadržava samo string ključeve iz auth payload-a.
     * EN: Keeps only string keys from the authentication payload.
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
