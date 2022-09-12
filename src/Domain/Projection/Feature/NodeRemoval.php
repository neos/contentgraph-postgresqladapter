<?php

/*
 * This file is part of the Neos.ContentGraph.PostgreSQLAdapter package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\ContentGraph\PostgreSQLAdapter\Domain\Projection\Feature;

use Doctrine\DBAL\Connection;
use Neos\ContentGraph\PostgreSQLAdapter\Domain\Projection\EventCouldNotBeAppliedToContentGraph;
use Neos\ContentGraph\PostgreSQLAdapter\Domain\Projection\HierarchyHyperrelationRecord;
use Neos\ContentGraph\PostgreSQLAdapter\Domain\Projection\NodeRecord;
use Neos\ContentGraph\PostgreSQLAdapter\Domain\Projection\NodeRelationAnchorPoint;
use Neos\ContentGraph\PostgreSQLAdapter\Domain\Projection\NodeRelationAnchorPoints;
use Neos\ContentGraph\PostgreSQLAdapter\Domain\Projection\ProjectionHypergraph;
use Neos\ContentGraph\PostgreSQLAdapter\Domain\Projection\ReferenceRelationRecord;
use Neos\ContentRepository\Feature\Common\RecursionMode;
use Neos\ContentRepository\Feature\NodeRemoval\Event\NodeAggregateCoverageWasRestored;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Event\NodeAggregateWasRemoved;

/**
 * The node removal feature set for the hypergraph projector
 *
 * @internal
 */
trait NodeRemoval
{
    /**
     * @throws \Throwable
     */
    private function whenNodeAggregateWasRemoved(NodeAggregateWasRemoved $event): void
    {
        $this->transactional(function () use ($event) {
            $affectedRelationAnchorPoints = [];
            // first step: remove hierarchy relations
            foreach ($event->affectedCoveredDimensionSpacePoints as $dimensionSpacePoint) {
                $nodeRecord = $this->getProjectionHypergraph()->findNodeRecordByCoverage(
                    $event->getContentStreamId(),
                    $dimensionSpacePoint,
                    $event->getNodeAggregateId()
                );
                if (is_null($nodeRecord)) {
                    throw EventCouldNotBeAppliedToContentGraph::becauseTheSourceNodeIsMissing(get_class($event));
                }

                /** @var HierarchyHyperrelationRecord $ingoingHierarchyRelation */
                $ingoingHierarchyRelation = $this->getProjectionHypergraph()
                    ->findHierarchyHyperrelationRecordByChildNodeAnchor(
                        $event->getContentStreamId(),
                        $dimensionSpacePoint,
                        $nodeRecord->relationAnchorPoint
                    );
                $ingoingHierarchyRelation->removeChildNodeAnchor(
                    $nodeRecord->relationAnchorPoint,
                    $this->getDatabaseConnection(),
                    $this->tableNamePrefix
                );
                $this->removeFromRestrictions(
                    $event->getContentStreamId(),
                    $dimensionSpacePoint,
                    $event->getNodeAggregateId()
                );

                $affectedRelationAnchorPoints[] = $nodeRecord->relationAnchorPoint;

                $this->cascadeHierarchy(
                    $event->getContentStreamId(),
                    $dimensionSpacePoint,
                    $nodeRecord->relationAnchorPoint,
                    $affectedRelationAnchorPoints
                );
            }

            // second step: remove orphaned nodes
            $this->getDatabaseConnection()->executeStatement(
                /** @lang PostgreSQL */
                '
                WITH deletedNodes AS (
                    DELETE FROM ' . $this->tableNamePrefix . '_node n
                    WHERE n.relationanchorpoint IN (
                        SELECT relationanchorpoint FROM ' . $this->tableNamePrefix . '_node
                            LEFT JOIN ' . $this->tableNamePrefix . '_hierarchyhyperrelation h
                                ON n.relationanchorpoint = ANY(h.childnodeanchors)
                        WHERE n.relationanchorpoint IN (:affectedRelationAnchorPoints)
                            AND h.contentstreamidentifier IS NULL
                    )
                    RETURNING relationanchorpoint
                )
                DELETE FROM ' . $this->tableNamePrefix . '_referencerelation r
                    WHERE sourcenodeanchor IN (SELECT relationanchorpoint FROM deletedNodes)
                ',
                [
                    'affectedRelationAnchorPoints' => $affectedRelationAnchorPoints
                ],
                [
                    'affectedRelationAnchorPoints' => Connection::PARAM_STR_ARRAY
                ]
            );
        });
    }

