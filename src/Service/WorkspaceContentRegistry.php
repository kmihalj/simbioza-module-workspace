<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Service;

use AaiEduHr\SimbiozaModuleWorkspace\Contract\WorkspaceContentProviderInterface;
use AaiEduHr\SimbiozaModuleWorkspace\Contract\WorkspaceContentPublicationInterface;

/**
 * EN: Holds lazy optional content providers; missing types fail closed.
 *
 * HR: Čuva odgođene opcionalne providere sadržaja; odsutne vrste sigurno odbija.
 */
final class WorkspaceContentRegistry
{
    /** @var array<string,callable():WorkspaceContentProviderInterface> */
    private array $factories = [];

    /** @var array<string,WorkspaceContentProviderInterface> */
    private array $providers = [];

    /**
     * EN: Registers one unique non-core type.
     *
     * HR: Registrira jedinstvenu vrstu izvan jezgre.
     *
     * @param callable():WorkspaceContentProviderInterface $factory
     */
    public function register(string $type, callable $factory): void
    {
        if (
            preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $type) !== 1
            || in_array($type, ['document', 'separator', 'internal_link', 'external_link'], true)
        ) {
            throw new \InvalidArgumentException('Invalid extension content type.');
        }

        $this->factories[$type] = $factory;
        unset($this->providers[$type]);
    }

    /**
     * EN: Checks availability without constructing a provider.
     *
     * HR: Provjerava dostupnost bez izrade providera.
     */
    public function has(string $type): bool
    {
        return isset($this->factories[$type]);
    }

    /**
     * EN: Resolves only a registered type.
     *
     * HR: Razrješava samo registriranu vrstu.
     */
    public function get(string $type): ?WorkspaceContentProviderInterface
    {
        if (!$this->has($type)) {
            return null;
        }

        $provider = $this->providers[$type] ??= ($this->factories[$type])();
        if ($provider->type() !== $type) {
            throw new \RuntimeException('Mismatched content provider.');
        }

        return $provider;
    }

    /**
     * EN: Lists registered providers lazily.
     *
     * HR: Odgođeno vraća registrirane providere.
     *
     * @return list<WorkspaceContentProviderInterface>
     */
    public function all(): array
    {
        $providers = [];
        foreach (array_keys($this->factories) as $type) {
            $provider = $this->get($type);
            if ($provider instanceof \AaiEduHr\SimbiozaModuleWorkspace\Contract\WorkspaceContentProviderInterface) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    /**
     * EN: Resolves actor-aware publication rules while retaining compatibility with existing providers.
     *
     * HR: Razrješava pravila objave za korisnika uz kompatibilnost s postojećim providerima.
     * @param array<string,mixed> $node
     * @param array<string,bool> $permissions
     * @param array<string,mixed>|null $user
     * @param list<string> $languages
     */
    public function visible(array $node, array $permissions, ?array $user, array $languages): bool
    {
        $provider = $this->get(WorkspaceValue::string($node['node_type'] ?? ''));
        return $provider instanceof WorkspaceContentPublicationInterface
        ? $provider->visibleTo($node, $permissions, $user, $languages)
        : ($provider?->visible($node, $permissions, $languages) ?? false);
    }

    /**
     * EN: Returns optional publication metadata without loading definitions or responses.
     *
     * HR: Vraća opcionalne podatke o objavi bez učitavanja definicija ili odgovora.
     * @param array<string,mixed> $node
     * @param array<string,bool> $permissions
     * @param array<string,mixed>|null $user
     * @return array{status:string,is_new_unpublished:bool}|null
     */
    public function publicationState(array $node, array $permissions, ?array $user): ?array
    {
        $provider = $this->get(WorkspaceValue::string($node['node_type'] ?? ''));
        return $provider instanceof WorkspaceContentPublicationInterface
        ? $provider->publicationState($node, $permissions, $user) : null;
    }

    /**
     * EN: Recognizes built-in page/link types.
     *
     * HR: Prepoznaje ugrađene vrste stranica i poveznica.
     */
    public function isCore(string $type): bool
    {
        return in_array($type, ['document', 'separator', 'internal_link', 'external_link'], true);
    }
}
