<?php
declare(strict_types=1);

namespace SandraCore\Import;

use SandraCore\EntityFactory;
use SandraCore\Exception\SandraException;

class CsvImporter
{
    private string $delimiter;
    private string $enclosure;
    private bool $hasHeader;
    private ?array $columnMapping = null;

    public function __construct(string $delimiter = ',', string $enclosure = '"', bool $hasHeader = true)
    {
        $this->delimiter = $delimiter;
        $this->enclosure = $enclosure;
        $this->hasHeader = $hasHeader;
    }

    public function setColumnMapping(array $mapping): self
    {
        $this->columnMapping = $mapping;
        return $this;
    }

    public function importString(EntityFactory $factory, string $csv): ImportResult
    {
        $result = new ImportResult();
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $this->processStream($stream, $factory, $result);

        fclose($stream);
        return $result;
    }

    public function importFile(EntityFactory $factory, string $filePath): ImportResult
    {
        if (!file_exists($filePath)) {
            throw new SandraException("Import file not found: $filePath");
        }

        $result = new ImportResult();
        $stream = fopen($filePath, 'r');

        $this->processStream($stream, $factory, $result);

        fclose($stream);
        return $result;
    }

    private function processStream($stream, EntityFactory $factory, ImportResult $result): void
    {
        $headers = null;
        $rowNum = 0;
        $dataRows = 0;

        while (($row = fgetcsv($stream, 0, $this->delimiter, $this->enclosure)) !== false) {
            $rowNum++;

            // Skip empty rows
            if (count($row) === 1 && ($row[0] === null || $row[0] === '')) {
                continue;
            }

            // First row is header
            if ($this->hasHeader && $headers === null) {
                $headers = $row;
                continue;
            }

            $dataRows++;

            $dataArray = $this->mapRowToData($row, $headers);

            try {
                $entity = $factory->createNew($dataArray);
                $result->addCreated($entity);
            } catch (\Throwable $e) {
                $result->addError($rowNum, $dataArray, $e->getMessage());
            }
        }

        $result->setTotalRows($dataRows);
    }

    private function mapRowToData(array $row, ?array $headers): array
    {
        $data = [];

        if ($this->columnMapping !== null) {
            foreach ($this->columnMapping as $source => $refName) {
                if (is_int($source)) {
                    // Index-based mapping
                    $data[$refName] = $row[$source] ?? '';
                } elseif ($headers !== null) {
                    // Header-name-based mapping
                    $index = array_search($source, $headers);
                    if ($index !== false) {
                        $data[$refName] = $row[$index] ?? '';
                    }
                }
            }
        } elseif ($headers !== null) {
            // Use headers directly as ref shortnames
            foreach ($headers as $i => $header) {
                $data[$header] = $row[$i] ?? '';
            }
        }

        return $data;
    }
}
