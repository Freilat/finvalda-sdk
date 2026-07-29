<?php

declare(strict_types=1);

namespace Finvalda\Enums;

/**
 * How credential values appear in recorded exchanges.
 */
enum CredentialMode: string
{
    /** Values become '***'. */
    case Masked = 'masked';

    /** Values become shell placeholders ($FVS_PASSWORD), keeping curl runnable. */
    case Env = 'env';

    /** Values kept verbatim. Never for production logs. */
    case Real = 'real';
}
