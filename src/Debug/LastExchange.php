<?php

declare(strict_types=1);

namespace Finvalda\Debug;

/**
 * The last request/response snapshot captured while debug mode is on. A mutable
 * object rather than two arrays on HttpClient so that a company-scoped client
 * created with HttpClient::withCompanyId() writes into the same snapshot its
 * parent reads from.
 */
final class LastExchange
{
    /** @var array<string, mixed> */
    private array $request = [];

    /** @var array<string, mixed> */
    private array $response = [];

    /**
     * @param  array<string, mixed>  $request
     */
    public function setRequest(array $request): void
    {
        $this->request = $request;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function setResponse(array $response): void
    {
        $this->response = $response;
    }

    /**
     * @return array{request: array<string, mixed>, response: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['request' => $this->request, 'response' => $this->response];
    }

    public function clear(): void
    {
        $this->request = [];
        $this->response = [];
    }
}
