<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Event;

/**
 * HR: Objavljuje neutralnu obavijest da se promijenio sadržaj područja koji
 *     izvedeni moduli, primjerice pretraga, mogu ponovno sinkronizirati.
 * EN: Publishes a neutral notification that indexable Workspace content has
 *     changed so derived modules, such as search, can synchronize it again.
 */
final readonly class WorkspaceContentChanged
{
    /**
     * HR: Sprema područje, razlog promjene te opcionalni čvor i jezik.
     * EN: Stores the Workspace, change reason, and optional node and language.
     */
    public function __construct(
        public int $workspaceId,
        public string $reason,
        public ?int $nodeId = null,
        public ?string $language = null,
        public ?int $actorUserId = null,
    ) {
    }
}
