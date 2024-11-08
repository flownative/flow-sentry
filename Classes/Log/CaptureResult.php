<?php
declare(strict_types=1);

namespace Flownative\Sentry\Log;

class CaptureResult {
    public function __construct(
        public readonly bool $suceess,
        public readonly string $message,
        public readonly string $eventId
    ) {}
}