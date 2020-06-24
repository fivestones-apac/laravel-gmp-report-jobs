<?php

namespace FiveStones\GmpReporting\Jobs;

use Exception;
use FiveStones\GmpReporting\Concerns\Awaitable;
use FiveStones\GmpReporting\Concerns\HasGoogleClient;
use FiveStones\GmpReporting\Concerns\HasResultJob;
use Google\Cloud\Core\ExponentialBackoff;
use Google_Client;
use Google_Service_DisplayVideo;
use Google_Service_DisplayVideo_Operation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class Dv360SdfAwait implements ShouldQueue
{
    use Dispatchable,
        InteractsWithQueue,
        Queueable,
        SerializesModels,
        Awaitable,
        HasGoogleClient,
        HasResultJob;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 10;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 20;

    /**
     * Operation object created for the SDF Download Task
     *
     * @var  \Google_Service_DisplayVideo_Operation
     */
    protected $operation;

    /**
     * Create a new job instance.
     *
     * @param  \Google_Service_DisplayVideo_Operation $operation
     * @return void
     */
    public function __construct(
        Google_Service_DisplayVideo_Operation $operation,
        // defined in Concerns\HasGoogleClient
        ?Google_Client $googleClient,
        ?Model $googleApiTokenModel,
        ?string $googleApiTokenModelGetClientMethod,
        ?string $googleApiTokenModelUpdateTokenMethod,
        // defined in Concerns\HasResultJob
        array $resultJobArguments,
        string $resultJobClass,
        string $resultJobQueue
    ) {
        $this->operation = $operation;
        $this->googleClient = $googleClient;
        $this->googleApiTokenModel = $googleApiTokenModel;
        $this->googleApiTokenModelGetClientMethod = $googleApiTokenModelGetClientMethod;
        $this->googleApiTokenModelUpdateTokenMethod = $googleApiTokenModelUpdateTokenMethod;
        $this->resultJobArguments = $resultJobArguments;
        $this->resultJobClass = $resultJobClass;
        $this->resultJobQueue = $resultJobQueue;
    }

    /**
     * Execute the job:
     * - Fetch the latest status for SDF download task operation
     * - Dispatch the result job if done, otherwise, retry it in an exponential backoff timeframe
     * - Throw exception if the operation is failed
     *
     * @return void
     */
    public function handle()
    {
        $client = $this->getGoogleClient();
        $backoff = new ExponentialBackoff;
        $dvService = new Google_Service_DisplayVideo($client);
        $dvOperationService = $dvService->sdfdownloadtasks_operations;

        $backoff->execute(function () use ($dvOperationService) {
            // expecting Google_Service_DisplayVideo_Operation
            $this->operation = $dvOperationService->get($this->operation->getName());
        });

        if ($this->operation->getDone()) {
            // expecting Google_Service_DisplayVideo_Status
            $error = $this->operation->getError();

            if (is_null($error)) {
                // operation completed, dispatch job for processing the result file
                $this->dispatchResult([ $this->operation ]);
            } else {
                // operation failed
                throw new RuntimeException('Sdf download task failed: ' . $error->getMessage());
            }
        } else {
            // operation still running, will retry with exponential backoff
            $this->retryWithExponentialBackoff();
        }
    }
}
