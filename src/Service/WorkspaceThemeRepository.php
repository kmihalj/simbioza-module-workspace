<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;
use JsonException;
use RuntimeException;

/**
 * HR: Čuva odabir i privatnu konfiguraciju teme uz područje, neovisno o sistemskim JSON temama.
 * EN: Stores theme selection and private configuration with a workspace, independently of system JSON themes.
 */
final readonly class WorkspaceThemeRepository
{
    public const SELECTION_DEFAULT = 'default';

    public const SELECTION_SYSTEM = 'system';

    public const SELECTION_CUSTOM = 'custom';

    /** HR: Dosadašnja visina zaglavlja prije uvođenja postavke. EN: Legacy header height. */
    public const DEFAULT_HEADER_HEIGHT_PX = 72;

    /** HR: Dosadašnja visina glavnog menija prije uvođenja postavke. EN: Legacy navigation height. */
    public const DEFAULT_NAVIGATION_HEIGHT_PX = 56;

    /**
     * HR: Prima prijenosni ORM Database servis.
     * EN: Receives the portable ORM Database service.
     */
    public function __construct(private Database $database)
    {
    }

    /**
     * HR: Provjerava je li migracija privatnih tema primijenjena.
     * EN: Checks whether the private-theme migration has been applied.
     */
    public function tablesReady(): bool
    {
        return $this->database->schema()->hasTable(ModuleWorkspace::TABLE_WORKSPACE_THEMES);
    }

    /**
     * HR: Vraća spremljenu postavku ili zadano nasljeđivanje sistemske teme.
     * EN: Returns stored settings or default system-theme inheritance.
     *
     * @return array{workspace_id:int,selection_type:string,source_theme_id:string,mode_policy:string,theme:?array<string,mixed>,updated_at:string}
     */
    public function forWorkspace(int $workspaceId): array
    {
        $fallback = $this->defaults($workspaceId);
        if (!$this->tablesReady() || $workspaceId <= 0) {
            return $fallback;
        }

        $row = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_THEMES)
            ->where('workspace_id', '=', $workspaceId)
            ->first();
        if (!is_array($row)) {
            return $fallback;
        }

        $selection = is_scalar($row['selection_type'] ?? null)
        ? strtolower(trim((string)$row['selection_type']))
        : self::SELECTION_DEFAULT;
        if (!in_array($selection, [self::SELECTION_DEFAULT, self::SELECTION_SYSTEM, self::SELECTION_CUSTOM], true)) {
            $selection = self::SELECTION_DEFAULT;
        }

        $theme = null;
        $json = is_scalar($row['theme_json'] ?? null) ? trim((string)$row['theme_json']) : '';
        if ($json !== '') {
            try {
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                $theme = is_array($decoded) ? WorkspaceValue::stringKeyArray($decoded) : null;
            } catch (JsonException) {
                $theme = null;
            }
        }

        return [
            'workspace_id' => $workspaceId,
            'selection_type' => $selection,
            'source_theme_id' => is_scalar($row['source_theme_id'] ?? null)
                ? trim((string)$row['source_theme_id'])
                : '',
            'mode_policy' => $this->mode($row['mode_policy'] ?? 'auto'),
            'theme' => $theme,
            'updated_at' => is_scalar($row['updated_at'] ?? null) ? (string)$row['updated_at'] : '',
        ];
    }

    /**
     * HR: Sprema odabir ili privatnu temu atomskim ažuriranjem jedinog retka područja.
     * EN: Saves a selection or private theme by atomically updating the workspace's single row.
     *
     * @param array<string, mixed>|null $theme
     */
    public function save(
        int $workspaceId,
        string $selectionType,
        string $sourceThemeId,
        string $modePolicy,
        ?array $theme,
        int $actorUserId,
    ): void {
        if (!$this->tablesReady()) {
            throw new RuntimeException(__('Migracija tema područja nije primijenjena.'));
        }

        if ($workspaceId <= 0) {
            throw new RuntimeException(__('Područje nije valjano.'));
        }

        $selectionType = strtolower(trim($selectionType));
        $selectionTypes = [self::SELECTION_DEFAULT, self::SELECTION_SYSTEM, self::SELECTION_CUSTOM];
        if (!in_array($selectionType, $selectionTypes, true)) {
            throw new RuntimeException(__('Odabir teme područja nije valjan.'));
        }

        $themeJson = null;
        if ($selectionType === self::SELECTION_CUSTOM) {
            if (!is_array($theme) || $theme === []) {
                throw new RuntimeException(__('Privatna tema područja nema konfiguraciju.'));
            }

            $theme = self::withMissingComponentHeights($theme);

            try {
                $themeJson = json_encode(
                    $theme,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                );
            } catch (JsonException $exception) {
                throw new RuntimeException(__('Privatnu temu područja nije moguće spremiti.'), 0, $exception);
            }
        }

        $now = date('Y-m-d H:i:s');
        $payload = [
            'selection_type' => $selectionType,
            'source_theme_id' => $sourceThemeId !== '' ? $sourceThemeId : null,
            'mode_policy' => $this->mode($modePolicy),
            'theme_json' => $themeJson,
            'updated_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
            'updated_at' => $now,
        ];
        $existing = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_THEMES)
            ->where('workspace_id', '=', $workspaceId)
            ->first();
        if (is_array($existing)) {
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_THEMES)
                ->where('workspace_id', '=', $workspaceId)
                ->update($payload);
            return;
        }

        $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_THEMES)->insert([
            'workspace_id' => $workspaceId,
            'created_at' => $now,
            ...$payload,
        ]);
    }

    /**
     * HR: Trajno dopunjuje samo nedostajuće visine privatnih tema područja.
     * EN: Permanently adds only missing heights to private Workspace themes.
     */
    public function persistMissingComponentHeights(?int $workspaceId = null): int
    {
        if (!$this->tablesReady() || ($workspaceId !== null && $workspaceId <= 0)) {
            return 0;
        }

        $query = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_THEMES)
            ->select(['id', 'theme_json']);
        if ($workspaceId !== null) {
            $query->where('workspace_id', '=', $workspaceId);
        }

        $changedThemes = 0;
        foreach (WorkspaceValue::rows($query->get()) as $row) {
            $id = WorkspaceValue::int($row['id'] ?? null);
            $json = is_scalar($row['theme_json'] ?? null) ? trim((string)$row['theme_json']) : '';
            if ($id <= 0 || $json === '') {
                continue;
            }

            try {
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                $theme = WorkspaceValue::stringKeyArray($decoded);
                if ($theme === []) {
                    continue;
                }

                $upgraded = self::withMissingComponentHeights($theme);
                if ($upgraded === $theme) {
                    continue;
                }

                $encoded = json_encode(
                    $upgraded,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                );
            } catch (JsonException) {
                continue;
            }

            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_THEMES)
                ->where('id', '=', $id)
                ->update(['theme_json' => $encoded]);
            ++$changedThemes;
        }

        return $changedThemes;
    }

    /**
     * HR: Vraća temu s dosadašnjim vrijednostima samo ondje gdje novi ključevi ne postoje.
     * EN: Returns a theme with legacy values only where the new keys are absent.
     *
     * @param array<string, mixed> $theme
     * @return array<string, mixed>
     */
    public static function withMissingComponentHeights(array $theme): array
    {
        $components = WorkspaceValue::stringKeyArray($theme['components'] ?? []);
        $header = WorkspaceValue::stringKeyArray($components['header'] ?? []);
        $navigation = WorkspaceValue::stringKeyArray($components['navigation'] ?? []);

        if (!array_key_exists('height_px', $header)) {
            $header['height_px'] = self::DEFAULT_HEADER_HEIGHT_PX;
        }

        if (!array_key_exists('height_px', $navigation)) {
            $navigation['height_px'] = self::DEFAULT_NAVIGATION_HEIGHT_PX;
        }

        $components['header'] = $header;
        $components['navigation'] = $navigation;
        $theme['components'] = $components;

        return $theme;
    }

    /**
     * HR: Briše postavku tako da područje ponovno potpuno prati sustav.
     * EN: Deletes settings so the workspace fully follows the system again.
     */
    public function delete(int $workspaceId): void
    {
        if (!$this->tablesReady() || $workspaceId <= 0) {
            return;
        }

        $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_THEMES)
            ->where('workspace_id', '=', $workspaceId)
            ->delete();
    }

    /**
     * HR: Gradi zadano stanje koje potpuno nasljeđuje trenutačnu sistemsku temu.
     * EN: Builds the default state that fully inherits the current system theme.
     *
     * @return array{workspace_id:int,selection_type:string,source_theme_id:string,mode_policy:string,theme:null,updated_at:string}
     */
    private function defaults(int $workspaceId): array
    {
        return [
            'workspace_id' => $workspaceId,
            'selection_type' => self::SELECTION_DEFAULT,
            'source_theme_id' => '',
            'mode_policy' => 'auto',
            'theme' => null,
            'updated_at' => '',
        ];
    }

    /**
     * HR: Normalizira politiku prikaza na automatsku, svijetlu ili tamnu vrijednost.
     * EN: Normalizes the display policy to the automatic, light, or dark value.
     */
    private function mode(mixed $mode): string
    {
        $mode = is_scalar($mode) ? strtolower(trim((string)$mode)) : 'auto';

        return in_array($mode, ['auto', 'light', 'dark'], true) ? $mode : 'auto';
    }
}
