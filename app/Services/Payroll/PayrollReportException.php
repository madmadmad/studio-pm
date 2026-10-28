<?php

namespace App\Services\Payroll;

use RuntimeException;

// A payroll report that can't be read or doesn't add up. Its message says
// which, in words for the person uploading it.
class PayrollReportException extends RuntimeException {}
