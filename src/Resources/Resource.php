<?php

declare(strict_types=1);

namespace Finvalda\Resources;

use Finvalda\Concerns\FormatsDate;
use Finvalda\Enums\ItemClass;
use Finvalda\HttpClient;
use Finvalda\Responses\Response;

abstract class Resource
{
    use FormatsDate;

    public function __construct(
        protected readonly HttpClient $http,
    ) {}

    /**
     * Extract a single-entity payload from a response.
     *
     * Single-entity reads (GetPreke, GetKlientas, GetPaslauga) wrap the
     * entity in its item class name: {"Fvs.Preke": {...}} when found,
     * {"Fvs.Preke": null} when not found. Also tolerates unwrapped and
     * single-element list shapes.
     *
     * @return array|null The entity fields, or null when the response
     *                    failed or carries no entity (not found).
     */
    protected function extractEntity(Response $response, ItemClass $itemClass): ?array
    {
        if (! $response->successful()) {
            return null;
        }

        $data = $response->data;

        if (array_key_exists($itemClass->value, $data)) {
            $data = $data[$itemClass->value];
        }

        if (is_array($data) && is_array($data[0] ?? null)) {
            $data = $data[0];
        }

        return is_array($data) && $data !== [] ? $data : null;
    }

    /**
     * Safely encode SDK-owned outbound data to JSON, throwing on failure.
     *
     * @throws \JsonException
     */
    protected function jsonEncode(mixed $data): string
    {
        return $this->http->encodeJson($data);
    }
}
