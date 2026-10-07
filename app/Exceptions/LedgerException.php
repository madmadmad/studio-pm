<?php

namespace App\Exceptions;

use RuntimeException;

// A ledger rule refused something: an unbalanced entry, a date in a locked
// period, an edit to a posted entry. The message says which, in words fit
// to show the person who tried.
class LedgerException extends RuntimeException {}
