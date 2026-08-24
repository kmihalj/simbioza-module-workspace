<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleWorkspace\Service;

use Psr\Container\ContainerInterface;
use Throwable;

use function is_array;
use function is_int;
use function is_object;
use function is_string;
use function method_exists;

/**
 * HR: Izolira opcionalnu integraciju s održavanjem HTML Editora. Workspace ne
 *     poznaje editorove tablice ni datotečne putanje.
 * EN: Isolates optional HTML Editor maintenance integration. Workspace does
 *     not know the editor's tables or filesystem paths.
 */
final readonly class WorkspaceMaintenanceBridge
{
    private const SERVICE = 'AaiEduHr\\HeartPhrameModuleEditorHtml\\Service\\EditorMaintenanceService';

    /** HR: Prima spremnik za opcionalno razrješavanje Editor servisa. EN: Receives the container for optional Editor-service resolution. */
    public function __construct(private ContainerInterface $container)
    {
    }

    /**
     * HR: Dohvaća skupne statistike uz dodatnu zaštitu verzija koje koristi Workspace workflow.
     * EN: Retrieves bulk statistics while protecting versions used by the Workspace workflow.
     *
     * @param array<string, list<string>|null> $scopes
     * @param array<string, array<string, array<string, list<int>>>> $protectedVersionsByScope
     * @return array<string, array<string, int>>
     */
    public function statisticsForScopes(array $scopes, array $protectedVersionsByScope = []): array
    {
        $service = $this->service();
        if (!is_object($service) || !method_exists($service, 'statisticsForScopes')) {
            return [];
        }

        try {
            $result = $service->statisticsForScopes($scopes, $protectedVersionsByScope);
            return $this->integerStatistics(is_array($result) ? $result : []);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * HR: Delegira nepovratno čišćenje vlasniku editorovih podataka i datoteka.
     * EN: Delegates irreversible cleanup to the owner of editor data and files.
     *
     * @param list<string>|null $documentKeys
     * @param array<string, array<string, list<int>>> $protectedVersions
     * @return array<string, mixed>
     */
    public function clean(
        ?array $documentKeys,
        string $historyPolicy,
        int $historyValue,
        int $deletedDays,
        array $protectedVersions,
    ): array {
        $service = $this->service();
        if (!is_object($service) || !method_exists($service, 'clean')) {
            return [];
        }

        $result = $service->clean(
            $documentKeys,
            $historyPolicy,
            $historyValue,
            $deletedDays,
            $protectedVersions,
        );
        return $this->stringKeyedArray(is_array($result) ? $result : []);
    }

    /**
     * HR: Nepovratno uklanja točno zadane dokumente preko javne Editor metode.
     * EN: Irreversibly removes exactly the supplied documents through the public Editor method.
     *
     * @param list<string> $documentKeys
     * @return array<string, mixed>
     */
    public function purgeDocuments(array $documentKeys): array
    {
        if ($documentKeys === []) {
            return [
                'purged_documents' => 0,
                'purged_versions' => 0,
                'purged_assets' => 0,
                'failed_files' => 0,
            ];
        }

        $service = $this->service();
        if (!is_object($service) || !method_exists($service, 'purgeDocuments')) {
            return [];
        }

        $result = $service->purgeDocuments($documentKeys);

        return $this->stringKeyedArray(is_array($result) ? $result : []);
    }

    /**
     * HR: Normalizira dinamički rezultat opcionalnog servisa u strogi statistički oblik.
     * EN: Normalizes an optional service's dynamic result into a strict statistics shape.
     *
     * @param array<mixed> $statistics
     * @return array<string, array<string, int>>
     */
    private function integerStatistics(array $statistics): array
    {
        $normalized = [];
        foreach ($statistics as $scope => $values) {
            if (!is_string($scope)) {
                continue;
            }

            if (!is_array($values)) {
                continue;
            }

            $scopeValues = [];
            foreach ($values as $key => $value) {
                if (is_string($key) && is_int($value)) {
                    $scopeValues[$key] = $value;
                }
            }

            $normalized[$scope] = $scopeValues;
        }

        return $normalized;
    }

    /**
     * HR: Zadržava samo tekstualne ključeve dinamičkog rezultata čišćenja.
     * EN: Keeps only string keys from the dynamic cleanup result.
     *
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * HR: Opcionalno dohvaća Editorov servis bez stvaranja čvrste ovisnosti modula.
     * EN: Optionally resolves the Editor service without creating a hard module dependency.
     */
    private function service(): ?object
    {
        try {
            $service = $this->container->get(self::SERVICE);
            return is_object($service) ? $service : null;
        } catch (Throwable) {
            return null;
        }
    }
}
