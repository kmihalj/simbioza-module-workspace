<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Event;

/**
 * EN: Announces irreversible Workspace removal before documents and Workspace
 *     rows are deleted so optional modules can remove their own related data.
 *
 * HR: Najavljuje nepovratno uklanjanje područja prije brisanja dokumenata i
 *     Workspace redaka kako bi opcionalni moduli uklonili vlastite povezane podatke.
 */
final readonly class WorkspacePermanentlyDeleting
{
    /**
     * EN: Stores the stable scope required by owners of additional data.
     *
     * HR: Sprema stabilni opseg potreban vlasnicima dodatnih podataka.
     *
     * @param list<int> $nodeIds
     * @param list<string> $documentKeys
     */
    public function __construct(
        public int $workspaceId,
        public string $workspaceSlug,
        public array $nodeIds,
        public array $documentKeys,
        public int $actorUserId,
    ) {
    }
}
