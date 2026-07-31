<?php

/**
 * Manager Connector plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\migrations;

use craft\db\Migration;

/**
 * Records the artifact format this site has committed to, permanently.
 *
 * A ratchet rather than a setting. It goes to `v2` the first time this site either finds recovery-key
 * fingerprints pinned in its own configuration or completes a v2 backup, and after that no response
 * from the platform can lower it.
 *
 * That asymmetry is the whole point. Every other defence against a compromised platform is something
 * the platform participates in, and therefore something a compromised one can decline to do. This is a
 * value in this site's own database, changed only by this site's own code, and lowering it means
 * editing a file on this server. It is the one downgrade control that survives the platform being the
 * adversary.
 *
 * Not backfilled. A site that has never taken a v2 backup starts at `v1`, which is the honest reading
 * of its history, and the first pinned fingerprint moves it.
 */
class m260805_090000_add_backup_format_floor extends Migration
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
        // Deliberately does not drop the column. Rolling this migration back on a site that has taken
        // zero-knowledge backups would put it back on a format the platform can read, which is not
        // something a schema rollback should be able to decide.
        echo "m260805_090000_add_backup_format_floor cannot be reverted: the column records a security commitment.\n";

        return false;
    }
}
