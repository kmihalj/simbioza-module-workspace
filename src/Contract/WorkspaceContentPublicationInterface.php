<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Contract;

/**
 * EN: Allows optional content to distinguish a reader's publication from an author's draft.
 *
 * HR: Omogućuje dodatnom sadržaju razlikovanje objave za čitatelja od autorskog nacrta.
 */
interface WorkspaceContentPublicationInterface
{
    /**
     * EN: Applies publication rules to the explicit actor after host permissions.
     *
     * HR: Primjenjuje pravila objave za izričitog korisnika nakon ovlasti domaćina.
     * @param array<string,mixed> $node
     * @param array<string,bool> $permissions
     * @param array<string,mixed>|null $user
     * @param list<string> $languages
     */
    public function visibleTo(array $node, array $permissions, ?array $user, array $languages): bool;

    /**
     * EN: Exposes a tree badge only to actors allowed to see the working revision.
     *
     * HR: Izlaže oznaku u stablu samo korisnicima koji smiju vidjeti radnu reviziju.
     * @param array<string,mixed> $node
     * @param array<string,bool> $permissions
     * @param array<string,mixed>|null $user
     * @return array{status:string,is_new_unpublished:bool}|null
     */
    public function publicationState(array $node, array $permissions, ?array $user): ?array;
}
