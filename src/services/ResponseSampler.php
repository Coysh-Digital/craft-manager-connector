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
use Craft;
use craft\base\Component;
use Throwable;

/**
 * How long this site takes to build a response.
 *
 * Sampled here rather than measured from the platform, and the difference is the whole design. The
 * platform never calls out to a site — that is what lets a connector work from behind NAT with no
 * inbound port — so there is nobody outside to hold a stopwatch. What there is, already, is a
 * listener on requests that were happening anyway.
 *
 * **This is not time to first byte, and must not be presented as though it were.** It measures the
 * interval from PHP starting to the response being finished: no DNS, no TCP, no TLS handshake, no
 * queueing in front of PHP-FPM, no network to the visitor. A site with a two-second TTFB and a 40ms
 * render looks fast here and is not. The platform says so on the screen; this class says so here so
 * that nobody wires it up to a label reading "TTFB" without reading this first.
 *
 * Three properties make it safe to leave switched on:
 *
 *  - **It stores numbers, not requests.** A duration and nothing else — no URL, no query string, no
 *    user, no address. A record of who fetched what and when would be a log of real people's
 *    behaviour on somebody else's website, and there is no stated purpose for collecting it.
 *  - **It is bounded.** A fixed-size ring in the cache, so a busy site and a quiet one cost the
 *    same. It never grows, and it never touches the database.
 *  - **It fails silently.** A cache that is unavailable means no samples, which means the report
 *    omits the section. Timing a page must never be able to break serving it.
 */
class ResponseSampler extends Component
{
    /**
     * Where the ring lives.
     */
    private const CACHE_KEY = 'manager-connector.response-samples';

    /**
     * How many samples to keep.
     *
     * Two hundred is enough for a stable median and a usable 95th percentile, and small enough that
     * the whole ring is a few kilobytes. More would buy precision nobody is going to act on.
     */
    private const CAPACITY = 200;

    /**
     * How long a ring survives without being written to.
     *
     * Longer than the reporting interval, so a quiet site still has something to report; short
     * enough that figures from last week never appear as though they were from this morning.
     */
    private const TTL = 21600;

    /**
     * Record this request's duration.
     *
     * Called from the after-request listener, after the response has been sent. Nothing waits for it.
     */
    public function record(): void
    {
        try {
            // `Plugin::getInstance()`, like every other service here. This read used to call
            // `Plugin::instanceOrNull()`, which does not exist on any class in the hierarchy and was
            // additionally resolving against this namespace rather than the plugin's — so it raised an
            // Error, the catch below swallowed it, and no response time was ever recorded. The runtime
            // report's `response` section was silently always absent, which looks exactly like a site
            // that had turned the setting off.
            if (!Plugin::getInstance()->getSettings()->sampleResponseTimes) {
                return;
            }

            $startedAt = $this->requestStartedAt();

            if ($startedAt === null) {
                return;
            }

            $milliseconds = (microtime(true) - $startedAt) * 1000;

            // A negative or absurd figure means the clock moved or the constant was wrong, and a
            // wrong number is worse than a missing one on a screen somebody makes decisions from.
            if ($milliseconds <= 0 || $milliseconds > 600000) {
                return;
            }

            $cache = Craft::$app->getCache();

            /** @var list<float> $samples */
            $samples = $cache->get(self::CACHE_KEY) ?: [];
            $samples[] = round($milliseconds, 2);

            if (count($samples) > self::CAPACITY) {
                $samples = array_slice($samples, -self::CAPACITY);
            }

            $cache->set(self::CACHE_KEY, $samples, self::TTL);
        } catch (Throwable) {
            // Never fatal. A visitor's page must not fail because a measurement of it did.
        }
    }

    /**
     * The summary that goes into a system report, or null when there is nothing worth reporting.
     *
     * Twenty samples is the floor. A mean of three requests is a number that will move violently for
     * reasons nobody can act on, and putting it on a dashboard invites somebody to act on it.
     *
     * @return array<string, mixed>|null
     */
    public function summarise(): ?array
    {
        try {
            /** @var list<float> $samples */
            $samples = Craft::$app->getCache()->get(self::CACHE_KEY) ?: [];
        } catch (Throwable) {
            return null;
        }

        $count = count($samples);

        if ($count < 20) {
            return null;
        }

        sort($samples);

        return [
            'samples' => $count,
            'window_hours' => (int) ceil(self::TTL / 3600),
            'mean_ms' => round(array_sum($samples) / $count, 1),
            'p50_ms' => round($this->percentile($samples, 0.50), 1),
            'p95_ms' => round($this->percentile($samples, 0.95), 1),
            'max_ms' => round((float) end($samples), 1),
        ];
    }

    /**
     * When PHP began handling this request.
     *
     * `REQUEST_TIME_FLOAT` is set by the SAPI before any application code runs, which makes it the
     * earliest honest starting point available from inside the process. Absent on the console and on
     * some SAPIs, in which case there is nothing to measure and nothing is recorded.
     */
    private function requestStartedAt(): ?float
    {
        $value = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;

        return is_float($value) || is_int($value) ? (float) $value : null;
    }

    /**
     * @param  list<float>  $sorted
     */
    private function percentile(array $sorted, float $fraction): float
    {
        $index = (int) floor($fraction * (count($sorted) - 1));

        return (float) $sorted[max(0, $index)];
    }
}
