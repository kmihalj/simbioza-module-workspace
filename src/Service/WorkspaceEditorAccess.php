<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use HeartPhrame\Routing\UrlGenerator;

use function array_key_exists;
use function array_values;
use function in_array;
use function is_array;
use function max;
use function strtolower;
use function trim;

final class WorkspaceEditorAccess
{
    /**
     * EN: Caches a document and its workspace for one request because the Editor
     *     builds several links and actions for the same document.
     *
     * HR: Pamti dokument i njegovo područje tijekom jednog zahtjeva jer Editor
     *     gradi više poveznica i akcija za isti dokument.
     *
     * @var array<string, array{
     *     node: array<string, mixed>,
     *     workspace: array<string, mixed>
     * }|null>
     */
    private array $documentContextCache = [];

    /**
     * EN: Caches effective permissions per document and user for one request.
     *
     * HR: Pamti efektivna prava po dokumentu i korisniku tijekom jednog zahtjeva.
     *
     * @var array<string, array<string, bool>>
     */
    private array $documentPermissionCache = [];

    /**
     * EN: Caches public paths per document and language for one request.
     *
     * HR: Pamti javne putanje po dokumentu i jeziku tijekom jednog zahtjeva.
     *
     * @var array<string, string>
     */
    private array $documentPathCache = [];

    /**
     * EN: Caches the published version number per document and language for one request.
     *
     * HR: Pamti broj objavljene verzije po dokumentu i jeziku tijekom jednog zahtjeva.
     *
     * @var array<string, int>
     */
    private array $publicationVersionCache = [];

    /**
     * EN: Connects editor actions to the workspace that owns a document.
     *
     * HR: Povezuje editorove akcije s područjem koje posjeduje dokument.
     */
    public function __construct(
        private readonly WorkspaceRepository $repository,
        private readonly WorkspaceAccessService $access,
        private readonly WorkspaceConfig $config,
        private readonly UrlGenerator $urlGenerator,
        private readonly WorkspaceWorkflowService $workflow,
        private readonly WorkspaceNotificationBridge $notifications,
        private readonly WorkspaceDynamicContentService $dynamicContent,
        private readonly WorkspacePresentationRegistry $presentations,
        private readonly WorkspaceThemeService $themes,
    ) {
    }

    /**
     * EN: Activates the theme of the Workspace owning an Editor document so
     *     editing and other page actions retain the Workspace visual context.
     *
     * HR: Aktivira temu područja kojem pripada Editor dokument tako da uređivanje
     *     i ostale radnje nad stranicom zadrže isti vizualni kontekst područja.
     */
    public function activateDocumentTheme(string $documentKey): void
    {
        $context = $this->documentContext($documentKey);
        if (!is_array($context)) {
            return;
        }

        $this->themes->activate($context['workspace']);
    }

    /**
     * EN: Exposes explicit storage ownership without applying interactive ACL or active-node filters.
     *
     * HR: Izlaže izričito vlasništvo pohrane bez interaktivnog ACL-a ili filtra aktivnih čvorova.
     */
    public function storageWorkspaceId(string $documentKey): ?int
    {
        return $this->repository->storageWorkspaceIdForDocument($documentKey);
    }

    /**
     * EN: Exposes the readable data directory independently of page visibility or permissions.
     *
     * HR: Izlaže čitljivi podatkovni direktorij neovisno o vidljivosti stranice ili pravima.
     */
    public function storageWorkspaceDirectory(int $workspaceId): string
    {
        return $this->config->workspaceDirectoryName($workspaceId);
    }

    /**
     * EN: Shared documents must not inherit an obsolete single-owner path.
     *
     * HR: Dijeljeni dokument ne smije naslijediti zastarjelu putanju jednog vlasnika.
     */
    public function hasMultipleStorageOwners(string $documentKey): bool
    {
        return $this->repository->hasMultipleStorageOwners($documentKey);
    }

    /**
     * EN: Checks whether a user may create a document inside the given workspace.
     *
     * HR: Provjerava smije li korisnik kreirati dokument unutar zadanog područja.
     */
    public function canCreateDocument(string $workspaceSlug): bool
    {
        $workspace = $this->repository->findWorkspaceBySlug($workspaceSlug);
        if (!is_array($workspace)) {
            return false;
        }

        return $this->access->workspacePermissions($workspace)['can_add'];
    }

    /**
     * EN: Checks inherited read permission for an editor document.
     *
     * HR: Provjerava nasljedno pravo čitanja editor dokumenta.
     */
    public function canReadDocument(string $documentKey): bool
    {
        return (bool)($this->documentPermissions($documentKey)['can_view'] ?? false);
    }

    /**
     * EN: Checks document read permission for an explicit API user.
     *
     * HR: Provjerava pravo čitanja dokumenta za eksplicitnog API korisnika.
     *
     * @param array<string,mixed> $user
     */
    public function canReadDocumentForUser(string $documentKey, array $user): bool
    {
        return (bool)($this->documentPermissionsForUser($documentKey, $user)['can_view'] ?? false);
    }

