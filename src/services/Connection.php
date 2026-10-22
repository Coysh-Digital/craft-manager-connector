<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 *
 * @link      https://managerforcraft.com
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\services;

use coyshdigital\managerconnector\records\ConnectionRecord;
use coyshdigital\managerprotocol\Keys;
use coyshdigital\managerprotocol\Protocol;
use coyshdigital\managerprotocol\Sealing;
use Craft;
use craft\base\Component;
use DateTime;
use RuntimeException;

/**
 * Owns this site's cryptographic identity and its pairing state.
 *
 * The keypair is generated here, on this machine, and the private half never leaves it. That is
 * what makes a stolen copy of the platform database worthless for impersonating any site: all the
 * platform ever receives is the public key.
 *
 * The secret is held encrypted at rest using Craft's own security key, and is decrypted only
 * inside {@see self::secretKey()} for the moment it takes to sign a request.
 */
class Connection extends Component
{
    private ?ConnectionRecord $cached = null;

    private ?bool $belongsHere = null;

    /**
     * The current pairing, if this site has one.
     */
    public function current(): ?ConnectionRecord
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $record = ConnectionRecord::find()->one();

        return $this->cached = $record instanceof ConnectionRecord ? $record : null;
    }

    public function isPaired(): bool
    {
        $record = $this->current();

        return $record !== null && $record->state !== ConnectionRecord::STATE_DISCONNECTED;
    }

    /**
     * Whether this site is paired, confirmed, and being served from the host it was paired on.
     *
     * The host test is what keeps a restored copy of a production database quiet. This is the gate
     * the scheduler, the task runner, the cron entry and the console commands all ask, so a copy is
     * refused before a job is queued rather than failing inside one.
     */
    public function isActive(): bool
    {
        $record = $this->current();

        return $record !== null
            && $record->state === ConnectionRecord::STATE_ACTIVE
            && $this->belongsHere();
    }

    /**
     * The host the primary site is served from right now.
     *
     * Lower-cased, and empty when it cannot be worked out - a console request with no `@web` set, or a
     * base URL that is not a URL. The same value is sent at pairing and stored with the pairing, so the
     * two cannot drift apart.
     */
    public function currentHost(): string
    {
        $baseUrl = Craft::$app->getSites()->getPrimarySite()->getBaseUrl();

        return $baseUrl === null ? '' : strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
    }

    /**
     * The host this pairing was made on, or null when it was never bound to one.
     */
    public function pairedHost(): ?string
    {
        $record = $this->current();

        // Between updating the plugin and running its migrations the column does not exist yet, and
        // reading it would throw on every request. No column means no binding, which is what this
        // site had a moment ago.
        $host = $record !== null && $record->hasAttribute('pairedHost') ? $record->pairedHost : null;

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * Whether the pairing on record is one this environment should act on.
     *
     * False means the row arrived from somewhere else - almost always a production backup restored into
     * a development copy - and using it would report that copy's state as the live site's. Derived on
     * every read and never stored, so putting the database back on its own host needs no repair.
     */
    public function belongsHere(): bool
    {
        return $this->belongsHere ??= self::hostMatches($this->pairedHost(), $this->currentHost());
    }

    /**
     * A pairing exists and was made on a different host from this one.
     */
    public function isCopy(): bool
    {
        return $this->current() !== null && !$this->belongsHere();
    }

    /**
     * Whether two hosts are the same host, for the purpose of deciding whether to stay quiet.
     *
     * Fails open when either side is unknown, which is the choice worth stating. A pairing with no
     * recorded host predates the binding or was made where none could be determined, and a request
     * whose host cannot be worked out is usually a cron run with no `@web` configured. Treating either
     * as a mismatch would silence a production site's schedule on a technicality, and a monitoring
     * plugin that goes quiet by accident is worse than one that fails to go quiet on a copy.
     *
     * Exact otherwise: `www.example.com` and `example.com` differ, deliberately. Guessing that they are
     * the same site is the sort of leniency that lets a staging copy through.
     */
    public static function hostMatches(?string $paired, string $current): bool
    {
        if ($paired === null || $paired === '' || $current === '') {
            return true;
        }

        return strtolower($paired) === strtolower($current);
    }

    /**
     * Generate a fresh keypair for pairing.
     *
     * Returned rather than stored, because pairing might not succeed and a half-written identity
     * would be worse than none.
     *
     * @return array{public: string, secret: string}
     */
    public function generateKeypair(): array
    {
        return Keys::generateKeypair();
    }

    /**
     * Record a successful pairing.
     *
     * @param  array{public: string, secret: string}  $keypair
     * @param  list<string>  $capabilities
     */
    public function store(
        string $platformUrl,
        string $siteIdentifier,
        array $keypair,
        string $platformPublicKey,
        array $capabilities,
        string $state,
        ?string $platformBackupPublicKey = null,
    ): ConnectionRecord {
        $record = $this->current() ?? new ConnectionRecord();

        $record->platformUrl = rtrim($platformUrl, '/');
        $record->siteIdentifier = $siteIdentifier;

        if ($record->hasAttribute('pairedHost')) {
            $record->pairedHost = $this->currentHost() ?: null;
        }

        $record->publicKey = $keypair['public'];
        $record->secretKey = $this->encrypt($keypair['secret']);
        $record->platformPublicKey = $platformPublicKey;
        $record->platformBackupPublicKey = $platformBackupPublicKey;
        $record->capabilities = json_encode($capabilities, JSON_THROW_ON_ERROR);
        $record->state = $state;
        $record->pairedAt = new DateTime();
        $record->keyRotatedAt = new DateTime();
        $record->connectorVersion = \coyshdigital\managerconnector\Plugin::VERSION;

        if (!$record->save()) {
            throw new RuntimeException('Could not store the Manager connection: ' . json_encode($record->getErrors()));
        }

        $this->cached = $record;
        $this->belongsHere = null;

        return $record;
    }

    /**
     * The signing key, in plaintext, for the duration of one call.
     *
     * Refused on a copy, and here as well as at the gate in {@see self::isActive()}. Every signed
     * request goes through this method, so a path that forgot to ask whether the site was active still
     * cannot sign as a site it has been copied from.
     */
    public function secretKey(): string
    {
        $record = $this->current();

        if ($record === null) {
            throw new RuntimeException('This site is not paired with a Manager platform.');
        }

        if (!$this->belongsHere()) {
            throw new RuntimeException(sprintf(
                'This connection belongs to %s and this site is served from %s, so it will not sign as that site.',
                $this->pairedHost() ?? 'another host',
                $this->currentHost() ?: 'an unknown host',
            ));
        }

        return $this->decrypt($record->secretKey);
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        $record = $this->current();

        if ($record === null || $record->capabilities === null) {
            return [];
        }

        /** @var list<string> $decoded */
        $decoded = json_decode($record->capabilities, true) ?: [];

        return $decoded;
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /**
     * Adopt the capability set the platform reports.
     *
     * Only ever called with a set that arrived on a **signature-verified** response. A capability
     * list is security-sensitive configuration: adopting one from an unverified source would let
     * whatever sits between this site and the platform decide what the site is willing to report.
     *
     * @param  list<string>  $capabilities
     * @return bool whether anything changed
     */
    public function updateCapabilities(array $capabilities): bool
    {
        $record = $this->current();

        if ($record === null) {
            return false;
        }

        sort($capabilities);
        $current = $this->capabilities();
        sort($current);

        if ($current === $capabilities) {
            return false;
        }

        $record->capabilities = json_encode($capabilities, JSON_THROW_ON_ERROR);
        $record->save(false);

        $this->cached = $record;

        Craft::info(
            'Manager Connector capabilities updated to: ' . (implode(', ', $capabilities) ?: 'none'),
            'manager-connector',
        );

        return true;
    }

    /**
     * The artifact format this site has committed to.
     *
     * A ratchet, and the only downgrade control that survives the platform being the adversary. Every
     * other defence is something the platform participates in and can therefore decline to perform;
     * this is a value in this site's own database, raised by this site's own code.
     */
    public function backupFormatFloor(): string
    {
        $record = $this->current();

        $floor = $record?->backupFormatFloor;

        return $floor === Protocol::BACKUP_FORMAT_V2
            ? Protocol::BACKUP_FORMAT_V2
            : Protocol::BACKUP_FORMAT_V1;
    }

    /**
     * The runtime-report version this site should send.
     *
     * Defaults to the oldest, and only moves when the platform has said in a reply that it
     * understands something newer. Assuming the newest is what would make a plugin upgrade a flag
     * day: the two sides are upgraded by different people on different days, and a report refused
     * for its version is refused silently - the report is fire-and-forget, so the symptom is a
     * Health screen that stops moving rather than an error anybody sees.
     *
     * Bounded by what this connector can actually build, not by what the platform claims. A
     * platform that advertised a version this plugin has never heard of would otherwise talk it
     * into sending a payload it cannot produce.
     */
    public function systemReportSchema(): string
    {
        $stored = $this->current()?->systemReportSchema;

        return in_array($stored, SystemReporter::SCHEMAS, true) ? $stored : SystemReporter::OLDEST_SCHEMA;
    }

    /**
     * Record which report version the platform says it accepts.
     *
     * Not a ratchet, unlike the format floor below it, and the difference is what each one is about.
     * That is a commitment this site makes about itself, which no response may weaken. This is a
     * fact about somebody else's software: a platform can be rolled back, and a site can be pointed
     * at a different one, so this has to be able to move down as well as up.
     *
     * The newest version both sides know wins. Anything this connector does not recognise is
     * ignored rather than stored.
     *
     * @param  list<string>  $accepted  versions the platform advertised, newest first
     * @return bool whether anything changed
     */
    public function rememberSystemReportSchema(array $accepted): bool
    {
        $record = $this->current();

        if ($record === null) {
            return false;
        }

        $chosen = null;

        foreach (SystemReporter::SCHEMAS as $known) {
            if (in_array($known, $accepted, true)) {
                $chosen = $known;

                break;
            }
        }

        if ($chosen === null || $chosen === $record->systemReportSchema) {
            return false;
        }

        $record->systemReportSchema = $chosen;
        $record->save(false);

        $this->cached = $record;

        Craft::info(
            "Manager Connector will send runtime reports as {$chosen}.",
            'manager-connector',
        );

        return true;
    }

    /**
     * Commit this site to the zero-knowledge format, permanently.
     *
     * There is no counterpart. Lowering the floor means going back to backups the platform can read,
     * and that must require editing a file on this server rather than receiving a response.
     *
     * @return bool whether anything changed
     */
    public function raiseBackupFormatFloor(): bool
    {
        $record = $this->current();

        if ($record === null || $record->backupFormatFloor === Protocol::BACKUP_FORMAT_V2) {
            return false;
        }

        $record->backupFormatFloor = Protocol::BACKUP_FORMAT_V2;

        // The platform's own artifact key is discarded at the same moment. Keeping it would leave a
        // usable value in the row for a code path that no longer exists, and the absence of it is a
        // second, independent reason a v1 backup cannot happen here.
        $record->platformBackupPublicKey = null;

        $record->save(false);

        $this->cached = $record;

        Craft::info(
            'Manager Connector will now encrypt backups only to this organisation\'s own recovery keys.',
            'manager-connector',
        );

        return true;
    }

    /**
     * The key artifacts are sealed to, if the platform has one.
     */
    public function backupPublicKey(): ?string
    {
        // Above the floor there is no such key, whatever the column says. Read defensively rather than
        // relying on the column having been cleared, so that a row edited by hand cannot resurrect a
        // code path this site has committed to leaving behind.
        if ($this->backupFormatFloor() === Protocol::BACKUP_FORMAT_V2) {
            return null;
        }

        $record = $this->current();

        $key = $record?->platformBackupPublicKey;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Adopt the artifact encryption key the platform reports.
     *
     * Same rule as {@see self::updateCapabilities()}, and for the same reason: only ever called with a
     * value that arrived on a signature-verified response. Sealing an artifact key to something handed
     * over by whatever sits between this site and the platform would mean encrypting a customer
     * database to an attacker's key and calling it protected.
     *
     * @return bool whether anything changed
     */
    public function updateBackupPublicKey(?string $publicKey): bool
    {
        $record = $this->current();

        if ($record === null) {
            return false;
        }

        if ($this->backupFormatFloor() === Protocol::BACKUP_FORMAT_V2) {
            // Above the floor this is a no-op, and a loud one. A platform sending an artifact key to a
            // site that has committed to recovery keys is either running old code or attempting a
            // downgrade, and both are worth a line in the log.
            if ($publicKey !== null) {
                Craft::warning(
                    'Manager Connector ignored an artifact encryption key: this site encrypts backups '
                    . 'only to its own recovery keys.',
                    'manager-connector',
                );
            }

            return false;
        }

        // Refused rather than stored if it is not a well-formed key. A malformed value would fail at
        // seal time instead, in the middle of a backup that has already dumped the database.
        if ($publicKey !== null && !Sealing::isValidBoxPublicKey($publicKey)) {
            Craft::warning(
                'Manager Connector refused a malformed artifact encryption key.',
                'manager-connector',
            );

            return false;
        }

        if ($record->platformBackupPublicKey === $publicKey) {
            return false;
        }

        $record->platformBackupPublicKey = $publicKey;
        $record->save(false);

        $this->cached = $record;

        return true;
    }

    public function recordSuccess(): void
    {
        $record = $this->current();

        if ($record === null) {
            return;
        }

        $record->lastSuccessAt = new DateTime();
        $record->save(false);
    }

    /**
     * Forget this site's identity entirely.
     *
     * The row is deleted rather than flagged, so the private key is gone from the database
     * immediately. There is nothing left to reactivate: reconnecting means a new keypair and a new
     * enrolment code, which is the correct amount of ceremony for handing a platform access again.
     */
    public function forget(): void
    {
        $record = $this->current();

        $record?->delete();

        $this->cached = null;
        $this->belongsHere = null;
    }

    /**
     * Encrypt with Craft's security key, then base64 the result.
     *
     * encryptByKey() returns raw binary, which a UTF-8 text column will not accept - MySQL rejects
     * the insert outright rather than storing something corrupt, which is the better failure but
     * still a failure. Base64 keeps the stored value plain ASCII.
     */
    private function encrypt(string $plaintext): string
    {
        return base64_encode(Craft::$app->getSecurity()->encryptByKey($plaintext));
    }

    private function decrypt(string $stored): string
    {
        $ciphertext = base64_decode($stored, true);

        if ($ciphertext === false) {
            throw new RuntimeException('The stored signing key is not readable. Re-pair this site.');
        }

        return Craft::$app->getSecurity()->decryptByKey($ciphertext);
    }
}
