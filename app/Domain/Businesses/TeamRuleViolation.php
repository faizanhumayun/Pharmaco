<?php

namespace App\Domain\Businesses;

use DomainException;

/**
 * A change to who works in a business that would leave it in a bad state —
 * no owner, an owner locked out by their own hand, the App Owner made a
 * member. The message is written for the person making the change.
 */
class TeamRuleViolation extends DomainException {}
