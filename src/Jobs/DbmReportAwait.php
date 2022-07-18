<?php

namespace FiveStones\GmpReporting\Jobs;

use FiveStones\GmpReporting\Concerns\Awaitable;
use FiveStones\GmpReporting\Concerns\HasGoogleClient;
use FiveStones\GmpReporting\Concerns\HasResultJob;
use Google\Cloud\Core\ExponentialBackoff;
use Google\Service\DoubleClickBidManager;
use Google\Service\DoubleClickBidManager\Query as DoubleClickBidManagerQuery;
use Google\Service\DoubleClickBidManager\Report as DoubleClickBidManagerReport;
use Google\Service\DoubleClickBidManager\ReportFailure as DoubleClickBidManagerReportFailure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class DbmReportAwait implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use Awaitable;
    use HasGoogleClient;
    use HasResultJob;

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
     * DBM report query object
     *
     * @var  \Google\Service\DoubleClickBidManager\Query
     */
    protected $query;

    /**
     * Create a new job instance.
     *
     * @param  \Google\Service\DoubleClickBidManager\Query $query
     * @return void
     */
    public function __construct(
        DoubleClickBidManagerQuery $query,
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
        $dbmService = new DoubleClickBidManager($client);
        $reportsService = $dbmService->queries_reports;

        // expect Google\Service\DoubleClickBidManager\ListReportsResponse
        $listReportsResponse = null;

        $backoff->execute(function () use ($reportsService, &$listReportsResponse) {
            $listReportsResponse = $reportsService->listQueriesReports($this->query->getQueryId());
        });

        // extract the latest report object, i.e. Google\Service\DoubleClickBidManager\Report
        $reports = collect($listReportsResponse->getReports());
        $latestReport = $reports->last();

        if (!($latestReport instanceof DoubleClickBidManagerReport)) {
            throw new RuntimeException('No report in the query');
        }

        // expect Google\Service\DoubleClickBidManager\ReportMetadata
        $reportMetadata = $latestReport->getMetadata();

        // expect Google\Service\DoubleClickBidManager\ReportStatus
        $reportStatus = $reportMetadata->getStatus();

        // use the state of the report to determine the next step
        switch ($reportStatus->getState()) {
            case 'DONE':
                // report completed, dispatch job for processing the result file
                $this->dispatchResult([ $latestReport ]);
                break;

            case 'FAILED':
                // expect Google\Service\DoubleClickBidManager\ReportFailure
                $failure = $reportStatus->getFailure();

                if ($failure instanceof DoubleClickBidManagerReportFailure) {
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
