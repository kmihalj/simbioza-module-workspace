<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Contract;

/**
 * HR: Omogućuje izvedenom modulu da promijeni isključivo prikazne vrijednosti
 *     područja za traženi jezik, bez promjene spremljenog Workspace zapisa.
 * EN: Allows a derived module to change only a Workspace's presentation values
 *     for the requested locale without mutating the stored Workspace record.
 */
interface WorkspacePresentationProviderInterface
{
    /**
     * HR: Vraća prikazne vrijednosti za već učitana područja.
     * EN: Returns presentation values for already loaded Workspaces.
     *
     * @param list<array<string,mixed>> $workspaces
     * @return list<array<string,mixed>>
     */
    public function present(array $workspaces, string $locale): array;
}
