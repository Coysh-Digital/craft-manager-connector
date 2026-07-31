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
 * php craft manager-connector/heartbeat
 *
 * Tells the platform this site is alive. Carries no site data whatsoever. Intended for cron,
 * typically every few minutes.
 */
class HeartbeatController extends BaseController
{
    public function actionIndex(): int
    {
        try {
            $this->stdout($this->plugin()->tasks->run(Tasks::HEARTBEAT) . "\n", Console::FG_GREEN);
        } catch (Throwable $e) {
            $this->stderr(ucfirst($e->getMessage()) . "\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        return ExitCode::OK;
    }
}
