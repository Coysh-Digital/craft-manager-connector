<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\migrations;

use coyshdigital\managerconnector\records\ConnectionRecord;
use craft\db\Migration;

/**
 * Somewhere to keep the platform's artifact encryption key.
 *
 * Nullable and unbackfilled on purpose. An existing connection learns the key from its next
 * signature-verified claim response, the same way it learns its capability set — there is nothing
 * useful to guess at here, and a column defaulted to some other platform's key would be worse than an
 * empty one.
 */
class m260730_120000_add_backup_public_key extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(ConnectionRecord::tableName(), 'platformBackupPublicKey')) {
            $this->addColumn(
                ConnectionRecord::tableName(),
                'platformBackupPublicKey',
                (string) $this->string(64)->after('platformPublicKey'),
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(ConnectionRecord::tableName(), 'platformBackupPublicKey')) {
            $this->dropColumn(ConnectionRecord::tableName(), 'platformBackupPublicKey');
        }

        return true;
    }
}
