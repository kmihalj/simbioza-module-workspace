<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use RuntimeException;

use function date;
use function in_array;
use function is_array;
use function is_numeric;
use function is_scalar;
use function strtolower;
use function trim;

/**
 * HR: Upravlja procesom objave po stranici i jeziku, od nacrta do arhive.
 *     HTML i njegove nepromjenjive verzije ostaju vlasništvo Editor modula.
 * EN: Manages the publishing workflow per page and locale, from draft through
 *     archive. HTML and its immutable versions remain owned by the Editor module.
 */
final readonly class WorkspaceWorkflowService
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    private const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_IN_REVIEW,
        self::STATUS_PUBLISHED,
        self::STATUS_ARCHIVED,
    ];

    /**
     * HR: Prima repozitorij koji jedini izravno pristupa workflow tablici.
     * EN: Receives the repository that alone accesses the workflow table directly.
     */
    public function __construct(private WorkspaceRepository $repository)
    {
    }

    /**
     * HR: Označava novu Editor verziju nacrtom, ali čuva broj zadnje objavljene
     *     verzije kako bi je čitatelji nastavili vidjeti do sljedeće objave.
     * EN: Marks a new Editor version as draft while retaining the last published
     *     version number so readers continue seeing it until the next publish.
     *
     * @return array<string, mixed>|null
     */
    public function markDocumentDraft(
        string $documentKey,
        string $language,
        int $versionNumber,
        int $actorUserId,
    ): ?array {
        $node = $this->repository->findNodeByDocumentKey($documentKey);
        if (!is_array($node) || $versionNumber <= 0) {
            return null;
        }

        $nodeId = WorkspaceValue::int($node['id'] ?? 0);
        $existing = $this->repository->nodeWorkflow($nodeId, $language);
        $status = $this->status($existing['status'] ?? self::STATUS_DRAFT);
        $values = [
            'status' => $status === self::STATUS_ARCHIVED
                ? self::STATUS_ARCHIVED
                : self::STATUS_DRAFT,
            'current_version_number' => $versionNumber,
            'submitted_by_user_id' => null,
            'submitted_at' => null,
        ];

        return $this->repository->saveNodeWorkflow(
            $nodeId,
            $language,
            $values,
            $actorUserId,
        );
    }

    /**
     * HR: Vraća broj verzije koju smije vidjeti čitatelj. Null znači da dokument
     *     nije Workspace stranica, a nula da objavljeni sadržaj ne postoji.
     * EN: Returns the version number a reader may see. Null means the document is
     *     not a Workspace page, while zero means no published content exists.
     */
    public function publicationVersion(string $documentKey, string $language): ?int
    {
        $node = $this->repository->findNodeByDocumentKey($documentKey);
        if (!is_array($node)) {
            return null;
        }

        return $this->publicationVersionForNode(
            WorkspaceValue::int($node['id'] ?? 0),
            $language,
        );
    }

    /**
     * HR: Vraća objavljenu verziju već poznatog čvora bez ponovnog traženja
     *     dokumenta. Nula znači da objavljeni sadržaj nije dostupan.
     * EN: Returns the published version for an already known node without
     *     looking up the document again. Zero means no published content is available.
     */
    public function publicationVersionForNode(int $nodeId, string $language): int
    {
        $workflow = $this->repository->nodeWorkflow(
            $nodeId,
            $language,
        );
        if (!is_array($workflow)) {
            return 0;
        }

        if ($this->status($workflow['status'] ?? '') === self::STATUS_ARCHIVED) {
            return 0;
        }

        return $this->positiveInt($workflow['published_version_number'] ?? null);
    }

    /**
     * HR: Provjerava ima li jezična inačica objavljeni sadržaj za čitatelja bez
     *     prava uređivanja. Nepraćena stranica tretira se kao nacrt.
     * EN: Checks whether a locale has published content for a reader without edit
     *     rights. An untracked page is treated as a draft.
     */
    public function isReadable(int $nodeId, string $language): bool
    {
        return $this->isReadableWorkflow($this->repository->nodeWorkflow($nodeId, $language));
    }

    /**
     * HR: Provjerava čitljivost već učitanog workflow retka bez dodatnog upita.
     * EN: Checks a preloaded workflow row for readability without another query.
     *
     * @param array<string, mixed>|null $workflow
     */
    public function isReadableWorkflow(?array $workflow): bool
    {
        return is_array($workflow)
        && $this->status($workflow['status'] ?? '') !== self::STATUS_ARCHIVED
        && $this->positiveInt($workflow['published_version_number'] ?? null) > 0;
    }

    /**
     * HR: Izvršava dopušteni workflow prijelaz nakon provjere edit/publish/manage prava
     *     i dostupnosti aktualne nepromjenjive Editor verzije.
     * EN: Executes an allowed workflow transition after checking edit/publish/manage
     *     permission and the availability of the current immutable Editor version.
     *
     * @return array<string, mixed>
     */
    public function transition(
        int $nodeId,
        string $language,
        string $action,
        int $latestVersionNumber,
        int $actorUserId,
        bool $canEdit,
        bool $canPublish,
        bool $canManage,
    ): array {
        if ($nodeId <= 0 || $latestVersionNumber <= 0) {
            throw new RuntimeException(__('Dokument nema verziju koju je moguće objaviti.'));
        }

        $action = strtolower(trim($action));
        $existing = $this->repository->nodeWorkflow($nodeId, $language);
        $status = $this->status($existing['status'] ?? self::STATUS_DRAFT);
        $now = date('Y-m-d H:i:s');
        $values = ['current_version_number' => $latestVersionNumber];

        if ($action === 'submit' && $canEdit && $status === self::STATUS_DRAFT) {
            $values = [
                ...$values,
                'status' => self::STATUS_IN_REVIEW,
                'submitted_by_user_id' => $actorUserId,
                'submitted_at' => $now,
            ];
        } elseif (
            $action === 'withdraw'
            && $canEdit
            && $status === self::STATUS_IN_REVIEW
        ) {
            $values = [
                ...$values,
                'status' => self::STATUS_DRAFT,
                'submitted_by_user_id' => null,
                'submitted_at' => null,
            ];
        } elseif (
            $action === 'publish'
            && $canPublish
            && in_array($status, [self::STATUS_DRAFT, self::STATUS_IN_REVIEW], true)
        ) {
            $values = [
                ...$values,
                'status' => self::STATUS_PUBLISHED,
                'published_version_number' => $latestVersionNumber,
                'published_by_user_id' => $actorUserId,
                'published_at' => $now,
                'submitted_by_user_id' => null,
                'submitted_at' => null,
                'archived_by_user_id' => null,
                'archived_at' => null,
            ];
        } elseif (
            $action === 'archive'
            && $canManage
            && $status === self::STATUS_PUBLISHED
        ) {
            $values = [
                ...$values,
                'status' => self::STATUS_ARCHIVED,
                'archived_by_user_id' => $actorUserId,
                'archived_at' => $now,
            ];
        } elseif (
            $action === 'restore_draft'
            && $canManage
            && $status === self::STATUS_ARCHIVED
        ) {
            $values = [
                ...$values,
                'status' => self::STATUS_DRAFT,
                'published_version_number' => null,
                'published_by_user_id' => null,
                'published_at' => null,
                'archived_by_user_id' => null,
                'archived_at' => null,
            ];
        } else {
            throw new RuntimeException(__('Odabrani prijelaz statusa nije dopušten.'));
        }

        return $this->repository->saveNodeWorkflow(
            $nodeId,
            $language,
            $values,
            $actorUserId,
        );
    }

    /**
     * HR: Nakon odbacivanja zajedničkog Editor nacrta vraća workflow na zadnju
     *     objavu ili na prazan neobjavljeni status nove stranice.
     * EN: After discarding the shared Editor draft, returns workflow to the last
     *     publication or to the empty unpublished state of a new page.
     *
     * @return array<string, mixed>
     */
    public function discardDraft(
        int $nodeId,
        string $language,
        int $currentVersionNumber,
        int $actorUserId,
    ): array {
        $existing = $this->repository->nodeWorkflow($nodeId, $language);
        $publishedVersion = is_array($existing)
        ? $this->positiveInt($existing['published_version_number'] ?? null)
        : 0;

        return $this->repository->saveNodeWorkflow(
            $nodeId,
            $language,
            [
                'status' => $publishedVersion > 0
                    ? self::STATUS_PUBLISHED
                    : self::STATUS_DRAFT,
                'current_version_number' => $publishedVersion > 0
                    ? $publishedVersion
                    : ($currentVersionNumber > 0 ? $currentVersionNumber : null),
                'submitted_by_user_id' => null,
                'submitted_at' => null,
            ],
            $actorUserId,
        );
    }

    /**
     * HR: Gradi mali UI model statusa, zadnje objavljene verzije i trenutno
     *     dopuštenih akcija za otvorenu stranicu.
     * EN: Builds a compact UI model containing status, last published version,
     *     and actions currently allowed for the open page.
     *
     * @return array<string, mixed>
     */
    public function viewModel(
        int $nodeId,
        string $language,
        int $latestVersionNumber,
        bool $canEdit,
        bool $canPublish,
        bool $canManage,
    ): array {
        $workflow = $this->repository->nodeWorkflow($nodeId, $language);
        $status = is_array($workflow)
        ? $this->status($workflow['status'] ?? self::STATUS_DRAFT)
        : self::STATUS_DRAFT;
        $publishedVersion = is_array($workflow)
        ? $this->positiveInt($workflow['published_version_number'] ?? null)
        : 0;
        $actions = [];

        if ($canEdit && $status === self::STATUS_DRAFT) {
            $actions[] = ['action' => 'submit', 'label' => __('Pošalji na pregled'), 'style' => 'primary'];
        }

        if ($canEdit && $status === self::STATUS_IN_REVIEW) {
            $actions[] = ['action' => 'withdraw', 'label' => __('Vrati u nacrt'), 'style' => 'secondary'];
        }

        if (
            $canPublish
            && in_array($status, [self::STATUS_DRAFT, self::STATUS_IN_REVIEW], true)
        ) {
            $actions[] = ['action' => 'publish', 'label' => __('Objavi'), 'style' => 'success'];
        }

        if ($canManage && $status === self::STATUS_PUBLISHED) {
            $actions[] = ['action' => 'archive', 'label' => __('Arhiviraj'), 'style' => 'secondary'];
        }

        if ($canManage && $status === self::STATUS_ARCHIVED) {
            $actions[] = ['action' => 'restore_draft', 'label' => __('Vrati u nacrt'), 'style' => 'primary'];
        }

        return [
            'status' => $status,
            'label' => $this->statusLabel($status),
            'badge' => $this->statusBadge($status),
            'latest_version_number' => $latestVersionNumber,
            'published_version_number' => $publishedVersion,
            'has_unpublished_changes' => $latestVersionNumber > 0
                && $latestVersionNumber !== $publishedVersion,
            'is_new_unpublished_page' => $latestVersionNumber > 0
                && $publishedVersion === 0,
            'actions' => $actions,
        ];
    }

    /**
     * HR: Normalizira status iz baze na zatvoren podržani skup.
     * EN: Normalizes a database status to the closed supported set.
     */
    private function status(mixed $status): string
    {
        $status = is_scalar($status) ? strtolower(trim((string)$status)) : '';

        return in_array($status, self::STATUSES, true) ? $status : self::STATUS_DRAFT;
    }

    /**
     * HR: Vraća lokalizirani prikazni naziv workflow statusa.
     * EN: Returns the localized display name of a workflow status.
     */
    private function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_IN_REVIEW => __('Na pregledu'),
            self::STATUS_PUBLISHED => __('Objavljeno'),
            self::STATUS_ARCHIVED => __('Arhivirano'),
            default => __('Nacrt'),
        };
    }

    /**
     * HR: Odabire semantičku Bootstrap boju statusne značke.
     * EN: Selects the semantic Bootstrap color for the status badge.
     */
    private function statusBadge(string $status): string
    {
        return match ($status) {
            self::STATUS_IN_REVIEW => 'warning',
            self::STATUS_PUBLISHED => 'success',
            self::STATUS_ARCHIVED => 'secondary',
            default => 'info',
        };
    }

    /**
     * HR: Pretvara proizvoljnu DB vrijednost u pozitivan broj verzije ili nulu.
     * EN: Converts an arbitrary database value to a positive version number or zero.
     */
    private function positiveInt(mixed $value): int
    {
        $value = is_numeric($value) ? (int)$value : 0;

        return max($value, 0);
    }
}
