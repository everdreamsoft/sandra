<?php
declare(strict_types=1);

namespace SandraCore\Export;

use SandraCore\EntityFactory;

class CsvExporter implements ExporterInterface
{
    private string $delimiter;
    private string $enclosure;
    private bool $includeHeader;

    public function __construct(string $delimiter = ',', string $enclosure = '"', bool $includeHeader = true)
    {
        $this->delimiter = $delimiter;
        $this->enclosure = $enclosure;
        $this->includeHeader = $includeHeader;
    }

    public function export(EntityFactory $factory, array $columns = []): string
    {
        if (empty($columns)) {
            $columns = $this->autoDetectColumns($factory);
        }

        $stream = fopen('php://temp', 'r+');

        if ($this->includeHeader) {
            fputcsv($stream, $columns, $this->delimiter, $this->enclosure);
        }

        foreach ($factory->getEntities() as $entity) {
            $row = [];
            foreach ($columns as $col) {
                $value = $entity->get($col);
                $row[] = $value ?? '';
            }
            fputcsv($stream, $row, $this->delimiter, $this->enclosure);
        }

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        return $output;
    }

    public function exportToFile(EntityFactory $factory, string $filePath, array $columns = []): int
    {
        $csv = $this->export($factory, $columns);
        return file_put_contents($filePath, $csv);
    }

    private function autoDetectColumns(EntityFactory $factory): array
    {
        $columns = [];
        $refMap = $factory->getReferenceMap();

        if (!is_array($refMap)) {
            return $columns;
        }

        foreach ($refMap as $concept) {
            $shortname = $concept->getShortname();
            if ($shortname === 'creationTimestamp') {
                continue;
            }
            $columns[] = $shortname;
        }

        return $columns;
    }
}
