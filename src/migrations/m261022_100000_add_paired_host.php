<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 *
 * @link      https://managerforcraft.com
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\migrations;

use Craft;
use craft\db\Migration;

/**
 * The host this pairing was made from.
 *
 * A backup of a production database carries this table with it, and restoring one into a development
 * environment restores a signing key that works. The restored site then reports as production - or,
 * where the two environments have different security keys, queues a job every few minutes that can
 * only fail. Recording the host the pairing belongs to is what lets a copy tell that it is one.
 *
 * Backfilled with the host this database is being migrated on, which is the right answer exactly
 * once: each database runs this migration for itself, so production binds to the production host, and
 * a dev database restored from it afterwards finds that value waiting and goes quiet. A database that
 * was already restored into dev *before* upgrading binds to the dev host instead, and the only remedy
 * is to disconnect it there - there is nothing in the row that says which host it was made on.
 */
class m261022_100000_add_paired_host extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%managerconnector_connection}}';

        if (!$this->db->columnExists($table, 'pairedHost')) {
            $this->addColumn($table, 'pairedHost', (string) $this->string(255)->after('siteIdentifier'));
        }

        $site = Craft::$app->getSites()->getPrimarySite();
        $host = strtolower((string) parse_url((string) $site->getBaseUrl(), PHP_URL_HOST));

        // Left null when no host can be worked out, which the service reads as "no binding" rather
        // than as a mismatch. An empty string is not written for the same reason.
        if ($host !== '') {
            $this->update($table, ['pairedHost' => $host], ['pairedHost' => null], [], false);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%managerconnector_connection}}';

        if ($this->db->columnExists($table, 'pairedHost')) {
            $this->dropColumn($table, 'pairedHost');
        }

        return true;
    }
}
