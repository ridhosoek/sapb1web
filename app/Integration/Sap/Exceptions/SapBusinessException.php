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

    /**
     * SAP refuses to create a document from a base document it still considers to be awaiting approval (-5002).
     */
    public function isBaseDocumentAwaitingApproval(): bool
    {
        return str_contains(strtolower((string) $this->technicalMessage), 'based on documents awaiting approval');
    }
}
