<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Storage;

/**
 * Paginated result for flow runs listing.
 *
 * @template T
 */
final class PaginatedResult
{
    /**
     * @param T[] $items  The items for the current page
     * @param int $total  Total number of items across all pages
     * @param int $limit  Items per page
     * @param int $offset Starting offset (0-indexed)
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $limit,
        public readonly int $offset,
    ) {
    }

    /**
     * Check if there are more items after this page.
     */
    public function hasMore(): bool
    {
        return ($this->offset + \count($this->items)) < $this->total;
    }

    /**
     * Get the current page number (1-indexed).
     */
    public function getPage(): int
    {
        if (0 === $this->limit) {
            return 1;
        }

        return (int) floor($this->offset / $this->limit) + 1;
    }

    /**
     * Get the total number of pages.
     */
    public function getTotalPages(): int
    {
        if (0 === $this->limit || 0 === $this->total) {
            return 1;
        }

        return (int) ceil($this->total / $this->limit);
    }

    /**
     * Get the number of items on this page.
     */
    public function getCount(): int
    {
        return \count($this->items);
    }
}
