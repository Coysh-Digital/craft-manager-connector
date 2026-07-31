<?php

/**
 * Manager Connector plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector;

use coyshdigital\managerconnector\models\Settings;
use coyshdigital\managerconnector\services\BackupRunner;
use coyshdigital\managerconnector\services\Client;
use coyshdigital\managerconnector\services\Connection;
use coyshdigital\managerconnector\services\JobRunner;
use coyshdigital\managerconnector\services\LoginsReporter;
use coyshdigital\managerconnector\services\RecipientPin;
use coyshdigital\managerconnector\services\Reporter;
use coyshdigital\managerconnector\services\ResponseSampler;
use coyshdigital\managerconnector\services\Scheduler;
use coyshdigital\managerconnector\services\SystemReporter;
use coyshdigital\managerconnector\services\Tasks;
use coyshdigital\managerconnector\services\UpdatesReporter;
use coyshdigital\managerconnector\utilities\ConnectorUtility;
use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\console\Application as ConsoleApplication;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\UrlHelper;
use craft\services\Utilities;
use craft\web\Application as CraftWebApplication;
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
 * @property-read RecipientPin $recipientPin
 * @property-read Tasks $tasks
 * @property-read Scheduler $scheduler
 * @property-read SystemReporter $system
 * @property-read LoginsReporter $logins
 * @property-read ResponseSampler $responseSampler
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
    public const VERSION = '1.8.1';

    /**
     * @inheritdoc
     */
    public string $schemaVersion = '1.2.0';

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

        // The schedule, driven by traffic, for sites with no cron. A listener on requests that were
        // happening anyway — it reads nothing from the request and there is no URL that reaches it.
        //
        // Registered only for web requests. On the console the commands are the schedule.
        if (!Craft::$app instanceof ConsoleApplication) {
            Event::on(
                CraftWebApplication::class,
                CraftWebApplication::EVENT_AFTER_REQUEST,
                static function(): void {
                    // Bare, like every other service call in this plugin. Craft types getInstance()
                    // as returning the plugin rather than a nullable, and this listener is only
                    // registered on web requests, where the plugin is by definition loaded.
                    $plugin = Plugin::getInstance();

                    // Timing first, and separately, because the two have different failure modes and
                    // neither should be able to take the other down. The sampler records a number
                    // into a fixed-size cache ring — no URL, no visitor, no address — and swallows
                    // everything, because a visitor's page must not fail because a measurement of it
                    // did. See ResponseSampler for why this is render time and not TTFB.
                    $plugin->responseSampler->record();

                    $plugin->scheduler->runDue();
                },
            );
        }

        // The screen is a utility, so Craft routes it — this plugin registers no URL rules at all.
        // The two state-changing actions are reached through Craft's action mechanism, which requires
        // POST and a CSRF token, so they need no route either. Nothing here can be reached by following
        // a link, which is one less thing to reason about.
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = ConnectorUtility::class;
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
        // To the utility, which is where the screen lives. A different URL from this one, unlike the
        // version of this method that redirected to the settings page it was called from and looped.
        return Craft::$app->getResponse()->redirect(
            UrlHelper::cpUrl('utilities/manager-connector')
        );
    }
}
