<?php

/**
 * Manager Connector plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\services;

use coyshdigital\managerconnector\Plugin;
use coyshdigital\managerprotocol\KeyFingerprint;
use coyshdigital\managerprotocol\ProtocolException;
use Craft;
use craft\base\Component;
use RuntimeException;

/**
 * Deciding which keys this site is willing to encrypt a backup to.
 *
 * This is the single most important thing the connector does, and it is worth being precise about why.
 *
 * A backup is encrypted to keys the platform names. That is unavoidable — the site has to be told which
 * keys the organisation holds, and the platform is what tells it. Sealing the artifact key to a
 * recovery key rather than to the platform's own therefore protects a customer against a platform whose
 * *storage* is stolen, and against a platform that is merely curious. It protects them against nothing
 * at all if the platform can choose the recipients, because a Manager installation that had been
 * compromised — or that was compelled — could name a key of its own, and this site would encrypt to it
 * with nothing looking wrong from either end. No error, no warning, no missing backup. Just a backup
 * somebody else can read.
 *
 * Pinning closes that. The fingerprints live in `config/manager-connector.php`, in the customer's
 * version control, on the customer's server, and nothing the platform sends can change them. It is the
 * same structural argument as the upload destination: the safe design is not to check what was sent
 * but to take the value from somewhere the adversary cannot reach.
 *
 * Three decisions inside that, each of which had an easier alternative:
 *
 *  - **An unpinned key fails the whole backup, rather than being filtered out.** Continuing with the
 *    keys that did match would produce a working backup and hide the fact that the platform offered a
 *    key nobody authorised. The same reasoning as the schema validator rejecting an unknown field
 *    rather than stripping it: the anomaly is the signal.
 *
 *  - **The check runs before the dump.** Ordering is not incidental. A refusal after `takeDump()` has
 *    already written a complete copy of the database to disk is a refusal that has already created the
 *    most dangerous file on the server.
 *
 *  - **Fingerprints are recomputed from the key material.** The platform sends both a key and a
 *    fingerprint for it; only the key means anything. Comparing the *sent* fingerprint against the pins
 *    would let a platform send its own key labelled with a customer's fingerprint, and the check would
 *    pass while sealing to the wrong key.
 */
class RecipientPin extends Component
{
    /**
     * The keys this site will seal an artifact to.
     *
     * @param  list<array<string, mixed>>  $offered  recipients from a signature-verified claim response
     * @return list<array{fingerprint: string, public_key: string, label: string|null}>
     *
     * @throws RuntimeException if the offer is not one this site accepts
     */
    public function accept(array $offered): array
    {
        $settings = Plugin::getInstance()->getSettings();

        /** @var list<string> $configured */
        $configured = $settings->recoveryKeyFingerprints;

        $pinned = [];

        foreach ($configured as $fingerprint) {
            $normalised = KeyFingerprint::normalise((string) $fingerprint);

            if (!KeyFingerprint::isWellFormed($normalised)) {
                // A typo in a configuration file must not silently shrink the pinned set, because a
                // shrunken set that still matched would let a real key through and look fine.
                throw new RuntimeException(
                    'a recovery key fingerprint in config/manager-connector.php is not a valid fingerprint'
                );
            }

            $pinned[] = $normalised;
        }

        if ($pinned === [] && $settings->requirePinnedRecoveryKeys) {
            throw new RuntimeException(
                'this site has no pinned recovery key fingerprints, and is configured to require them'
            );
        }

        if ($offered === []) {
            throw new RuntimeException(
                'the platform offered no recovery keys, so there is nothing to encrypt this backup to'
            );
        }

        $accepted = [];

        foreach ($offered as $recipient) {
            $publicKey = $recipient['public_key'] ?? null;

            if (!is_string($publicKey) || $publicKey === '') {
                throw new RuntimeException('the platform offered a recovery key with no key material');
            }

            try {
                // Recomputed, never read. A fingerprint the platform sent is a label it chose.
                $fingerprint = KeyFingerprint::forRecoveryKey($publicKey);
            } catch (ProtocolException) {
                throw new RuntimeException('the platform offered a malformed recovery key');
            }

            $claimed = $recipient['fingerprint'] ?? null;

            if (is_string($claimed) && !KeyFingerprint::matches($claimed, $fingerprint)) {
                // The platform's own label disagrees with its own key. Whether that is a bug or an
                // attempt to disguise a substituted key, it is not something to proceed through.
                throw new RuntimeException(
                    'the platform offered a recovery key whose fingerprint does not match it'
                );
            }

            if ($pinned !== [] && !$this->isPinned($fingerprint, $pinned)) {
                /*
                 | The finding this class exists for.
                 |
                 | The message names the fingerprint deliberately. It is public, the operator needs it
                 | to work out whether this is a key they forgot to pin or a key they have never seen,
                 | and those two require very different responses.
                 */
                Craft::error(
                    "Manager Connector refused a backup: the platform offered recovery key {$fingerprint}, "
                    . 'which is not pinned in config/manager-connector.php.',
                    'manager-connector',
                );

                throw new RuntimeException(
                    "the platform offered recovery key {$fingerprint}, which this site has not pinned"
                );
            }

            $accepted[] = [
                'fingerprint' => $fingerprint,
                'public_key' => $publicKey,
                'label' => isset($recipient['label']) && is_string($recipient['label'])
                    ? $recipient['label']
                    : null,
            ];
        }

        return $accepted;
    }

    /**
     * Whether this site has any pins configured at all.
     *
     * Reported to the platform as a boolean on the heartbeat, so a fleet's unpinned sites are visible
     * on one screen. Deliberately not the fingerprints themselves: a platform that learned which keys
     * a site expects would know exactly which one to substitute.
     */
    public function isConfigured(): bool
    {
        return Plugin::getInstance()->getSettings()->recoveryKeyFingerprints !== [];
    }

    /**
     * @param  list<string>  $pinned
     */
    private function isPinned(string $fingerprint, array $pinned): bool
    {
        foreach ($pinned as $candidate) {
            if (KeyFingerprint::matches($fingerprint, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
