<?php

/**
 * Manager Connector plugin for Craft CMS 4.x and 5.x
 *
 * @link      https://managerforcraft.com
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\controllers;

use coyshdigital\managerconnector\jobs\RunTask;
use coyshdigital\managerconnector\Plugin;
use coyshdigital\managerconnector\records\ConnectionRecord;
use coyshdigital\managerconnector\services\Tasks;
use coyshdigital\managerprotocol\CanonicalNudge;
use coyshdigital\managerprotocol\Nonce;
use coyshdigital\managerprotocol\Protocol;
use Craft;
use craft\helpers\App;
use craft\queue\Queue as CraftQueue;
use craft\web\Controller;
use craft\web\Response as CraftResponse;
use Throwable;
use yii\web\Response;

/**
 * The platform asking this site to check in now.
 *
 * This is the only anonymous endpoint in the plugin, and the first thing anywhere that lets the
 * platform reach in rather than wait to be called. That is a real narrowing of invariants 4 and 5, so
 * it is worth being exact about what has and has not changed.
 *
 * **What it can do is poll.** That is the entire vocabulary. This controller reads no parameters, takes
 * no body, and pushes one queue job - the same `jobs` task the site's own scheduler pushes on its own
 * timer, from ordinary web traffic, with nobody's permission. What happens next is an ordinary signed
 * claim in which this site asks the platform what is waiting and decides for itself what to do about
 * the answer. Every check that matters lives behind that claim and is untouched by this file:
 * {@see \coyshdigital\managerconnector\services\JobRunner::canHandle()} still refuses job types it does
 * not implement, {@see \coyshdigital\managerconnector\services\RecipientPin} still refuses unpinned
 * recovery keys, and the upload host is still derived rather than accepted.
 *
 * So the worst a forged, replayed or misdirected nudge achieves is an early poll. There is nothing to
 * put an instruction in, which is a stronger guarantee than validating one would be.
 *
 * **Why it exists.** A backup requested in Manager is a queued row and nothing more. This site collects
 * it on its next claim - up to five minutes away with cron, and on a site whose scheduler runs off
 * ordinary web traffic, however long it takes for somebody to visit. For that whole time the screen in
 * Manager is correct and looks broken.
 *
 * **Why it runs the queue rather than only pushing to it.** Craft arranges for a pushed job to actually
 * run by injecting JavaScript into an HTML response, so the *visitor's browser* starts the runner. A
 * request that returns an empty 202 gets none of that. Pushing without running would therefore improve
 * nothing on exactly the cron-less sites this exists for. Running it here is not a new class of
 * behaviour: Craft already ships `queue/run` as an anonymous endpoint on every site, and this does what
 * that does, after checking a signature it does not check.
 *
 * The job is pushed and *then* the queue is run, rather than the task being called inline, so the
 * queue's own reservation still applies. {@see RunTask::getTtr()} reserves for six hours and
 * {@see RunTask::canRetry()} is false, both because a second concurrent run would mean a second dump of
 * a production database. Calling the task directly would bypass that.
 *
 * `runQueueAutomatically` is honoured. An operator who turned it off did so to keep queue work out of
 * web requests, and running a multi-hour backup in one against their wishes would be worse than the
 * latency this is trying to remove.
 */
class NudgeController extends Controller
{
    /**
     * Anonymous, because the platform holds no Craft session and never will.
     *
     * `ALLOW_ANONYMOUS_LIVE` rather than `true`: an offline site should fall back to polling rather than
     * accept a nudge, because a site that is offline is usually one somebody is working on.
     *
     * @var array<int|string, int|string>|bool|int
     */
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

    /**
     * No CSRF token, because authentication here is the signature rather than the session.
     *
     * Stated rather than inherited. A CSRF token protects a request made by a browser that holds a
     * session; the platform is neither. What replaces it is an Ed25519 signature over a canonical
     * string, a timestamp window and a single-use nonce - which is strictly more than a token proves.
     *
     * @var bool
     */
    public $enableCsrfValidation = false;

    /**
     * A syntactically valid key that verifies nothing, used when this site is not paired.
     *
     * Verifying against it costs the same as verifying against a real key, so an unpaired site cannot be
     * distinguished from a bad signature by how long the refusal takes. The platform does the same thing
     * for the same reason. Public test material from the protocol fixtures; it is not secret and is not
     * anybody's key.
     */
    private const DECOY_PUBLIC_KEY = 'wtTQtctkVi1Kxeol29zmBxJZiTP35EF6A51LAb12sRs=';

    /**
     * Prefix on the cache keys that make a nonce single-use.
     */
    private const NONCE_PREFIX = 'manager-connector.nudge.nonce.';

    /**
     * The key that stops a burst of nudges becoming a burst of claims.
     */
    private const CLAIM_KEY = 'manager-connector.nudge.claim';

    /**
     * Seconds between nudge-driven claims, however many nudges arrive.
     *
     * The point of a nudge is that the site reacts in seconds rather than minutes, so this is short
     * enough to stay useful and long enough that nothing can turn one endpoint into a way of making this
     * site hammer the platform.
     */
    private const CLAIM_SECONDS = 30;

    /**
     * Check in now.
     *
     * Returns 202 when the nudge was authentic and 401 when it was not, both with an empty body. The
     * refusal is uniform on purpose: an unpaired site, a wrong site identifier, a stale timestamp, a bad
     * signature and a replayed nonce are indistinguishable from the outside, so this cannot be used to
     * learn which of them applied.
     *
     * A nudge that arrives while a recent one is still being honoured also gets 202. It was authentic
     * and it was accepted; there is simply nothing left to do about it.
     */
    public function actionPoll(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->acceptNudges) {
            return $this->refuse();
        }

