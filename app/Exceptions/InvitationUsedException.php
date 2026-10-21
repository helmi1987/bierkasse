<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The invitation was used by a parallel registration in the meantime.
 */
final class InvitationUsedException extends RuntimeException {}
