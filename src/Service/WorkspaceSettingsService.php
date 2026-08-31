<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use HeartPhrame\Routing\Routes;
use RuntimeException;

use function array_values;
use function is_array;
use function is_dir;
use function is_scalar;
use function ltrim;
use function mkdir;
use function preg_replace;
use function str_starts_with;
use function strtok;
use function strtolower;
use function trim;
use function var_export;

final readonly class WorkspaceSettingsService
{
    /**
     * HR: Prima efektivne postavke i router potreban za provjeru konflikta root putanje.
     * EN: Receives effective settings and the router used to detect root-path conflicts.
     */
    public function __construct(
        private WorkspaceConfig $config,
        private Routes $routes,
        private WorkspaceRepository $repository,
    ) {
    }

    /**
     * HR: Priprema efektivne postavke za administratorsku formu.
     * EN: Prepares effective settings for the administration form.
     *
     * @return array<string, mixed>
     */
    public function settingsForForm(): array
    {
        return [
            'root_path' => $this->config->rootPath(),
            'default_visibility' => $this->config->defaultVisibility(),
            'tree_visible' => $this->config->treeVisibleByDefault(),
            'contents_visible' => $this->config->contentsVisibleByDefault(),
            'creator_users' => $this->repository->directorySubjectsByIds(
                WorkspaceRepository::SUBJECT_USER,
                $this->config->creatorUserIds(),
            ),
            'creator_groups' => $this->repository->directorySubjectsByIds(
                WorkspaceRepository::SUBJECT_GROUP,
                $this->config->creatorGroupIds(),
            ),
            'shorts_depth' => $this->config->shortsDefaultDepth(),
            'shorts_limit' => $this->config->shortsDefaultLimit(),
            'shorts_order' => $this->config->shortsDefaultOrder(),
            'shorts_display_options_visible' => $this->config->shortsDisplayOptionsVisibleByDefault(),
            'settings_file_path' => $this->config->settingsFilePath(),
        ];
    }

    /**
     * HR: Validira i zapisuje čistu aplikacijsku konfiguraciju područja.
     * EN: Validates and writes the clean application Workspace configuration.
     *
     * @param array<string, mixed> $input
     */
    public function saveFromForm(array $input): void
    {
        $rootPath = $this->rootPath($input['root_path'] ?? 'workspace');
        $this->assertRootPathDoesNotConflict($rootPath);
        $visibility = is_scalar($input['default_visibility'] ?? null)
        ? strtolower(trim((string)$input['default_visibility']))
        : '';
        if (!in_array($visibility, ['restricted', 'authenticated', 'public'], true)) {
            $visibility = 'restricted';
        }

        $settings = [
            'routing' => [
                'root_path' => $rootPath,
            ],
            'defaults' => [
                'visibility' => $visibility,
                'tree_visible' => $this->boolValue($input['tree_visible'] ?? false),
                'contents_visible' => $this->boolValue($input['contents_visible'] ?? false),
            ],
            'creation' => [
                'users' => $this->validCreatorIds(
                    WorkspaceRepository::SUBJECT_USER,
                    $input['creator_users'] ?? [],
                ),
                'groups' => $this->validCreatorIds(
                    WorkspaceRepository::SUBJECT_GROUP,
                    $input['creator_groups'] ?? [],
                ),
            ],
            'shorts' => [
                'depth' => $this->allowedInt($input['shorts_depth'] ?? 2, [1, 2, 3], 2),
                'limit' => $this->allowedInt(
                    $input['shorts_limit'] ?? 10,
                    [5, 10, 25, 50],
                    10,
                ),
                'order' => $this->allowedString(
                    $input['shorts_order'] ?? 'newest',
                    ['hierarchy', 'newest', 'oldest'],
                    'newest',
                ),
                'display_options_visible' => $this->boolValue(
                    $input['shorts_display_options_visible'] ?? false,
                ),
            ],
            'menu' => [
                'auto_register_top' => true,
                'auto_register_settings' => true,
            ],
        ];

        $path = $this->config->settingsFilePath();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(__('Nije moguće kreirati direktorij postavki područja.'));
        }

        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn "
        . var_export($settings, true)
        . ";\n";
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException(__('Nije moguće zapisati postavke područja.'));
        }
    }

    /**
     * HR: Zadržava samo postojeće aktivne Auth korisnike ili grupe.
     * EN: Keeps only existing active Auth users or groups.
     *
     * @return list<int>
     */
    private function validCreatorIds(string $category, mixed $values): array
    {
        $ids = [];
        foreach (is_array($values) ? $values : [] as $value) {
            $id = WorkspaceValue::int($value);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        $valid = [];
        foreach ($this->repository->directorySubjectsByIds($category, array_values($ids)) as $subject) {
            $id = WorkspaceValue::int($subject['id'] ?? 0);
            if ($id > 0) {
                $valid[] = $id;
            }
        }

        return $valid;
    }

    /**
     * HR: Odbija root segment koji već koristi druga GET ruta.
     * EN: Rejects a root segment already occupied by another GET route.
     */
    private function assertRootPathDoesNotConflict(string $rootPath): void
    {
        foreach ($this->routes->getNamedRoutes() as $name => $route) {
            if (str_starts_with((string)$name, 'workspace.')) {
                continue;
            }

            if ((string)($route['method'] ?? '') !== 'GET') {
                continue;
            }

            if ($this->firstRouteSegment((string)($route['path'] ?? '')) === $rootPath) {
                throw new RuntimeException(__('Odabranu putanju već koristi druga ruta aplikacije.'));
            }
        }
    }

    /**
     * HR: Normalizira administratorski unos na jedan siguran URL segment.
     * EN: Normalizes administrator input into one safe URL segment.
     */
    private function rootPath(mixed $value): string
    {
        $path = is_scalar($value) ? strtolower(trim((string)$value)) : '';
        $path = trim((string)preg_replace('/[^a-z0-9-]+/', '-', $path), '-');

        return $path !== '' ? $path : 'workspace';
    }

    /**
     * HR: Izdvaja prvi statički segment registrirane rute.
     * EN: Extracts the first static segment of a registered route.
     */
    private function firstRouteSegment(string $path): string
    {
        $path = trim($path, '/');
        if ($path === '' || str_starts_with($path, '{')) {
            return '';
        }

        return strtolower(ltrim(strtok($path, '/') ?: '', '/'));
    }

    /**
     * HR: Pretvara checkbox i druge skalarne vrijednosti u boolean.
     * EN: Converts checkbox and other scalar values into a boolean.
     */
    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (!is_scalar($value)) {
            return false;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * HR: Prihvaća samo jednu od izričito dopuštenih brojčanih postavki.
     * EN: Accepts only one of the explicitly allowed numeric settings.
     *
     * @param list<int> $allowed
     */
    private function allowedInt(mixed $value, array $allowed, int $fallback): int
    {
        $number = is_scalar($value) ? (int)$value : 0;

        return in_array($number, $allowed, true) ? $number : $fallback;
    }

    /**
     * HR: Prihvaća samo jednu od izričito dopuštenih tekstualnih postavki.
     * EN: Accepts only one of the explicitly allowed string settings.
     *
     * @param list<string> $allowed
     */
    private function allowedString(mixed $value, array $allowed, string $fallback): string
    {
        $normalized = is_scalar($value) ? strtolower(trim((string)$value)) : '';

        return in_array($normalized, $allowed, true) ? $normalized : $fallback;
    }
}