    /**
     * EN: Batch-checks ownership and read access for a list of Editor documents
     *     for the current user. The result explicitly distinguishes a standalone
     *     document from a Workspace document whose access was denied.
     *
     * HR: Grupno provjerava pripadnost i pravo čitanja popisa Editor dokumenata
     *     za trenutačnog korisnika. Rezultat izričito razlikuje samostalni
     *     dokument od Workspace dokumenta kojem je pristup odbijen.
     *
     * @param list<string> $documentKeys
     * @return array<string, array{owned: bool, can_read: bool}>
     */
    public function documentReadAccessMap(array $documentKeys): array
    {
        $user = $this->access->currentUser();

        return $this->documentReadAccessMapForUser(
            $documentKeys,
            is_array($user) ? $user : [],
        );
    }

    /**
     * EN: Batch-checks ownership and read access for a list of Editor documents
     *     for an explicit API user. Nodes, workspaces, and ACL rows are loaded
     *     in batches so large pickers do not create N+1 queries.
     *
     * HR: Grupno provjerava pripadnost i pravo čitanja popisa Editor dokumenata
     *     za eksplicitno zadanog API korisnika. Čvorovi, područja i ACL zapisi
     *     učitavaju se paketno kako veliki birači ne bi stvarali N+1 upite.
     *
     * @param list<string> $documentKeys
     * @param array<string,mixed> $user
     * @return array<string, array{owned: bool, can_read: bool}>
     */
    public function documentReadAccessMapForUser(array $documentKeys, array $user): array
    {
        $normalizedKeys = [];
        foreach ($documentKeys as $documentKey) {
            /*
             * EN: An imported document may have a numeric key which PHP casts to
             *     an integer array key; normalize it back to text here.
             *
             * HR: Uvezeni dokument može imati brojčanu oznaku koju PHP kao ključ
             *     polja pretvara u cijeli broj; ovdje je vraćamo u tekst.
             */
            $documentKey = trim((string)$documentKey);
            if ($documentKey !== '') {
                $normalizedKeys[$documentKey] = true;
            }
        }

        $result = [];
        foreach (array_keys($normalizedKeys) as $documentKey) {
            $result[$documentKey] = ['owned' => false, 'can_read' => false];
        }

        if ($result === []) {
            return [];
        }

        $contexts = $this->repository->documentContextsByKeys(array_keys($result));
        $workspacesById = [];
        $documentNodesByWorkspace = [];
        foreach ($contexts as $documentKey => $context) {
            $node = $context['node'] ?? null;
            $workspace = $context['workspace'] ?? null;
            if (!is_array($node)) {
                continue;
            }

            if (!is_array($workspace)) {
                continue;
            }

            $workspaceId = WorkspaceValue::int($workspace['id'] ?? 0);
            $nodeId = WorkspaceValue::int($node['id'] ?? 0);
            if ($workspaceId <= 0) {
                continue;
            }

            if ($nodeId <= 0) {
                continue;
            }

            $this->documentContextCache[$documentKey] = $context;
            $workspacesById[$workspaceId] = $workspace;
            $documentNodesByWorkspace[$workspaceId][$documentKey] = $node;
            $result[$documentKey] = [
                'owned' => true,
                'can_read' => $result[$documentKey]['can_read'],
            ];
        }

        $userId = WorkspaceValue::int($user['id'] ?? 0);
        $isAdministrator = $this->access->isAdministrator($user);
        foreach ($documentNodesByWorkspace as $workspaceId => $documentNodes) {
            $workspace = $workspacesById[$workspaceId];

            /*
             * EN: The complete tree is required for inherited parent restrictions.
             *     The repository loads it once per workspace, not once per document.
             *
             * HR: Cijelo stablo potrebno je za naslijeđena ograničenja roditelja.
             *     Repository ga učitava jednom po području, ne jednom po dokumentu.
             */
            $allNodes = $this->repository->nodesForWorkspace($workspaceId);
            $permissionsByNode = $this->access->nodePermissionsForNodes(
                $workspace,
                $allNodes,
                $user,
            );

            foreach ($documentNodes as $documentKey => $node) {
                $nodeId = WorkspaceValue::int($node['id'] ?? 0);
                $permissions = $permissionsByNode[$nodeId] ?? [];
                $result[$documentKey] = [
                    'owned' => $result[$documentKey]['owned'],
                    'can_read' => (bool)($permissions['can_view'] ?? false),
                ];
                $cacheKey = $documentKey . '|' . $userId . '|' . (int)$isAdministrator;
                $this->documentPermissionCache[$cacheKey] = $permissions;
            }
        }

        return $result;
    }

    /**
     * EN: Checks inherited edit permission for an editor document.
     *
     * HR: Provjerava nasljedno pravo uređivanja editor dokumenta.
     */
    public function canEditDocument(string $documentKey): bool
    {
        return (bool)($this->documentPermissions($documentKey)['can_edit'] ?? false);
    }

