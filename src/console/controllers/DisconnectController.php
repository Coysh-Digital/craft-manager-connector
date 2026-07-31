<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 *
 * @link      https://managerforcraft.com
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\console\controllers;

use Craft;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * php craft manager-connector/disconnect
 *
 * Deletes this site's Manager identity.
 *
 * The keypair is removed outright rather than flagged, so there is nothing left to reactivate.
 * Reconnecting means a new keypair and a new enrolment code, which is the right amount of ceremony
 * for handing a platform access to a production site again.
 */
class DisconnectController extends BaseController
{
    public function actionIndex(): int
    {
        $plugin = $this->plugin();

        if (!$plugin->connection->isPaired()) {
            $this->stdout("This site is not paired.\n");

            return ExitCode::OK;
        }

        if ($this->interactive && !$this->confirm("Delete this site's Manager identity? Reconnecting will need a new enrolment code.")) {
            return ExitCode::OK;
        }

        $plugin->connection->forget();

        Craft::info('Manager Connector disconnected.', 'manager-connector');

        $this->stdout("Disconnected. The signing key has been deleted from this site.\n", Console::FG_GREEN);
        $this->stdout("Revoke the connector in Manager too, so the platform stops expecting it.\n");

        return ExitCode::OK;
    }
}
