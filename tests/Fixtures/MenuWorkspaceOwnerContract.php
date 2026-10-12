<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleMenu\Service;

/**
 * EN: Static-analysis contract for optional workspace backup selection supplied by Menu.
 *
 * HR: Ugovor statičke analize za opcionalni odabir backupa područja koji daje Menu.
 */
interface MenuWorkspaceContextOwnerResolverInterface extends MenuContextOwnerResolverInterface
{
    /**
     * EN: Returns declared membership, or null for shared contexts.
     *
     * HR: Vraća deklariranu pripadnost ili null za zajedničke kontekste.
     *
     * @param array<string,mixed> $context
     */
    public function belongsToWorkspace(array $context, string $slug): ?bool;
}
