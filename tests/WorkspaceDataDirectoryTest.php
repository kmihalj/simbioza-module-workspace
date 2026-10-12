<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceDataDirectory;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * EN: Verifies readable storage identities independently of the GUI and public routes.
 *
 * HR: Provjerava čitljive identitete pohrane neovisno o GUI-ju i javnim rutama.
 */
#[CoversClass(WorkspaceDataDirectory::class)]
final class WorkspaceDataDirectoryTest extends TestCase
{
    /**
     * EN: Retains the technical label after public slug changes and rejects unknown owners.
     *
     * HR: Čuva tehničku oznaku nakon promjene javnog sluga i odbija nepoznate vlasnike.
     */
    public function testWorkspaceStorageIdentityIsIndependentOfPublicSlug(): void
    {
        $helper = new Helper();
        $config = new Config($helper, ['database' => ['connections' => ['default' => [
            'driver' => 'sqlite', 'database' => ':memory:',
        ]]]]);
        $database = new Database($config, $helper);
        $database->schema()->create('workspaces', static function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 96);
            $table->string('storage_slug', 96)->default('');
        });
        $database->table('workspaces')->insert(['id' => 7, 'slug' => 'public-original']);
        $paths = new WorkspaceDataDirectory($database);
        $this->assertCurrentWorkspaceIdentity($paths, 7, 'workspace--7');
        $database->table('workspaces')->where('id', '=', 7)->update(['storage_slug' => 'initial-label']);
        $this->assertCurrentWorkspaceIdentity($paths, 7, 'initial-label--7');
        $database->table('workspaces')->where('id', '=', 7)->update(['slug' => 'renamed-public-route']);
        $this->assertCurrentWorkspaceIdentity($paths, 7, 'initial-label--7');
        $this->expectException(RuntimeException::class);
        $paths->workspace(41);
    }

    /**
     * EN: Checks a fresh database read on the same resolver after each fixture mutation.
     *
     * HR: Provjerava novo čitanje baze istim resolverom nakon svake izmjene fixture.
     */
    private function assertCurrentWorkspaceIdentity(WorkspaceDataDirectory $paths, int $id, string $expected): void
    {
        $this->assertSame($expected, $paths->workspace($id));
    }

    /**
     * EN: Keeps full UUIDs and bounded safe labels, including imported traversal-like names.
     *
     * HR: Čuva potpune UUID-e i ograničene sigurne oznake, uključujući uvezene traversal-like nazive.
     */
    public function testPageNamesAreBoundedSafeAndCollisionResistant(): void
    {
        $uuid = '10000000-0000-4000-8000-000000000007';
        $this->assertSame('my-page--' . $uuid, WorkspaceDataDirectory::page('../My Page/', $uuid));
        $this->assertSame('page--' . $uuid, WorkspaceDataDirectory::page('../', $uuid));
        $this->assertSame(
            str_repeat('a', 48) . '--' . $uuid,
            WorkspaceDataDirectory::page(str_repeat('a', 190), $uuid),
        );
        $this->assertSame('workspace--41', WorkspaceDataDirectory::name(41, '../'));
        $this->assertSame('workspace--41', WorkspaceDataDirectory::name(41, ''));
        $this->expectException(RuntimeException::class);
        WorkspaceDataDirectory::page('page', '../not-a-uuid');
    }

    /**
     * EN: Refuses invalid IDs even when only already-loaded metadata is used.
     *
     * HR: Odbija neispravne ID-eve i kada koristi samo već učitane metapodatke.
     */
    public function testCachedMetadataRejectsInvalidOwner(): void
    {
        $this->expectException(RuntimeException::class);
        WorkspaceDataDirectory::name(0, 'label');
    }
}
