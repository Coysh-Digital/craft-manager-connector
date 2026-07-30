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
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            [['platformUrl'], 'string'],
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
