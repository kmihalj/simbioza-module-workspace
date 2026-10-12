<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use AaiEduHr\HeartPhrameModuleBackup\Exception\BackupException;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupImportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope;
use AaiEduHr\SimbiozaModuleWorkspace\Backup\WorkspaceConfigurationIdentityTransform;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkspaceConfigurationIdentityTransform::class)]
#[UsesClass(WorkspaceValue::class)]
final class WorkspaceConfigurationIdentityTransformTest extends TestCase
{
    /**
     * EN: Global references use new database identities while unknown fields remain unchanged.
     *
     * HR: Globalne reference koriste nove DB identitete, a nepoznata polja ostaju ista.
     */
    public function testAllReferencesUseImportedIdentities(): void
    {
        $context = new BackupImportContext(new BackupScope(BackupScope::SITE), BackupImportContext::CONFLICT_COPY, []);
        foreach (
            ['auth.user' => [7, 70], 'auth.group' => [8, 80],
            'workspace.node' => [17, 170], 'workspace.workspace' => [19, 190]] as $namespace => [$source, $target]
        ) {
            $context->state->map($namespace, $source, $target);
        }

        $values = [
            'creation' => ['users' => [7], 'groups' => [8]],
            'homepage' => ['public_target' => ['type' => 'page', 'node_id' => 17, 'show_tree' => false],
                'authenticated_target' => ['type' => 'shorts', 'workspace_id' => 19], 'allow_user_selection' => false],
            'extension' => ['retained' => true],
        ];
        $mapped = (new WorkspaceConfigurationIdentityTransform())->transform($values, $context);
        $this->assertSame([70], $mapped['creation']['users']);
        $this->assertSame([80], $mapped['creation']['groups']);
        $this->assertSame(170, $mapped['homepage']['public_target']['node_id']);
        $this->assertSame(190, $mapped['homepage']['authenticated_target']['workspace_id']);
        $this->assertFalse($mapped['homepage']['public_target']['show_tree']);
        $this->assertFalse($mapped['homepage']['allow_user_selection']);
        $this->assertSame($values['extension'], $mapped['extension']);
    }

    /**
     * EN: A full restore never silently reuses an identity that was not imported.
     *
     * HR: Potpuni restore nikad tiho ne koristi ID koji nije importiran.
     */
    public function testFullRestoreWithoutMappingFailsClosed(): void
    {
        $context = new BackupImportContext(new BackupScope(BackupScope::SITE), BackupImportContext::CONFLICT_COPY, []);
        $this->expectException(BackupException::class);
        (new WorkspaceConfigurationIdentityTransform())->transform(['creation' => ['users' => [7]]], $context);
    }

    /**
     * EN: Fields retained on the target are not source IDs, even with active database providers.
     *
     * HR: Polja zadržana na cilju nisu izvorni ID-ovi, čak ni uz aktivne DB providere.
     */
    public function testPartialImportPreservesTargetLocalReferences(): void
    {
        $context = new BackupImportContext(new BackupScope(BackupScope::SITE), BackupImportContext::CONFLICT_MERGE, []);
        $context->state->map('workspace.node', 17, 170);
        $context->state->map('auth.user', 7, 70);

        $values = ['creation' => ['users' => [7]],
            'homepage' => ['public_target' => ['type' => 'page', 'node_id' => 17]],
            'shorts' => ['limit' => 25]];
        $this->assertSame($values, (new WorkspaceConfigurationIdentityTransform())->transform(
            $values,
            $context,
            ['shorts' => ['limit' => 25]],
        ));
        $this->assertSame($values, (new WorkspaceConfigurationIdentityTransform())->transform(
            $values,
            $context,
            ['homepage' => ['public_target' => ['show_tree' => true]]],
        ));
    }

    /**
     * EN: Direct settings-only restoration retains local references.
     *
     * HR: Pri izravnom povratu samo postavki reference zadržavaju lokalne ID-ove.
     */
    public function testSettingsOnlyTransformPreservesLocalIdentitiesAndPartialObjects(): void
    {
        $context = new BackupImportContext(
            new BackupScope(BackupScope::COMPONENT),
            BackupImportContext::CONFLICT_MERGE,
            ['workspace-configuration'],
        );
        $values = [
            'creation' => ['users' => [7]],
            'homepage' => ['public_target' => ['type' => 'page', 'node_id' => 17]],
        ];
        $this->assertSame($values, (new WorkspaceConfigurationIdentityTransform())->transform($values, $context));
    }
}
