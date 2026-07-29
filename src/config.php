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

    // Send a heartbeat from Craft's queue in addition to cron. Leave off unless a queue worker is
    // definitely running: otherwise a stalled queue makes a healthy site look offline.
    'useQueue' => false,

    // Seconds to wait for the platform. A slow platform must never become a slow website.
    'timeout' => 10,
];
