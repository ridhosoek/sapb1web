<?php

namespace App\Domain\Sap\Enums;

enum IntegrationStatus: string
{
    case PendingPost = 'PENDING_POST';
    case Posting = 'POSTING';
    case PostingUnknown = 'POSTING_UNKNOWN';
    case InSync = 'IN_SYNC';
    case ChangeDetected = 'CHANGE_DETECTED';
    case Syncing = 'SYNCING';
    case SapError = 'SAP_ERROR';
}
