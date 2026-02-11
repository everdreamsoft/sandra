<?php
declare(strict_types=1);

namespace SandraCore\Export;

use SandraCore\EntityFactory;

interface ExporterInterface
{
    public function export(EntityFactory $factory, array $columns = []): string;
}
