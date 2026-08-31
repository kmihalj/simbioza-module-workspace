<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

/**
 * HR: Čuva nepromjenjive rezultate Workspace repozitorija samo tijekom jednog
 *     HTTP/CLI zahtjeva. Time ponovljeni prikazi stabla ne ponavljaju isti SQL,
 *     dok svaki novi zahtjev uvijek počinje sa svježim podacima.
 * EN: Stores immutable Workspace repository results only for one HTTP/CLI
 *     request. Repeated tree rendering therefore avoids identical SQL while
 *     every new request always starts with fresh data.
 */
final class WorkspaceRepositoryRequestCache
{
    /** @var array<int, list<array<string, mixed>>> */
    public array $nodesByWorkspace = [];

    /** @var array<int, array<string, mixed>> */
    public array $nodesById = [];

    /** @var array<int, array<int, list<array<string, mixed>>>> */
    public array $ancestorNodesByWorkspaceAndNode = [];
}
