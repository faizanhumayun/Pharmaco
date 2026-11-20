<?php

namespace App\Domain\Migration;

use RuntimeException;

/** The old POS file could not be read, said in words the back office can act on. */
class OldPosException extends RuntimeException {}
