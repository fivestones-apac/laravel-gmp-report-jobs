<?php

namespace FiveStones\GmpReporting\Concerns;

use Illuminate\Contracts\Queue\ShouldQueue;

trait HasAwaitJob
{
    /**
     * Queue name to be used for await jobs
     *
     * @var  string
     */
    protected $awaitJobQueue = 'default';

    /**
     * Setter for queue name for await job
     *
     * @param  string $queue
     * @return self
     */
    public function setAwaitJobQueue(string $queue): self
    {
        $this->awaitJobQueue = $queue;

        return $this;
    }

    /**
     * Dispatch await job to queue $awaitJobQueue
     *
     * @param  \Illuminate\Contracts\Queue\ShouldQueue $job
     * @return void
     */
    protected function dispatchAwait(ShouldQueue $job): void
    {
        dispatch($job)->onQueue($this->awaitJobQueue);
    }
}
