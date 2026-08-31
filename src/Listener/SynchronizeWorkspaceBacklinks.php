<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Listener;

use AaiEduHr\SimbiozaModuleWorkspace\Event\WorkspaceContentChanged;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceBacklinkIndexer;
use Psr\Log\LoggerInterface;
use Throwable;

use function in_array;
use function is_string;

/**
 * HR: Sinkronizira izvedene backlinkove nakon promjene Workspace sadržaja.
 * EN: Synchronizes derived backlinks after Workspace content changes.
 */
final readonly class SynchronizeWorkspaceBacklinks
{
    /** HR: Prima indeks i tehnički logger. EN: Receives the index and technical logger. */
    public function __construct(
        private WorkspaceBacklinkIndexer $indexer,
        private LoggerInterface $logger,
    ) {
    }

    /** HR: Cilja objavu jednog jezika, a strukturne promjene potpuno obnavlja. EN: Targets one locale publication and fully rebuilds structural changes. */
    public function __invoke(WorkspaceContentChanged $event): void
    {
        if (in_array($event->reason, ['workspace_created', 'node_created', 'unpublished_node_deleted'], true)) {
            return;
        }

        try {
            if (
                $event->reason === 'publication_changed'
                && $event->nodeId !== null
                && is_string($event->language)
                && $event->language !== ''
            ) {
                $this->indexer->synchronizeNode($event->workspaceId, $event->nodeId, $event->language);

                return;
            }

            $this->indexer->synchronizeWorkspace($event->workspaceId, $event->language);
        } catch (Throwable $throwable) {
            $this->logger->error('Workspace backlink synchronization failed.', [
                'module' => 'workspace',
                'workspace_id' => $event->workspaceId,
                'page_id' => $event->nodeId,
                'language' => $event->language,
                'reason' => $event->reason,
                'exception' => $throwable,
            ]);
            throw $throwable;
        }
    }
}