    /**
     * EN: Checks document edit permission for an explicit API user.
     *
     * HR: Provjerava pravo uređivanja dokumenta za eksplicitnog API korisnika.
     *
     * @param array<string,mixed> $user
     */
    public function canEditDocumentForUser(string $documentKey, array $user): bool
    {
        return (bool)($this->documentPermissionsForUser($documentKey, $user)['can_edit'] ?? false);
    }

    /**
     * EN: Checks the separate inherited publishing permission for an editor document.
     *
     * HR: Provjerava zasebno nasljedno pravo objavljivanja editor dokumenta.
     */
    public function canPublishDocument(string $documentKey): bool
    {
        return (bool)($this->documentPermissions($documentKey)['can_publish'] ?? false);
    }

    /**
     * EN: Checks document publishing permission for an explicit API user.
     *
     * HR: Provjerava pravo objavljivanja dokumenta za eksplicitnog API korisnika.
     *
     * @param array<string,mixed> $user
     */
    public function canPublishDocumentForUser(string $documentKey, array $user): bool
    {
        return (bool)($this->documentPermissionsForUser($documentKey, $user)['can_publish'] ?? false);
    }

    /**
     * EN: Checks inherited delete permission for an editor document.
     *
     * HR: Provjerava nasljedno pravo brisanja editor dokumenta.
     */
    public function canDeleteDocument(string $documentKey): bool
    {
        return (bool)($this->documentPermissions($documentKey)['can_delete'] ?? false);
    }

    /**
     * EN: Checks document deletion permission for an explicit API user.
     *
     * HR: Provjerava pravo brisanja dokumenta za eksplicitnog API korisnika.
     *
     * @param array<string,mixed> $user
     */
    public function canDeleteDocumentForUser(string $documentKey, array $user): bool
    {
        return (bool)($this->documentPermissionsForUser($documentKey, $user)['can_delete'] ?? false);
    }

    /**
     * EN: Checks management permission for an editor document.
     *
     * HR: Provjerava upravljačko pravo nad editor dokumentom.
     */
    public function canManageDocument(string $documentKey): bool
    {
        return (bool)($this->documentPermissions($documentKey)['can_manage'] ?? false);
    }

    /**
     * EN: Checks document management permission for an explicit API user.
     *
     * HR: Provjerava upravljačko pravo dokumenta za eksplicitnog API korisnika.
     *
     * @param array<string,mixed> $user
     */
    public function canManageDocumentForUser(string $documentKey, array $user): bool
    {
        return (bool)($this->documentPermissionsForUser($documentKey, $user)['can_manage'] ?? false);
    }

    /**
     * EN: Checks whether an API user may add a document at the root or below a
     * document page in the supplied Workspace.
     *
     * HR: Provjerava smije li API korisnik dodati dokument u korijen ili ispod
     * dokument-stranice zadanog područja.
     *
     * @param array<string,mixed> $user
     */
    public function canCreateDocumentForUser(
        string $workspaceSlug,
        int $parentId,
        array $user,
    ): bool {
        $workspace = $this->repository->findWorkspaceBySlug(trim($workspaceSlug));
        if (!is_array($workspace)) {
            return false;
        }

        if ($parentId <= 0) {
            return $this->access->workspacePermissions($workspace, $user)['can_add'];
        }

        $parent = $this->repository->findNodeById($parentId);
        $sameWorkspace = is_array($parent)
        && WorkspaceValue::int($parent['workspace_id'] ?? 0)
        === WorkspaceValue::int($workspace['id'] ?? 0);

        return $sameWorkspace
        && is_array($parent)
        && WorkspaceValue::string($parent['node_type'] ?? '') === 'document'
        && $this->access->nodePermissions($workspace, $parent, $user)['can_add'];
    }

