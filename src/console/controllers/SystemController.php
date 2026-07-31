<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
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
 * php craft manager-connector/system
 *
 * Reports disk usage, PHP limits and sampled response timings.
 *
 * The expensive one: it walks the asset volumes, bounded by a time budget, so a volume too large to
 * measure inside it is reported as unmeasured rather than as empty. Six-hourly on the schedule —
 * disk usage moves over days, and a directory walk every hour on a million-file volume is a cost the
 * site pays for a number nobody reads that often.
 *
 * Sends byte counts and numeric limits. Never a path, never a file name, never a listing.
 */
class SystemController extends BaseController
{
    public function actionIndex(): int
    {
        try {
            $this->stdout($this->plugin()->tasks->system() . "\n", Console::FG_GREEN);
        } catch (Throwable $e) {
            $this->stderr(ucfirst($e->getMessage()) . "\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        return ExitCode::OK;
    }
}
