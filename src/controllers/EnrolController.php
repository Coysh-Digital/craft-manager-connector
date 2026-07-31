<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\controllers;

use coyshdigital\managerconnector\Plugin;
use coyshdigital\managerconnector\records\ConnectionRecord;
use coyshdigital\managerconnector\utilities\ConnectorUtility;
use Craft;
use craft\web\Controller;
use Throwable;
use yii\web\Response;

/**
 * Pairing and disconnecting from the control panel.
 *
 * This is the only web-reachable code in the plugin, and it exists because the console is not always
 * available. Plenty of Craft sites live on managed hosting with no shell at all, and "you cannot use
 * this unless you have SSH" is not a limitation, it is an exclusion.
 *
 * It does not weaken invariants 4 and 5, and it is worth being precise about why rather than asserting
 * it. Invariant 4 forbids a **public** inbound management endpoint; these actions require an
 * authenticated Craft administrator and a POST carrying Craft's CSRF token. Invariant 5 requires
 * connections to be initiated outbound by the connector; that is exactly what happens — a button here
 * causes this site to call the platform. Nothing about these routes lets the platform call in, and
 * nothing here accepts an instruction from the platform.
 *
 * The risk this design does have to answer is different: a hijacked control-panel session pairing the
 * site to somebody else's Manager, then granting itself backup permission from that end and collecting
 * the database. Two things address it.
 *
 * First, when `platformUrl` is set in `config/manager-connector.php` the form cannot override it. That
 * file needs a deployment to change, so on any site configured that way the destination is fixed and
 * this screen can only pair with the platform the operator already chose.
 *
 * Second, where no configuration exists the screen says plainly what it is about to do and to check the
 * address, because on a site with no configuration and no shell there is no more authoritative source
 * for it than the person standing in front of the screen.
 *
 * Worth noting what a Craft administrator can already do: with `allowAdminChanges` on they can install
 * arbitrary plugins, which is unrestricted code execution. On such a site this route adds nothing they
 * did not have. It is only on a hardened installation that this is new authority, which is why it is
 * limited to administrators rather than offered on a permission any editor might hold.
 */
class EnrolController extends Controller
{
    /**
     * Never anonymous. Stated rather than inherited, because the default mattering silently is how it
     * ends up changed by accident.
     *
     * @var array<int|string, int|string>|bool|int
     */
    protected array|bool|int $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // The utility's own permission. Craft already enforces it to view the screen; enforced again
        // here because the action is reachable without visiting the screen first.
        //
        // Not requireAdmin(). An owner who grants this permission means the holder to be able to use it,
        // and on managed hosting the person who looks after a site is often not a Craft administrator.
        // The permission carries real authority and the screen says so — the same arrangement as Craft's
        // own Database Backup utility, which lets its holder download the entire database.
        $this->requirePermission('utility:' . ConnectorUtility::id());

        return true;
    }

    /**
     * Pair this site using an enrolment code.
     */
    public function actionPair(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();
        $session = Craft::$app->getSession();

        $code = trim((string) $request->getBodyParam('enrolmentCode', ''));

        if ($code === '') {
            $session->setError(Craft::t('manager-connector', 'Enter the enrolment code from Manager.'));

            return $this->redirectToPostedUrl();
        }

        $configured = trim($plugin->getSettings()->platformUrl);

        // The configured URL wins outright when there is one. A form field that could override a
        // deployed setting would make the deployment pointless.
        $platformUrl = $configured !== ''
            ? $configured
            : trim((string) $request->getBodyParam('platformUrl', ''));

        if ($platformUrl === '') {
            $session->setError(Craft::t('manager-connector', 'Enter the address of your Manager platform.'));

            return $this->redirectToPostedUrl();
        }

        $keypair = $plugin->connection->generateKeypair();

        try {
            $response = $plugin->client->pair($platformUrl, $code, $keypair);
        } catch (Throwable $e) {
            // The message is safe to show: it comes from this plugin or is a bounded transport summary,
            // and the code is never included in it.
            $session->setError($e->getMessage());

            // Deliberately not logged. Craft's log would otherwise hold a pairing failure alongside the
            // request that carried the code.
            return $this->redirectToPostedUrl();
        }

        $isLive = ($response['state'] ?? '') === 'active';

        $plugin->connection->store(
            platformUrl: $platformUrl,
            siteIdentifier: (string) ($response['site_id'] ?? ''),
            keypair: $keypair,
            platformPublicKey: (string) ($response['platform_public_key'] ?? ''),
            capabilities: $response['capabilities'] ?? [],
            state: $isLive ? ConnectionRecord::STATE_ACTIVE : ConnectionRecord::STATE_PENDING_CONFIRMATION,
            platformBackupPublicKey: is_string($response['backup_public_key'] ?? null) && $response['backup_public_key'] !== ''
                ? (string) $response['backup_public_key']
                : null,
        );

        $session->setNotice($isLive
            ? Craft::t('manager-connector', 'Paired with Manager.')
            : Craft::t('manager-connector', 'Paired, but held for confirmation: the domain this site reported differs from the one Manager expected. Approve it in Manager.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Forget this site's identity.
     *
     * Available here as well as on the console because somebody who paired without a shell needs to be
     * able to undo it without one. Safe to expose: it only ever removes access.
     */
    public function actionDisconnect(): Response
    {
        $this->requirePostRequest();

        Plugin::getInstance()->connection->forget();

        Craft::$app->getSession()->setNotice(Craft::t(
            'manager-connector',
            'Disconnected. The signing key has been deleted from this site. Revoke the connector in Manager too, so the platform stops expecting it.',
        ));

        return $this->redirectToPostedUrl();
    }
}
