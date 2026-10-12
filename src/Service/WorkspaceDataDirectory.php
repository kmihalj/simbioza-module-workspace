<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;
use RuntimeException;

/**
 * EN: Resolves readable data directories from stable domain identities, not configuration or GUI state.
 * The initial storage slug is immutable; changing a displayed name never detaches existing files.
 *
 * HR: Određuje čitljive podatkovne direktorije iz stabilnih identiteta, ne konfiguracije ili GUI-a.
 * Početni storage slug je nepromjenjiv; izmjena prikazanog naziva ne odvaja postojeće datoteke.
 */
final readonly class WorkspaceDataDirectory
{
    /**
     * EN: Shares the domain database with the Editor, Menu and private-theme integrations.
     *
     * HR: Dijeli domensku bazu s integracijama Editora, Menua i privatnih tema.
     */
    public function __construct(private Database $database)
    {
    }

    /**
     * EN: Reads the immutable storage slug and always returns a labelled owner directory.
     * Each call reads current domain state rather than caching authorization-sensitive owners.
     *
     * HR: Čita nepromjenjivi storage slug i uvijek vraća označeni direktorij vlasnika.
     * Svaki poziv čita aktualno domensko stanje umjesto pamćenja vlasnika bitnih za ovlasti.
     *
     * @phpstan-impure
     */
    public function workspace(int $id): string
    {
        $row = $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)->where('id', '=', $id)->first();
        if ($id <= 0 || !is_array($row)) {
            throw new RuntimeException('Workspace data owner is unavailable.');
        }

        $slug = $row['storage_slug'] ?? '';
        return self::name($id, is_string($slug) ? $slug : '');
    }

    /**
     * EN: Reuses immutable storage metadata already loaded in the request, without caching permissions.
     *
     * HR: Koristi nepromjenjive metapodatke pohrane već učitane u zahtjevu, bez pamćenja ovlasti.
     */
    public static function name(int $id, string $slug): string
    {
        if ($id <= 0) {
            throw new RuntimeException('Workspace data owner is unavailable.');
        }

        return self::label($slug, 'workspace') . '--' . $id;
    }

    /**
     * EN: Uses a bounded ASCII label and the full stable UUID; labels never determine authorization.
     *
     * HR: Koristi ograničenu ASCII oznaku i puni stabilni UUID; oznake ne određuju autorizaciju.
     */
    public static function page(string $key, string $uuid): string
    {
        if (preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di', $uuid) !== 1) {
            throw new RuntimeException('Workspace page UUID is invalid.');
        }

        return self::label($key, 'page') . '--' . $uuid;
    }

    /**
     * EN: Sanitizes names before filesystem use, including unsafe imported labels and long document keys.
     *
     * HR: Pročišćava nazive prije uporabe na disku, uključujući nesigurne uvezene oznake i duge ključeve.
     */
    public static function label(string $value, string $fallback): string
    {
        $value = trim(substr((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), 0, 48), '-');
        return $value !== '' ? $value : $fallback;
    }
}
