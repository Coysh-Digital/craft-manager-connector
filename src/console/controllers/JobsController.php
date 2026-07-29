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
        if (($refusal = $this->requireActiveConnection()) !== null) {
            return $refusal;
        }

        try {
            $tally = $this->plugin()->jobs->run();
        } catch (Throwable $e) {
            $this->stderr('Could not claim jobs: '.$e->getMessage()."\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        if ($tally['claimed'] === 0) {
            $this->stdout("Nothing to do.\n");

            return ExitCode::OK;
        }

        $this->stdout(sprintf(
            "%d claimed: %d succeeded, %d failed, %d refused.\n",
            $tally['claimed'],
            $tally['succeeded'],
            $tally['failed'],
            $tally['refused'],
        ), $tally['failed'] + $tally['refused'] > 0 ? Console::FG_YELLOW : Console::FG_GREEN);

        if ($tally['refused'] > 0) {
            $this->stdout("  Refused jobs are types this connector version does not implement.\n");
            $this->stdout("  Upgrade the connector, or check why the platform is issuing them.\n");
        }

        return ExitCode::OK;
    }
}
