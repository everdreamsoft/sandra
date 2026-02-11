<?php
declare(strict_types=1);

namespace SandraCore\Api;

class ApiResponse
{
    private int $status;
    private array $data;
    private ?string $error;

    public function __construct(int $status, array $data = [], ?string $error = null)
    {
        $this->status = $status;
        $this->data = $data;
        $this->error = $error;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function toJson(): string
    {
        $payload = [];

        if ($this->error !== null) {
            $payload['error'] = $this->error;
        }

        if (!empty($this->data) || $this->error === null) {
            $payload['data'] = $this->data;
        }

        $payload['status'] = $this->status;

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
