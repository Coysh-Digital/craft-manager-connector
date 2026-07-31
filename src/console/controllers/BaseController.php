<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\console\controllers;

use coyshdigital\managerconnector\Plugin;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Shared behaviour for the connector's commands.
 *
 * One controller per command, each with an actionIndex, so the routes read as
 * `manager-connector/pair` rather than `manager-connector/default/pair`. These are commands an
 * administrator types during setup and then puts in cron, and the short form is what ends up in
 * the documentation and in a crontab.
 *
 * Console rather than control panel throughout. Pairing and disconnection are deliberate acts by
 * someone with server access; behind a button, a hijacked control-panel session could hand a
 * platform access to a production site, or take it away.
 */
abstract class BaseController extends Controller
{
    protected function plugin(): Plugin
    {
        return Plugin::getInstance();
    }

    /**
     * Refuse to proceed unless the site is actively paired.
     *
     * A pairing held for confirmation counts as not paired: nothing is reported until a person has
     * approved it.
     */
    protected function requireActiveConnection(): ?int
    {
        if ($this->plugin()->connection->isActive()) {
            return null;
        }

        $this->stderr("This site is not actively paired with a Manager platform.\n", Console::FG_RED);
        $this->stdout("Run: php craft manager-connector/status\n");

        return ExitCode::UNAVAILABLE;
    }
}
