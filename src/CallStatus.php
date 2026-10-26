<?php

declare(strict_types=1);

namespace Dskripchenko\Llm;

/**
 * The outcome of one HTTP call to one provider, as written to the call log.
 */
enum CallStatus: string
{
    /** The provider answered and the answer was accepted. */
    case Ok = 'ok';

    /** The provider answered, but the answer did not match the schema. */
    case InvalidOutput = 'invalid_output';

    /** Timeout, connection failure, 5xx or 429: the next route was tried. */
    case Unavailable = 'unavailable';

    /** The provider refused the request (4xx): not retried elsewhere. */
    case Rejected = 'rejected';
}
