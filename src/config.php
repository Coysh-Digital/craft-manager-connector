<?php

/**
 * Manager Connector configuration.
 *
 * Copy to config/manager-connector.php in your project and set the platform URL. Keeping this in
 * version control rather than in the database means pointing a site at a different Manager
 * platform takes a deployment, which is the intended level of friction.
 */

return [
    // The Manager platform this site reports to.
    'platformUrl' => '',

    // Drive the schedule from ordinary web traffic, for hosting with no cron. On by default.
    //
    // Each task fires at most once per interval however much traffic the site gets, and all a request
    // does is push a queue job — the visitor waits for nothing. Turn it off if you have cron and would
    // rather the schedule came from one place.
    //
    // It needs Craft's queue to actually run. That is Craft's default; if you have set
    // runQueueAutomatically to false, something else must run the queue or nothing will report.
    'webTrigger' => true,

    // Kept for compatibility. Superseded by webTrigger, which is what actually drives the schedule.
    'useQueue' => false,

    // Seconds to wait for the platform. A slow platform must never become a slow website.
    'timeout' => 10,
];
