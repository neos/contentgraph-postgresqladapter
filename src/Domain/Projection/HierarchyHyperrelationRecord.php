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

namespace Neos\ContentGraph\PostgreSQLAdapter\Domain\Projection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\SharedModel\Workspace\ContentStreamIdentifier;
use Neos\Flow\Annotations as Flow;

/**
 * The active record for reading and writing hierarchy hyperrelations from and to the database
 *
 * @Flow\Proxy(false)
 */
final class HierarchyHyperrelationRecord
{
    public const TABLE_NAME = 'neos_contentgraph_hierarchyhyperrelation';

    public function __construct(
        public ContentStreamIdentifier $contentStreamIdentifier,
        public NodeRelationAnchorPoint $parentNodeAnchor,
        public DimensionSpacePoint $dimensionSpacePoint,
        /** The child node relation anchor points, indexed by sorting position */
        public NodeRelationAnchorPoints $childNodeAnchors
    ) {
    }

    /**
     * @param array<string,string> $databaseRow
     */
    public static function fromDatabaseRow(array $databaseRow): self
    {
        return new self(
            ContentStreamIdentifier::fromString($databaseRow['contentstreamidentifier']),
            NodeRelationAnchorPoint::fromString($databaseRow['parentnodeanchor']),
            DimensionSpacePoint::fromJsonString($databaseRow['dimensionspacepoint']),
            NodeRelationAnchorPoints::fromDatabaseString(
                $databaseRow['childnodeanchors']
            )
        );
    }

    public function replaceParentNodeAnchor(
        NodeRelationAnchorPoint $newParentNodeAnchor,
        Connection $databaseConnection
    ): void {
        /** @todo do this directly in the database */
        $databaseConnection->update(
            self::TABLE_NAME,
            [
                'parentnodeanchor' => (string)$newParentNodeAnchor
            ],
            $this->getDatabaseIdentifier()
        );
        $this->parentNodeAnchor = $newParentNodeAnchor;
    }

    public function replaceChildNodeAnchor(
        NodeRelationAnchorPoint $oldChildNodeAnchor,
        NodeRelationAnchorPoint $newChildNodeAnchor,
        Connection $databaseConnection
    ): void {
        /** @todo do this directly in the database */
        $childNodeAnchors = $this->childNodeAnchors->replace(
            $oldChildNodeAnchor,
            $newChildNodeAnchor
        );
        $this->updateChildNodeAnchors($childNodeAnchors, $databaseConnection);
    }

    public function addChildNodeAnchor(
        NodeRelationAnchorPoint $childNodeAnchor,
        ?NodeRelationAnchorPoint $succeedingSiblingAnchor,
        Connection $databaseConnection
    ): void {
        /** @todo do this directly in the database */
        $childNodeAnchors = $this->childNodeAnchors->add(
            $childNodeAnchor,
            $succeedingSiblingAnchor
        );
        $this->updateChildNodeAnchors($childNodeAnchors, $databaseConnection);
    }

    /**
     * There might be multiple candidates for the role of succeeding sibling.
     * The first match from the actually available ones will be selected.
     */
    public function addChildNodeAnchorAfterFirstCandidate(
        NodeRelationAnchorPoint $childNodeAnchor,
        NodeRelationAnchorPoints $succeedingSiblingCandidates,
        Connection $databaseConnection
    ): void {
        $succeedingSiblingAnchor = null;
        if (!$succeedingSiblingCandidates->isEmpty()) {
            foreach ($this->childNodeAnchors as $childNodeAnchor) {
                if ($succeedingSiblingCandidates->contains($childNodeAnchor)) {
                    $succeedingSiblingAnchor = $childNodeAnchor;
                    break;
                }
            }
        }

        $this->addChildNodeAnchor(
            $childNodeAnchor,
            $succeedingSiblingAnchor,
            $databaseConnection
        );
    }

    public function removeChildNodeAnchor(
        NodeRelationAnchorPoint $childNodeAnchor,
        Connection $databaseConnection
    ): void {
        /** @todo do this directly in the database */
        $childNodeAnchors = $this->childNodeAnchors->remove($childNodeAnchor);
        if (count($childNodeAnchors) === 0) {
            $this->removeFromDatabase($databaseConnection);
        } else {
            $this->updateChildNodeAnchors($childNodeAnchors, $databaseConnection);
        }
    }

    private function updateChildNodeAnchors(
        NodeRelationAnchorPoints $childNodeAnchors,
        Connection $databaseConnection
    ): void {
        $databaseConnection->update(
            self::TABLE_NAME,
            [
                'childnodeanchors' => $childNodeAnchors->toDatabaseString()
            ],
            $this->getDatabaseIdentifier()
        );
        $this->childNodeAnchors = $childNodeAnchors;
    }

    /**
     * @throws DBALException
     */
    public function addToDatabase(Connection $databaseConnection): void
    {
        $databaseConnection->insert(
            self::TABLE_NAME,
            [
                'contentstreamidentifier' => $this->contentStreamIdentifier,
                'parentnodeanchor' => $this->parentNodeAnchor,
                'dimensionspacepoint' => \json_encode($this->dimensionSpacePoint),
                'dimensionspacepointhash' => $this->dimensionSpacePoint->hash,
                'childnodeanchors' => $this->childNodeAnchors->toDatabaseString()
            ]
        );
    }

    /**
     * @throws DBALException
     */
    public function removeFromDatabase(Connection $databaseConnection): void
    {
        $databaseConnection->delete(self::TABLE_NAME, $this->getDatabaseIdentifier());
    }

    /**
     * @return array<string,string>
     */
    public function getDatabaseIdentifier(): array
    {
        return [
            'contentstreamidentifier' => (string)$this->contentStreamIdentifier,
            'parentnodeanchor' => (string)$this->parentNodeAnchor,
            'dimensionspacepointhash' => $this->dimensionSpacePoint->hash
        ];
    }
}
