<?php

namespace FiveStones\GmpReporting\Concerns;

use Illuminate\Contracts\Queue\ShouldQueue;

trait Awaitable
{
    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $retryAfter = 2;

    /**
     * Return the seconds that this application should wait based on the exponential backoff
     * definiation
     *
     * @param  int $attempts
     * @param  int $backoffFactor
     * @param  int $additionalWaitTime
     * @return int
     */
    protected function getExponentialBackoffDelaySeconds(
        int $attempts = 1,
        int $backoffFactor = 2,
        int $additionalWaitTime = 0
    ): int {
        return ($additionalWaitTime + $backoffFactor ** $attempts);
    }

    /**
     * Release current job back to the queue with backoff
     *
     * @return void
     */
    protected function retryWithExponentialBackoff(): void
    {
        // getExponentialBackoffDelaySeconds() is provided by ExponentialBackoff trait
        // the max wait time @ 10th attempts would be 59,109 seconds, which is around 16 hours
        $this->retryAfter = $this->getExponentialBackoffDelaySeconds($this->attempts(), 3, 60);
        $this->release($this->retryAfter);
    }
}
