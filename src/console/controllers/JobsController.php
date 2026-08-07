<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 *
 * @link      https://managerforcraft.com
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\console\controllers;

use coyshdigital\managerconnector\services\Tasks;
use craft\helpers\Console;
use Throwable;
use yii\console\ExitCode;

/**
 * php craft manager-connector/jobs
 *
 * Asks the platform whether there is anything to do, and does it. Intended for cron alongside the
 * heartbeat.
 *
 * No work is ever pushed to this site. The platform may knock to ask for an early check-in, but what it
 * can say is "check in now" and nothing else - this command, and the claim it makes, is still the site
 * choosing to ask and deciding for itself what to do with the answer. Nothing depends on that knock
 * arriving, which is what lets this work from behind NAT with no inbound firewall rule.
 */
class JobsController extends BaseController
{
    public function actionIndex(): int
    {
        try {
            $this->stdout($this->plugin()->tasks->run(Tasks::JOBS) . "\n", Console::FG_GREEN);
        } catch (Throwable $e) {
            $this->stderr(ucfirst($e->getMessage()) . "\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        return ExitCode::OK;
    }
}
