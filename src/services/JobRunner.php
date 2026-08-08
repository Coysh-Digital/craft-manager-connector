<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 *
 * @link      https://managerforcraft.com
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\services;

use coyshdigital\managerconnector\Plugin;
use coyshdigital\managerprotocol\Jobs;
use coyshdigital\managerprotocol\Protocol;
use Craft;
use craft\base\Component;
use craft\helpers\UrlHelper;
use Throwable;

/**
 * Claims work from the platform and runs it.
 *
 * The important property is what this class *cannot* do. It holds a fixed map from job type to
 * handler method, and a type absent from that map is refused - reported back as failed, never
 * attempted. So a platform that has been compromised, or a response that has been tampered with,
 * cannot make this site do anything the site does not already implement.
 *
 * That refusal is the connector's own check, independent of the platform's. The platform refuses to
 * *issue* an unknown type; this refuses to *execute* one. Two independent refusals, because they
 * protect against different failures.
 *
 * There is no dynamic dispatch here on purpose - no call to a method named by the payload, no
 * callable resolved from a string. A match expression over known constants is harder to abuse than
 * any amount of validation around a variable method name.
 */
class JobRunner extends Component
{
    /**
     * What this site sends when it claims work.
     *
     * Just the path the platform should knock on to ask for an early check-in, and only when this site
     * is willing to answer one. It rides on the claim rather than on pairing because pairing happens
     * once: an `actionTrigger` rename, a move into a subfolder or a plugin upgrade would otherwise break
     * nudging silently and permanently, whereas this is re-stated every time the site checks in and
     * therefore heals itself. Not on the heartbeat either, which is deliberately content-free.
     *
     * **A path, never a URL.** The platform pins the host to the domain its operator typed and takes
     * only the path from here - the mirror image of the rule this connector already enforces on itself,
     * where an upload host is derived from the configured platform URL rather than accepted from a
     * response. Neither side lets the other choose where it connects.
     *
     * @return array<string, string>
     */
    private function claimBody(): array
    {
        if (!Plugin::getInstance()->getSettings()->acceptNudges) {
            return [];
        }

        $path = parse_url(UrlHelper::actionUrl('manager-connector/nudge/poll'), PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            return [];
        }

        $query = parse_url(UrlHelper::actionUrl('manager-connector/nudge/poll'), PHP_URL_QUERY);

        // `omitScriptNameInUrls = false` puts the action behind `index.php?p=...`, so the query is part
        // of the address rather than decoration and has to travel with it.
        return ['nudge_path' => is_string($query) && $query !== '' ? $path . '?' . $query : $path];
    }

