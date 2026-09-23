<?php

namespace App\Exceptions;

use RuntimeException;

/** Base for every way a posting can be refused. All are programmer errors. */
class LedgerException extends RuntimeException {}
