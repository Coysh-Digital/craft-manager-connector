<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 *
 * @link      https://managerforcraft.com
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\migrations;

use craft\db\Migration;

/**
 * Repairs sites whose connection table never got `backupFormatFloor`.
 *
 * The column was introduced in 1.7.0 by `m260805_090000_add_backup_format_floor` but never added to
 * `Install`, and Craft installs a plugin by running the install migration and then marking every
 * other migration as applied *without running it* (`craft\base\Plugin::install()`). So a site that
 * upgraded into 1.7.0 ran the migration and has the column, and a site that installed 1.7.0 or later
 * from scratch has the migration recorded as applied and no column at all.
 *
 * The symptom is not a missing feature. `ConnectionRecord` resolves its attributes from the table, so
 * the first read raises `Getting unknown property: ...ConnectionRecord::backupFormatFloor` - which
 * reaches the backup path, where `BackupRunner` asks for the floor before deciding a format.
 *
 * `Install` now creates the column, which fixes the next fresh install but not the ones already out
 * there: their migration history says this work is done. Hence a new migration rather than an edit to
 * the old one. Editing that would change nothing on the sites that need it, since it is already
 * recorded as applied.
 *
 * Idempotent, and safe to run beside the original: whichever reaches the column first creates it, and
 * `v1` is the correct starting value either way. A site that has genuinely taken v2 backups already
 * had the column and does not pass through here.
 */
class m260731_210000_backfill_backup_format_floor extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%managerconnector_connection}}';

        if (!$this->db->columnExists($table, 'backupFormatFloor')) {
            $this->addColumn($table, 'backupFormatFloor', (string) $this->string(8)->notNull()->defaultValue('v1')->after('platformBackupPublicKey'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        // Same reasoning as the migration this repairs: dropping the column would put a site back on a
        // format the platform can read, which is not a decision a schema rollback should make.
        echo "m260731_210000_backfill_backup_format_floor cannot be reverted: the column records a security commitment.\n";

        return false;
    }
}
