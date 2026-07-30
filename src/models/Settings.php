<?php

/**
 * Manager Connector plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\models;

use craft\base\Model;

/**
 * Plugin settings.
 *
 * Deliberately thin. What the connector may do is decided by the platform through capabilities,
 * not here — a setting on this side would be one a compromised site could change.
 */
class Settings extends Model
{
    /**
     * @var string The Manager platform base URL, e.g. https://manager.example.org
     *
     * Set through config/manager-connector.php in version control rather than through the control
     * panel, so that pointing a site at a different platform requires a deployment.
     */
    public string $platformUrl = '';

    /**
     * @var bool Drive the schedule from ordinary web traffic, for hosting with no cron.
     *
     * On by default, because the alternative failure is a site that pairs successfully and then reports
     * nothing at all — which looks like a broken plugin rather than a missing scheduled task.
     *
     * Costs one cache read per request. When a task is due it pushes a queue job and returns; the
     * visitor whose request triggered it waits for nothing.
     *
     * Cron is still more predictable, and a site with no overnight traffic reports nothing overnight.
     * Turn this off if you have cron and would rather the schedule came from one place.
     */
    public bool $webTrigger = true;

    /**
     * @var bool Whether to send a heartbeat from Craft's queue as well as from cron.
     *
     * Off by default: most installations drive the connector from cron, and a queue that is not
     * running would otherwise make a site look offline when it is fine.
     */
    public bool $useQueue = false;

    /**
     * @var int Seconds to wait for the platform before giving up.
     *
     * Short on purpose. A slow platform must never become a slow website.
     */
    public int $timeout = 10;

    /**
     * @var int Seconds to wait while uploading a backup artifact.
     *
     * Separate from the ordinary timeout, and much longer, because this one is measured in megabytes
     * rather than milliseconds. Still bounded: an upload that has stalled has to end eventually, and a
     * connector holding a socket open indefinitely is a connector holding a PHP process open
     * indefinitely.
     */
    public int $uploadTimeout = 900;

    /**
     * @var int Largest database this connector will attempt to back up, in megabytes.
     *
     * A safety valve rather than a policy. Dumping a database far larger than expected is how a backup
     * job fills a disk on a production site, and failing early with a clear message beats failing late
     * with a full volume.
     */
    public int $maxBackupMegabytes = 2048;

    /**
     * @var bool Time the site's own responses, for the runtime report.
     *
     * On by default, and cheap: one cache write per request into a fixed-size ring of two hundred
     * durations. It stores a number and nothing else — no URL, no visitor, no address — because a
     * record of who fetched what would be a log of real people's behaviour on somebody else's
     * website, and there is no stated purpose for collecting that.
     *
     * Nothing is transmitted unless the platform has been granted `runtime:read`. Turning this off
     * means the runtime report simply omits the timings.
     */
    public bool $sampleResponseTimes = true;

    /**
     * @var int Seconds to spend measuring asset volumes before giving up on the rest.
     *
     * Walking a directory tree is the one genuinely expensive thing this plugin does, and an asset
     * volume with a million files on network storage can take minutes. A volume that runs out of
     * budget is reported as unmeasured rather than as empty — those are different facts and only one
     * of them is alarming.
     */
    public int $storageWalkSeconds = 5;

    /**
     * @var list<string> Recovery key fingerprints this site will encrypt backups to, and no others.
     *
     * **This is the most important setting in the plugin.** Everything else about backup encryption
     * assumes it is set.
     *
     * A backup is encrypted to keys the platform names. That is unavoidable — the site has to be told
     * which keys the organisation holds, and the platform is what tells it. Which means a Manager
     * installation that had been compromised, or that was compelled, could name a key of its own, and
     * this site would encrypt to it with nothing looking wrong from either end.
     *
     * Pinning is what stops that. Fingerprints listed here are compared against every key the platform
     * offers, and a backup is refused outright if any offered key is not among them. Refused, not
     * filtered: a response containing an unpinned key is evidence of a misconfiguration or an attack,
     * and quietly dropping it would hide both.
     *
     * It lives in `config/manager-connector.php`, in your version control, on your server. Changing it
     * takes a deployment. The platform cannot reach it. That is the entire idea, and it is why this
     * setting is deliberately **not** editable from the control panel — a hijacked control-panel
     * session that could re-pin this site would defeat the only control that actually works.
     *
     * Empty by default, because a plugin that shipped with somebody's fingerprint in it would be
     * absurd. See `requirePinnedRecoveryKeys` for what an empty list means.
     */
    public array $recoveryKeyFingerprints = [];

    /**
     * @var bool Refuse to take a backup at all when no fingerprints are pinned.
     *
     * Off by default, and that default is a compromise worth naming rather than hiding. On, this is
     * strictly safer: a site with no pins takes no backups, so there is no window in which the
     * platform chooses recipients unchecked. Off, a site that has been paired but not yet pinned still
     * backs up, and the platform's own screen nags about it.
     *
     * The reason for the weaker default is that the stronger one turns "we have not finished the
     * setup" into "we have no backups", silently, on the day somebody needs one. Turn it on once the
     * fleet is pinned — the documentation says so, and so does the control panel.
     */
    public bool $requirePinnedRecoveryKeys = false;

    /**
     * @var string Host to upload backup artifacts to, when the platform issues a direct upload grant.
     *
     * Empty by default, which disables direct uploads entirely and sends artifacts through the
     * platform as before.
     *
     * The platform supplies a path and a query string. It never supplies a host, and there is no code
     * path by which it could: the URL is built as `https://` plus this value plus what it sent. That is
     * stronger than checking a supplied host against an allow-list, because no host from the platform
     * is used even as an input to a comparison.
     *
     * Config file only, for the same reason as the fingerprints above.
     */
    public string $backupUploadHost = '';

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            [['platformUrl'], 'string'],
            [['recoveryKeyFingerprints'], 'each', 'rule' => ['string']],
            [['requirePinnedRecoveryKeys'], 'boolean'],

            // A bare host, so that a value with a scheme or a path cannot smuggle anything into the
            // URL the connector builds. `https://` is prepended in one place and only one place.
            [['backupUploadHost'], 'match', 'pattern' => '/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i'],
            [['storageWalkSeconds'], 'integer', 'min' => 1, 'max' => 60],
            [['sampleResponseTimes'], 'boolean'],
            // HTTPS only, not merely defaulted to. 'defaultScheme' fills in a missing scheme but
            // accepts an explicit http://, which would put the enrolment code on the wire in clear.
            [['platformUrl'], 'url', 'defaultScheme' => 'https', 'validSchemes' => ['https']],
            [['timeout'], 'integer', 'min' => 1, 'max' => 60],
            [['uploadTimeout'], 'integer', 'min' => 30, 'max' => 7200],
            [['maxBackupMegabytes'], 'integer', 'min' => 1, 'max' => 10240],
            [['useQueue', 'webTrigger'], 'boolean'],
        ];
    }
}
