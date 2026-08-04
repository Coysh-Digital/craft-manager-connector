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
 * Which runtime-report schema the platform this site reports to understands.
 *
 * Not a ratchet, and deliberately unlike `backupFormatFloor` beside it. That one is a security
 * commitment this site makes about itself and no response may lower; this is a fact about somebody
 * else's software, learned from that software, and it can move in both directions - a platform can
 * be rolled back, and a site pointed at a different one entirely.
 *
 * Defaults to the oldest version, because assuming the newest is what makes an upgrade a flag day.
 * The two sides are upgraded by different people on different days: whoever runs the platform
 * upgrades it, and each site upgrades its own plugin. A connector that assumed the newer schema
 * would have its reports refused by any platform that had not caught up, and a runtime report is
 * fire-and-forget - the only symptom is a Health screen that quietly stops moving.
 */
class m260806_100000_add_system_report_schema extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%managerconnector_connection}}';

        if (!$this->db->columnExists($table, 'systemReportSchema')) {
            $this->addColumn($table, 'systemReportSchema', (string) $this->string(32)->notNull()->defaultValue('system.v1')->after('backupFormatFloor'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%managerconnector_connection}}';

        // Safe to drop, unlike the format floor: losing it means this site goes back to sending the
        // oldest report version, which every platform accepts.
        if ($this->db->columnExists($table, 'systemReportSchema')) {
            $this->dropColumn($table, 'systemReportSchema');
        }

        return true;
    }
}
