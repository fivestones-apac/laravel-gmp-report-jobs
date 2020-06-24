## Google Marketing Platform report running in Laravel Queues

This package takes one of the below Google API objects:
- Google_Service_DisplayVideo_CreateSdfDownloadTaskRequest

Then runs them in Laravel queue asynchronously and dispatch a previously defined job for processing the downloaded report file.
