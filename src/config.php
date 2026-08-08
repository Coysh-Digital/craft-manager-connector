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
    // does is push a queue job - the visitor waits for nothing. Turn it off if you have cron and would
    // rather the schedule came from one place.
    //
    // It needs Craft's queue to actually run. That is Craft's default; if you have set
    // runQueueAutomatically to false, something else must run the queue or nothing will report.
    'webTrigger' => true,

    // Answer the platform when it asks this site to check in now. On by default.
    //
    // Without this, a backup requested in Manager waits for the next scheduled check-in - up to five
    // minutes with cron, and until somebody visits the site if the schedule runs off web traffic.
    //
    // It enables one anonymous endpoint whose entire vocabulary is "poll". It reads no parameters and
    // takes no body; all it does is push the same `jobs` task this site already runs on its own timer,
    // after checking a signature from the platform this site paired with, a timestamp window and a
    // single-use nonce. Everything that decides anything happens in the ordinary signed claim that
    // follows, so the worst a forged nudge achieves is an early poll.
    //
    // Set it to false if this site must expose nothing the platform can reach.
    'acceptNudges' => true,

    // Kept for compatibility. Superseded by webTrigger, which is what actually drives the schedule.
    'useQueue' => false,

    // Seconds to wait for the platform. A slow platform must never become a slow website.
    'timeout' => 10,

    /*
    |----------------------------------------------------------------------------------------------
    | Recovery keys
    |----------------------------------------------------------------------------------------------
    |
    | The fingerprints of the keys this site will encrypt backups to, and no others.
    |
    | This is the most important setting here, and the easiest to skip. Backups are encrypted to keys
    | Manager names, because this site has to be told which keys your organisation holds and Manager
    | is what tells it. That means a Manager installation that had been compromised - or that was
    | compelled - could name a key of its own, and this site would encrypt to it. No error, no missing
    | backup. Just a backup somebody else can read.
    |
    | Listing the fingerprints here closes that. They live on your server, in your version control,
    | and Manager cannot reach them. Any key it offers that is not on this list fails the whole backup
    | rather than being quietly skipped, and the check runs before the database is dumped.
    |
    | Get them from `manager-restore fingerprint recovery-key.pub`, or from the Manager settings
    | screen - but note that comparing Manager's screen against Manager's own claim proves nothing.
    | The file on your laptop is the reference.
    */
    // 'recoveryKeyFingerprints' => [
    //     'MGRK-4F3A-9C2B-7D18-E605-2A9F-33C1',
    // ],

    /*
    | Refuse to back up at all when no fingerprints are listed above.
    |
    | Off by default, and that is a compromise rather than a recommendation. On, this is strictly
    | safer. Off, a site that has been paired but not yet pinned still backs up, and Manager's own
    | screen nags about it - which beats turning "we have not finished the setup" into "we have no
    | backups", silently, on the day somebody needs one.
    |
    | Turn it on once your fleet is pinned.
    */
    // 'requirePinnedRecoveryKeys' => false,

    /*
    | Host to upload backup artifacts to when Manager issues a direct upload grant.
    |
    | Optional. Left unset, the host is derived as `uploads.` in front of the platform host you typed
    | at pairing - so a direct upload grant is honoured without this line, and the destination is
    | still something this site worked out rather than something Manager sent.
    |
    | This used to say an empty value disabled direct uploads and sent artifacts through Manager.
    | That stopped being true when the derivation was added, and it is the kind of stale sentence
    | worth correcting loudly: an operator reading it would believe artifacts went nowhere but the
    | platform, and choose not to set a value on that basis.
    |
    | Set it to point a fleet at your own bucket. A value here always wins over the derived one.
    |
    | Manager supplies a path and a query string; it never supplies a host, and there is no code path
    | by which it could. The URL is built as `https://` plus this value plus what it sent, which is
    | stronger than checking a host it supplied - no host from Manager is used even as an input to a
    | comparison.
    */
    // 'backupUploadHost' => '',
];
