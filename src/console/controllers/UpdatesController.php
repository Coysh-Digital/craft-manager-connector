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
 * php craft manager-connector/updates
 *
 * Checks for available Craft and plugin updates and reports what is available.
 *
 * Reports whether an update exists and whether any release in between is flagged critical. It does
 * not send release notes: those describe what a version fixes, and forwarding them would put a
 * description of an unpatched vulnerability, attached to this site, into a dashboard.
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
