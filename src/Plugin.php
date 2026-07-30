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
use coyshdigital\managerconnector\services\BackupRunner;
use coyshdigital\managerconnector\services\JobRunner;
use coyshdigital\managerconnector\services\Reporter;
use coyshdigital\managerconnector\services\UpdatesReporter;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\console\Application as ConsoleApplication;
use craft\helpers\UrlHelper;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use yii\base\Event;

/**
 * Manager Connector.
 *
 * Privileged code running inside somebody's production website, so it is deliberately small.
 *
 * Three things it does **not** do, each on purpose:
 *
 *  - It registers no site route at all, and its only control-panel route is an administrator-gated
 *    form for pairing. Every exchange with the platform is initiated by this plugin, outbound — the
 *    platform cannot call in. That is invariants 4 and 5, and it is why the plugin works from behind
 *    NAT with no inbound firewall rules.
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
 * @property-read UpdatesReporter $updates
 * @property-read JobRunner $jobs
 * @property-read BackupRunner $backups
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
    public const VERSION = '1.3.0';

    /**
     * @inheritdoc
     */
    public string $schemaVersion = '1.1.0';

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

        // One control-panel route: the connector's own screen. The two state-changing actions are
        // reached through Craft's action mechanism, which requires POST and a CSRF token, so they need
        // no URL rule of their own — and a route that cannot be visited by following a link is one less
        // thing to reason about.
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function (RegisterUrlRulesEvent $event): void {
                $event->rules['manager-connector/settings'] = 'manager-connector/enrol/index';
            },
        );
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
    public function getSettingsResponse(): mixed
    {
        // To the plugin's own page, which is a different URL from this one. Redirecting to the settings
        // URL itself — as this once did — is an infinite loop, and the page could not be opened at all.
        return Craft::$app->getResponse()->redirect(
            UrlHelper::cpUrl('manager-connector/settings')
        );
    }
}
