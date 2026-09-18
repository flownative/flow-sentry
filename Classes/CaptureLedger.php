<?php
declare(strict_types=1);

namespace Flownative\Sentry;

/*
 * This file is part of the Flownative.Sentry package.
 *
 * (c) Robert Lemke, Flownative GmbH - www.flownative.com
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Throwable;
use WeakMap;

/**
 * Per-process ledger of throwables that already produced an accepted Sentry
 * event. Prevents duplicate events from log-then-rethrow patterns (e.g.
 * Flow's Doctrine Query wrapper or job queue wrappers): a throwable — or a
 * wrapper carrying it anywhere in its getPrevious() chain — is only
 * captured once.
 *
 * Backed by a WeakMap, so entries vanish with the throwable; retried jobs
 * construct new throwable instances and are captured per attempt.
 */
final class CaptureLedger
{
    private WeakMap $capturedThrowables;

    public function __construct()
    {
        $this->capturedThrowables = new WeakMap();
    }

    public function hasBeenCaptured(Throwable $throwable): bool
    {
        $seenObjectIds = [];
        for ($current = $throwable; $current !== null; $current = $current->getPrevious()) {
            $objectId = spl_object_id($current);
            if (isset($seenObjectIds[$objectId])) {
                break;
            }
            $seenObjectIds[$objectId] = true;
            if (isset($this->capturedThrowables[$current])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Only call this after a capture was ACCEPTED (not excluded, not dropped
     * by a scrubber without replacement) — a rejected capture must not
     * suppress a later legitimate one.
     */
    public function remember(Throwable $throwable): void
    {
        $seenObjectIds = [];
        for ($current = $throwable; $current !== null; $current = $current->getPrevious()) {
            $objectId = spl_object_id($current);
            if (isset($seenObjectIds[$objectId])) {
                break;
            }
            $seenObjectIds[$objectId] = true;
            $this->capturedThrowables[$current] = true;
        }
    }
}