    /**
     * EN: Links a newly created Editor document to a Workspace node and
     * initializes its single unpublished draft.
     *
     * HR: Povezuje upravo kreirani Editor dokument s Workspace čvorom i
     * inicijalizira njegov jedini neobjavljeni nacrt.
     *
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public function attachDocumentForUser(
        string $workspaceSlug,
        int $parentId,
        string $documentKey,
        string $title,
        string $nodeSlug,
        string $language,
        int $versionNumber,
        array $user,
    ): array {
        if (!$this->canCreateDocumentForUser($workspaceSlug, $parentId, $user)) {
            throw new \RuntimeException(__('Nemate pravo dodavanja stranice u ovo područje.'));
        }

        $workspace = $this->repository->findWorkspaceBySlug(trim($workspaceSlug));
        if (!is_array($workspace)) {
            throw new \RuntimeException(__('Područje nije pronađeno.'));
        }

        $workspaceId = WorkspaceValue::int($workspace['id'] ?? 0);
        $primaryLanguage = $this->config->siteDefaultLanguage();
        $titleTranslations = [$primaryLanguage => $title];
        if ($language !== $primaryLanguage) {
            $titleTranslations[$language] = $title;
        }

        $node = $this->repository->saveNode(
            $workspaceId,
            [
                'title' => $title,
                'title_translations' => $titleTranslations,
                'primary_language' => $primaryLanguage,
                'supported_languages' => $this->config->supportedLanguages(),
                'slug' => $nodeSlug,
                'node_type' => 'document',
                'document_key' => $documentKey,
                'parent_id' => $parentId > 0 ? $parentId : null,
                'sort_order' => 100,
                'is_homepage' => !$this->workspaceHasHomepage($workspaceId),
            ],
            WorkspaceValue::int($user['id'] ?? 0),
        );
        if ($versionNumber > 0) {
            $this->workflow->markDocumentDraft(
                $documentKey,
                $language,
                $versionNumber,
                WorkspaceValue::int($user['id'] ?? 0),
            );
        }

        $this->access->clearRequestCache();
        $this->documentContextCache = [];
        $this->documentPermissionCache = [];

        return $node;
    }

    /**
     * EN: Saves a localized page title for the user in the current web session.
     *
     * HR: Sprema naslov jezične varijante za korisnika trenutačne web-sesije.
     */
    public function saveDocumentTitleTranslation(
        string $documentKey,
        string $language,
        string $title,
    ): void {
        $this->documentContext($documentKey);
        $user = $this->access->currentUser();
        if (!is_array($user)) {
            throw new \RuntimeException(__('Nemate pravo uređivanja ove stranice.'));
        }

        $this->saveDocumentTitleTranslationForUser(
            $documentKey,
            $language,
            $title,
            $user,
        );
    }

    /**
     * EN: Stores an Editor document title in the language map of its Workspace
     * tree item. The primary title remains required and other languages fall
     * back to it when no translation is provided.
     *
     * HR: Sprema naslov Editor dokumenta u jezičnu kartu pripadajuće stavke
     * stabla. Primarni naslov ostaje obavezan, a ostali jezici koriste njegov
     * fallback kada prijevod nije upisan.
     *
     * @param array<string,mixed> $user
     */
    public function saveDocumentTitleTranslationForUser(
        string $documentKey,
        string $language,
        string $title,
        array $user,
    ): void {
        $context = $this->documentContext($documentKey);
        if (!is_array($context) || !$this->canEditDocumentForUser($documentKey, $user)) {
            throw new \RuntimeException(__('Nemate pravo uređivanja ove stranice.'));
        }

        $node = $context['node'];
        $primaryLanguage = $this->config->siteDefaultLanguage();
        $translations = $this->repository->translationMap($node['title_translations'] ?? null);
        $translations[$language] = trim($title);
        if (($translations[$primaryLanguage] ?? '') === '') {
            $translations[$primaryLanguage] = trim(WorkspaceValue::string($node['title'] ?? $title));
        }

        $this->repository->saveNode(
            WorkspaceValue::int($node['workspace_id'] ?? 0),
            [
                ...$node,
                'title_translations' => $translations,
                'primary_language' => $primaryLanguage,
                'supported_languages' => $this->config->supportedLanguages(),
            ],
            WorkspaceValue::int($user['id'] ?? 0),
        );

        $this->access->clearRequestCache();
        $this->documentContextCache = [];
        $this->documentPermissionCache = [];
    }

    /**
     * EN: Removes a document's Workspace node; a never-published new page is
     * deleted physically, while a published page uses tree soft deletion.
     *
     * HR: Uklanja Workspace čvor dokumenta; neobjavljena nova stranica briše
     * se fizički, a objavljena stranica koristi soft-delete stabla.
     *
     * @param array<string,mixed> $user
     */
    public function removeDocumentNodeForUser(
        string $documentKey,
        bool $neverPublished,
        array $user,
    ): void {
        $context = $this->documentContext($documentKey);
        if (
            !is_array($context)
            || !$this->canDeleteDocumentForUser($documentKey, $user)
        ) {
            throw new \RuntimeException(__('Nemate pravo brisanja ove stranice.'));
        }

        $workspaceId = WorkspaceValue::int($context['workspace']['id'] ?? 0);
        $nodeId = WorkspaceValue::int($context['node']['id'] ?? 0);
        $actorId = WorkspaceValue::int($user['id'] ?? 0);
        if ($neverPublished) {
            $this->repository->deleteUnpublishedNodePermanently(
                $workspaceId,
                $nodeId,
                $actorId,
            );
        } else {
            $this->repository->disableNodeTree($workspaceId, $nodeId, $actorId);
        }

        $this->access->clearRequestCache();
        $this->documentContextCache = [];
        $this->documentPermissionCache = [];
    }

