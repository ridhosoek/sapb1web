<?php

namespace App\Exceptions;

/**
 * A serial chosen when the Goods Issue was submitted is no longer available in SAP just before the POST (BP-12).
 * Nothing was sent to SAP and no other serial is substituted.
 */
class SerialSelectionStaleException extends SapSubmissionException {}