    /**
     * Claim and run whatever is waiting.
     *
     * @return array{claimed: int, succeeded: int, failed: int, refused: int}
     */
    public function run(): array
    {
        $plugin = Plugin::getInstance();

        // expectSigned: this response carries instructions, so an unverified one is discarded.
        $response = $plugin->client->post(
            '/api/connector/v1/jobs/claim',
            $this->claimBody(),
            expectSigned: true,
        );

        // The platform's current view of what this site may do. Adopted from a verified response, so
        // the connector stays in step when a capability is granted or revoked - without it, the
        // capability list would stay frozen at whatever pairing agreed, and granting one on the
        // platform would silently change nothing.
        if (isset($response['capabilities']) && is_array($response['capabilities'])) {
            $plugin->connection->updateCapabilities(array_values($response['capabilities']));
        }

        // Same rule, same reason: security-sensitive configuration, adopted only from a verified
        // response. Without this the connector would never learn a rotated artifact encryption key,
        // and every backup after a rotation would be sealed to a key the platform no longer holds.
        if (array_key_exists('backup_public_key', $response)) {
            $key = $response['backup_public_key'];

            $plugin->connection->updateBackupPublicKey(is_string($key) && $key !== '' ? $key : null);
        }

        /*
         | Which format to produce, and which recovery keys to seal to.
         |
         | Held in a local rather than stored. The backup job is handled later in this same request, so
         | there is nothing to persist, and a recipient list written to disk is one that can go stale —
         | a key revoked this morning must not be sealed to this evening because a row still named it.
         |
         | Adopting this from a signature-verified response proves only that the platform said it. It
         | does not make it true, and a compromised platform could name a key of its own. What stops
         | that is {@see RecipientPin}, which refuses anything not pinned in a file on this server.
         */
        $backup = is_array($response['backup'] ?? null) ? $response['backup'] : [];

        $format = is_string($backup['format'] ?? null)
            ? $backup['format']
            : Protocol::BACKUP_FORMAT_V1;

        $recipients = is_array($backup['recipients'] ?? null)
            ? array_values(array_filter($backup['recipients'], 'is_array'))
            : [];

        /*
         | Which declaration schemas this platform accepts, most preferred first.
         |
         | Absent means a platform older than backup.v3, and the connector falls back to v2 - which is
         | the whole reason this is advertised rather than assumed. A connector that simply started
         | sending v3 would break against every platform in the field until each one upgraded, and the
         | thing it would break is backups.
         |
         | Read here rather than from a job's parameters deliberately. Which formats a platform can
         | read is a property of the platform, not of one job: a job claimed before an upgrade and run
         | after it would otherwise instruct a format that is no longer the right answer. A size may
         | travel in a job payload because a size can only ever cause less work; a schema version
         | selects a code path.
         */
        $declarations = is_array($backup['declarations'] ?? null)
            ? array_values(array_filter($backup['declarations'], 'is_string'))
            : [];

        // What this platform will accept, so a site with a large database can be refused before it
        // dumps rather than after. A ceiling, never a destination.
        $maxArtifactBytes = isset($backup['max_artifact_bytes']) && is_int($backup['max_artifact_bytes'])
            ? $backup['max_artifact_bytes']
            : null;

        /** @var list<array<string, mixed>> $jobs */
        $jobs = $response['jobs'] ?? [];

        $tally = ['claimed' => count($jobs), 'succeeded' => 0, 'failed' => 0, 'refused' => 0];

        foreach ($jobs as $job) {
            $type = (string) ($job['type'] ?? '');
            $id = (string) ($job['id'] ?? '');

            if ($id === '') {
                continue;
            }

            // The connector's own allowlist. A type this build does not implement is refused rather
            // than attempted, whatever the platform said.
            if (!$this->canHandle($type)) {
                $tally['refused']++;

                $this->report($id, false, [], "this connector does not implement '{$type}'");

                Craft::warning(
                    "Manager Connector refused an unrecognised job type: {$type}",
                    'manager-connector',
                );

                continue;
            }

            try {
                $result = $this->handle($type, $id, $job['parameters'] ?? [], $recipients, $format, $declarations, $maxArtifactBytes);

                $this->report($id, true, $result);
                $tally['succeeded']++;
            } catch (Throwable $e) {
                $tally['failed']++;

                // The message is the connector's own, so it is safe to send. Site content never
                // reaches an exception message here because nothing in a handler touches content.
                $this->report($id, false, [], $this->safeFailureMessage($e));

                Craft::error(
                    'Manager Connector job failed: ' . $e->getMessage(),
                    'manager-connector',
                );
            }
        }

        return $tally;
    }

    /**
     * Whether this build implements a job type.
     */
    public function canHandle(string $type): bool
    {
        return in_array($type, [
            Jobs::INVENTORY_REFRESH,
            Jobs::UPDATES_CHECK,
            Jobs::BACKUP_CREATE,
        ], true);
    }

