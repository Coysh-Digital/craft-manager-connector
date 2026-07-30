<?php

/**
 * Manager Connector plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
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
 * Nothing is pushed to this site: the platform has no way to reach it. This is the site choosing to
 * ask, which is what lets it work from behind NAT with no inbound firewall rule.
 */
class JobsController extends BaseController
{
    public function actionIndex(): int
    {
        try {
            $this->stdout($this->plugin()->tasks->run(Tasks::JOBS)."\n", Console::FG_GREEN);
        } catch (Throwable $e) {
            $this->stderr(ucfirst($e->getMessage())."\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        return ExitCode::OK;
    }
}
