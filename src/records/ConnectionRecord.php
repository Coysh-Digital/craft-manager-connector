<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 *
 * @link      https://managerforcraft.com
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\records;

use craft\db\ActiveRecord;
use DateTime;

/**
 * The site's pairing with a Manager platform.
 *
 * There is at most one row. The secret key column holds ciphertext produced with Craft's own
 * security key, so a copy of the database alone does not yield a usable signing key.
 *
 * @property int $id
 * @property string $platformUrl
 * @property string $siteIdentifier the platform's external identifier for this site
 * @property string $publicKey base64 Ed25519
 * @property string $secretKey ciphertext, decrypted only in memory when signing
 * @property string $platformPublicKey base64 Ed25519, used to verify instructions from the platform
 * @property string|null $capabilities JSON list of what the platform has granted
 * @property string|null $platformBackupPublicKey base64 X25519, legacy artifact encryption
 * @property string $backupFormatFloor 'v1' or 'v2'; raised once and never lowered by a response
 * @property string $systemReportSchema the runtime-report version the platform accepts; moves either way
 * @property string $state
 * @property DateTime|null $pairedAt
 * @property DateTime|null $lastSuccessAt
 * @property DateTime|null $keyRotatedAt
 * @property string|null $connectorVersion
 */
class ConnectionRecord extends ActiveRecord
{
    public const STATE_ACTIVE = 'active';

    public const STATE_PENDING_CONFIRMATION = 'pending_confirmation';

    public const STATE_DISCONNECTED = 'disconnected';

    public static function tableName(): string
    {
        return '{{%managerconnector_connection}}';
    }
}
