<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleWorkspace\Service;

use AaiEduHr\HeartPhrameModuleWorkspace\Event\WorkspaceContentChanged;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * HR: Odgađa obavijesti izvedenim indeksima tijekom skupnih promjena i šalje
 *     samo jednu objedinjenu obavijest za svako promijenjeno područje.
 * EN: Defers derived-index notifications during bulk mutations and emits only
 *     one consolidated notification for each changed Workspace.
 */
final class WorkspaceContentChangeBatch
{
    private int $depth = 0;

    /** @var array<int, array{workspace_id:int,actor_user_id:?int}> */
    private array $pending = [];

    /** HR: Prima zajednički dispatcher i tehnički logger. EN: Receives the shared dispatcher and technical logger. */
    public function __construct(
        private readonly EventDispatcherInterface $events,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * HR: Izvodi skupnu operaciju i u finally bloku osvježava izvedene podatke,
     *     čak i kada je izvorni upis djelomično uspio prije iznimke.
     * EN: Runs a bulk operation and refreshes derived data in a finally block,
     *     even when source writes partially succeeded before an exception.
     *
     * @template TResult
     * @param callable():TResult $operation
     * @return TResult
     */
    public function run(callable $operation): mixed
    {
        ++$this->depth;

        try {
            return $operation();
        } finally {
            --$this->depth;
            if ($this->depth === 0) {
                $this->flush();
            }
        }
    }

    /**
     * HR: Izvodi jedan nastavivi dio velike operacije bez osvježavanja
     *     izvedenih indeksa. U slučaju pogreške ipak ih osvježava kako
     *     djelomično spremljen sadržaj ne bi ostao neusklađen.
     * EN: Runs one resumable part of a large operation without refreshing
     *     derived indexes. On failure it still refreshes them so partially
     *     persisted content does not remain inconsistent.
     *
     * Pozivatelj mora nakon svih uspješnih dijelova pokrenuti run() s barem
     * jednom promjenom sadržaja. / After all successful parts, the caller must
     * run run() with at least one content change.
     *
     * @template TResult
     * @param callable():TResult $operation
     * @return TResult
     */
    public function runDeferred(callable $operation): mixed
    {
        ++$this->depth;
        $failed = false;

        try {
            return $operation();
        } catch (Throwable $throwable) {
            $failed = true;
            throw $throwable;
        } finally {
            --$this->depth;
            if ($this->depth === 0) {
                if ($failed) {
                    $this->flush();
                } else {
                    $this->pending = [];
                }
            }
        }
    }

    /**
     * HR: Odmah šalje običnu promjenu ili je pamti dok traje skupna operacija.
     * EN: Dispatches an ordinary change immediately or remembers it during a bulk operation.
     */
    public function publish(WorkspaceContentChanged $event): void
    {
        if ($this->depth <= 0) {
            $this->dispatch($event);

            return;
        }

        $pending = $this->pending[$event->workspaceId] ?? [
            'workspace_id' => $event->workspaceId,
            'actor_user_id' => null,
        ];
        if ($event->actorUserId !== null) {
            $pending['actor_user_id'] = $event->actorUserId;
        }

        $this->pending[$event->workspaceId] = $pending;
    }

    /** HR: Šalje po jednu završnu obavijest za sva promijenjena područja. EN: Emits one final notification for every changed Workspace. */
    private function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $change) {
            $this->dispatch(new WorkspaceContentChanged(
                $change['workspace_id'],
                'bulk_content_changed',
                null,
                null,
                $change['actor_user_id'],
            ));
        }
    }

    /** HR: Kvar opcionalnog izvedenog indeksa ne poništava izvorni sadržaj. EN: An optional derived-index failure does not undo source content. */
    private function dispatch(WorkspaceContentChanged $event): void
    {
        try {
            $this->events->dispatch($event);
        } catch (Throwable $throwable) {
            $this->logger?->error('Workspace content-change listeners failed for workspace {workspace_id}.', [
                'module' => 'workspace',
                'workspace_id' => $event->workspaceId,
                'node_id' => $event->nodeId,
                'reason' => $event->reason,
                'exception' => $throwable,
            ]);
        }
    }
}
