<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleMenu\Service;

/**
 * EN: Static-analysis contract for the optional Menu integration, not a runtime implementation.
 * The actual Menu module supplies this interface whenever the integration is enabled.
 *
 * HR: Ugovor statičke analize za opcionalnu Menu integraciju, ne runtime implementacija.
 * Stvarni Menu modul daje ovo sučelje kada je integracija uključena.
 */
interface MenuContextOwnerResolverInterface
{
    /**
     * EN: Returns a validated owner directory relative to application data, or shared ownership.
     *
     * HR: Vraća provjeren vlasnički direktorij relativan podatcima aplikacije ili zajedničko vlasništvo.
     *
     * @param array<string,mixed> $context
     */
    public function directory(array $context): ?string;
}
