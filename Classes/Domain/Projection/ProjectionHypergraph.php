<?php
declare(strict_types=1);

namespace Neos\ContentGraph\PostgreSQLAdapter\Domain\Projection;

/*
 * This file is part of the Neos.ContentGraph.PostgreSQLAdapter package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Neos\ContentGraph\PostgreSQLAdapter\Domain\Projection\Query\ProjectionHypergraphQuery;
use Neos\ContentGraph\PostgreSQLAdapter\Infrastructure\DbalClient;
use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Domain\ContentStream\ContentStreamIdentifier;
use Neos\ContentRepository\Domain\NodeAggregate\NodeAggregateIdentifier;
use Neos\EventSourcedContentRepository\Domain\Context\NodeAggregate\OriginDimensionSpacePoint;
use Neos\EventSourcedContentRepository\Domain\ValueObject\PropertyName;
use Neos\Flow\Annotations as Flow;

/**
 * The alternate reality-aware projection-time hypergraph for the PostgreSQL backend via Doctrine DBAL
 * @Flow\Proxy(false)
 */
final class ProjectionHypergraph
{
    private DbalClient $databaseClient;

    public function __construct(
        DbalClient $databaseClient
    ) {
        $this->databaseClient = $databaseClient;
    }

    /**
     * @param NodeRelationAnchorPoint $relationAnchorPoint
     * @return NodeRecord|null
     * @throws DBALException
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function findNodeRecordByRelationAnchorPoint(
        NodeRelationAnchorPoint $relationAnchorPoint
    ): ?NodeRecord
    {
        $query = /** @lang PostgreSQL */
            'SELECT n.*
            FROM ' . NodeRecord::TABLE_NAME .' n
            WHERE n.relationanchorpoint = :relationAnchorPoint';

        $parameters = [
            'relationanchorpoint' => (string)$relationAnchorPoint
        ];

        $result = $this->getDatabaseConnection()->executeQuery($query, $parameters)->fetchAssociative();