    /**
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws \Doctrine\DBAL\Exception
     * @param array<int,NodeRelationAnchorPoint> &$affectedRelationAnchorPoints
     */
    private function cascadeHierarchy(
        ContentStreamId $contentStreamIdentifier,
        DimensionSpacePoint $dimensionSpacePoint,
        NodeRelationAnchorPoint $nodeRelationAnchorPoint,
        array &$affectedRelationAnchorPoints
    ): void {
        $childHierarchyRelation = $this->getProjectionHypergraph()->findHierarchyHyperrelationRecordByParentNodeAnchor(
            $contentStreamIdentifier,
            $dimensionSpacePoint,
            $nodeRelationAnchorPoint
        );
        if ($childHierarchyRelation) {
            $childHierarchyRelation->removeFromDatabase($this->getDatabaseConnection(), $this->tableNamePrefix);

            foreach ($childHierarchyRelation->childNodeAnchors as $childNodeAnchor) {
                /** @var NodeRecord $nodeRecord */
                $nodeRecord = $this->getProjectionHypergraph()
                    ->findNodeRecordByRelationAnchorPoint($childNodeAnchor);
                $ingoingHierarchyRelations = $this->getProjectionHypergraph()
                    ->findHierarchyHyperrelationRecordsByChildNodeAnchor($childNodeAnchor);
                if (empty($ingoingHierarchyRelations)) {
                    ReferenceRelationRecord::removeFromDatabaseForSource(
                        $nodeRecord->relationAnchorPoint,
                        $this->getDatabaseConnection(),
                        $this->tableNamePrefix
                    );
                    $affectedRelationAnchorPoints[] = $nodeRecord->relationAnchorPoint;
                }
                $this->removeFromRestrictions(
                    $contentStreamIdentifier,
                    $dimensionSpacePoint,
                    $nodeRecord->nodeAggregateIdentifier
                );
                $this->cascadeHierarchy(
                    $contentStreamIdentifier,
                    $dimensionSpacePoint,
                    $nodeRecord->relationAnchorPoint,
                    $affectedRelationAnchorPoints
                );
            }
        }
    }

    /**
     * @param ContentStreamId $contentStreamIdentifier
     * @param DimensionSpacePoint $dimensionSpacePoint
     * @param NodeAggregateId $nodeAggregateIdentifier
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws \Doctrine\DBAL\Exception
     */
    private function removeFromRestrictions(
        ContentStreamId $contentStreamIdentifier,
        DimensionSpacePoint $dimensionSpacePoint,
        NodeAggregateId $nodeAggregateIdentifier
    ): void {
        foreach (
            $this->getProjectionHypergraph()->findIngoingRestrictionRelations(
                $contentStreamIdentifier,
                $dimensionSpacePoint,
                $nodeAggregateIdentifier
            ) as $restrictionRelation
        ) {
            $restrictionRelation->removeAffectedNodeAggregateIdentifier(
                $nodeAggregateIdentifier,
                $this->getDatabaseConnection(),
                $this->tableNamePrefix
            );
        }
    }

