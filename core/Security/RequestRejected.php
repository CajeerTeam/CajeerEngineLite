<?php
declare(strict_types=1);
namespace CajeerEngine\Security;

/** A bounded, safe client failure with an explicit HTTP status. */
final class RequestRejected extends \RuntimeException
{
    public function __construct(public readonly int $httpStatus, string $message)
    {
        parent::__construct($message);
    }
}
