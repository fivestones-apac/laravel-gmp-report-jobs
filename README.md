## Google Marketing Platform report running in Laravel Queues

This package takes one of the below Google PHP SDK objects:
- Google_Service_DisplayVideo_CreateSdfDownloadTaskRequest
- Google_Service_DoubleClickBidManager_Query

Then runs them in Laravel queue asynchronously and dispatch a previously defined job for processing the downloaded report file.
