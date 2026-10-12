<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Event;

/**
 * EN: Neutral event for a successful Workspace or page view. It is emitted
 *     only after effective ACL verification and does not depend on Audit.
 *
 * HR: Neutralni događaj uspješnog pregleda područja ili njegove stranice.
 *     Emitira se tek nakon provjere efektivnog ACL-a i ne ovisi o Audit modulu.
 */
final readonly class WorkspaceContentViewed
{
    /**
     * EN: Receives stable context for the viewed resource without its content.
     *
     * HR: Prima stabilan kontekst pregledanog sadržaja bez samog sadržaja.
     */
    public function __construct(
        public int $workspaceId,
        public string $workspaceLabel,
        public ?int $nodeId,
        public ?string $nodeLabel,
        public string $language,
    ) {
    }
}
