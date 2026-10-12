<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Backup;

use AaiEduHr\HeartPhrameModuleBackup\Exception\BackupException;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupImportContext;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;

/**
 * EN: Keeps configuration references aligned when a site restore changes IDs.
 *
 * HR: Konfiguracijske reference prati pri promjeni ID-ova u povratu cijelog sitea.
 */
final class WorkspaceConfigurationIdentityTransform
{
    /**
     * EN: Resolves identities after DB imports without selecting local paths.
     * The imported argument contains only fields from the archive.
     *
     * HR: Razrješava identitete nakon DB importa, nikad ne bira lokalne putanje.
     * Argument imported sadrži samo polja iz arhiva.
     *
     * @param array<string,mixed> $values
     * @param array<string,mixed>|null $imported
     * @return array<string,mixed>
     */
    public function transform(array $values, BackupImportContext $context, ?array $imported = null): array
    {
        $imported ??= $values;
        $creation = WorkspaceValue::stringKeyArray($values['creation'] ?? []);
        $importedCreation = WorkspaceValue::stringKeyArray($imported['creation'] ?? []);
        foreach (['users' => 'auth.user', 'groups' => 'auth.group'] as $key => $namespace) {
            if (!array_key_exists($key, $importedCreation)) {
                continue;
            }

            $ids = is_array($creation[$key] ?? null) ? $creation[$key] : [];
            $mapped = [];
            foreach ($ids as $id) {
                if (!is_int($id) || $id <= 0) {
                    throw new BackupException('Invalid Workspace creator reference.');
                }

                $mapped[] = $this->mappedId($namespace, $id, 'auth', $context);
            }

            $creation[$key] = array_values(array_unique($mapped));
        }

        if (array_key_exists('creation', $values)) {
            $values['creation'] = $creation;
        }

        $homepage = WorkspaceValue::stringKeyArray($values['homepage'] ?? []);
        $importedHomepage = WorkspaceValue::stringKeyArray($imported['homepage'] ?? []);
        foreach (['public_target', 'authenticated_target'] as $key) {
            if (!array_key_exists($key, $homepage)) {
                continue;
            }

            $target = WorkspaceValue::stringKeyArray($homepage[$key] ?? []);
            $importedTarget = WorkspaceValue::stringKeyArray($importedHomepage[$key] ?? []);
            if (($target['type'] ?? 'default') === 'page' && array_key_exists('node_id', $importedTarget)) {
                $target['node_id'] = $this->mappedId(
                    'workspace.node',
                    WorkspaceValue::int($target['node_id'] ?? 0),
                    'workspace',
                    $context,
                );
            } elseif (
                ($target['type'] ?? 'default') === 'shorts' && array_key_exists('workspace_id', $importedTarget)
            ) {
                $target['workspace_id'] = $this->mappedId(
                    'workspace.workspace',
                    WorkspaceValue::int($target['workspace_id'] ?? 0),
                    'workspace',
                    $context,
                );
            }

            $homepage[$key] = $target;
        }

        if (array_key_exists('homepage', $values)) {
            $values['homepage'] = $homepage;
        }

        return $values;
    }

    /**
     * EN: An active DB provider must supply a mapping; settings-only restore retains local IDs.
     *
     * HR: Aktivni DB provider mora dati mapiranje; pri zasebnom povratu postavki ID ostaje lokalni.
     */
    private function mappedId(string $namespace, int $id, string $provider, BackupImportContext $context): int
    {
        if ($id <= 0) {
            throw new BackupException('Invalid Workspace configuration identity.');
        }

        $mapped = $context->state->getMapped($namespace, $id);
        $providerIncluded = in_array($provider, $context->selectedProviders, true)
        || ($context->selectedProviders === [] && !in_array($provider, $context->explicitlySkippedProviders, true));
        if ($mapped === null && $providerIncluded) {
            throw new BackupException('Missing Workspace configuration identity mapping: ' . $namespace);
        }

        $value = $mapped === null ? $id : WorkspaceValue::int($mapped);
        if ($value <= 0) {
            throw new BackupException('Invalid mapped Workspace configuration identity.');
        }

        return $value;
    }
}