    public function whenNodeAggregateCoverageWasRestored(NodeAggregateCoverageWasRestored $event): void
    {
        $nodeRecord = $this->projectionHypergraph->findNodeRecordByOrigin(
            $event->contentStreamIdentifier,
            $event->sourceDimensionSpacePoint,
            $event->nodeAggregateIdentifier
        );
        if (!$nodeRecord instanceof NodeRecord) {
            throw EventCouldNotBeAppliedToContentGraph::becauseTheSourceNodeIsMissing(get_class($event));
        }

        // create or adjust the target parent's child hierarchy hyperrelations
        foreach ($event->affectedCoveredDimensionSpacePoints as $coveredDimensionSpacePoint) {
            $hierarchyRelation
                = $this->projectionHypergraph->findParentHierarchyHyperrelationRecordByOriginInDimensionSpacePoint(
                    $event->contentStreamIdentifier,
                    $event->sourceDimensionSpacePoint,
                    $coveredDimensionSpacePoint,
                    $event->nodeAggregateIdentifier
                );

            if ($hierarchyRelation instanceof HierarchyHyperrelationRecord) {
                $succeedingSiblingCandidates = $this->projectionHypergraph
                    ->findSucceedingSiblingRelationAnchorPointsByOriginInDimensionSpacePoint(
                        $event->contentStreamIdentifier,
                        $event->sourceDimensionSpacePoint,
                        $coveredDimensionSpacePoint,
                        $event->nodeAggregateIdentifier
                    );
                $hierarchyRelation->addChildNodeAnchorAfterFirstCandidate(
                    $nodeRecord->relationAnchorPoint,
                    $succeedingSiblingCandidates,
                    $this->getDatabaseConnection()
                );
            } else {
                $parentNodeRecord = $this->projectionHypergraph->findParentNodeRecordByOriginInDimensionSpacePoint(
                    $event->contentStreamIdentifier,
                    $event->sourceDimensionSpacePoint,
                    $coveredDimensionSpacePoint,
                    $event->nodeAggregateIdentifier
                );
                if (!$parentNodeRecord instanceof NodeRecord) {
                    throw EventCouldNotBeAppliedToContentGraph::becauseTheTargetParentNodeIsMissing(get_class($event));
                }

                (new HierarchyHyperrelationRecord(
                    $event->contentStreamIdentifier,
                    $parentNodeRecord->relationAnchorPoint,
                    $coveredDimensionSpacePoint,
                    new NodeRelationAnchorPoints(
                        $nodeRecord->relationAnchorPoint
                    )
                ))->addToDatabase($this->getDatabaseConnection());
            }
        }

        // cascade to all descendants
        $this->getDatabaseConnection()->executeStatement(
            /** @lang PostgreSQL */
            '
            /**
             * First, we collect all hierarchy relations to be copied in the restoration process.
             * These are the descendant relations in the origin to be used:
             * parentnodeanchor and childnodeanchors only, the rest will be changed
             */
            WITH RECURSIVE descendantNodes(relationanchorpoint) AS (
                /**
                 * Initial query: find all outgoing child node relations from the starting node in its origin;
                 * which ones are resolved depends on the recursion mode.
                 */
                SELECT
                    n.relationanchorpoint,
                    h.parentnodeanchor
                FROM ' . $this->tableNamePrefix . '_node n
                    JOIN ' . $this->tableNamePrefix . '_hierarchyhyperrelation h
                        ON n.relationanchorpoint = ANY(h.childnodeanchors)
                WHERE h.parentnodeanchor = :relationAnchorPoint
                  AND h.contentstreamidentifier = :contentStreamIdentifier
                  AND h.dimensionspacepointhash = :originDimensionSpacePointHash
                  ' . ($event->recursionMode === RecursionMode::MODE_ONLY_TETHERED_DESCENDANTS
                ? ' AND n.classification = :classification'
                : '') . '

                UNION ALL
                /**
                 * Iteration query: find all outgoing tethered child node relations from the parent node in its origin;
                 * which ones are resolved depends on the recursion mode.
                 */
                SELECT
                    c.relationanchorpoint,
                    h.parentnodeanchor
                FROM
                    descendantNodes p
                    JOIN ' . $this->tableNamePrefix . '_hierarchyhyperrelation h
                        ON h.parentnodeanchor = p.relationanchorpoint
                    JOIN ' . $this->tableNamePrefix . '_node c ON c.relationanchorpoint = ANY(h.childnodeanchors)
                WHERE h.contentstreamidentifier = :contentStreamIdentifier
                    AND h.dimensionspacepointhash = :originDimensionSpacePointHash
                    ' . ($event->recursionMode === RecursionMode::MODE_ONLY_TETHERED_DESCENDANTS
                ? ' AND c.classification = :classification'
                : '') . '
            )
            INSERT INTO ' . $this->tableNamePrefix . '_hierarchyhyperrelation
                SELECT
                    :contentStreamIdentifier AS contentstreamidentifier,
                    parentnodeanchor,
                    CAST(dimensionspacepoint AS json),
                    dimensionspacepointhash,
                    array_agg(relationanchorpoint) AS childnodeanchors
                FROM descendantNodes
                    /** Here we join the affected dimension space points to actually create the new edges */
                    JOIN (
                        SELECT unnest(ARRAY[:dimensionSpacePoints]) AS dimensionspacepoint,
                        unnest(ARRAY[:dimensionSpacePointHashes]) AS dimensionspacepointhash
                    ) dimensionSpacePoints ON true
                GROUP BY parentnodeanchor, dimensionspacepoint, dimensionspacepointhash
            ',
            [
                'contentStreamIdentifier' => (string)$event->contentStreamIdentifier,
                'classification' => NodeAggregateClassification::CLASSIFICATION_TETHERED->value,
                'relationAnchorPoint' => (string)$nodeRecord->relationAnchorPoint,
                'originDimensionSpacePointHash' => $event->sourceDimensionSpacePoint->hash,
                'dimensionSpacePoints' => array_map(
                    fn(DimensionSpacePoint $dimensionSpacePoint): string
                        => json_encode($dimensionSpacePoint, JSON_THROW_ON_ERROR),
                    $event->affectedCoveredDimensionSpacePoints->points
                ),
                'dimensionSpacePointHashes' => $event->affectedCoveredDimensionSpacePoints->getPointHashes()
            ],
            [
                'dimensionSpacePoints' => Connection::PARAM_STR_ARRAY,
                'dimensionSpacePointHashes' => Connection::PARAM_STR_ARRAY
            ]
        );
    }

    abstract protected function getProjectionHypergraph(): ProjectionHypergraph;

    /**
     * @throws \Throwable
     */
    abstract protected function transactional(\Closure $operations): void;

    abstract protected function getDatabaseConnection(): Connection;
}
