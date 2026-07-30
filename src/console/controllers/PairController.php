<?php

/**
 * Manager Connector plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\console\controllers;

use coyshdigital\managerconnector\records\ConnectionRecord;
use craft\helpers\Console;
use Throwable;
use yii\console\ExitCode;

/**
 * php craft manager-connector/pair <enrolment-code>
 *
 * Generates this site's keypair and exchanges a single-use enrolment code for an identity.
 * The private key is created here and never leaves this machine.
 */
class PairController extends BaseController
{
    /**
     * @var string|null Platform URL, overriding the configured one.
     */
    public ?string $platformUrl = null;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['platformUrl']);
    }

    public function actionIndex(string $enrolmentCode): int
    {
        $plugin = $this->plugin();
        $platformUrl = $this->platformUrl ?? $plugin->getSettings()->platformUrl;

        if ($platformUrl === '') {
            $this->stderr("No platform URL configured.\n", Console::FG_RED);
            $this->stdout("Set 'platformUrl' in config/manager-connector.php, or pass --platform-url.\n");

            return ExitCode::CONFIG;
        }

        if ($plugin->connection->isPaired()) {
            $this->stderr("This site is already paired.\n", Console::FG_RED);
            $this->stdout("Disconnect first, and have an administrator authorise the replacement when\n");
            $this->stdout("issuing the new enrolment code.\n");

            return ExitCode::UNAVAILABLE;
        }

        $keypair = $plugin->connection->generateKeypair();

        try {
            $response = $plugin->client->pair($platformUrl, $enrolmentCode, $keypair);
        } catch (Throwable $e) {
            $this->stderr('Pairing failed: '.$e->getMessage()."\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $isLive = ($response['state'] ?? '') === 'active';

        $plugin->connection->store(
            platformUrl: $platformUrl,
            siteIdentifier: (string) ($response['site_id'] ?? ''),
            keypair: $keypair,
            platformPublicKey: (string) ($response['platform_public_key'] ?? ''),
            capabilities: $response['capabilities'] ?? [],
            state: $isLive ? ConnectionRecord::STATE_ACTIVE : ConnectionRecord::STATE_PENDING_CONFIRMATION,

            // Null when the platform has no backup key configured. Stored from this verified response
            // and refreshed from every later one, so a rotation follows automatically.
            platformBackupPublicKey: is_string($response['backup_public_key'] ?? null) && $response['backup_public_key'] !== ''
                ? (string) $response['backup_public_key']
                : null,
        );

        if (! $isLive) {
            $this->stdout("Paired, but held for confirmation.\n", Console::FG_YELLOW);
            $this->stdout(sprintf(
                "The platform expected '%s' and this site reported a different host, so nothing is\n".
                "reported until an administrator confirms the pairing in Manager.\n",
                (string) ($response['expected_domain'] ?? 'a different domain'),
            ));

            return ExitCode::OK;
        }

        $this->stdout("Paired successfully.\n", Console::FG_GREEN);
        $this->stdout('  Granted: '.(implode(', ', $response['capabilities'] ?? []) ?: 'nothing yet')."\n");
        $this->stdout("  Next: add 'php craft manager-connector/heartbeat' to cron.\n");

        return ExitCode::OK;
    }
}
