<?php

namespace FiveStones\GmpReporting\Jobs;

use FiveStones\GmpReporting\Concerns\Awaitable;
use FiveStones\GmpReporting\Concerns\HasGoogleClient;
use FiveStones\GmpReporting\Concerns\HasResultJob;
use Google\Cloud\Core\ExponentialBackoff;
use Google_Client;
use Google_Service_DisplayVideo;
use Google_Service_DoubleClickBidManager;
use Google_Service_DoubleClickBidManager_Query;
use Google_Service_DoubleClickBidManager_Report;
use Google_Service_DoubleClickBidManager_ReportFailure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class DbmReportAwait implements ShouldQueue
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
    public $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 20;

    /**
     * DBM report query object
     *
     * @var  \Google_Service_DoubleClickBidManager_Query
     */
    protected $query;

    /**
     * Create a new job instance.
     *
     * @param  \Google_Service_DoubleClickBidManager_Query $query
     * @return void
     */
    public function __construct(
        Google_Service_DoubleClickBidManager_Query $query,
        // defined in Concerns\HasGoogleClient
        ?array $googleClientAccessToken,
        ?Model $googleApiTokenModel,
        ?string $googleApiTokenModelGetClientMethod,
        ?string $googleApiTokenModelUpdateTokenMethod,
        // defined in Concerns\HasResultJob
        array $resultJobArguments,
        string $resultJobClass,
        string $resultJobQueue
    ) {
        $this->query = $query;
        $this->googleClientAccessToken = $googleClientAccessToken;
        $this->googleApiTokenModel = $googleApiTokenModel;
        $this->googleApiTokenModelGetClientMethod = $googleApiTokenModelGetClientMethod;
        $this->googleApiTokenModelUpdateTokenMethod = $googleApiTokenModelUpdateTokenMethod;
        $this->resultJobArguments = $resultJobArguments;
        $this->resultJobClass = $resultJobClass;
        $this->resultJobQueue = $resultJobQueue;
    }

    /**
     * Execute the job:
     * - Fetch the latest status for DBM query
     * - Dispatch the result job if done, otherwise, retry it in an exponential backoff timeframe
     * - Throw exception if the query is failed
     *
     * @return void
     */
    public function handle()
    {
        $client = $this->getGoogleClient();
        $backoff = new ExponentialBackoff;
        $dbmService = new Google_Service_DoubleClickBidManager($client);
        $reportsService = $dbmService->reports;

        // expect Google_Service_DoubleClickBidManager_ListReportsResponse
        $listReportsResponse = null;

        $backoff->execute(function () use ($reportsService, &$listReportsResponse) {
            $listReportsResponse = $reportsService->listreports($this->query->getQueryId());
        });

        // extract the latest report object, i.e. Google_Service_DoubleClickBidManager_Report
        $reports = collect($listReportsResponse->getReports());
        $latestReport = $reports->last();

        if (!($latestReport instanceof Google_Service_DoubleClickBidManager_Report)) {
            throw new RuntimeException('No report in the query');
        }

        // expect Google_Service_DoubleClickBidManager_ReportMetadata
        $reportMetadata = $latestReport->getMetadata();

        // expect Google_Service_DoubleClickBidManager_ReportStatus
        $reportStatus = $reportMetadata->getStatus();

        // use the state of the report to determine the next step
        switch ($reportStatus->getState()) {
            case 'DONE':
                // report completed, dispatch job for processing the result file
                $this->dispatchResult([ $latestReport ]);
                break;

            case 'FAILED':
                // expect Google_Service_DoubleClickBidManager_ReportFailure
                $failure = $reportStatus->getFailure();

                if ($failure instanceof Google_Service_DoubleClickBidManager_ReportFailure) {
                    throw new RuntimeException('Report failed with code ' . $failure->getErrorCode());
                } else {
                    throw new RuntimeException('Report failed');
                }
                break;

            case 'RUNNING':
            default:
                // report still running, will retry with exponential backoff
                $this->retryWithExponentialBackoff();
                break;
        }
    }
}