    /**
     * EN: Returns the public Workspace path instead of the standalone editor slug route.
     *
     * HR: Vraća javnu Workspace putanju dokumenta umjesto samostalne editor slug rute.
     */
    public function documentPath(string $documentKey, string $language = ''): string
    {
        $cacheKey = $documentKey . '|' . $language;
        if (array_key_exists($cacheKey, $this->documentPathCache)) {
            return $this->documentPathCache[$cacheKey];
        }

        $context = $this->documentContext($documentKey);
        if (!is_array($context)) {
            return '';
        }

        $node = $context['node'];
        $workspace = $context['workspace'];
        $workspaceSlug = WorkspaceValue::string($workspace['slug'] ?? '');
        $nodeSlug = WorkspaceValue::string($node['slug'] ?? '');
        $path = $this->urlGenerator->namedRouteExists('workspace.node.show')
        ? $this->urlGenerator->getPathFor('workspace.node.show', [
            'workspaceSlug' => $workspaceSlug,
            'nodeSlug' => $nodeSlug,
        ])
        : rtrim($this->urlGenerator->getBasePath(), '/')
        . '/'
        . trim($this->config->rootPath(), '/')
        . '/'
        . rawurlencode($workspaceSlug)
        . '/'
        . rawurlencode($nodeSlug);
        if ($language !== '') {
            $path .= '?lang=' . rawurlencode($language);
        }

        return $this->documentPathCache[$cacheKey] = $path;
    }

    /**
     * EN: Resolves a portable workspace/page slug pair to its Editor document.
     *     This method does not bypass ACL; callers must still check read or
     *     change access for the returned document.
     *
     * HR: Razrješava prenosivi par slugova područja i stranice u Editorov
     *     dokument. Metoda ne zaobilazi ACL: pozivatelj i dalje mora provjeriti
     *     pravo čitanja ili promjene nad vraćenim dokumentom.
     */
    public function documentKeyForPage(string $workspaceSlug, string $nodeSlug): string
    {
        $workspace = $this->repository->findWorkspaceBySlug(trim($workspaceSlug));
        if (!is_array($workspace)) {
            return '';
        }

        $workspaceId = WorkspaceValue::int($workspace['id'] ?? 0);
        $node = $workspaceId > 0
        ? $this->repository->findNodeBySlug($workspaceId, trim($nodeSlug))
        : null;
        if (!is_array($node)) {
            return '';
        }

        return WorkspaceValue::string($node['document_key'] ?? '');
    }

    /**
     * EN: Returns true when a document belongs to an active Workspace node.
     *
     * HR: Vraća true kada dokument pripada aktivnom Workspace čvoru.
     */
    public function ownsDocument(string $documentKey): bool
    {
        return is_array($this->documentContext($documentKey));
    }

    /**
     * EN: Returns ACL-safe Workspace metadata for grouping pages in optional
     *     Editor selectors. A document without read permission exposes neither
     *     its Workspace name nor slug.
     *
     * HR: Vraća ACL-sigurne podatke područja za grupiranje stranica u
     *     opcionalnim Editor izbornicima. Dokument bez prava čitanja ne otkriva
     *     ni naziv ni slug svojeg područja.
     *
     * @return array{slug:string,title:string}|null
     */
    public function documentWorkspace(string $documentKey): ?array
    {
        $context = $this->documentContext($documentKey);
        if (!is_array($context) || !$this->canReadDocument($documentKey)) {
            return null;
        }

        $slug = WorkspaceValue::string($context['workspace']['slug'] ?? '');
        if ($slug === '') {
            return null;
        }

        return [
            'slug' => $slug,
            'title' => WorkspaceValue::string($context['workspace']['title'] ?? $slug),
        ];
    }

