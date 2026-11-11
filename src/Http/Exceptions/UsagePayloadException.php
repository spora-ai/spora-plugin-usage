<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Http\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The refusal a payload earns.
 *
 * Its own type because a refusal is a fact about the payload rather than
 * about the response: the error code, the message and the status travel
 * together, and the controller turns them into the envelope the API
 * answers with. A reader hunting for the cause should not have to know
 * that "some RuntimeException" is the only one this path can throw.
 */
final class UsagePayloadException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = Response::HTTP_UNPROCESSABLE_ENTITY,
    ) {
        parent::__construct($message);
    }
}
