<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 *
 * @link      https://managerforcraft.com
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\console\controllers;

use craft\helpers\Console;
use Throwable;
use yii\console\ExitCode;

/**
 * php craft manager-connector/logins
 *
 * Reports counts of failed control-panel sign-ins.
 *
 * Counts, and nothing else - no username, no email address, no source address, no per-attempt
 * record. The operator's question is "is this site being attacked, and is anybody locked out", and
 * that is answered by four integers; a log of who tried to sign in as whom would be a record of real
 * people's behaviour on somebody else's website.
 */
class LoginsController extends BaseController
{
    public function actionIndex(): int
    {
        try {
            $this->stdout($this->plugin()->tasks->logins() . "\n", Console::FG_GREEN);
        } catch (Throwable $e) {
            $this->stderr(ucfirst($e->getMessage()) . "\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        return ExitCode::OK;
    }
}
