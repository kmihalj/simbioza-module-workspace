<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Contract;

/**
 * HR: Razrješava prijenosnu oznaku područja iz vanjskog sustava tek kada je
 *     odgovarajuće lokalno područje dostupno.
 * EN: Resolves a portable external Workspace reference only after its local
 *     Workspace becomes available.
 */
interface WorkspaceExternalReferenceProviderInterface
{
    /** HR: Stabilni naziv izvornog sustava. EN: Stable source-system name. */
    public function provider(): string;

    /**
     * HR: Vraća lokalni slug i naziv ili null dok cilj još nije dostupan.
     * EN: Returns the local slug and title, or null while the target is unavailable.
     *
     * @return array{slug:string,title:string}|null
     */
    public function resolve(string $reference): ?array;
}