    /**
     * EN: Returns a lightweight ACL-safe Workspace list for Editor pickers.
     *
     * HR: Vraća lagani ACL-sigurni popis područja za Editorove birače.
     *
     * @return list<array{slug:string,title:string,is_personal_workspace:bool}>
     */
    public function workspaceChoices(string $language): array
    {
        $workspaces = $this->presentations->many($this->access->visibleWorkspaces(), $language);
        $result = [];
        foreach ($workspaces as $workspace) {
            $slug = WorkspaceValue::string($workspace['slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $title = WorkspaceValue::string($workspace['name'] ?? $slug);
            $result[] = [
                'slug' => $slug,
                'title' => $title !== '' ? $title : $slug,
                'is_personal_workspace' => (bool)($workspace['is_personal_workspace'] ?? false),
            ];
        }

        return $result;
    }

    /**
     * EN: Returns the direct outline-visibility policy of the linked Workspace page.
     *
     * HR: Vraća izravnu politiku prikaza sadržaja povezane Workspace stranice.
     */
    public function documentContentsVisibility(string $documentKey): string
    {
        $context = $this->documentContext($documentKey);
        $policy = is_array($context)
        ? WorkspaceValue::string($context['node']['contents_visibility'] ?? 'inherit')
        : 'inherit';

        return in_array($policy, ['inherit', 'shown', 'hidden'], true) ? $policy : 'inherit';
    }

    /**
     * EN: Saves the direct outline policy only when the user may edit the page.
     *
     * HR: Sprema izravnu politiku prikaza sadržaja samo kada korisnik smije uređivati stranicu.
     */
    public function saveDocumentContentsVisibility(string $documentKey, string $policy): void
    {
        $this->documentContext($documentKey);
        $user = $this->access->currentUser();
        if (!is_array($user)) {
            throw new \RuntimeException(__('Nemate pravo uređivanja ove stranice.'));
        }

        $this->saveDocumentContentsVisibilityForUser($documentKey, $policy, $user);
    }

    /**
     * EN: Saves the display policy for an explicit API user without relying on
     *     an HTTP session, which intentionally does not exist for API-key auth.
     *
     * HR: Sprema politiku prikaza za eksplicitnog API korisnika, bez oslanjanja
     *     na HTTP sesiju koja kod autentikacije API ključem namjerno ne postoji.
     *
     * @param array<string,mixed> $user
     */
    public function saveDocumentContentsVisibilityForUser(
        string $documentKey,
        string $policy,
        array $user,
    ): void {
        $context = $this->documentContext($documentKey);
        if (!is_array($context) || !$this->canEditDocumentForUser($documentKey, $user)) {
            throw new \RuntimeException(__('Nemate pravo uređivanja ove stranice.'));
        }

        $this->repository->updateNodeContentsVisibility(
            WorkspaceValue::int($context['node']['id'] ?? 0),
            $policy,
            WorkspaceValue::int($user['id'] ?? 0),
        );
        $this->documentContextCache = [];
    }

    /**
     * EN: Returns the published version for a linked page locale; null leaves
     *     standalone Editor rendering untouched, while zero hides unpublished content.
     *
     * HR: Vraća objavljenu verziju povezane jezične stranice; null ostavlja
     *     samostalni Editor prikaz netaknut, a nula skriva neobjavljeni sadržaj.
     */
    public function publicationVersion(string $documentKey, string $language): ?int
    {
        $context = $this->documentContext($documentKey);
        if (!is_array($context)) {
            return null;
        }

        $cacheKey = $documentKey . '|' . $language;
        if (array_key_exists($cacheKey, $this->publicationVersionCache)) {
            return $this->publicationVersionCache[$cacheKey];
        }

        return $this->publicationVersionCache[$cacheKey] = $this->workflow->publicationVersionForNode(
            WorkspaceValue::int($context['node']['id'] ?? 0),
            $language,
        );
    }

    /**
     * EN: Batch-returns published versions for Workspace documents. Standalone
     *     documents are absent while unpublished Workspace documents map to zero.
     *
     * HR: Skupno vraća objavljene verzije Workspace dokumenata. Dokumenti koji
     *     ne pripadaju Workspaceu nisu u rezultatu, a neobjavljeni imaju vrijednost nula.
     *
     * @param list<string> $documentKeys
     * @return array<string,int>
     */
    public function publicationVersionMap(array $documentKeys, string $language): array
    {
        $contexts = [];
        $missingDocumentKeys = [];
        foreach ($documentKeys as $documentKey) {
            $documentKey = trim((string)$documentKey);
            if ($documentKey === '') {
                continue;
            }

            if (array_key_exists($documentKey, $this->documentContextCache)) {
                $context = $this->documentContextCache[$documentKey];
                if (is_array($context)) {
                    $contexts[$documentKey] = $context;
                }

                continue;
            }

            $missingDocumentKeys[] = $documentKey;
        }

        if ($missingDocumentKeys !== []) {
            $contexts += $this->repository->documentContextsByKeys($missingDocumentKeys);
        }

        $nodeIds = [];
        foreach ($contexts as $documentKey => $context) {
            $documentKey = trim((string)$documentKey);
            $node = $context['node'] ?? null;
            if ($documentKey === '' || !is_array($node)) {
                continue;
            }

            $nodeId = WorkspaceValue::int($node['id'] ?? 0);
            if ($nodeId <= 0) {
                continue;
            }

            $this->documentContextCache[$documentKey] = $context;
            $nodeIds[$documentKey] = $nodeId;
        }

        $workflows = $this->repository->nodeWorkflowsForNodes(array_values($nodeIds), $language);
        $versions = [];
        foreach ($nodeIds as $documentKey => $nodeId) {
            $workflow = $workflows[$nodeId] ?? null;
            $status = is_array($workflow)
            ? strtolower(WorkspaceValue::string($workflow['status'] ?? ''))
            : '';
            $version = is_array($workflow) && $status !== WorkspaceWorkflowService::STATUS_ARCHIVED
            ? WorkspaceValue::int($workflow['published_version_number'] ?? 0)
            : 0;
            $versions[$documentKey] = max(0, $version);
            $this->publicationVersionCache[$documentKey . '|' . $language] = $versions[$documentKey];
        }

        return $versions;
    }

    /**
     * EN: Materializes native Workspace blocks in Editor views and exports.
     *
     * HR: Materijalizira nativne blokove područja u Editorovu prikazu i izvozu.
     */
    public function renderDynamicContent(
        string $html,
        string $documentKey,
        string $language,
        bool $interactive = true,
    ): string {
        return $this->dynamicContent->render($html, $documentKey, $language, $interactive);
    }

    /**
     * EN: Builds a declarative element that Editor and API can safely persist.
     *
     * HR: Gradi deklarativni element koji Editor i API mogu sigurno spremiti.
     *
     * @param array<string,mixed> $configuration
     */
    public function dynamicContentPlaceholder(string $kind, array $configuration = []): string
    {
        return $this->dynamicContent->placeholder($kind, $configuration);
    }

    /**
     * EN: After an Editor save, marks the linked page as draft and records the
     *     newly created immutable version number.
     *
     * HR: Nakon Editor spremanja označava povezanu stranicu nacrtom i bilježi
     *     broj upravo nastale nepromjenjive verzije.
     */
    public function markDocumentDraft(
        string $documentKey,
        string $language,
        int $versionNumber,
    ): void {
        $user = $this->access->currentUser();
        $this->markDocumentDraftForUser(
            $documentKey,
            $language,
            $versionNumber,
            is_array($user) ? $user : [],
        );
    }

    /**
     * EN: Records a new working document version for an explicit API user.
     *
     * HR: Bilježi novu radnu verziju dokumenta za eksplicitnog API korisnika.
     *
     * @param array<string,mixed> $user
     */
    public function markDocumentDraftForUser(
        string $documentKey,
        string $language,
        int $versionNumber,
        array $user,
    ): void {
        $this->workflow->markDocumentDraft(
            $documentKey,
            $language,
            $versionNumber,
            WorkspaceValue::int($user['id'] ?? 0),
        );
    }

    /**
     * EN: Synchronizes the Workspace workflow after an Editor publication while
     *     independently enforcing the publishing permission.
     *
     * HR: Nakon Editorove objave usklađuje Workspace workflow uz zasebnu
     *     provjeru prava objavljivanja.
     */
    public function publishDocumentDraft(
        string $documentKey,
        string $language,
        int $versionNumber,
    ): void {
        $user = $this->access->currentUser();
        $this->publishDocumentDraftForUser(
            $documentKey,
            $language,
            $versionNumber,
            is_array($user) ? $user : [],
        );
    }

    /**
     * EN: Submits the current Workspace draft for review for the authenticated user.
     *
     * HR: Šalje aktualni Workspace nacrt na pregled za prijavljenog korisnika.
     */
    public function submitDocumentDraft(
        string $documentKey,
        string $language,
        int $versionNumber,
    ): void {
        $user = $this->access->currentUser();
        $this->submitDocumentDraftForUser(
            $documentKey,
            $language,
            $versionNumber,
            is_array($user) ? $user : [],
        );
    }

    /**
     * EN: Submits a Workspace draft for review with an explicit API user's
     *     permissions and notifies all effective publishers.
     *
     * HR: Šalje Workspace nacrt na pregled uz prava eksplicitnog API korisnika
     *     i obavještava sve efektivne objavljivače.
     *
     * @param array<string,mixed> $user
     */
    public function submitDocumentDraftForUser(
        string $documentKey,
        string $language,
        int $versionNumber,
        array $user,
    ): void {
        $context = $this->documentContext($documentKey);
        if (!is_array($context) || !$this->canEditDocumentForUser($documentKey, $user)) {
            throw new \RuntimeException(__('Nemate pravo uređivanja ove stranice.'));
        }

        $node = $context['node'];
        $workspace = $context['workspace'];
        $permissions = $this->documentPermissionsForUser($documentKey, $user);
        $actorUserId = WorkspaceValue::int($user['id'] ?? 0);
        $this->workflow->transition(
            WorkspaceValue::int($node['id'] ?? 0),
            $language,
            'submit',
            $versionNumber,
            $actorUserId,
            (bool)($permissions['can_edit'] ?? false),
            (bool)($permissions['can_publish'] ?? false),
            (bool)($permissions['can_manage'] ?? false),
        );
        $this->notifications->pageSubmitted(
            $workspace,
            $node,
            $language,
            $versionNumber,
            $actorUserId,
        );
    }

    /**
     * EN: Publishes a Workspace draft with an explicit API user's permissions.
     *
     * HR: Objavljuje Workspace nacrt uz prava eksplicitnog API korisnika.
     *
     * @param array<string,mixed> $user
     */
    public function publishDocumentDraftForUser(
        string $documentKey,
        string $language,
        int $versionNumber,
        array $user,
    ): void {
        $context = $this->documentContext($documentKey);
        if (!is_array($context) || !$this->canPublishDocumentForUser($documentKey, $user)) {
            throw new \RuntimeException(__('Nemate pravo objavljivanja ove stranice.'));
        }

        $node = $context['node'];
        $workspace = $context['workspace'];
        $permissions = $this->documentPermissionsForUser($documentKey, $user);
        $actorUserId = WorkspaceValue::int($user['id'] ?? 0);
        $workflowBeforePublish = $this->repository->nodeWorkflow(
            WorkspaceValue::int($node['id'] ?? 0),
            $language,
        );
        $this->workflow->transition(
            WorkspaceValue::int($node['id'] ?? 0),
            $language,
            'publish',
            $versionNumber,
            $actorUserId,
            (bool)($permissions['can_edit'] ?? false),
            (bool)($permissions['can_publish'] ?? false),
            (bool)($permissions['can_manage'] ?? false),
        );
        $this->notifications->pagePublished(
            $workspace,
            $node,
            $language,
            $versionNumber,
            WorkspaceValue::int($workflowBeforePublish['submitted_by_user_id'] ?? 0),
            $actorUserId,
        );
        unset($this->publicationVersionCache[$documentKey . '|' . $language]);
    }

    /**
     * EN: After Editor draft discard, returns the workflow to the last
     *     publication or to a clean initial draft for a new page.
     *
     * HR: Nakon Editorova odbacivanja nacrta vraća workflow na zadnju objavu
     *     ili na čisti početni nacrt nove stranice.
     */
    public function discardDocumentDraft(
        string $documentKey,
        string $language,
        int $currentVersionNumber,
    ): void {
        $user = $this->access->currentUser();
        $this->discardDocumentDraftForUser(
            $documentKey,
            $language,
            $currentVersionNumber,
            is_array($user) ? $user : [],
        );
    }

    /**
     * EN: Discards a Workspace draft with an explicit API user's permissions.
     *
     * HR: Odbacuje Workspace nacrt uz prava eksplicitnog API korisnika.
     *
     * @param array<string,mixed> $user
     */
    public function discardDocumentDraftForUser(
        string $documentKey,
        string $language,
        int $currentVersionNumber,
        array $user,
    ): void {
        $context = $this->documentContext($documentKey);
        if (!is_array($context) || !$this->canEditDocumentForUser($documentKey, $user)) {
            throw new \RuntimeException(__('Nemate pravo uređivanja ove stranice.'));
        }

        $node = $context['node'];
        $this->workflow->discardDraft(
            WorkspaceValue::int($node['id'] ?? 0),
            $language,
            $currentVersionNumber,
            WorkspaceValue::int($user['id'] ?? 0),
        );
        unset($this->publicationVersionCache[$documentKey . '|' . $language]);
    }

    /**
     * EN: Loads a document and its workspace only once during a request. Null is
     *     cached as well so a missing document is not looked up repeatedly.
     *
     * HR: Učitava dokument i pripadajuće područje samo jednom tijekom zahtjeva.
     *     Null se također pamti kako nepostojeći dokument ne bi stalno tražili.
     *
     * @return array{
     *     node: array<string, mixed>,
     *     workspace: array<string, mixed>
     * }|null
     */
    private function documentContext(string $documentKey): ?array
    {
        if (array_key_exists($documentKey, $this->documentContextCache)) {
            return $this->documentContextCache[$documentKey];
        }

        $node = $this->repository->findNodeByDocumentKey($documentKey);
        if (!is_array($node)) {
            return $this->documentContextCache[$documentKey] = null;
        }

        $workspace = $this->repository->findWorkspaceById(
            WorkspaceValue::int($node['workspace_id'] ?? 0),
        );
        if (!is_array($workspace)) {
            return $this->documentContextCache[$documentKey] = null;
        }

        return $this->documentContextCache[$documentKey] = [
            'node' => $node,
            'workspace' => $workspace,
        ];
    }

    /**
     * EN: Calculates and caches the complete permission set so individual Editor
     *     read, edit, publish, and delete checks do not repeat the same ACL work.
     *
     * HR: Računa i pamti cijeli skup prava kako pojedinačne Editor provjere
     *     čitanja, izmjene, objave i brisanja ne ponavljaju isti ACL izračun.
     *
     * @return array<string, bool>
     */
    private function documentPermissions(string $documentKey): array
    {
        $user = $this->access->currentUser();
        return $this->documentPermissionsForUser(
            $documentKey,
            is_array($user) ? $user : [],
        );
    }

    /**
     * EN: Calculates effective document permissions for an explicitly supplied user.
     *
     * HR: Računa efektivna prava dokumenta za eksplicitno predanog korisnika.
     *
     * @param array<string,mixed> $user
     * @return array<string,bool>
     */
    private function documentPermissionsForUser(string $documentKey, array $user): array
    {
        $cacheKey = $documentKey
        . '|'
        . WorkspaceValue::int($user['id'] ?? 0)
        . '|'
        . (int)$this->access->isAdministrator($user);
        if (array_key_exists($cacheKey, $this->documentPermissionCache)) {
            return $this->documentPermissionCache[$cacheKey];
        }

        $context = $this->documentContext($documentKey);
        if (!is_array($context)) {
            return $this->documentPermissionCache[$cacheKey] = [];
        }

        return $this->documentPermissionCache[$cacheKey] = $this->access->nodePermissions(
            $context['workspace'],
            $context['node'],
            $user,
        );
    }

    /**
     * EN: Checks whether a Workspace has an active document homepage.
     *
     * HR: Provjerava ima li područje aktivnu početnu dokument-stranicu.
     */
    private function workspaceHasHomepage(int $workspaceId): bool
    {
        foreach ($this->repository->nodesForWorkspace($workspaceId) as $node) {
            if (
                WorkspaceValue::string($node['node_type'] ?? '') === 'document'
                && (bool)($node['is_homepage'] ?? false)
            ) {
                return true;
            }
        }

        return false;
    }
}
