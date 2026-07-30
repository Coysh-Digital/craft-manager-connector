<?php

/**
 * Manager Connector plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\console\controllers;

use coyshdigital\managerconnector\Plugin;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * php craft manager-connector/status
 *
 * Shows the current connection. The same facts as the settings screen, for people who live in a
 * terminal.
 */
class StatusController extends BaseController
{
    public function actionIndex(): int
    {
        $plugin = $this->plugin();
        $connection = $plugin->connection->current();

        if ($connection === null) {
            $this->stdout("Not paired with a Manager platform.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->stdout("Manager connection\n", Console::FG_GREEN);
        $this->stdout('  State:             ' . $connection->state . "\n");
        $this->stdout('  Platform:          ' . $connection->platformUrl . "\n");
        $this->stdout('  Site identifier:   ' . $connection->siteIdentifier . "\n");
        $this->stdout('  Capabilities:      ' . (implode(', ', $plugin->connection->capabilities()) ?: 'none') . "\n");
        $this->stdout('  Last success:      ' . ($connection->lastSuccessAt ?? 'never') . "\n");
        $this->stdout('  Key rotated:       ' . ($connection->keyRotatedAt ?? 'never') . "\n");
        $this->stdout('  Connector version: ' . Plugin::VERSION . "\n");

        return ExitCode::OK;
    }
}
