<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Contract;

use Psr\Http\Message\ServerRequestInterface;

/**
 * EN: Extends page content without replacing Workspace ACL, tree or rendering.
 *
 * HR: Proširuje sadržaj stranice bez zamjene ovlasti, stabla ili prikaza područja.
 */
interface WorkspaceContentProviderInterface
{
    /**
     * EN: Returns a stable node type.
     *
     * HR: Vraća stabilnu vrstu čvora.
     */
    public function type(): string;

    /**
     * EN: Preloads a node batch.
     *
     * HR: Unaprijed učitava skup čvorova.
     *
     * @param array<string,mixed> $workspace
     * @param list<array<string,mixed>> $nodes
     */
    public function prepare(array $workspace, array $nodes): void;

    /**
     * EN: Adds type-specific base rights before restrictions.
     *
     * HR: Dodaje tipska bazna prava prije ograničenja.
     *
     * @param array<string,mixed> $workspace
     * @param array<string,mixed> $node
     * @param array<string,mixed>|null $user
     * @param array<string,bool> $base
     * @return array<string,bool>
     */
    public function permissions(array $workspace, array $node, ?array $user, array $base): array;

    /**
     * EN: Checks publication after effective ACL.
     *
     * HR: Provjerava objavu nakon efektivnih ovlasti.
     *
     * @param array<string,mixed> $node
     * @param array<string,bool> $permissions
     * @param list<string> $languages
     */
    public function visible(array $node, array $permissions, array $languages): bool;

    /**
     * EN: Returns an authorized module partial, never arbitrary PHP.
     *
     * HR: Vraća ovlašten modulski prikaz, ne proizvoljni PHP.
     * @param array<string,mixed> $workspace
     * @param array<string,mixed> $node
     * @return array{package:string,view:string,data:array<string,mixed>,title:string}
     */
    public function view(ServerRequestInterface $request, array $workspace, array $node): array;

    /**
     * EN: Offers an authorized create action.
     *
     * HR: Nudi ovlaštenu radnju kreiranja.
     *
     * @param array<string,mixed> $workspace
     * @param array<string,mixed>|null $user
     * @return array{label:string,href:string,icon?:string}|null
     */
    public function createAction(array $workspace, ?array $user): ?array;

    /**
     * EN: Grants navigation only for this content type.
     *
     * HR: Dopušta navigaciju samo za ovu vrstu sadržaja.
     *
     * @param array<string,mixed> $workspace
     * @param array<string,mixed>|null $user
     */
    public function workspaceVisible(array $workspace, ?array $user): bool;

    /**
     * EN: Offers a manager-only membership section.
     *
     * HR: Nudi odjeljak članstva samo upravitelju.
     *
     * @param array<string,mixed> $workspace
     * @return array{package:string,view:string,data:array<string,mixed>}|null
     */
    public function memberSection(array $workspace): ?array;
}
