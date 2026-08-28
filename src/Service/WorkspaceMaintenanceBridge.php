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

    private const IMAGE_VARIANT_SERVICE
    = 'AaiEduHr\\HeartPhrameModuleEditorHtml\\Service\\EditorImageVariantService';

    private const IMAGE_OPTIMIZATION_SERVICE
    = 'AaiEduHr\\HeartPhrameModuleEditorHtml\\Service\\EditorImageOptimizationService';

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
     * HR: Izrađuje nedostajuće web-varijante svih postojećih slika bez
     *     mijenjanja ili uklanjanja izvornih datoteka.
     * EN: Creates missing web variants for all existing images without
     *     changing or removing the source files.
     *
     * @return array{documents: int, generated: int, skipped: int}|array{}
     */
    public function optimizeImages(): array
    {
        $service = $this->optionalService(self::IMAGE_VARIANT_SERVICE);
        if (!is_object($service) || !method_exists($service, 'prewarmAllDocuments')) {
            return [];
        }

        try {
            $result = $service->prewarmAllDocuments();
            if (!is_array($result)) {
                return [];
            }

            return [
                'documents' => is_int($result['documents'] ?? null) ? $result['documents'] : 0,
                'generated' => is_int($result['generated'] ?? null) ? $result['generated'] : 0,
                'skipped' => is_int($result['skipped'] ?? null) ? $result['skipped'] : 0,
            ];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * HR: Pokreće nastavivi posao optimizacije. EN: Starts the resumable optimization job.
     * @return array<string, mixed>
     */
    public function startImageOptimization(): array
    {
        return $this->imageOptimizationCall('start');
    }

    /**
     * HR: Vraća napredak posla optimizacije. EN: Returns optimization job progress.
     * @return array<string, mixed>
     */
    public function imageOptimizationStatus(): array
    {
        return $this->imageOptimizationCall('status');
    }

    /**
     * HR: Obrađuje jednu ograničenu seriju slika. EN: Processes one bounded image batch.
     * @return array<string, mixed>
     */
    public function stepImageOptimization(int $limit): array
    {
        return $this->imageOptimizationCall('step', $limit);
    }

    /**
     * HR: Sigurno poziva opcionalni servis posla optimizacije. EN: Safely calls the optional optimization-job service.
     * @return array<string, mixed>
     */
    private function imageOptimizationCall(string $method, ?int $limit = null): array
    {
        $service = $this->optionalService(self::IMAGE_OPTIMIZATION_SERVICE);
        if (!is_object($service) || !method_exists($service, $method)) {
            return [];
        }

        try {
            $result = $limit === null ? $service->{$method}() : $service->{$method}($limit);

            return $this->stringKeyedArray(is_array($result) ? $result : []);
        } catch (Throwable) {
            return [];
        }
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
        return $this->optionalService(self::SERVICE);
    }

    /**
     * HR: Sigurno razrješava opcionalni servis po nazivu klase.
     * EN: Safely resolves an optional service by its class name.
     */
    private function optionalService(string $serviceClass): ?object
    {
        try {
            $service = $this->container->get($serviceClass);
            return is_object($service) ? $service : null;
        } catch (Throwable) {
            return null;
        }
    }
}
