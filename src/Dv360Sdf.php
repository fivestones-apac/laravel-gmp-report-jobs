<?php

namespace FiveStones\GmpReporting;

use Exception;
use FiveStones\GmpReporting\Jobs\Dv360SdfAwait;
use Google\Cloud\Core\ExponentialBackoff;
use GuzzleHttp\Psr7\StreamWrapper;
use Google_Service_DisplayVideo;
use Google_Service_DisplayVideo_CreateSdfDownloadTaskRequest;
use Google_Service_DisplayVideo_Operation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

class Dv360Sdf
{
    use Concerns\HasAwaitJob,
        Concerns\HasGoogleClient,
        Concerns\HasResultJob;

    /**
     * SDF download task
     *
     * @var  \Google_Service_DisplayVideo_CreateSdfDownloadTaskRequest
     */
    protected $request;

    /**
     * Operation object created for the SDF Download Task
     *
     * @var  \Google_Service_DisplayVideo_Operation
     */
    protected $operation;

    /**
     * Setter for SDF download task
     *
     * @param  \Google_Service_DisplayVideo_CreateSdfDownloadTaskRequest $request
     * @return \FiveStones\GmpReporting\Dv360Sdf
     */
    public function setRequest(Google_Service_DisplayVideo_CreateSdfDownloadTaskRequest $request): self
    {
        $this->request = $request;

        return $this;
    }

    /**
     * Setter for SDF download task operation
     *
     * @param  \Google_Service_DisplayVideo_Operation $operation
     * @return \FiveStones\GmpReporting\Dv360Sdf
     */
    public function setOperation(Google_Service_DisplayVideo_Operation $operation): self
    {
        $this->operation = $operation;

        return $this;
    }

    /**
     * Submit the create SDF download task request and dispatch queued job to wait for the completion
     *
     * @return void
     */
    public function run(): void
    {
        $client = $this->getGoogleClient();
        $dvService = new Google_Service_DisplayVideo($client);
        $dvSdfDownloadService = $dvService->sdfdownloadtasks;

        $backoff = new ExponentialBackoff;
        $backoff->execute(function () use ($dvSdfDownloadService) {
            // expecting Google_Service_DisplayVideo_Operation
            $this->operation = $dvSdfDownloadService->create($this->request);
        });

        $this->dispatchAwait(new Dv360SdfAwait(
            $this->operation,
            // defined in Concerns\HasGoogleClient
            $this->googleClient->getAccessToken(),
            $this->googleApiTokenModel,
            $this->googleApiTokenModelGetClientMethod,
            $this->googleApiTokenModelUpdateTokenMethod,
            // defined in Concerns\HasResultJob
            $this->resultJobArguments,
            $this->resultJobClass,
            $this->resultJobQueue
        ));
    }

    /**
     * Download the zip file to temporary location and return the file path
     *
     * @return string
     */
    public function download(): string
    {
        $client = $this->getGoogleClient();
        $backoff = new ExponentialBackoff;
        $dvService = new Google_Service_DisplayVideo($client);
        $dvOperationService = $dvService->sdfdownloadtasks_operations;
        $dvMediaService = $dvService->media;

        // fetch the operation object from API
        $backoff->execute(function () use ($dvOperationService) {
            // expecting Google_Service_DisplayVideo_Operation
            $this->operation = $dvOperationService->get($this->operation->getName());
        });

        // get stream inside a Guzzle response
        $stream = null;
        $backoff->execute(function () use ($dvMediaService, &$stream) {
            // expecting GuzzleHttp\Psr7\Response
            $response = $dvMediaService->download($this->operation->getResponse()['resourceName'], ['alt' => 'media']);
            $stream = $response->getBody();
        });

        // for safety, check the $stream class type
        if (!($stream instanceof StreamInterface)) {
            throw new RuntimeException('Unable to download file.');
        }

        // get resource from GuzzleStream
        $sourceResource = StreamWrapper::getResource($stream);

        // create a temporary file locally
        $destinationPath = tempnam(sys_get_temp_dir(), class_basename(self::class));
        $destinationResource = fopen($destinationPath, 'w');

        // copy between stream for better performance
        stream_copy_to_stream($sourceResource, $destinationResource);

        // done copying and return the path
        fclose($destinationResource);

        return $destinationPath;
    }
}
