<?php

namespace FiveStones\GmpReporting;

use FiveStones\GmpReporting\Jobs\DbmReportAwait;
use Google\Cloud\Core\ExponentialBackoff;
use Google\Service\DoubleClickBidManager;
use Google\Service\DoubleClickBidManager\Query as DoubleClickBidManagerQuery;
use Google\Service\DoubleClickBidManager\Report as DoubleClickBidManagerReport;
use Google\Service\DoubleClickBidManager\RunQueryRequest as DoubleClickBidManagerRunQueryRequest;
use Google\Service\DoubleClickBidManager\Resource\Queries as DoubleClickBidManagerQueries;
use Google\Service\Exception as GoogleException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\StreamWrapper;
use RuntimeException;

class DbmReport
{
    use Concerns\HasAwaitJob;
    use Concerns\HasGoogleClient;
    use Concerns\HasResultJob;

    /**
     * DBM query
     *
     * @var  \Google\Service\DoubleClickBidManager\Query
     */
    protected $query;

    /**
     * DBM report
     *
     * @var  \Google\Service\DoubleClickBidManager\Report
     */
    protected $report;

    /**
     * Setter for DBM query
     *
     * @param  \Google\Service\DoubleClickBidManager\Query $query
     * @return \FiveStones\GmpReporting\DbmReport
     */
    public function setQuery(DoubleClickBidManagerQuery $query): self
    {
        $this->query = $query;

        return $this;
    }

    /**
     * Setter for DBM report
     *
     * @param  \Google\Service\DoubleClickBidManager\Report $report
     * @return \FiveStones\GmpReporting\DbmReport
     */
    public function setReport(DoubleClickBidManagerReport $report): self
    {
        $this->report = $report;

        return $this;
    }

    /**
     * Submit the query and dispatch queued job to wait for the completion
     *
     * @return void
     */
    public function run(): void
    {
        if (!($this->query instanceof DoubleClickBidManagerQuery)) {
            throw new RuntimeException('DBM query object is not provided');
        }

        $client = $this->getGoogleClient();
        $dbmService = new DoubleClickBidManager($client);
        $queriesService = $dbmService->queries;

        $backoff = new ExponentialBackoff;
        $backoff->execute(function () use ($queriesService) {
            $queryCreated = null;

            try {
                $queryCreated = $queriesService->create($this->query);
            } catch (GoogleException $e) {
                // since the DBM API usually response a 503 error but the query is actually created,
                // we would search for the created query and continue our process instead of retry the createquery()
                $queryCreated = $this->searchForCreatedQuery($queriesService);

                if (($queryCreated instanceof DoubleClickBidManagerQuery) !== true) {
                    throw $e;
                }
            }

            if ($queryCreated instanceof DoubleClickBidManagerQuery) {
                $this->query = $queryCreated;

                $queriesService->run(
                    $this->query->getQueryId(),
                    new DoubleClickBidManagerRunQueryRequest,
                    ['synchronous' => false],
                );   
            }
        });

        $this->dispatchAwait(new DbmReportAwait(
            $this->query,
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
     * Fetch queries list to see whether the query is actually created or not
     *
     * @param  \Google\Service\DoubleClickBidManager\Resource\Queries $queriesService
     * @return \Google\Service\DoubleClickBidManager\Query
     */
    protected function searchForCreatedQuery(DoubleClickBidManagerQueries $queriesService): ?DoubleClickBidManagerQuery {
        // expect Google\Service\DoubleClickBidManager\QueryMetadata
        $targetQueryMetadata = $this->query->getMetadata();
        $targetQueryTitle = $targetQueryMetadata->getTitle();

        // expect Google\Service\DoubleClickBidManager\ListQueriesResponse
        $response = $queriesService->listQueries();
        $queries = $response->getQueries();

        foreach ($queries as $query) {
            $metadata = $query->getMetadata();
            $title = $metadata->getTitle();

            if (strcmp($title, $targetQueryTitle) === 0) {
                return $query;
            }
        }

        return null;
    }

    /**
     * Download the report file to temporary location and return the file path
     *
     * @return string
     */
    public function download(): string
    {
        if (!($this->report instanceof DoubleClickBidManagerReport)) {
            throw new RuntimeException('DBM report object is not provided');
        }

        // expect DoubleClickBidManagerReportMetadata
        $reportMetadata = $this->report->getMetadata();
        $downloadPath = $reportMetadata->getGoogleCloudStoragePath();

        // initial GuzzleHttp with access token
        $client = $this->getGoogleClient();
        $httpClient = $client->authorize();

        // get stream inside a Guzzle response
        $stream = null;

        $backoff = new ExponentialBackoff;
        $backoff->execute(function () use ($httpClient, $downloadPath, &$stream) {
            $request = new Request('GET', $downloadPath);
            $response = $httpClient->send($request);

            // expect GuzzleHttp\Psr7\Stream
            $stream = $response->getBody();
        });

        // for safety, check the $stream class type
        if (!($stream instanceof Stream)) {
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
