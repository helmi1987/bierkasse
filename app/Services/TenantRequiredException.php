<?php

namespace App\Services;

use RuntimeException;

/** A tenant-owned row was created without a current tenant (multi mode without tenant context). */
class TenantRequiredException extends RuntimeException {}
