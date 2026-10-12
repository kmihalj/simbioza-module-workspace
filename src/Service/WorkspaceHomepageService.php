<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use HeartPhrame\Config\ConfigInterface;
use HeartPhrame\Localization\TranslatorInterface;
use HeartPhrame\Routing\UrlGenerator;
use RuntimeException;

use function array_values;
use function in_array;
use function is_array;
use function is_numeric;
use function is_scalar;
use function preg_match;
use function rawurlencode;
use function rtrim;
use function strtolower;
use function trim;

/**
 * EN: Resolves the application homepage from public, authenticated, and
 * personal policy while rechecking ACL and publication before every redirect.
 *
 * HR: Određuje naslovnicu aplikacije prema javnoj, prijavljenoj i osobnoj
 * politici te prije svakog redirecta ponovno provjerava ACL i objavljenost.
 */
final readonly class WorkspaceHomepageService
{
    private const GENERIC_AUTHENTICATED_USER_ID = 2147483647;

    /**
     * EN: Receives Workspace data, ACL, workflow, and URL services without an Auth reverse dependency.
     *
     * HR: Prima Workspace podatke, ACL, workflow i URL servise bez obrnute ovisnosti Autha.
     */
    public function __construct(
        private WorkspaceRepository $repository,
        private WorkspaceHomepageRepository $homepages,
        private WorkspaceAccessService $access,
        private WorkspaceWorkflowService $workflow,
        private WorkspaceConfig $workspaceConfig,
        private UrlGenerator $urlGenerator,
        private TranslatorInterface $translator,
        private ConfigInterface $config,
    ) {
    }

    /**
     * EN: Checks whether the complete Workspace schema is ready.
     *
     * HR: Provjerava je li potpuna Workspace shema spremna.
     */
    public function tablesReady(): bool
    {
        return $this->repository->tablesReady() && $this->homepages->tablesReady();
    }

    /**
     * EN: Prepares administrator values and separate safe choices for guests and authenticated users.
     *
     * HR: Priprema administratorske vrijednosti i odvojene sigurne izbore za goste i prijavljene.
     *
     * @return array<string, mixed>
     */
    public function settingsForForm(): array
    {
        return $this->settingsForFormForLocale('');
    }

    /**
     * EN: Prepares administrator values without relying on the web session for locale.
     *
     * HR: Priprema administratorske vrijednosti bez oslanjanja na web sesiju za jezik.
     *
     * @return array<string, mixed>
     */
    public function settingsForFormForLocale(string $locale): array
    {
        $settings = $this->homepages->settings();

        return [
            'settings' => $settings,
            'view_options_ready' => $this->homepages->viewOptionsReady(),
            'public_option_groups' => $this->selectablePageGroups([], $locale),
            'authenticated_option_groups' => $this->selectablePageGroups([
                'id' => self::GENERIC_AUTHENTICATED_USER_ID,
                'is_admin' => false,
            ], $locale),
        ];
    }

    /**
     * EN: Validates and stores the administrator homepage policy.
     *
     * HR: Validira i sprema administratorsku politiku naslovnice.
     *
     * @param array<string, mixed> $input
     */
    public function saveSettings(array $input, int $actorUserId): void
    {
        $this->saveSettingsForLocale($input, $actorUserId, '');
    }

    /**
     * EN: Stores administrator policy using the API or web caller's explicit locale.
     *
     * HR: Sprema administratorsku politiku uz izričit jezik pozivatelja API-ja ili weba.
     *
     * @param array<string, mixed> $input
     */
    public function saveSettingsForLocale(array $input, int $actorUserId, string $locale): void
    {
        if (!$this->tablesReady()) {
            throw new RuntimeException(__('Migracija naslovnice područja nije primijenjena.'));
        }

        $publicTarget = $this->targetFromInput($input, 'public');
        $authenticatedTarget = $this->targetFromInput($input, 'authenticated');
        if (
            $publicTarget['type'] !== 'default'
            && !$this->groupsContainTarget($this->selectablePageGroups([], $locale), $publicTarget)
        ) {
            throw new RuntimeException(__('Javna naslovnica mora biti objavljena i dostupna gostima.'));
        }

        $genericUser = ['id' => self::GENERIC_AUTHENTICATED_USER_ID, 'is_admin' => false];
        if (
            $authenticatedTarget['type'] !== 'default'
            && !$this->groupsContainTarget(
                $this->selectablePageGroups($genericUser, $locale),
                $authenticatedTarget,
            )
        ) {
            throw new RuntimeException(
                __('Naslovnica za prijavljene mora biti objavljena i dostupna svim prijavljenim korisnicima.'),
            );
        }

        $this->homepages->saveSettings(
            $publicTarget,
            $authenticatedTarget,
            $this->boolValue($input['allow_user_selection'] ?? false),
        );
    }

    /**
     * EN: Prepares personal selection only for the current authenticated user.
     *
     * HR: Priprema osobni odabir samo za trenutačnog prijavljenog korisnika.
     *
     * @return array<string, mixed>|null
     */
    public function accountData(int $userId): ?array
    {
        return $this->accountDataForUser($userId, $this->access->currentUser() ?? []);
    }

    /**
     * EN: Prepares personal selection for an explicitly supplied authenticated
     *     user, such as an API-key owner, without relying on the web session.
     *
     * HR: Priprema osobni odabir za izričito zadanog autentificiranog korisnika,
     *     primjerice vlasnika API ključa, bez oslanjanja na web sesiju.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>|null
     */
    public function accountDataForUser(int $userId, array $user, string $locale = ''): ?array
    {
        if (!$this->tablesReady() || $userId <= 0) {
            return null;
        }

        if ($this->userId($user) !== $userId) {
            return null;
        }

        $settings = $this->homepages->settings();
        if (!$settings['allow_user_selection']) {
            return null;
        }

        $groups = $this->selectablePageGroups($user, $locale);
        $selectedTarget = $this->homepages->userTarget($userId);
        $selectionUnavailable = $selectedTarget['type'] !== 'default'
        && !$this->groupsContainTarget($groups, $selectedTarget);

        return [
            'selectedNodeId' => $selectionUnavailable ? 0 : $selectedTarget['node_id'],
            'selectedTarget' => $selectionUnavailable ? $this->defaultTarget() : $selectedTarget,
            'selectedTargetValue' => $selectionUnavailable
            ? 'default'
            : $this->targetValue($selectedTarget),
            'selectionUnavailable' => $selectionUnavailable,
            'optionGroups' => $groups,
            'viewOptionsReady' => $this->homepages->viewOptionsReady(),
        ];
    }

    /**
     * EN: Stores a personal selection only when the user may currently open it.
     *
     * HR: Sprema osobni odabir samo ako ga korisnik trenutno smije otvoriti.
     *
     * @param array<string, mixed>|int $selection
     */
    public function saveUserSelection(int $userId, array|int $selection): void
    {
        $this->saveUserSelectionForUser(
            $userId,
            $selection,
            $this->access->currentUser() ?? [],
        );
    }

    /**
     * EN: Stores the explicitly supplied user's personal selection after an ACL
     *     check, without taking the identity from the web session.
     *
     * HR: Sprema osobni odabir izričito zadanog korisnika nakon ACL provjere,
     *     bez preuzimanja identiteta iz web sesije.
     *
     * @param array<string, mixed>|int $selection
     * @param array<string, mixed> $user
     */
    public function saveUserSelectionForUser(
        int $userId,
        array|int $selection,
        array $user,
        string $locale = '',
    ): void {
        if (!$this->tablesReady()) {
            throw new RuntimeException(__('Migracija naslovnice područja nije primijenjena.'));
        }

        if ($userId <= 0 || $this->userId($user) !== $userId) {
            throw new RuntimeException(__('Za osobnu naslovnicu potrebna je prijava.'));
        }

        if (!$this->homepages->settings()['allow_user_selection']) {
            throw new RuntimeException(__('Osobni odabir naslovnice nije omogućen.'));
        }

        $target = is_int($selection)
        ? ($selection > 0
            ? [...$this->defaultTarget(), 'type' => 'page', 'node_id' => $selection]
            : $this->defaultTarget())
        : $this->targetFromInput(WorkspaceValue::stringKeyArray($selection), '');
        if (
            $target['type'] !== 'default'
            && !$this->groupsContainTarget($this->selectablePageGroups($user, $locale), $target)
        ) {
            throw new RuntimeException(__('Odabrana stranica nije objavljena ili joj nemate pristup.'));
        }

        $this->homepages->saveUserTarget($userId, $target);
    }

    /**
     * EN: Returns the canonical Workspace path using personal, authenticated, and public precedence.
     *
     * HR: Vraća kanonsku Workspace putanju prema osobnom, prijavljenom i javnom prioritetu.
     */
    public function resolvePath(): ?string
    {
        if (!$this->tablesReady()) {
            return null;
        }

        $settings = $this->homepages->settings();
        $user = $this->access->currentUser();
        $userId = $this->userId($user);
        $candidateTargets = [];
        if ($userId > 0 && $settings['allow_user_selection']) {
            $candidateTargets[] = $this->homepages->userTarget($userId);
        }

        if ($userId > 0) {
            $candidateTargets[] = WorkspaceValue::stringKeyArray(
                $settings['authenticated_target'] ?? null,
            );
        }

        $candidateTargets[] = WorkspaceValue::stringKeyArray($settings['public_target'] ?? null);
        $seen = [];
        foreach ($candidateTargets as $target) {
            $target = $this->normalizedTarget($target);
            $key = $this->targetValue($target)
            . ':' . ($target['show_tree'] ? '1' : '0')
            . ':' . ($target['show_display_options'] ? '1' : '0');
            if ($target['type'] === 'default') {
                continue;
            }

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $path = $this->targetPath($target, $user);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    /**
     * EN: Builds grouped options of published document pages visible to the audience.
     *
     * HR: Gradi grupirane opcije objavljenih dokument-stranica koje publika smije vidjeti.
     *
     * @param array<string, mixed>|null $user
     * @return list<array{name:string,options:list<array<string,mixed>>}>
     */
    private function selectablePageGroups(?array $user, string $locale = ''): array
    {
        if (!$this->tablesReady()) {
            return [];
        }

        $groups = [];
        foreach ($this->repository->activeWorkspaces() as $workspace) {
            if (!$this->access->workspacePermissions($workspace, $user)['can_view']) {
                continue;
            }

            $nodes = $this->repository->nodesForWorkspace(WorkspaceValue::int($workspace['id'] ?? 0));
            $permissions = $this->access->nodePermissionsForNodes($workspace, $nodes, $user);
            $documentNodeIds = [];
            foreach ($nodes as $node) {
                if (WorkspaceValue::string($node['node_type'] ?? '') === 'document') {
                    $documentNodeIds[] = WorkspaceValue::int($node['id'] ?? 0);
                }
            }

            $workflows = $this->repository->nodeWorkflowsForNodesAllLanguages($documentNodeIds);
            $options = [];
            if ($this->homepages->viewOptionsReady()) {
                $options[] = [
                    'id' => 0,
                    'value' => 'shorts:' . WorkspaceValue::int($workspace['id'] ?? 0),
                    'type' => 'shorts',
                    'workspace_id' => WorkspaceValue::int($workspace['id'] ?? 0),
                    'title' => __('Sažetci'),
                ];
            }

            foreach ($nodes as $node) {
                $nodeId = WorkspaceValue::int($node['id'] ?? 0);
                if (WorkspaceValue::string($node['node_type'] ?? '') !== 'document') {
                    continue;
                }

                if (!(bool)($permissions[$nodeId]['can_view'] ?? false)) {
                    continue;
                }

                if ($this->readableLanguage($workflows[$nodeId] ?? [], $locale) === '') {
                    continue;
                }

                $options[] = [
                    'id' => $nodeId,
                    'value' => 'page:' . $nodeId,
                    'type' => 'page',
                    'workspace_id' => WorkspaceValue::int($workspace['id'] ?? 0),
                    'title' => WorkspaceValue::string($node['title'] ?? ''),
                ];
            }

            if ($options !== []) {
                $groups[] = [
                    'name' => WorkspaceValue::string($workspace['name'] ?? ''),
                    'options' => $options,
                ];
            }
        }

        return $groups;
    }

    /**
     * EN: Validates the node, workspace, ACL, and published locale before building an internal URL.
     *
     * HR: Provjerava čvor, područje, ACL i objavljeni jezik prije gradnje internog URL-a.
     *
     * @param array<string, mixed>|null $user
     * @param array{type:string,node_id:int,workspace_id:int,show_tree:bool,show_display_options:bool} $target
     */
    private function targetPath(array $target, ?array $user): ?string
    {
        if ($target['type'] === 'shorts') {
            $workspace = $this->repository->findWorkspaceById($target['workspace_id']);
            if (
                !is_array($workspace)
                || !$this->access->workspacePermissions($workspace, $user)['can_view']
            ) {
                return null;
            }

            $workspaceSlug = WorkspaceValue::string($workspace['slug'] ?? '');
            if ($workspaceSlug === '') {
                return null;
            }

            $language = WorkspaceValue::string($this->languagePreference()[0] ?? '')
            ?: $this->workspaceConfig->siteDefaultLanguage();
            $query = [
                'lang' => $language,
                'tree' => $target['show_tree'] ? '1' : '0',
                'options' => $target['show_display_options'] ? '1' : '0',
            ];
            if ($this->urlGenerator->namedRouteExists('workspace.shorts')) {
                return $this->urlGenerator->getPathFor(
                    'workspace.shorts',
                    ['workspaceSlug' => $workspaceSlug],
                    $query,
                );
            }

            return rtrim($this->urlGenerator->getBasePath(), '/')
            . '/'
            . trim($this->workspaceConfig->rootPath(), '/')
            . '/'
            . rawurlencode($workspaceSlug)
            . '/shorts?'
            . http_build_query($query);
        }

        $nodeId = $target['node_id'];
        $node = $this->repository->findNodeById($nodeId);
        if (!is_array($node) || WorkspaceValue::string($node['node_type'] ?? '') !== 'document') {
            return null;
        }

        $workspace = $this->repository->findWorkspaceById(WorkspaceValue::int($node['workspace_id'] ?? 0));
        if (
            !is_array($workspace)
            || !$this->access->workspacePermissions($workspace, $user)['can_view']
            || !$this->access->nodePermissions($workspace, $node, $user)['can_view']
        ) {
            return null;
        }

        $workflows = $this->repository->nodeWorkflowsForNodesAllLanguages([$nodeId]);
        $language = $this->readableLanguage($workflows[$nodeId] ?? []);
        if ($language === '') {
            return null;
        }

        $workspaceSlug = WorkspaceValue::string($workspace['slug'] ?? '');
        $nodeSlug = WorkspaceValue::string($node['slug'] ?? '');
        if ($workspaceSlug === '' || $nodeSlug === '') {
            return null;
        }

        if ($this->urlGenerator->namedRouteExists('workspace.node.show')) {
            $path = $this->urlGenerator->getPathFor('workspace.node.show', [
                'workspaceSlug' => $workspaceSlug,
                'nodeSlug' => $nodeSlug,
            ]);
        } else {
            $path = rtrim($this->urlGenerator->getBasePath(), '/')
            . '/'
            . trim($this->workspaceConfig->rootPath(), '/')
            . '/'
            . rawurlencode($workspaceSlug)
            . '/'
            . rawurlencode($nodeSlug);
        }

        return $path . '?lang=' . rawurlencode($language);
    }

    /**
     * EN: Selects the current, fallback, or first published page locale.
     *
     * HR: Bira aktualni, fallback ili prvi objavljeni jezik stranice.
     *
     * @param list<array<string, mixed>> $workflows
     */
    private function readableLanguage(array $workflows, string $locale = ''): string
    {
        $readable = [];
        foreach ($workflows as $workflow) {
            if (!$this->workflow->isReadableWorkflow($workflow)) {
                continue;
            }

            $language = $this->normalizeLanguage(WorkspaceValue::string($workflow['language_code'] ?? ''));
            if ($language !== '') {
                $readable[$language] = true;
            }
        }

        foreach ($this->languagePreference($locale) as $language) {
            if (isset($readable[$language])) {
                return $language;
            }
        }

        return WorkspaceValue::string(array_key_first($readable));
    }

    /**
     * EN: Returns the application locale priority without assuming HR and EN locales.
     *
     * HR: Vraća jezični prioritet aplikacije bez pretpostavke da su jezici HR i EN.
     *
     * @return list<string>
     */
    private function languagePreference(string $locale = ''): array
    {
        $languages = [
            $locale !== ''
                ? $this->normalizeLanguage($locale)
                : $this->normalizeLanguage($this->translator->getLocale()),
            $this->workspaceConfig->siteDefaultLanguage(),
            $this->normalizeLanguage(
                $this->config->getAsString('localization.fallback_locale')
                    ?? $this->config->getAsString('app.localization.fallback_locale', 'en')
                    ?? 'en',
            ),
        ];
        $supportedLocales = $this->config->getAsArrayWithValuesAsNonEmptyStrings(
            'localization.supported_locales',
        ) ?? $this->config->getAsArrayWithValuesAsNonEmptyStrings(
            'app.localization.supported_locales',
        ) ?? [];
        foreach ($supportedLocales as $language) {
            $languages[] = $this->normalizeLanguage($language);
        }

        return array_values(array_unique(array_filter($languages)));
    }

    /**
     * EN: Checks whether a structured target exists in ACL-filtered options.
     *
     * HR: Provjerava nalazi li se strukturirani cilj u ACL-filtriranim opcijama.
     *
     * @param list<array{name:string,options:list<array<string,mixed>>}> $groups
     * @param array<string, mixed> $target
     */
    private function groupsContainTarget(array $groups, array $target): bool
    {
        $value = $this->targetValue($this->normalizedTarget($target));
        foreach ($groups as $group) {
            foreach ($group['options'] as $option) {
                if (WorkspaceValue::string($option['value'] ?? '') === $value) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * EN: Reads the current structured form target.
     *
     * HR: Čita aktualni strukturirani cilj iz forme.
     *
     * @param array<string, mixed> $input
     * @return array{type:string,node_id:int,workspace_id:int,show_tree:bool,show_display_options:bool}
     */
    private function targetFromInput(array $input, string $prefix): array
    {
        $fieldPrefix = $prefix !== '' ? $prefix . '_' : '';
        $value = WorkspaceValue::string(
            $input[$fieldPrefix . 'target'] ?? '',
        );
        $target = $this->defaultTarget();
        if (preg_match('/^(page|shorts):([1-9]\d*)$/', $value, $matches) === 1) {
            $target['type'] = $matches[1];
            if ($target['type'] === 'page') {
                $target['node_id'] = (int)$matches[2];
            } else {
                $target['workspace_id'] = (int)$matches[2];
            }
        }

        $target['show_tree'] = $this->boolValue($input[$fieldPrefix . 'show_tree'] ?? false);
        $target['show_display_options'] = $this->boolValue(
            $input[$fieldPrefix . 'show_display_options'] ?? false,
        );

        return $target;
    }

    /**
     * EN: Normalizes a structured target and rejects incomplete ID values.
     *
     * HR: Normalizira strukturirani cilj i odbacuje nepotpune ID vrijednosti.
     *
     * @param array<string, mixed> $target
     * @return array{type:string,node_id:int,workspace_id:int,show_tree:bool,show_display_options:bool}
     */
    private function normalizedTarget(array $target): array
    {
        $type = WorkspaceValue::string($target['type'] ?? 'default');
        if (!in_array($type, ['page', 'shorts'], true)) {
            return $this->defaultTarget();
        }

        $normalized = [
            'type' => $type,
            'node_id' => $type === 'page' ? WorkspaceValue::int($target['node_id'] ?? 0) : 0,
            'workspace_id' => $type === 'shorts' ? WorkspaceValue::int($target['workspace_id'] ?? 0) : 0,
            'show_tree' => (bool)($target['show_tree'] ?? true),
            'show_display_options' => (bool)($target['show_display_options'] ?? true),
        ];

        return ($type === 'page' && $normalized['node_id'] > 0)
        || ($type === 'shorts' && $normalized['workspace_id'] > 0)
        ? $normalized
        : $this->defaultTarget();
    }

    /**
     * EN: Converts a structured target into a stable HTML select value.
     *
     * HR: Pretvara strukturirani cilj u stabilnu vrijednost HTML select kontrole.
     *
     * @param array{type:string,node_id:int,workspace_id:int,show_tree:bool,show_display_options:bool} $target
     */
    private function targetValue(array $target): string
    {
        return match ($target['type']) {
            'page' => 'page:' . $target['node_id'],
            'shorts' => 'shorts:' . $target['workspace_id'],
            default => 'default',
        };
    }

    /**
     * EN: Returns a neutral target with safe default display options.
     *
     * HR: Vraća neutralni cilj sa sigurnim zadanim opcijama prikaza.
     *
     * @return array{type:string,node_id:int,workspace_id:int,show_tree:bool,show_display_options:bool}
     */
    private function defaultTarget(): array
    {
        return [
            'type' => 'default',
            'node_id' => 0,
            'workspace_id' => 0,
            'show_tree' => true,
            'show_display_options' => true,
        ];
    }

    /**
     * EN: Reads the ID from a normalized Auth session payload.
     *
     * HR: Čita ID iz normaliziranog Auth session payloada.
     *
     * @param array<string, mixed>|null $user
     */
    private function userId(?array $user): int
    {
        return is_array($user) && is_numeric($user['id'] ?? null) ? (int)$user['id'] : 0;
    }

    /**
     * EN: Normalizes a checkbox value.
     *
     * HR: Normalizira checkbox vrijednost.
     */
    private function boolValue(mixed $value): bool
    {
        return is_scalar($value)
        && in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * EN: Accepts only short BCP-47 locale tags understood by the Workspace route.
     *
     * HR: Prihvaća samo kratke BCP-47 oznake jezika koje Workspace ruta razumije.
     */
    private function normalizeLanguage(string $language): string
    {
        $language = strtolower(trim($language));

        return preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $language) === 1 ? $language : '';
    }
}
