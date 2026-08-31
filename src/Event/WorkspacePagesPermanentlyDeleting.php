<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Event;

/**
 * HR: Najavljuje nepovratno uklanjanje jedne ili više stranica prije brisanja
 *     njihovih Workspace redaka kako bi dodatni moduli očistili vlastite veze.
 * EN: Announces irreversible removal of one or more pages before their
 *     Workspace rows are deleted so optional modules can clean their own links.
 */
final readonly class WorkspacePagesPermanentlyDeleting
{
    /**
     * HR: Sprema stabilne identifikatore stranica i korisnika koji je pokrenuo brisanje.
     * EN: Stores stable page identifiers and the user who initiated the deletion.
     *
     * @param list<array{workspace_id: int, node_id: int, document_key: string}> $pages
     */
    public function __construct(
        public array $pages,
        public int $actorUserId,
    ) {
    }
}
