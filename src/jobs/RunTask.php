<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 *
 * @link      https://managerforcraft.com
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\jobs;

use coyshdigital\managerconnector\Plugin;
use coyshdigital\managerconnector\services\Tasks;
use Craft;
use craft\queue\BaseJob;
use Throwable;
use yii\queue\RetryableJobInterface;

/**
 * Runs one connector task through Craft's queue.
 *
 * This is what makes the plugin usable on hosting with no cron. Ordinary web traffic notices the
 * schedule is due and pushes one of these; Craft's queue runs it out of band, so the visitor whose
 * request happened to trigger it waits for nothing.
 *
 * The task name is validated against the closed set in {@see Tasks::schedule()} rather than trusted.
 * It is set by this plugin's own scheduler and cannot come from a request - but a queue payload is a
 * row in a database, and a row is a thing that can be edited.
 */
class RunTask extends BaseJob implements RetryableJobInterface
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

    /**
     * How long the queue should reserve this job before deciding it has died.
     *
     * The queue's default is five minutes. That is fine for a heartbeat and wrong for the job that
     * takes a backup: a large site dumps, encrypts and uploads for hours, and a queue that reclaims a
     * job partway through does not stop the work already running - it starts a second copy of it, on
     * a server currently writing a copy of its own database to its own disk.
     *
     * Derived from `uploadTimeout` rather than chosen independently, so an operator who has already
     * raised the one number describing a slow upload does not have to find this one too. The upload
     * is the long phase; the half hour on top covers the dump and the encryption.
     */
    public function getTtr(): int
    {
        return Plugin::getInstance()->getSettings()->uploadTimeout + 1800;
    }

    /**
     * Never automatically.
     *
     * This preserves the behaviour this job already had - the queue's default is a single attempt —
     * and it is worth stating rather than inheriting now that the interface asks. A failed backup is
     * re-attempted by the schedule, on the platform's terms and with a fresh claim; retrying inside
     * the queue would instead take a second dump of a production database because the first upload
     * timed out.
     *
     * @param  int  $attempt
     * @param  \Throwable  $error
     */
    public function canRetry($attempt, $error): bool
    {
        return false;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('manager-connector', 'Manager Connector: {task}', ['task' => $this->task]);
    }
}
