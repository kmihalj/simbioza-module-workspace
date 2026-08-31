<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleWorkspace\Tests;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\SimbiozaModuleWorkspace\Event\WorkspaceContentChanged;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceValue;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceWorkflowService;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

#[CoversClass(WorkspaceWorkflowService::class)]
#[UsesClass(WorkspaceContentChanged::class)]
#[UsesClass(WorkspaceRepository::class)]
#[UsesClass(WorkspaceValue::class)]
final class WorkspaceWorkflowServiceTest extends TestCase
{
    private WorkspaceWorkflowService $workflow;

    /** @var list<WorkspaceContentChanged> */
    private array $events = [];

    /**
     * HR: Priprema prijenosnu SQLite shemu i jednu dokument-stranicu bez
     *     ovisnosti o host aplikaciji ili stvarnim korisnicima.
     * EN: Prepares a portable SQLite schema and one document page without
     *     depending on the host application or real users.
     */
    protected function setUp(): void
    {
        $helper = new Helper();
        $config = new Config($helper, [
            'database' => [
                'connections' => [
                    'default' => [
                        'driver' => 'sqlite',
                        'database' => ':memory:',
                    ],
                ],
            ],
        ]);
        $database = new Database($config, $helper);
        $migration = require dirname(__DIR__) . '/resources/migrations/initial_workspace_schema.php';
        $this->assertInstanceOf(ReversibleMigrationInterface::class, $migration);
        $migration->up($database);

        $now = '2026-07-18 10:00:00';
        $database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)->insert([
            'uuid' => '00000000-0000-4000-8000-000000000001',
            'workspace_id' => 1,
            'parent_id' => null,
            'node_type' => 'document',
            'slug' => 'upute',
            'title' => 'Upute',
            'document_key' => 'upute',
            'route_name' => null,
            'target_url' => null,
            'sort_order' => 100,
            'is_homepage' => true,
            'is_enabled' => true,
            'created_by_user_id' => 1,
            'updated_by_user_id' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $dispatcher = new class (
            fn(WorkspaceContentChanged $event): WorkspaceContentChanged => $this->events[] = $event,
        ) implements EventDispatcherInterface {
            public function __construct(private readonly \Closure $record)
            {
            }

            public function dispatch(object $event): object
            {
                if ($event instanceof WorkspaceContentChanged) {
                    ($this->record)($event);
                }

                return $event;
            }
        };
        $this->workflow = new WorkspaceWorkflowService(
            new WorkspaceRepository($database, $dispatcher),
        );
    }

    /**
     * HR: Dokazuje da novi nacrt ne zamjenjuje javnu verziju dok upravitelj
     *     izričito ne objavi aktualnu radnu verziju.
     * EN: Proves that a new draft does not replace the public version until a
     *     manager explicitly publishes the current working version.
     */
    public function testDraftRetainsLastPublishedVersionUntilRepublished(): void
    {
        $this->assertSame(0, $this->workflow->publicationVersion('upute', 'hr'));
        $this->assertFalse($this->workflow->isReadable(1, 'hr'));

        $draft = $this->workflow->markDocumentDraft('upute', 'hr', 1, 7);
        $this->assertIsArray($draft);
        $this->assertSame(WorkspaceWorkflowService::STATUS_DRAFT, $draft['status']);
        $this->assertSame(0, $this->workflow->publicationVersion('upute', 'hr'));

        $published = $this->workflow->transition(1, 'hr', 'publish', 1, 7, true, true, true);
        $this->assertSame(WorkspaceWorkflowService::STATUS_PUBLISHED, $published['status']);
        $this->assertSame(1, $this->workflow->publicationVersion('upute', 'hr'));
        $this->assertSame(1, $this->workflow->publicationVersionForNode(1, 'hr'));

        $newDraft = $this->workflow->markDocumentDraft('upute', 'hr', 2, 7);
        $this->assertIsArray($newDraft);
        $this->assertSame(WorkspaceWorkflowService::STATUS_DRAFT, $newDraft['status']);
        $this->assertSame(1, $this->workflow->publicationVersion('upute', 'hr'));

        $publishedEventsBeforeRepublish = count($this->events);

        $review = $this->workflow->transition(1, 'hr', 'submit', 2, 7, true, false, false);
        $this->assertSame(WorkspaceWorkflowService::STATUS_IN_REVIEW, $review['status']);
        $this->assertSame(1, $this->workflow->publicationVersion('upute', 'hr'));

        $republished = $this->workflow->transition(1, 'hr', 'publish', 2, 1, false, true, false);
        $this->assertSame(WorkspaceWorkflowService::STATUS_PUBLISHED, $republished['status']);
        $this->assertSame(2, $this->workflow->publicationVersion('upute', 'hr'));
        $this->assertGreaterThan($publishedEventsBeforeRepublish, count($this->events));
        $this->assertSame('hr', $this->events[array_key_last($this->events)]->language);
    }

    /**
     * HR: Arhiva skriva objavu, a povratak stvara neobjavljeni nacrt koji se
     *     mora ponovno objaviti.
     * EN: Archive hides the publication, while restore creates an unpublished
     *     draft that must be published again.
     */
    public function testArchiveAndRestoreRequireAnewPublication(): void
    {
        $this->workflow->markDocumentDraft('upute', 'en', 3, 1);
        $this->workflow->transition(1, 'en', 'publish', 3, 1, true, true, true);

        $archived = $this->workflow->transition(1, 'en', 'archive', 3, 1, false, false, true);

        $this->assertSame(WorkspaceWorkflowService::STATUS_ARCHIVED, $archived['status']);
        $this->assertSame(0, $this->workflow->publicationVersion('upute', 'en'));
        $this->assertFalse($this->workflow->isReadable(1, 'en'));

        $restored = $this->workflow->transition(1, 'en', 'restore_draft', 3, 1, false, false, true);
        $this->assertSame(WorkspaceWorkflowService::STATUS_DRAFT, $restored['status']);
        $this->assertSame(0, $this->workflow->publicationVersion('upute', 'en'));
    }

    /**
     * HR: Neovlašten prijelaz mora završiti poslovnom greškom bez promjene stanja.
     * EN: An unauthorized transition must fail without changing workflow state.
     */
    public function testUnauthorizedTransitionIsRejected(): void
    {
        $this->workflow->markDocumentDraft('upute', 'hr', 1, 7);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Odabrani prijelaz statusa nije dopušten.');
        $this->workflow->transition(1, 'hr', 'publish', 1, 7, true, false, false);
    }
}
