<?php

namespace App\Orders;

use RuntimeException;

/** The order is not (or no longer) in a state that allows this action. */
class InvalidTransition extends RuntimeException {}
