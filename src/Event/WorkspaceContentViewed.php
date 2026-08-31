<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Event;

/**
 * HR: Neutralni događaj uspješnog pregleda područja ili njegove stranice.
 *     Emitira se tek nakon provjere efektivnog ACL-a i ne ovisi o Audit modulu.
 * EN: Neutral event for a successful Workspace or page view. It is emitted
 *     only after effective ACL verification and does not depend on Audit.
 */
final readonly class WorkspaceContentViewed
{
    /**
     * HR: Prima stabilan kontekst pregledanog sadržaja bez samog sadržaja.
     * EN: Receives stable context for the viewed resource without its content.
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
