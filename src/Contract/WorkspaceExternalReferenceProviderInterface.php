<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Contract;

/**
 * EN: Resolves a portable external Workspace reference only after its local
 *     Workspace becomes available.
 *
 * HR: Razrješava prijenosnu oznaku područja iz vanjskog sustava tek kada je
 *     odgovarajuće lokalno područje dostupno.
 */
interface WorkspaceExternalReferenceProviderInterface
{
    /**
     * EN: Stable source-system name.
     *
     * HR: Stabilni naziv izvornog sustava.
     */
    public function provider(): string;

    /**
     * EN: Returns the local slug and title, or null while the target is unavailable.
     *
     * HR: Vraća lokalni slug i naziv ili null dok cilj još nije dostupan.
     *
     * @return array{slug:string,title:string}|null
     */
    public function resolve(string $reference): ?array;
}
