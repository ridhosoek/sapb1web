<?php

namespace App\Integration\Sap\Exceptions;

use RuntimeException;

class SapBusinessException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $sapCode = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $technicalMessage = null,
    ) {
        parent::__construct($message);
    }
}
