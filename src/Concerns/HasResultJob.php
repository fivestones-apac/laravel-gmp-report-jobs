<?php

namespace FiveStones\GmpReporting\Concerns;

use Illuminate\Contracts\Queue\ShouldQueue;
use ReflectionClass;

trait HasResultJob
{
    /**
     * Arguments to be passed to the result job
     *
     * @var string
     */
    protected $resultJobArguments = [];

    /**
     * Class name of result job to be dispatched
     *
     * @var string
     */
    protected $resultJobClass;

    /**
     * Queue name to be used for await jobs
     *
     * @var  string
     */
    protected $resultJobQueue = 'default';

    /**
     * Setter for result job arguments
     *
     * @param  array $arguments
     * @return object
     */
    public function setResultJobArguments(array $arguments = []): self
    {
        $this->resultJobArguments = $arguments;

        return $this;
    }

    /**
     * Setter for result job class name
     *
     * @param  string $className
     * @return object
     */
    public function setResultJobClass(string $className): self
    {
        $this->resultJobClass = $className;

        return $this;
    }

    /**
     * Setter for result job queue name
     *
     * @param  string $queueName
     * @return object
     */
    public function setResultJobQueue(string $queueName): self
    {
        $this->resultJobQueue = $queueName;

        return $this;
    }

    /**
     * Dispatch a new queued job with pre-defined arguments
     *
     * @param  array $extraArguments
     * @return void
     */
    protected function dispatchResult(array $extraArguments = []): void
    {
        $class = new ReflectionClass($this->resultJobClass);
        $instance = $class->newInstanceArgs(array_merge($this->resultJobArguments, $extraArguments));

        dispatch($instance)->onQueue($this->resultJobQueue);
    }
}
