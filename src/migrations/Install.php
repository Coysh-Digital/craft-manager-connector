<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 *
 * @link      https://managerforcraft.com
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\migrations;

use coyshdigital\managerconnector\records\ConnectionRecord;
use craft\db\Migration;

/**
 * Creates the single table this plugin owns.
 *
 * One table, one row. A connector that needed a schema would be a second application inside Craft,
 * which is exactly what the specification says this must not become.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists(ConnectionRecord::tableName())) {
            return true;
        }

        $this->createTable(ConnectionRecord::tableName(), [
            'id' => $this->primaryKey(),
            'platformUrl' => $this->string(255)->notNull(),
            'siteIdentifier' => $this->string(64)->notNull(),

            // base64 Ed25519, 32 raw bytes.
            'publicKey' => $this->string(64)->notNull(),

            // Ciphertext, not the key. Encrypted with Craft's security key so that a database
            // dump on its own confers no ability to sign as this site.
            'secretKey' => $this->text()->notNull(),

            // The platform's public key, learned during pairing. Every instruction the platform
            // sends is verified against this before it is acted on.
            'platformPublicKey' => $this->string(64)->notNull(),

            // The platform's X25519 encryption key, separate from the signing key above and used
            // only to seal a backup artifact's key. Nullable: a platform with no backup key
            // configured tells us nothing, and a connector that was told nothing seals nothing
            // rather than guessing.
            'platformBackupPublicKey' => $this->string(64),

            // The artifact format this site has committed to. A ratchet: raised to v2 the first time
            // this site pins recovery fingerprints or completes a v2 backup, and never lowered by a
            // response. Craft marks every non-install migration as applied on a fresh install without
            // running it, so a column that exists only in m260805_090000 exists only on sites that
            // upgraded into it — it has to be created here as well.
            'backupFormatFloor' => $this->string(8)->notNull()->defaultValue('v1'),

            // Which runtime-report schema the platform understands, learned from its own replies and
            // defaulting to the oldest. Same reason as the line above for existing here as well as
            // in its own migration.
            'systemReportSchema' => $this->string(32)->notNull()->defaultValue('system.v1'),

            'capabilities' => $this->text(),
            'state' => $this->string(32)->notNull()->defaultValue(ConnectionRecord::STATE_ACTIVE),
            'pairedAt' => $this->dateTime(),
            'lastSuccessAt' => $this->dateTime(),
            'keyRotatedAt' => $this->dateTime(),
            'connectorVersion' => $this->string(32),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(ConnectionRecord::tableName());

        return true;
    }
}