    /**
     * Dispatch to a handler.
     *
     * A match over constants rather than a method name derived from the payload. There is nothing
     * here for a crafted job type to reach.
     *
     * @param  array<string, mixed>  $parameters
     * @param  list<array<string, mixed>>  $recipients
     * @param  list<string>  $declarations  schemas the platform accepts, most preferred first
     * @return array<string, mixed>
     */
    private function handle(
        string $type,
        string $jobId,
        array $parameters,
        array $recipients = [],
        string $format = Protocol::BACKUP_FORMAT_V1,
        array $declarations = [],
        ?int $maxArtifactBytes = null,
    ): array {
        return match ($type) {
            Jobs::INVENTORY_REFRESH => $this->refreshInventory(),
            Jobs::UPDATES_CHECK => $this->checkUpdates($parameters),

            /*
             | The job identifier, the recipient list, and a size ceiling. Never a destination.
             |
             | Where the artifact goes still comes from the connection record and nowhere else; who
             | can open it is checked against this site's own pinned fingerprints before anything is
             | dumped. Those are the two the platform may not influence, and a build check enforces
             | both by refusing any read of a url, endpoint or destination from here.
             |
             | A size ceiling is a different kind of thing: it can only ever permit less work than
             | this site would otherwise do, or lift a limit on a platform that has taken
             | responsibility for storing the result. It cannot redirect a backup or widen who can
             | read one.
             */
            Jobs::BACKUP_CREATE => Plugin::getInstance()->backups->run(
                $jobId,
                $recipients,
                $format,
                isset($parameters['max_megabytes']) ? (int) $parameters['max_megabytes'] : null,
                $declarations,
                $maxArtifactBytes,
            ),
            // Unreachable: canHandle() gates this. Present so adding a constant without a handler
            // fails loudly rather than silently doing nothing.
            default => throw new \LogicException("No handler for '{$type}'."),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function refreshInventory(): array
    {
        $plugin = Plugin::getInstance();

        ['payload' => $payload, 'problems' => $problems] = $plugin->reporter->buildValidated();

        if ($problems !== []) {
            throw new \RuntimeException('the inventory report did not satisfy the agreed schema');
        }

        $plugin->client->post('/api/connector/v1/inventory', $payload);

        return ['reported' => true, 'schema_version' => 'inventory.v1'];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function checkUpdates(array $parameters): array
    {
        $plugin = Plugin::getInstance();

        ['payload' => $payload, 'problems' => $problems] = $plugin->updates
            ->buildValidated(force: (bool) ($parameters['force'] ?? false));

        if ($problems !== []) {
            throw new \RuntimeException('the update report did not satisfy the agreed schema');
        }

        $plugin->client->post('/api/connector/v1/updates', $payload);

        $pluginUpdates = 0;
        $securityReleases = (bool) ($payload['craft']['security_release_available'] ?? false) ? 1 : 0;

        foreach ($payload['plugins'] ?? [] as $reported) {
            if ((bool) ($reported['update_available'] ?? false)) {
                $pluginUpdates++;
            }

            if ((bool) ($reported['security_release_available'] ?? false)) {
                $securityReleases++;
            }
        }

        return [
            'reported' => true,
            'craft_update_available' => (bool) ($payload['craft']['update_available'] ?? false),
            'plugin_updates' => $pluginUpdates,
            'security_releases' => $securityReleases,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function report(string $jobId, bool $succeeded, array $result = [], ?string $failureReason = null): void
    {
        try {
            Plugin::getInstance()->client->post("/api/connector/v1/jobs/{$jobId}/result", array_filter([
                'succeeded' => $succeeded,
                'result' => $result === [] ? null : $result,
                'failure_reason' => $failureReason,
            ], static fn(mixed $value): bool => $value !== null));
        } catch (Throwable $e) {
            // Reporting failed, not the job. Left unreported, the platform expires it after the
            // definition's maximum runtime - which is the right outcome, and better than retrying
            // here and risking running the work twice.
            Craft::error(
                'Manager Connector could not report a job result: ' . $e->getMessage(),
                'manager-connector',
            );
        }
    }

    /**
     * A failure message safe to send.
     *
     * An exception from deep in Craft can carry a query, a path or a value. The platform gets the
     * class name and a bounded message, which is enough to diagnose a connector without becoming a
     * way to exfiltrate anything.
     */
    private function safeFailureMessage(Throwable $e): string
    {
        $shortName = (new \ReflectionClass($e))->getShortName();

        return mb_substr($shortName . ': ' . $e->getMessage(), 0, 200);
    }
}
