<?php

namespace App\Payments;

use RuntimeException;

/** The provider refused an operation (e.g. refunding more than was captured). */
class PaymentFailed extends RuntimeException {}
