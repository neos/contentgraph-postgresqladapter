<?php

declare(strict_types=1);

namespace Neos\ContentGraph\PostgreSQLAdapter\Domain\Projection\Feature;

use Doctrine\DBAL\Connection;
use Neos\ContentGraph\PostgreSQLAdapter\ContentGraphTableNames;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Event\RootWorkspaceWasCreated;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Event\WorkspaceWasCreated;
use Neos\ContentRepository\Core\Feature\WorkspaceModification\Event\WorkspaceBaseWorkspaceWasChanged;
use Neos\ContentRepository\Core\Feature\WorkspaceModification\Event\WorkspaceWasRemoved;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Event\WorkspaceWasDiscarded;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Event\WorkspaceWasPublished;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Event\WorkspaceRebaseFailed;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Event\WorkspaceWasRebased;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\EventStore\Model\Event\Version;
use Neos\EventStore\Model\EventEnvelope;

trait Workspace
{
    // ### ----------- event dispatchers
    private function whenRootWorkspaceWasCreated(RootWorkspaceWasCreated $event, EventEnvelope $eventEnvelope): void
    {
        $this->createWorkspace($event->workspaceName, null, $event->newContentStreamId, $eventEnvelope->version);
    }

    private function whenWorkspaceWasCreated(WorkspaceWasCreated $event, EventEnvelope $eventEnvelope): void
    {
        $this->createWorkspace($event->workspaceName, $event->baseWorkspaceName, $event->newContentStreamId, $eventEnvelope->version);
    }

    private function whenWorkspaceWasDiscarded(WorkspaceWasDiscarded $event, EventEnvelope $eventEnvelope): void
    {
        $this->updateWorkspaceContentStreamId($event->workspaceName, $event->newContentStreamId, $eventEnvelope->version);
    }

    private function whenWorkspaceWasPublished(WorkspaceWasPublished $event, EventEnvelope $eventEnvelope): void
    {
        $this->updateWorkspaceContentStreamId($event->sourceWorkspaceName, $event->newSourceContentStreamId, $eventEnvelope->version);
    }

    private function whenWorkspaceWasRebased(WorkspaceWasRebased $event, EventEnvelope $eventEnvelope): void
    {
        $this->updateWorkspaceContentStreamId($event->workspaceName, $event->newContentStreamId, $eventEnvelope->version);
    }

    private function whenWorkspaceBaseWorkspaceWasChanged(WorkspaceBaseWorkspaceWasChanged $event, EventEnvelope $eventEnvelope): void
    {
        $this->updateBaseWorkspace($event->workspaceName, $event->baseWorkspaceName, $event->newContentStreamId, $eventEnvelope->version);
    }

    private function whenWorkspaceRebaseFailed(WorkspaceRebaseFailed $event): void
    {
        // legacy handling:
        // before https://github.com/neos/neos-development-collection/pull/4965 this event was emitted and set the content stream status to `REBASE_ERROR`
        // instead of setting the error state on replay for old events we make it almost behave like if the rebase had failed today: reopen the workspaces content stream id
        // the candidateContentStreamId will be removed by the ContentStreamPruner
        $this->reopenContentStream($event->sourceContentStreamId);
    }

    private function whenWorkspaceWasRemoved(WorkspaceWasRemoved $event): void
    {
        $this->removeWorkspace($event->workspaceName);
    }

    // ### ----------- internal API

    private function createWorkspace(WorkspaceName $workspaceName, ?WorkspaceName $baseWorkspaceName, ContentStreamId $contentStreamId, Version $version): void
    {
        $this->getDatabaseConnection()->insert(
            $this->getTableNames()->workspace(),
            [
                'name' => $workspaceName->value,
                'baseworkspacename' => $baseWorkspaceName?->value,
                'currentcontentstreamid' => $contentStreamId->value,
                'version' => $version->value
            ]
        );
    }

    private function removeWorkspace(WorkspaceName $workspaceName): void
    {
        $this->getDatabaseConnection()->delete(
            $this->getTableNames()->workspace(),
            ['name' => $workspaceName->value]
        );
    }

    private function updateBaseWorkspace(WorkspaceName $workspaceName, WorkspaceName $baseWorkspaceName, ContentStreamId $newContentStreamId, Version $version): void
    {
        $this->getDatabaseConnection()->update(
            $this->getTableNames()->workspace(),
            [
                'baseworkspacename' => $baseWorkspaceName->value,
                'currentcontentstreamid' => $newContentStreamId->value,
                'version' => $version->value,
            ],
            ['name' => $workspaceName->value]
        );
    }

    private function updateWorkspaceContentStreamId(
        WorkspaceName $workspaceName,
        ContentStreamId $contentStreamId,
        Version $version,
    ): void {
        $this->getDatabaseConnection()->update($this->getTableNames()->workspace(), [
            'currentcontentstreamid' => $contentStreamId->value,
            'version' => $version->value,
        ], [
            'name' => $workspaceName->value
        ]);
    }

    abstract protected function getDatabaseConnection(): Connection;

    abstract protected function getTableNames(): ContentGraphTableNames;
}
