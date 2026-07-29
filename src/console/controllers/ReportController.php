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
 * php craft manager-connector/report
 *
 * Sends an inventory report. Runs less often than the heartbeat — hourly is plenty, since version
 * numbers do not change between deployments.
 */
class ReportController extends BaseController
{
    public function actionIndex(): int
    {
        if (($refusal = $this->requireActiveConnection()) !== null) {
            return $refusal;
        }

        $plugin = $this->plugin();

        if (! $plugin->connection->hasCapability('inventory:read')) {
            $this->stderr("The platform has not granted inventory:read to this site.\n", Console::FG_YELLOW);

            return ExitCode::UNAVAILABLE;
        }

        ['payload' => $payload, 'problems' => $problems] = $plugin->reporter->buildValidated();

        if ($problems !== []) {
            // Caught here rather than as a rejection somebody has to go looking for. The platform
            // validates again on arrival regardless.
            $this->stderr("This report does not satisfy the agreed schema and was not sent:\n", Console::FG_RED);

            foreach ($problems as $problem) {
                $this->stderr("  - {$problem}\n");
            }

            return ExitCode::DATAERR;
        }

        try {
            $plugin->client->post('/api/connector/v1/inventory', $payload);
        } catch (Throwable $e) {
            $this->stderr('Report failed: '.$e->getMessage()."\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $this->stdout("Inventory reported.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
