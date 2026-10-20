<?php

namespace App\Services;

use RuntimeException;

/**
 * A booking that is not allowed (message is shown to the user).
 */
class LedgerException extends RuntimeException {}
