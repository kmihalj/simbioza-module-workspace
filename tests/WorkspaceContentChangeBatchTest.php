<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleWorkspace\Tests;

use AaiEduHr\HeartPhrameModuleWorkspace\Event\WorkspaceContentChanged;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceContentChangeBatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

#[CoversClass(WorkspaceContentChangeBatch::class)]
#[UsesClass(WorkspaceContentChanged::class)]
final class WorkspaceContentChangeBatchTest extends TestCase
{
    /**
     * HR: Više promjena istog područja pokreće samo jednu završnu obnovu,
     *     dok se drugo područje zadržava kao zasebna cjelina.
     * EN: Multiple changes in one Workspace trigger only one final refresh,
     *     while another Workspace remains a separate unit.
     */
    public function testBulkChangesAreConsolidatedPerWorkspace(): void
    {
        $dispatcher = new class () implements EventDispatcherInterface {
            /** @var list<WorkspaceContentChanged> */
            private array $events = [];

            public function dispatch(object $event): object
            {
                if ($event instanceof WorkspaceContentChanged) {
                    $this->events[] = $event;
                }

                return $event;
            }

            /** @return list<WorkspaceContentChanged> */
            public function events(): array
            {
                return $this->events;
            }
        };
        $batch = new WorkspaceContentChangeBatch($dispatcher);

        $result = $batch->run(function () use ($batch, $dispatcher): string {
            $batch->publish(new WorkspaceContentChanged(7, 'node_updated', 10, 'hr', 3));
            $batch->publish(new WorkspaceContentChanged(7, 'publication_changed', 10, 'hr', 4));
            $batch->run(function () use ($batch): void {
                $batch->publish(new WorkspaceContentChanged(8, 'node_updated', 20, 'en', 5));
            });
            $this->assertSame([], $dispatcher->events());

            return 'completed';
        });

        $this->assertSame('completed', $result);
        $events = $dispatcher->events();
        $this->assertCount(2, $events);
        $this->assertSame(7, $events[0]->workspaceId);
        $this->assertSame('bulk_content_changed', $events[0]->reason);
        $this->assertNull($events[0]->nodeId);
        $this->assertNull($events[0]->language);
        $this->assertSame(4, $events[0]->actorUserId);
        $this->assertSame(8, $events[1]->workspaceId);
    }

    /**
     * HR: Djelomično uspjele promjene svejedno osvježavaju izvedene indekse
     *     prije ponovnog bacanja izvorne iznimke.
     * EN: Partially successful changes still refresh derived indexes before
     *     the original exception is rethrown.
     */
    public function testPendingChangesAreFlushedWhenOperationFails(): void
    {
        $dispatcher = new class () implements EventDispatcherInterface {
            /** @var list<WorkspaceContentChanged> */
            private array $events = [];

            public function dispatch(object $event): object
            {
                if ($event instanceof WorkspaceContentChanged) {
                    $this->events[] = $event;
                }

                return $event;
            }

            /** @return list<WorkspaceContentChanged> */
            public function events(): array
            {
                return $this->events;
            }
        };
        $batch = new WorkspaceContentChangeBatch($dispatcher);

        $caught = null;
        try {
            $batch->run(function () use ($batch): never {
                $batch->publish(new WorkspaceContentChanged(9, 'node_updated', 30, 'hr', 6));
                throw new RuntimeException('test failure');
            });
        } catch (RuntimeException $runtimeException) {
            $caught = $runtimeException;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertSame('test failure', $caught->getMessage());
        $events = $dispatcher->events();
        $this->assertCount(1, $events);
        $this->assertSame(9, $events[0]->workspaceId);
        $this->assertSame('bulk_content_changed', $events[0]->reason);
    }

    /**
     * HR: Uspješan nastavivi dio ne šalje skupi signal, dok neuspješan dio
     *     ipak usklađuje izvedene podatke s djelomično spremljenim sadržajem.
     * EN: A successful resumable part emits no expensive signal, while a
     *     failed part still aligns derived data with partially stored content.
     */
    public function testDeferredPartsDiscardSuccessAndFlushFailure(): void
    {
        $dispatcher = new class () implements EventDispatcherInterface {
            /** @var list<WorkspaceContentChanged> */
            public array $events = [];

            public function dispatch(object $event): object
            {
                if ($event instanceof WorkspaceContentChanged) {
                    $this->events[] = $event;
                }

                return $event;
            }

            /**
             * HR: Vraća posljednji zabilježeni događaj bez pretpostavke da lista nije prazna.
             * EN: Returns the last captured event without assuming that the list is non-empty.
             */
            public function lastEvent(): ?WorkspaceContentChanged
            {
                $event = end($this->events);

                return $event instanceof WorkspaceContentChanged ? $event : null;
            }
        };
        $batch = new WorkspaceContentChangeBatch($dispatcher);

        $batch->runDeferred(function () use ($batch): void {
            $batch->publish(new WorkspaceContentChanged(17, 'node_updated', 70, 'hr', 8));
        });
        $this->assertSame([], $dispatcher->events);

        try {
            $batch->runDeferred(function () use ($batch): never {
                $batch->publish(new WorkspaceContentChanged(18, 'node_updated', 80, 'hr', 9));
                throw new RuntimeException('deferred failure');
            });
        } catch (RuntimeException) {
            // HR: Iznimka je očekivani dio ovog testa. EN: The exception is expected in this test.
        }

        $this->assertCount(1, $dispatcher->events);
        $lastEvent = $dispatcher->lastEvent();
        $this->assertInstanceOf(WorkspaceContentChanged::class, $lastEvent);
        $this->assertSame(18, $lastEvent->workspaceId);
        $this->assertSame(9, $lastEvent->actorUserId);
    }
}
