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
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            [['platformUrl'], 'string'],
            [['platformUrl'], 'url', 'defaultScheme' => 'https'],
            [['timeout'], 'integer', 'min' => 1, 'max' => 60],
            [['useQueue'], 'boolean'],
        ];
    }
}
