<?php

/**
 * Manager Connector plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\jobs;

use coyshdigital\managerconnector\Plugin;
use coyshdigital\managerconnector\services\Tasks;
use Craft;
use craft\queue\BaseJob;
use Throwable;

/**
 * Runs one connector task through Craft's queue.
 *
 * This is what makes the plugin usable on hosting with no cron. Ordinary web traffic notices the
 * schedule is due and pushes one of these; Craft's queue runs it out of band, so the visitor whose
 * request happened to trigger it waits for nothing.
 *
 * The task name is validated against the closed set in {@see Tasks::schedule()} rather than trusted.
 * It is set by this plugin's own scheduler and cannot come from a request — but a queue payload is a
 * row in a database, and a row is a thing that can be edited.
 */
class RunTask extends BaseJob
{
    /**
     * @var string One of the Tasks constants.
     */
    public string $task = Tasks::HEARTBEAT;

    public function execute($queue): void
    {
        if (!Tasks::isKnown($this->task)) {
            // Refused rather than attempted. Failing loudly here is right: something has put a value in
            // this payload that this plugin never generates.
            throw new \RuntimeException("'{$this->task}' is not a task this connector implements.");
        }

        try {
            $outcome = Plugin::getInstance()->tasks->run($this->task);

            Craft::info("Manager Connector {$this->task}: {$outcome}", 'manager-connector');
        } catch (Throwable $e) {
            // Logged and rethrown, so Craft retries on its own terms. Reporting is not urgent enough to
            // warrant a custom retry policy, and one missed heartbeat is visible in Manager as a gap
            // rather than as an outage.
            Craft::warning(
                "Manager Connector {$this->task} failed: " . $e->getMessage(),
                'manager-connector',
            );

            throw $e;
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('manager-connector', 'Manager Connector: {task}', ['task' => $this->task]);
    }
}