        // A nudge has no body, and the canonical string has nowhere to put one. Refusing before anything
        // else means nothing can be smuggled past a signature that does not cover it.
        if ($request->getRawBody() !== '') {
            return $this->refuse();
        }

        $siteId = (string) $request->getHeaders()->get(Protocol::HEADER_SITE, '');
        $timestamp = (string) $request->getHeaders()->get(Protocol::HEADER_TIMESTAMP, '');
        $nonce = (string) $request->getHeaders()->get(Protocol::HEADER_NONCE, '');
        $signature = (string) $request->getHeaders()->get(Protocol::HEADER_SIGNATURE, '');

        if ($siteId === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            return $this->refuse();
        }

        if (!Nonce::isValid($nonce)) {
            return $this->refuse();
        }

        if (!preg_match('/^\d{1,10}$/', $timestamp)) {
            return $this->refuse();
        }

        if (abs(time() - (int) $timestamp) > Protocol::DEFAULT_TIMESTAMP_TOLERANCE) {
            return $this->refuse();
        }

        $signature = $this->signatureValue($signature);

        if ($signature === null) {
            return $this->refuse();
        }

        $record = $plugin->connection->current();

        $paired = $record instanceof ConnectionRecord
            && $record->state === ConnectionRecord::STATE_ACTIVE
            && hash_equals($record->siteIdentifier, $siteId);

        // The verifying key comes from the pairing this site stored, never from the request. A key on the
        // wire would make the signature a formality.
        $publicKey = $paired ? $record->platformPublicKey : self::DECOY_PUBLIC_KEY;

        $nudge = new CanonicalNudge($siteId, (int) $timestamp, $nonce);

        if (!$nudge->verify($signature, $publicKey) || !$paired) {
            return $this->refuse();
        }

        // Claimed last, once the nudge has proved authentic. Claiming earlier would let anyone who can
        // reach this URL fill the replay store with nonces that never belonged to a real nudge.
        if (!$this->claim(self::NONCE_PREFIX . $nonce, 2 * Protocol::DEFAULT_TIMESTAMP_TOLERANCE)) {
            return $this->refuse();
        }

        if (!$this->claim(self::CLAIM_KEY, self::CLAIM_SECONDS)) {
            return $this->accept();
        }

        return $this->poll();
    }

    /**
     * Push the claim task and, unless the operator has said otherwise, run it.
     */
    private function poll(): Response
    {
        try {
            Craft::$app->getQueue()->push(new RunTask(['task' => Tasks::JOBS]));
        } catch (Throwable $e) {
            // A queue that will not accept a job is a site problem, not a nudge problem, and the nudge
            // was still authentic. Logged and accepted, exactly as the web trigger does.
            Craft::warning(
                'Manager Connector could not queue a nudged check-in: ' . $e->getMessage(),
                'manager-connector',
            );

            return $this->accept();
        }

        // So a nudge and the ordinary schedule do not both fire a moment apart.
        Plugin::getInstance()->scheduler->markRan(Tasks::JOBS);

        $response = $this->accept();
        $queue = Craft::$app->getQueue();

        // An operator who turned this off did so to keep queue work out of web requests. Running a
        // multi-hour backup in one against their wishes would be worse than the latency.
        if (!Craft::$app->getConfig()->getGeneral()->runQueueAutomatically) {
            return $response;
        }

        // Craft lets the queue component be swapped for any yii\queue driver, and only Craft's own
        // exposes run(). A site running something else keeps the pushed job and whatever runs its queue
        // picks it up - which is the same fallback as every other way this can decline.
        if (!$queue instanceof CraftQueue) {
            return $response;
        }

        // Already working, or nothing to do. Craft's own queue/run makes the same two checks before
        // spending a request on it.
        if ($queue->getHasReservedJobs() || !$queue->getHasWaitingJobs()) {
            return $response;
        }

        // Answer first, then work. The platform learns the nudge landed without waiting for a backup,
        // and nothing upstream is holding a connection open for hours.
        if ($response instanceof CraftResponse) {
            $response->sendAndClose();
        }

        App::maxPowerCaptain();

        try {
            $queue->run();
        } catch (Throwable $e) {
            Craft::warning(
                'Manager Connector nudged the queue and it failed: ' . $e->getMessage(),
                'manager-connector',
            );
        }

        return $response;
    }

    /**
     * Claim a cache key atomically, failing closed.
     *
     * `add()` writes only when the key is absent, so exactly one of any number of concurrent requests
     * gets true - the same primitive the web trigger throttles on. No usable cache means no way to
     * enforce single use or throttling, and the safe failure is to refuse: the cost is one missed
     * optimisation, and the site falls back to its own schedule.
     */
    private function claim(string $key, int $seconds): bool
    {
        try {
            return Craft::$app->getCache()->add($key, 1, $seconds);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The signature out of a `v1=...` header value.
     */
    private function signatureValue(string $header): ?string
    {
        $prefix = Protocol::SIGNATURE_SCHEME . '=';

        if (!str_starts_with($header, $prefix)) {
            return null;
        }

        $value = substr($header, strlen($prefix));

        return $value === '' ? null : $value;
    }

    private function accept(): Response
    {
        $this->response->setStatusCode(202);
        $this->response->format = Response::FORMAT_RAW;
        $this->response->content = '';

        return $this->response;
    }

    /**
     * One refusal for every reason, so this cannot be used as an oracle.
     */
    private function refuse(): Response
    {
        $this->response->setStatusCode(401);
        $this->response->format = Response::FORMAT_RAW;
        $this->response->content = '';

        return $this->response;
    }
}
