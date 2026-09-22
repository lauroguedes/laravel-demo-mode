<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Exceptions;

use RuntimeException;

/**
 * The base every exception in this package extends, so an application can catch
 * the whole package with one class.
 */
class DemoModeException extends RuntimeException {}
