<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\services;

use coyshdigital\managerprotocol\SchemaValidator;
use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\db\Table;
use Throwable;

/**
 * Failed sign-ins to the control panel, as counts.
 *
 * Read from Craft's own columns on the users table — `invalidLoginCount`, `lastInvalidLoginDate`,
 * `lockoutDate` — rather than by listening for login-failure events and keeping a log. That choice
 * is the point of this class, so it is worth stating why.
 *
 * A listener would let this plugin build a record of who tried to sign in as whom, from where, and
 * when. That is a log of real people's behaviour on somebody else's website, held by a third party,
 * for a purpose nobody has stated. The operator's actual question — "is this site being attacked,
 * and is anybody locked out" — is answered by four integers, and four integers is therefore what
 * crosses the wire. No username, no email address, no user id, no source address, no per-attempt
 * record.
 *
 * **The caveat travels with the number.** Craft resets a user's counter to zero on a successful
 * sign-in, so a total here is a floor and not a count of everything ever attempted: an attacker who
 * eventually succeeds erases their own tally. The platform repeats this on the screen, because a
 * number presented without it invites the wrong conclusion from a reassuring zero.
 */
class LoginsReporter extends Component
{
    /**
     * How far back to count.
     *
     * A day. `lastInvalidLoginDate` records only the most recent failure per account, so a longer
     * window does not gather more history — it just keeps stale accounts in the total for longer.
     */
    private const WINDOW_HOURS = 24;

    /**
     * @return array{payload: array<string, mixed>, problems: list<string>}
     */
    public function buildValidated(): array
    {
        $payload = $this->build();

        return [
            'payload' => $payload,
            'problems' => SchemaValidator::forSchema('logins.v1')->validate($payload),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $rows = $this->safely(function(): array {
            $cutoff = date('Y-m-d H:i:s', time() - (self::WINDOW_HOURS * 3600));

            return (new Query())
                ->select([
                    'invalidLoginCount',
                    'lastInvalidLoginDate',
                    'lockoutDate',
                    'admin',
                ])
                ->from(Table::USERS)
                ->where(['not', ['lastInvalidLoginDate' => null]])
                ->andWhere(['>=', 'lastInvalidLoginDate', $cutoff])
                ->all();
        }, []);

        $attempts = 0;
        $accounts = 0;
        $locked = 0;
        $admins = 0;
        $lastFailure = null;

        foreach ($rows as $row) {
            $count = (int) ($row['invalidLoginCount'] ?? 0);

            if ($count > 0) {
                $attempts += $count;
                $accounts++;

                // The count, never which. Failed attempts against an administrator are a different
                // signal from failed attempts against an author, and that distinction is carried by
                // a number rather than by a name.
                if ((bool) ($row['admin'] ?? false)) {
                    $admins++;
                }
            }

            if (($row['lockoutDate'] ?? null) !== null) {
                $locked++;
            }

            $failedAt = strtotime((string) ($row['lastInvalidLoginDate'] ?? ''));

            if ($failedAt !== false && ($lastFailure === null || $failedAt > $lastFailure)) {
                $lastFailure = $failedAt;
            }
        }

        return array_filter([
            'schema_version' => 'logins.v1',
            'collected_at' => time(),
            'window_hours' => self::WINDOW_HOURS,
            'failed_attempts' => $attempts,
            'accounts_with_failures' => $accounts,
            'accounts_locked' => $locked,
            'admin_accounts_affected' => $admins,
            'last_failure_at' => $lastFailure,
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $reader
     * @param  T  $fallback
     * @return T
     */
    private function safely(callable $reader, mixed $fallback): mixed
    {
        try {
            return $reader();
        } catch (Throwable $e) {
            Craft::warning(
                'Manager Connector could not read sign-in counters: ' . $e->getMessage(),
                'manager-connector',
            );

            return $fallback;
        }
    }
}
