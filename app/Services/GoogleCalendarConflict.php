<?php

namespace App\Services;

use RuntimeException;

// A save or delete refused because the event changed in Google since the
// person opened it (its etag no longer matches): they see the latest instead
// of overwriting someone else's change.
class GoogleCalendarConflict extends RuntimeException {}
