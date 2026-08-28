<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleWorkspace\Service;

use HeartPhrame\Routing\UrlGenerator;
use Psr\Container\ContainerInterface;
use Throwable;

use function is_object;
use function method_exists;
use function rawurlencode;
use function rtrim;
use function sprintf;

/**
 * HR: Opcionalno povezuje Workspace workflow sa zajedničkim korisničkim inboxom.
 *     Workspace ostaje samostalan kada Notification modul nije instaliran.
 * EN: Optionally connects the Workspace workflow to the shared user inbox.
 *     Workspace remains standalone when the Notification module is absent.
 */
final readonly class WorkspaceNotificationBridge
{
    private const NOTIFICATION_NAMESPACE =
    'AaiEduHr\\HeartPhrameModuleNotification\\Service\\';

    private const NOTIFICATION_SERVICE =
    self::NOTIFICATION_NAMESPACE . 'NotificationService';

    /**
     * HR: Prima container, ACL servis i URL generator bez Composer ovisnosti o
     *     opcionalnom Notification modulu.
     * EN: Receives the container, ACL service, and URL generator without a
     *     Composer dependency on the optional Notification module.
     */
    public function __construct(
        private ContainerInterface $container,
        private WorkspaceAccessService $access,
        private UrlGenerator $urlGenerator,
        private WorkspaceRepository $repository,
        private WorkspaceConfig $config,
    ) {
    }

    /**
     * HR: Obavještava sve efektivne objavljivače da stranica čeka pregled.
     * EN: Notifies all effective publishers that a page is awaiting review.
     *
     * @param array<string, mixed> $workspace
     * @param array<string, mixed> $node
     */
    public function pageSubmitted(
        array $workspace,
        array $node,
        string $language,
        int $versionNumber,
        int $actorUserId,
    ): void {
        $workspace = $this->repository->localizeWorkspace(
            $workspace,
            $language,
            $this->config->siteDefaultLanguage(),
        );
        $node = $this->repository->localizeNode(
            $node,
            $language,
            $this->config->siteDefaultLanguage(),
        );
        $recipients = [];
        foreach ($this->access->userIdsWithNodePermission($workspace, $node, 'can_publish') as $userId) {
            if ($userId > 0 && $userId !== $actorUserId) {
                $recipients[] = $userId;
            }
        }

        if ($recipients === []) {
            return;
        }

        $nodeId = WorkspaceValue::int($node['id'] ?? 0);
        $title = __('Stranica čeka pregled');
        $message = sprintf(
            __('Stranica "%s" u području "%s" poslana je na pregled.'),
            WorkspaceValue::string($node['title'] ?? ''),
            WorkspaceValue::string($workspace['name'] ?? ''),
        );
        $this->notifyUsers(
            $recipients,
            'workspace.review_requested',
            $title,
            $message,
            $this->nodePath($workspace, $node, $language, true),
            $nodeId . ':' . $language,
            'workspace:review:' . $nodeId . ':' . $language . ':' . $versionNumber,
            [
                'workspace_id' => WorkspaceValue::int($workspace['id'] ?? 0),
                'node_id' => $nodeId,
                'language' => $language,
                'version_number' => $versionNumber,
            ],
        );
    }

    /**
     * HR: Nakon objave obavještava korisnika koji je nacrt poslao na pregled.
     * EN: After publication, notifies the user who submitted the draft for review.
     *
     * @param array<string, mixed> $workspace
     * @param array<string, mixed> $node
     */
    public function pagePublished(
        array $workspace,
        array $node,
        string $language,
        int $versionNumber,
        int $submittedByUserId,
        int $actorUserId,
    ): void {
        $workspace = $this->repository->localizeWorkspace(
            $workspace,
            $language,
            $this->config->siteDefaultLanguage(),
        );
        $node = $this->repository->localizeNode(
            $node,
            $language,
            $this->config->siteDefaultLanguage(),
        );
        if ($submittedByUserId <= 0 || $submittedByUserId === $actorUserId) {
            return;
        }

        $nodeId = WorkspaceValue::int($node['id'] ?? 0);
        $title = __('Stranica je objavljena');
        $message = sprintf(
            __('Stranica "%s" u području "%s" je objavljena.'),
            WorkspaceValue::string($node['title'] ?? ''),
            WorkspaceValue::string($workspace['name'] ?? ''),
        );
        $this->notifyUser(
            $submittedByUserId,
            'workspace.page_published',
            $title,
            $message,
            $this->nodePath($workspace, $node, $language, false),
            $nodeId . ':' . $language,
            'workspace:published:' . $nodeId . ':' . $language . ':' . $versionNumber,
            [
                'workspace_id' => WorkspaceValue::int($workspace['id'] ?? 0),
                'node_id' => $nodeId,
                'language' => $language,
                'version_number' => $versionNumber,
            ],
        );
    }

    /**
     * HR: Poziva grupno slanje samo kada je Notification servis raspoloživ.
     * EN: Invokes batch notification delivery only when the service is available.
     *
     * @param list<int> $userIds
     * @param array<string, mixed> $data
     */
    private function notifyUsers(
        array $userIds,
        string $key,
        string $title,
        string $message,
        string $link,
        string $reference,
        string $dedupKey,
        array $data,
    ): void {
        try {
            $service = $this->notificationService();
            if ($service === null || !method_exists($service, 'notifyUsers')) {
                return;
            }

            $service->notifyUsers(
                $userIds,
                $key,
                $title,
                $message,
                $link,
                'workspace',
                $reference,
                $dedupKey,
                $data,
                true,
            );
        } catch (Throwable) {
            // HR: Obavijesti su pomoćni kanal i ne smiju poništiti workflow prijelaz.
            // EN: Notifications are an auxiliary channel and must not undo a workflow transition.
        }
    }

    /**
     * HR: Poziva pojedinačno slanje samo kada je Notification servis raspoloživ.
     * EN: Invokes single-user delivery only when the Notification service is available.
     *
     * @param array<string, mixed> $data
     */
    private function notifyUser(
        int $userId,
        string $key,
        string $title,
        string $message,
        string $link,
        string $reference,
        string $dedupKey,
        array $data,
    ): void {
        try {
            $service = $this->notificationService();
            if ($service === null || !method_exists($service, 'notifyUser')) {
                return;
            }

            $service->notifyUser(
                $userId,
                $key,
                $title,
                $message,
                $link,
                'workspace',
                $reference,
                $dedupKey,
                $data,
                true,
            );
        } catch (Throwable) {
            // HR: Neuspjeh pomoćnog kanala ne mijenja već objavljenu stranicu.
            // EN: An auxiliary-channel failure does not change an already published page.
        }
    }

    /**
     * HR: Sigurno dohvaća opcionalni servis iz zajedničkog containera.
     * EN: Safely resolves the optional service from the shared container.
     */
    private function notificationService(): ?object
    {
        if (!class_exists(self::NOTIFICATION_SERVICE)) {
            return null;
        }

        $service = $this->container->get(self::NOTIFICATION_SERVICE);

        return is_object($service) ? $service : null;
    }

    /**
     * HR: Gradi link na objavljenu stranicu ili izravni pregled nacrta.
     * EN: Builds a link to the published page or directly to the draft preview.
     *
     * @param array<string, mixed> $workspace
     * @param array<string, mixed> $node
     */
    private function nodePath(
        array $workspace,
        array $node,
        string $language,
        bool $draftPreview,
    ): string {
        $workspaceSlug = WorkspaceValue::string($workspace['slug'] ?? '');
        $nodeSlug = WorkspaceValue::string($node['slug'] ?? '');
        if ($this->urlGenerator->namedRouteExists('workspace.node.show')) {
            $path = $this->urlGenerator->getPathFor('workspace.node.show', [
                'workspaceSlug' => $workspaceSlug,
                'nodeSlug' => $nodeSlug,
            ]);
        } else {
            $path = rtrim($this->urlGenerator->getBasePath(), '/')
            . '/workspace/'
            . rawurlencode($workspaceSlug)
            . '/'
            . rawurlencode($nodeSlug);
        }

        $query = '?lang=' . rawurlencode($language);
        if ($draftPreview) {
            $query .= '&draft=preview';
        }

        return $path . $query;
    }
}
