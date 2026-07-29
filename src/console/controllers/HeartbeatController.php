<?php

/**
 * Manager Connector plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\console\controllers;

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
        if (($refusal = $this->requireActiveConnection()) !== null) {
            return $refusal;
        }

        try {
            $this->plugin()->client->post('/api/connector/v1/heartbeat', []);
        } catch (Throwable $e) {
            $this->stderr('Heartbeat failed: '.$e->getMessage()."\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $this->stdout("Heartbeat sent.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
