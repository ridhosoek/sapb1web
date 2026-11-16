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

    /** A timeout or server error can occur after SAP has committed the document. */
    public function isDefiniteRejection(): bool
    {
        return $this->httpStatus !== null
            && $this->httpStatus >= 400
            && $this->httpStatus < 500
            && $this->httpStatus !== 408;
    }

    public function isCompanyConnectionFailure(): bool
    {
        return $this->sapCode === '-103'
            && str_contains(strtolower((string) $this->technicalMessage), 'unable to connect to company');
    }

    /**
     * SAP refuses the posting or document date because its posting period is closed, locked or not defined. The
     * wording is matched on known Service Layer texts and has not been confirmed against the production company.
     */
    public function isPostingPeriodRejection(): bool
    {
        $technical = strtolower((string) $this->technicalMessage);

        return str_contains($technical, 'posting period')
            || str_contains($technical, 'period is locked')
            || str_contains($technical, 'period locked')
            || str_contains($technical, 'permissible range');
    }

    /**
     * SAP refuses to create a document from a base document it still considers to be awaiting approval (-5002).
     */
    public function isBaseDocumentAwaitingApproval(): bool
    {
        return str_contains(strtolower((string) $this->technicalMessage), 'based on documents awaiting approval');
    }
}