        return $result ? NodeRecord::fromDatabaseRow($result) : null;
    }

    /**
     * @throws \Exception
     */
    public function findNodeRecordByCoverage(
        ContentStreamIdentifier $contentStreamIdentifier,
        DimensionSpacePoint $dimensionSpacePoint,
        NodeAggregateIdentifier $nodeAggregateIdentifier
    ): ?NodeRecord
    {
        $query = ProjectionHypergraphQuery::create($contentStreamIdentifier);
        $query =  $query->withDimensionSpacePoint($dimensionSpacePoint)
            ->withNodeAggregateIdentifier($nodeAggregateIdentifier);
        $result = $query->execute($this->getDatabaseConnection())->fetchAssociative();

        return $result ? NodeRecord::fromDatabaseRow($result) : null;
    }

    /**
     * @throws \Exception
     */
    public function findNodeRecordByOrigin(
        ContentStreamIdentifier $contentStreamIdentifier,
        OriginDimensionSpacePoint $originDimensionSpacePoint,
        NodeAggregateIdentifier $nodeAggregateIdentifier
    ): ?NodeRecord {
        $query = ProjectionHypergraphQuery::create($contentStreamIdentifier);
        $query = $query->withOriginDimensionSpacePoint($originDimensionSpacePoint);
        $query = $query->withNodeAggregateIdentifier($nodeAggregateIdentifier);
        $result = $query->execute($this->getDatabaseConnection())->fetchAssociative();

        return $result ? NodeRecord::fromDatabaseRow($result) : null;
    }

    /**
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param NodeAggregateIdentifier $nodeAggregateIdentifier
     * @param DimensionSpacePointSet $coveredDimensionSpacePoints
     * @return array|NodeRecord[]
     * @throws \Exception
     */
    public function findNodeRecordsForNodeAggregate(
        ContentStreamIdentifier $contentStreamIdentifier,
        NodeAggregateIdentifier $nodeAggregateIdentifier,
        DimensionSpacePointSet $coveredDimensionSpacePoints
    ): array {
        $query = ProjectionHypergraphQuery::create($contentStreamIdentifier);
        $query = $query->withNodeAggregateIdentifier($nodeAggregateIdentifier)
            ->withDimensionSpacePoints($coveredDimensionSpacePoints);

        $result = $query->execute($this->getDatabaseConnection())->fetchAllAssociative();

        return array_map(function ($row) {
            return NodeRecord::fromDatabaseRow($row);
        }, $result);
    }

    /**
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws DBALException
     */
    public function findHierarchyHyperrelationRecordByParentNodeAnchor(
        ContentStreamIdentifier $contentStreamIdentifier,
        DimensionSpacePoint $dimensionSpacePoint,
        NodeRelationAnchorPoint $parentNodeAnchor
    ): ?HierarchyHyperrelationRecord {
        $query = /** @lang PostgreSQL */
            'SELECT h.*
            FROM ' . HierarchyHyperrelationRecord::TABLE_NAME .' h
            WHERE h.contentstreamidentifier = :contentStreamIdentifier
                AND h.dimensionspacepointhash = :dimensionSpacePointHash
                AND h.parentnodeanchor = :parentNodeAnchor';

        $parameters = [
            'contentStreamIdentifier' => (string)$contentStreamIdentifier,
            'dimensionSpacePointHash' => $dimensionSpacePoint->getHash(),
            'parentNodeAnchor' => (string)$parentNodeAnchor
        ];

        $result = $this->getDatabaseConnection()->executeQuery($query, $parameters)->fetchAssociative();

        return $result ? HierarchyHyperrelationRecord::fromDatabaseRow($result) : null;
    }

    /**
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws DBALException
     */
    public function findHierarchyHyperrelationRecordByChildNodeAnchor(
        ContentStreamIdentifier $contentStreamIdentifier,
        DimensionSpacePoint $dimensionSpacePoint,
        NodeRelationAnchorPoint $childNodeAnchor
    ): ?HierarchyHyperrelationRecord {
        $query = /** @lang PostgreSQL */
            'SELECT h.*
            FROM ' . HierarchyHyperrelationRecord::TABLE_NAME .' h
            WHERE h.contentstreamidentifier = :contentStreamIdentifier
                AND h.dimensionspacepointhash = :dimensionSpacePointHash
                AND :childNodeAnchor = ANY(h.childnodeanchors)';

        $parameters = [
            'contentStreamIdentifier' => (string)$contentStreamIdentifier,
            'dimensionSpacePointHash' => $dimensionSpacePoint->getHash(),
            'childNodeAnchor' => (string)$childNodeAnchor
        ];

        $result = $this->getDatabaseConnection()->executeQuery($query, $parameters)->fetchAssociative();

        return $result ? HierarchyHyperrelationRecord::fromDatabaseRow($result) : null;
    }

    /**
     * @return array|HierarchyHyperrelationRecord[]
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws DBALException
     */
    public function findHierarchyHyperrelationRecordsByChildNodeAnchor(
        NodeRelationAnchorPoint $childNodeAnchor
    ): array {
        $query = /** @lang PostgreSQL */
            'SELECT h.*
            FROM ' . HierarchyHyperrelationRecord::TABLE_NAME .' h
            WHERE :childNodeAnchor = ANY(h.childnodeanchors)';

        $parameters = [
            'childNodeAnchor' => (string)$childNodeAnchor
        ];

        $hierarchyRelationRecords = [];
        $result = $this->getDatabaseConnection()->executeQuery($query, $parameters)->fetchAllAssociative();
        foreach ($result as $row) {
            $hierarchyRelationRecords[] = HierarchyHyperrelationRecord::fromDatabaseRow($row);
        }

        return $hierarchyRelationRecords;
    }

    /**
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws DBALException
     */
    public function findChildHierarchyHyperrelationRecord(
        ContentStreamIdentifier $contentStreamIdentifier,
        DimensionSpacePoint $dimensionSpacePoint,
        NodeAggregateIdentifier $nodeAggregateIdentifier
    ): ?HierarchyHyperrelationRecord {
        $query = /** @lang PostgreSQL */
            'SELECT h.*
            FROM ' . HierarchyHyperrelationRecord::TABLE_NAME .' h
            JOIN ' . NodeRecord::TABLE_NAME .' n ON h.parentnodeanchor = n.relationanchorpoint
            WHERE h.contentstreamidentifier = :contentStreamIdentifier
            AND n.nodeaggregateidentifier = :nodeAggregateIdentifier
            AND h.dimensionspacepointhash = :dimensionSpacePointHash';

        $parameters = [
            'contentStreamIdentifier' => (string)$contentStreamIdentifier,
            'nodeAggregateIdentifier' => (string)$nodeAggregateIdentifier,
            'dimensionSpacePointHash' => $dimensionSpacePoint->getHash()
        ];

        $result = $this->getDatabaseConnection()->executeQuery($query, $parameters)->fetchAssociative();

        return $result ? HierarchyHyperrelationRecord::fromDatabaseRow($result) : null;
    }

    /**
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param NodeRelationAnchorPoint $nodeRelationAnchorPoint
     * @return DimensionSpacePointSet
     * @throws DBALException
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function findCoverageByNodeRelationAnchorPoint(
        ContentStreamIdentifier $contentStreamIdentifier,
        NodeRelationAnchorPoint $nodeRelationAnchorPoint
    ): DimensionSpacePointSet {
        $query = /** @lang PostgreSQL */
            'SELECT h.dimensionspacepoint
            FROM ' . HierarchyHyperrelationRecord::TABLE_NAME .' h
            JOIN ' . NodeRecord::TABLE_NAME .' n ON h.parentnodeanchor = n.relationanchorpoint
            WHERE h.contentstreamidentifier = :contentStreamIdentifier
            AND n.relationanchorpoint = :relationAnchorPoint';
        $parameters = [
            'contentStreamIdentifier' => (string)$contentStreamIdentifier,
            'relationanchorpoint' => (string)$nodeRelationAnchorPoint
        ];

        $dimensionSpacePoints = [];
        foreach ($this->getDatabaseConnection()->executeQuery($query, $parameters)->fetchAllAssociative() as $row) {
            $dimensionSpacePoints[] = DimensionSpacePoint::fromJsonString($row['dimensionspacepoint']);
        }

        return DimensionSpacePointSet::fromArray($dimensionSpacePoints);
    }

    /**
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param DimensionSpacePointSet $dimensionSpacePoints
     * @param NodeAggregateIdentifier $nodeAggregateIdentifier
     * @return array|RestrictionHyperrelationRecord[]
     * @throws DBALException
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function findOutgoingRestrictionRelations(
        ContentStreamIdentifier $contentStreamIdentifier,
        DimensionSpacePointSet $dimensionSpacePoints,
        NodeAggregateIdentifier $nodeAggregateIdentifier
    ): array {
        $query = /** @lang PostgreSQL */
            'SELECT r.*
            FROM ' . RestrictionHyperrelationRecord::TABLE_NAME .' r
            WHERE r.contentstreamidentifier = :contentStreamIdentifier
            AND r.dimensionspacepointhash IN :dimensionSpacePointHashes
            AND r.originnodeaggregateidentifier = :nodeAggregateIdentifier';

        $parameters = [
            'contentStreamIdentifier' => (string)$contentStreamIdentifier,
            'dimensionSpacePointHashes' => $dimensionSpacePoints->getPointHashes(),
            'nodeAggregateIdentifier' => (string)$nodeAggregateIdentifier
        ];
        $types = [
            'dimensionSpacePointHashes' => Connection::PARAM_STR_ARRAY
        ];

        $restrictionRelationRecords = [];
        foreach ($this->getDatabaseConnection()->executeQuery($query, $parameters, $types)->fetchAllAssociative() as $row) {
            $restrictionRelationRecords = RestrictionHyperrelationRecord::fromDatabaseRow($row);
        }

        return $restrictionRelationRecords;
    }

    /**
     * @return array|RestrictionHyperrelationRecord[]
     * @throws DBALException
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function findIngoingRestrictionRelations(
        ContentStreamIdentifier $contentStreamIdentifier,
        DimensionSpacePoint $dimensionSpacePoint,
        NodeAggregateIdentifier $nodeAggregateIdentifier
    ): array {
        $query = /** @lang PostgreSQL */
            'SELECT r.*
            FROM ' . RestrictionHyperrelationRecord::TABLE_NAME .' r
            WHERE r.contentstreamidentifier = :contentStreamIdentifier
            AND r.dimensionspacepointhash = :dimensionSpacePointHash
            AND :nodeAggregateIdentifier = ANY(r.affectednodeaggregateidentifiers)';

        $parameters = [
            'contentStreamIdentifier' => (string)$contentStreamIdentifier,
            'dimensionSpacePointHash' => $dimensionSpacePoint->getHash(),
            'nodeAggregateIdentifier' => (string)$nodeAggregateIdentifier
        ];

        $restrictionRelations = [];
        $rows = $this->getDatabaseConnection()->executeQuery($query, $parameters)->fetchAllAssociative();
        foreach ($rows as $row) {
            $restrictionRelations[] = RestrictionHyperrelationRecord::fromDatabaseRow($row);
        }

        return $restrictionRelations;
    }

    /**
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param DimensionSpacePointSet $dimensionSpacePoints
     * @param NodeAggregateIdentifier $nodeAggregateIdentifier
     * @return array|NodeAggregateIdentifiers[]
     * @throws DBALException
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function findDescendantNodeAggregateIdentifiers(
        ContentStreamIdentifier $contentStreamIdentifier,
        DimensionSpacePointSet $dimensionSpacePoints,
        NodeAggregateIdentifier $nodeAggregateIdentifier
    ): array {
        $query = /** @lang PostgreSQL */ '
            -- ProjectionHypergraph::findDescendantNodeAggregateIdentifiers
            WITH RECURSIVE descendantNodes(nodeaggregateidentifier, relationanchorpoint, dimensionspacepointhash) AS (
                    -- --------------------------------
                    -- INITIAL query: select the root nodes
                    -- --------------------------------
                    SELECT
                       n.nodeaggregateidentifier,
                       n.relationanchorpoint,
                       h.dimensionspacepointhash
                    FROM ' . NodeRecord::TABLE_NAME . ' n
                    INNER JOIN ' . HierarchyHyperrelationRecord::TABLE_NAME . ' h ON n.relationanchorpoint = ANY(h.childnodeanchors)
                    WHERE n.nodeaggregateidentifier = :entryNodeAggregateIdentifier
                        AND h.contentstreamidentifier = :contentStreamIdentifier
                        AND h.dimensionspacepointhash IN (:affectedDimensionSpacePointHashes)

                UNION ALL
                    -- --------------------------------
                    -- RECURSIVE query: do one "child" query step
                    -- --------------------------------
                    SELECT
                        c.nodeaggregateidentifier,
                        c.relationanchorpoint,
                        h.dimensionspacepointhash
                    FROM
                        descendantNodes p
                    INNER JOIN ' . HierarchyHyperrelationRecord::TABLE_NAME . ' h ON h.parentnodeanchor = p.relationanchorpoint
                    INNER JOIN neos_contentgraph_node c ON c.relationanchorpoint = ANY(h.childnodeanchors)
                    WHERE
                        h.contentstreamidentifier = :contentStreamIdentifier
                        AND h.dimensionspacepointhash IN (:affectedDimensionSpacePointHashes)
            )
            SELECT nodeaggregateidentifier, dimensionspacepointhash from descendantNodes';

        $parameters = [
            'entryNodeAggregateIdentifier' => (string)$nodeAggregateIdentifier,
            'contentStreamIdentifier' => (string)$contentStreamIdentifier,
            'affectedDimensionSpacePointHashes' => $dimensionSpacePoints->getPointHashes()
        ];

        $types = [
            'affectedDimensionSpacePointHashes' => Connection::PARAM_STR_ARRAY
        ];

        $rows = $this->getDatabaseConnection()->executeQuery($query, $parameters, $types)->fetchAllAssociative();
        $nodeAggregateIdentifiersByDimensionSpacePoint = [];
        foreach ($rows as $row) {
            $nodeAggregateIdentifiersByDimensionSpacePoint[$row['dimensionspacepointhash']][$row['nodeaggregateidentifier']] = NodeAggregateIdentifier::fromString($row['nodeaggregateidentifier']);
        }

        return array_map(function (array $nodeAggregateIdentifiers) {
            return NodeAggregateIdentifiers::fromArray($nodeAggregateIdentifiers);
        }, $nodeAggregateIdentifiersByDimensionSpacePoint);
    }

    public function findReferenceRelationByOrigin(NodeRelationAnchorPoint $origin, PropertyName $name): ?ReferenceHyperrelationRecord
    {
        $query = /** @lang PostgreSQL */
            'SELECT ref.*
            FROM ' . ReferenceHyperrelationRecord::TABLE_NAME .' ref
            WHERE ref.originnodeanchor = :originNodeAnchor
            AND ref.name = :name';

        $parameters = [
            'originNodeAnchor' => (string)$origin,
            'name' => (string)$name
        ];

        $row = $this->getDatabaseConnection()->executeQuery($query, $parameters)->fetchAssociative();

        return $row ? ReferenceHyperrelationRecord::fromDatabaseRow($row) : null;
    }

    protected function getDatabaseConnection(): Connection
    {
        return $this->databaseClient->getConnection();
    }
}
