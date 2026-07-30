<?php

/**
 * Manager Connector plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\utilities;

use coyshdigital\managerconnector\Plugin;
use Craft;
use craft\base\Utility;

/**
 * The connector's screen, in Utilities.
 *
 * A utility rather than a settings page, for two reasons.
 *
 * The first is reachability, and an earlier version of this comment had it wrong. With
 * `allowAdminChanges` genuinely off, Craft renders the plugins list but **does not link** a plugin's
 * settings page — so enrolment behind plugin settings is undiscoverable on exactly the hardened
 * production sites that most need it. The first test of this appeared to show otherwise because the
 * setting was being overridden by CRAFT_ALLOW_ADMIN_CHANGES in the environment, which takes precedence
 * over config/general.php. Utilities are unaffected and stay in the navigation.
 *
 * The second is what this screen is. Settings hold things that *are set*. Utilities hold things you
 * *do*: Craft's own list is Updates, Caches, Database Backup, Migrations. Pairing is an action with an
 * effect on the outside world, not a value being recorded, and it belongs with those.
 *
 * Two practical consequences follow, and both matter on the kind of site this plugin is for:
 *
 *  - Utilities carry their own permission, so an owner can let one person connect a site without making
 *    them a Craft administrator. Plugin settings are admin-only, which on managed hosting often means
 *    the developer rather than the person who actually looks after the site.
 *  - Agencies routinely hide Settings from client users while leaving Utilities in place. An operational
 *    screen behind Settings is a screen those users cannot reach.
 *
 * Granting `utility:manager-connector` is therefore a meaningful decision: it lets somebody connect this
 * site to a Manager platform, and a platform decides for itself what it then asks the site for. That is
 * comparable to Craft's own Database Backup utility, which lets its holder download the entire database,
 * and the screen says as much rather than leaving it to be discovered.
 */
class ConnectorUtility extends Utility
{
    public static function id(): string
    {
        return 'manager-connector';
    }

    public static function displayName(): string
    {
        return Craft::t('manager-connector', 'Manager Connector');
    }

    public static function icon(): ?string
    {
        return 'satellite-dish';
    }

    /**
     * A one-line summary in the utilities list, so the state is visible before anybody clicks.
     */
    public static function badgeCount(): int
    {
        $connection = Plugin::getInstance()->connection->current();

        // Badged only when something wants attention: unpaired, or held for confirmation. A connected
        // site shows nothing, because a badge that is always lit is a badge nobody reads.
        return $connection === null || $connection->state !== 'active' ? 1 : 0;
    }

    public static function contentHtml(): string
    {
        $plugin = Plugin::getInstance();

        return Craft::$app->getView()->renderTemplate('manager-connector/settings', [
            'plugin' => $plugin,
            'settings' => $plugin->getSettings(),
            'connection' => $plugin->connection->current(),
            'connectorVersion' => Plugin::VERSION,
            'securityUrl' => 'https://coysh.digital/manager/docs/security/',
        ]);
    }
}
