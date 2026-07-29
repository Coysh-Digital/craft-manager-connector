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
        if (($refusal = $this->requireActiveConnection()) !== null) {
            return $refusal;
        }

        $plugin = $this->plugin();

        if (! $plugin->connection->hasCapability('updates:read')) {
            $this->stderr("The platform has not granted updates:read to this site.\n", Console::FG_YELLOW);

            return ExitCode::UNAVAILABLE;
        }

        ['payload' => $payload, 'problems' => $problems] = $plugin->updates->buildValidated($this->force);

        if ($problems !== []) {
            $this->stderr("This report does not satisfy the agreed schema and was not sent:\n", Console::FG_RED);

            foreach ($problems as $problem) {
                $this->stderr("  - {$problem}\n");
            }

            return ExitCode::DATAERR;
        }

        try {
            $plugin->client->post('/api/connector/v1/updates', $payload);
        } catch (Throwable $e) {
            $this->stderr('Update report failed: '.$e->getMessage()."\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $this->stdout("Updates reported.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
