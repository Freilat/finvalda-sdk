<?php

declare(strict_types=1);

namespace Finvalda\Recording;

use Finvalda\Enums\CredentialMode;

/**
 * Bounded in-memory history of request/response exchanges. Credentials are
 * substituted as exchanges are recorded, so the buffer never holds real secrets
 * unless CredentialMode::Real was explicitly requested.
 */
final class Recorder
{
    private readonly int $limit;

    /** @var list<Exchange> */
    private array $exchanges = [];

    /**
     * @param  int  $limit  Maximum exchanges kept; the oldest are dropped first
     * @param  CredentialMode  $credentials  How credential values appear in recordings
     */
    public function __construct(
        int $limit = 20,
        private readonly CredentialMode $credentials = CredentialMode::Masked,
    ) {
        $this->limit = max(1, $limit);
    }

    public function record(Exchange $exchange): void
    {
        $this->exchanges[] = $exchange->withCredentials($this->credentials);

        if (count($this->exchanges) > $this->limit) {
            $this->exchanges = array_slice($this->exchanges, -$this->limit);
        }
    }

    /**
     * Recorded exchanges, oldest first.
     *
     * @return list<Exchange>
     */
    public function all(): array
    {
        return $this->exchanges;
    }

    public function last(): ?Exchange
    {
        if ($this->exchanges === []) {
            return null;
        }

        return $this->exchanges[count($this->exchanges) - 1];
    }
}
