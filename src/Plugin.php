<?php

/**
 * Manager Connector plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector;

use Craft;
use coyshdigital\managerconnector\models\Settings;
use coyshdigital\managerconnector\services\Client;
use coyshdigital\managerconnector\services\Connection;
use coyshdigital\managerconnector\services\Reporter;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\console\Application as ConsoleApplication;
use craft\helpers\UrlHelper;

/**
 * Manager Connector.
 *
 * Privileged code running inside somebody's production website, so it is deliberately small.
 *
 * Three things it does **not** do, each on purpose:
 *
 *  - It registers no site or control-panel route that accepts management input. Every exchange is
 *    initiated by this plugin, outbound. That is invariants 4 and 5, and it is why the plugin works
 *    from behind NAT with no inbound firewall rules.
 *  - It executes nothing on instruction. There is no console-command runner, no PHP evaluation, no
 *    SQL, no file access. Phase 1 reports and nothing else.
 *  - It transmits no site content. What it may send is fixed by the shared inventory schema, and
 *    the platform rejects anything outside it.
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 *
 * @property-read Connection $connection
 * @property-read Client $client
 * @property-read Reporter $reporter
 *
 * @author Coysh Digital
 *
 * @since 1.0.0
 */
class Plugin extends BasePlugin
{
    /**
     * @var string The connector version reported to the platform and signed into every request.
     */
    public const VERSION = '1.0.0';

    /**
     * @inheritdoc
     */
    public string $schemaVersion = '1.0.0';

    /**
     * @inheritdoc
     */
    public bool $hasCpSettings = true;

    /**
     * @inheritdoc
     */
    public bool $hasCpSection = false;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'coyshdigital\\managerconnector\\console\\controllers';
        }
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     *
     * One read-only screen. The specification asks for a concise view of the connection and
     * nothing more, so pairing and disconnection are console commands: both are operations an
     * administrator performs deliberately, and neither belongs behind a button that a compromised
     * control-panel session could press.
     */
    protected function settingsHtml(): ?string
    {
        $connection = $this->connection->current();

        return Craft::$app->getView()->renderTemplate('manager-connector/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            'connection' => $connection,
            'connectorVersion' => self::VERSION,
            'securityUrl' => 'https://coysh.digital/manager/docs/security/',
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(
            UrlHelper::cpUrl('settings/plugins/manager-connector')
        );
    }
}
