<?php

namespace App\Services;

enum MagicLinkResult: string
{
    case Valid = 'valid';
    case AlreadyUsed = 'already_used';
    case Expired = 'expired';
    case Invalid = 'invalid';
}
