<?php
declare(strict_types=1);

namespace SandraCore\Api;

class ApiRequest
{
    private string $method;
    private string $path;
    private array $query;
    private array $body;

    public function __construct(string $method, string $path, array $query = [], array $body = [])
    {
        $this->method = strtoupper($method);
        $this->path = '/' . ltrim($path, '/');
        $this->query = $query;
        $this->body = $body;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): array
    {
        return $this->query;
    }

    public function getBody(): array
    {
        return $this->body;
    }
}
