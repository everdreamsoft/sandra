<?php
declare(strict_types=1);

namespace SandraCore\Search;

use SandraCore\EntityFactory;

interface SearchInterface
{
    /**
     * Search across all references of entities in a factory.
     *
     * @return \SandraCore\Entity[]
     */
    public function search(EntityFactory $factory, string $query, int $limit = 50): array;

    /**
     * Search within a specific reference field.
     *
     * @return \SandraCore\Entity[]
     */
    public function searchByField(EntityFactory $factory, string $field, string $query, int $limit = 50): array;
}
