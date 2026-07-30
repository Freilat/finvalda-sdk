<?php

declare(strict_types=1);

namespace Finvalda\Debug;

use Finvalda\Enums\CredentialMode;
use Finvalda\Recording\Recorder;
use Psr\Log\LoggerInterface;

/**
 * The observability state of a client: PSR-3 logger, debug flag, the debug-mode
 * snapshot and the recording buffer. One instance is shared by a client and every
 * company-scoped copy made with HttpClient::withCompanyId(), so switching logging,
 * debug or recording on or off at any time reaches all of them, and recorded
 * exchanges land in one ordered history.
 */
final class Diagnostics
{
    private bool $debug = false;

    private ?Recorder $recorder = null;

    private LastExchange $lastExchange;

    public function __construct(private ?LoggerInterface $logger = null)
    {
        $this->lastExchange = new LastExchange();
    }

    public function logger(): ?LoggerInterface
    {
        return $this->logger;
    }

    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function debugEnabled(): bool
    {
        return $this->debug;
    }

    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;

        if (! $debug) {
            $this->lastExchange->clear();
        }
    }

    public function lastExchange(): LastExchange
    {
        return $this->lastExchange;
    }

    public function recorder(): ?Recorder
    {
        return $this->recorder;
    }

    public function startRecording(int $limit, CredentialMode $credentials): void
    {
        $this->recorder = new Recorder($limit, $credentials);
    }

    public function stopRecording(): void
    {
        $this->recorder = null;
    }
}
