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
 * php craft manager-connector/updates
 *
 * Checks for available Craft and plugin updates and reports what is available.
 *
 * Reports whether an update exists, whether any release in between is flagged critical, and the
 * release notes themselves - bounded per note and across the report. See UpdatesReporter, which
 * holds the reasoning and the limits.
 *
 * This block used to say notes were never sent, on the grounds that forwarding one would put a
 * description of an unpatched vulnerability, attached to this site, into a dashboard. That argument
 * was reconsidered where the code lives rather than here: the notes are public, and what is not
 * public is the pairing of a note with a named site - which the platform already knows, because it
 * is being told the version. The comment stayed behind after the code moved on.
 */
class UpdatesController extends BaseController
{
    /**
     * @var bool Bypass Craft's cached answer.
     */
    public bool $force = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['force']);
    }

    public function actionIndex(): int
    {
        try {
            $this->stdout($this->plugin()->tasks->updates((bool) $this->force) . "\n", Console::FG_GREEN);
        } catch (Throwable $e) {
            $this->stderr(ucfirst($e->getMessage()) . "\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        return ExitCode::OK;
    }
}
