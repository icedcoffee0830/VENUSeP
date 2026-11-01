<?php

namespace App\Support;

/* The status words the code compares against, so a typo fails loudly instead of quietly matching nothing. */
final class Status
{
    public const PENDING = 'pending';
    public const RELEASED = 'released';
}
