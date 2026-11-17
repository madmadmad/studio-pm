<?php

namespace App\Services;

use RuntimeException;

// Google refused to renew someone's access (they removed it in their Google
// account, or the account is gone). Their connection has been deleted; they
// connect again from their profile.
class GoogleCalendarDisconnected extends RuntimeException {}
