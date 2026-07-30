<?php

/**
 * Manager Connector plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\console\controllers;

use coyshdigital\managerconnector\services\Tasks;
use craft\helpers\Console;
use Throwable;
use yii\console\ExitCode;

/**
 * php craft manager-connector/preview
 *
 * Prints exactly what this site would report, without sending it.
 *
 * Here so an administrator can satisfy themselves about what leaves their server rather than taking
 * the documentation's word for it. Works whether or not the site is paired, and — deliberately —
 * whether or not the capability behind each report has been granted: the question this answers is
 * "what would this reveal if I turned it on", which has to be answerable *before* turning it on.
 *
 * Every report the connector can produce, not only the inventory one. A preview covering a subset
 * would be worse than no preview at all, because it would look complete.
 */
class PreviewController extends BaseController
{
    /**
     * @var string|null Show one report only, e.g. --report=logins.
     */
    public ?string $report = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['report']);
    }

    public function actionIndex(): int
    {
        $plugin = $this->plugin();

        $reports = [
            Tasks::REPORT => ['inventory', 'inventory:read', fn(): array => $plugin->reporter->buildValidated()],
            Tasks::UPDATES => ['updates', 'updates:read', fn(): array => $plugin->updates->buildValidated()],
            Tasks::SYSTEM => ['system', 'runtime:read', fn(): array => $plugin->system->buildValidated()],
            Tasks::LOGINS => ['logins', 'logins:read', fn(): array => $plugin->logins->buildValidated()],
        ];

        if ($this->report !== null && !isset($reports[$this->report])) {
            $this->stderr(sprintf(
                "'%s' is not a report this connector produces. Try: %s\n",
                $this->report,
                implode(', ', array_keys($reports)),
            ), Console::FG_RED);

            return ExitCode::USAGE;
        }

        $failed = false;

        foreach ($reports as $task => [$label, $capability, $build]) {
            if ($this->report !== null && $this->report !== $task) {
                continue;
            }

            $granted = $plugin->connection->hasCapability($capability);

            $this->stdout("\n" . str_repeat('-', 72) . "\n");
            $this->stdout($label, Console::BOLD);
            $this->stdout(sprintf(
                "  (%s: %s)\n",
                $capability,
                $granted ? 'granted' : 'NOT granted, so none of this is currently being sent',
            ), $granted ? Console::FG_GREEN : Console::FG_YELLOW);
            $this->stdout(str_repeat('-', 72) . "\n\n");

            try {
                ['payload' => $payload, 'problems' => $problems] = $build();
            } catch (Throwable $e) {
                // One report failing to build must not hide the others. Somebody running this is
                // auditing what leaves their server, and a partial answer that looks whole is the
                // failure worth designing out.
                $this->stderr('Could not build this report: ' . $e->getMessage() . "\n", Console::FG_RED);
                $failed = true;

                continue;
            }

            $this->stdout(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

            if ($problems !== []) {
                $this->stderr("\nThis payload would be rejected by the platform:\n", Console::FG_RED);

                foreach ($problems as $problem) {
                    $this->stderr("  - {$problem}\n");
                }

                $failed = true;
            }
        }

        return $failed ? ExitCode::DATAERR : ExitCode::OK;
    }
}
