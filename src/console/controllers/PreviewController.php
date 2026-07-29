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
use yii\console\ExitCode;

/**
 * php craft manager-connector/preview
 *
 * Prints exactly what this site would report, without sending it.
 *
 * Here so an administrator can satisfy themselves about what leaves their server rather than
 * taking the documentation's word for it. Works whether or not the site is paired.
 */
class PreviewController extends BaseController
{
    public function actionIndex(): int
    {
        ['payload' => $payload, 'problems' => $problems] = $this->plugin()->reporter->buildValidated();

        $this->stdout(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        if ($problems !== []) {
            $this->stderr("\nThis payload would be rejected by the platform:\n", Console::FG_RED);

            foreach ($problems as $problem) {
                $this->stderr("  - {$problem}\n");
            }

            return ExitCode::DATAERR;
        }

        return ExitCode::OK;
    }
}
