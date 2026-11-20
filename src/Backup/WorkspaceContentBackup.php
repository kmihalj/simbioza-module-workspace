<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Backup;

use AaiEduHr\HeartPhrameModuleBackup\Exception\BackupException;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupArchiveReader;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupArchiveWriter;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupImportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope;
use AaiEduHr\SimbiozaModuleWorkspace\Contract\WorkspaceContentBackupInterface;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceContentRegistry;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceEditorBridge;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;

/**
 * EN: Keeps extension ownership explicit without importing any business module into Workspace.
 *
 * HR: Čuva izričito vlasništvo proširenja bez uvoza poslovnog modula u Područja.
 */
final readonly class WorkspaceContentBackup
{
    /**
     * EN: Uses registered content providers for extension-owned nodes.
     *
     * HR: Za čvorove kojima upravljaju proširenja koristi registrirane pružatelje sadržaja.
     */
    public function __construct(private ?WorkspaceContentRegistry $types = null)
    {
    }

    /**
     * EN: Requires the Markdown provider when a document belongs to the Markdown editor.
     *
     * HR: Zahtijeva pružatelja Markdown sadržaja kad dokument pripada Markdown editoru.
     *
     * @param array<string, mixed> $node
     */
    public function requireDocument(array $node, string $scope, BackupArchiveWriter $writer): void
    {
        $provider = $this->documentProvider(WorkspaceValue::string($node['document_key'] ?? ''), $scope);
        if ($provider !== null) {
            $writer->requireProvider($provider);
        }
    }

    /**
     * EN: Resolves the Markdown backup provider for the requested scope.
     *
     * HR: Za traženi opseg određuje pružatelja sigurnosne kopije Markdown sadržaja.
     */
    private function documentProvider(string $key, string $scope): ?string
    {
        if (!WorkspaceEditorBridge::isMarkdown($key)) {
            return null;
        }

        if (in_array($scope, [BackupScope::PAGE, BackupScope::WORKSPACE], true)) {
            return 'editor-md-workspace';
        }

        return 'editor-md';
    }

    /**
     * EN: Resolves the owner or refuses silent loss.
     *
     * HR: Razrješava vlasnika ili odbija tihi gubitak.
     */
    public function owner(string $type, string $scope): ?string
    {
        if (in_array($type, ['document', 'separator', 'internal_link', 'external_link'], true)) {
            return null;
        }

        $provider = $this->types?->get($type);
        $owner = $provider instanceof WorkspaceContentBackupInterface ? $provider->backupProviderId($scope) : null;
        if ($owner === null) {
            throw new BackupException('A complete backup provider is required for content type: ' . $type);
        }

        return $owner;
    }

    /**
     * EN: Rejects absent, skipped or unselected owners before restoring scaffolding.
     *
     * HR: Odbija odsutne, preskočene ili neodabrane vlasnike prije vraćanja čvorova.
     */
    public function checkImport(
        string $type,
        BackupImportContext $context,
        BackupArchiveReader $reader,
        string $documentKey = '',
    ): void {
        $scope = $context->scope->type;
        $owner = $this->documentProvider($documentKey, $scope) ?? $this->owner($type, $scope);
        if ($owner === null) {
            return;
        }

        $providers = [];
        $entries = $reader->manifest()['providers'] ?? [];
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null)) {
                $providers[$entry['id']] = $entry;
            }
        }

        if (
            !isset($providers[$owner]) || in_array($owner, $context->explicitlySkippedProviders, true)
            || ($context->selectedProviders !== [] && !in_array($owner, $context->selectedProviders, true)
                && !(bool)($providers[$owner]['automatic'] ?? false))
        ) {
            throw new BackupException('Complete content restore requires provider: ' . $owner);
        }
    }
}
