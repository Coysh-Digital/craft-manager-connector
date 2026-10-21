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
 * php craft manager-connector/run
 *
 * The one cron entry, every five minutes. Sends the heartbeat and collects work every time it fires,
 * and runs each of the other tasks when it is due.
 *
 * "Due" is decided by the same cache claims the web trigger uses, so the two share one clock. Cron
 * and web traffic cannot both fire a task in the same interval, which is why this is safe to run on
 * a site that still has `webTrigger` on - and why turning that off is recommended rather than needed.
 * See {@see \coyshdigital\managerconnector\services\Scheduler::selectDue()} for the decision itself.
 *
 * Tasks run here, in this process, one after another, and not through Craft's queue: a cron entry is
 * the thing that is supposed to make this plugin independent of it. One task failing does not stop
 * the rest, because a platform that rejects the sign-in report has said nothing about the heartbeat.
 * The exit code is non-zero if any did, so cron's own mail or a monitor on the exit status sees it.
 */
class RunController extends BaseController
{
    /**
     * @var bool Run every task now, whatever the schedule says.
     */
    public bool $force = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['force']);
    }

    public function actionIndex(): int
    {
        // Checked once, here, before anything is claimed. Each task would refuse for the same reason,
        // but only after this had used up a claim on every one of them.
        if (($refused = $this->requireActiveConnection()) !== null) {
            return $refused;
        }

        $scheduler = $this->plugin()->scheduler;
        $due = $scheduler->claimDue(force: (bool) $this->force);
        $failed = 0;

        foreach ($due as $task) {
            try {
                $this->stdout($task . ': ' . $this->plugin()->tasks->run($task) . "\n", Console::FG_GREEN);
            } catch (Throwable $e) {
                $failed++;
                $this->stderr($task . ': ' . ucfirst($e->getMessage()) . "\n", Console::FG_RED);
            }
        }

        $skipped = array_values(array_diff(array_keys(Tasks::schedule()), $due));

        if ($skipped !== []) {
            $this->stdout('Not due: ' . implode(', ', $skipped) . "\n");
        }

        return $failed === 0 ? ExitCode::OK : ExitCode::UNAVAILABLE;
    }
}
