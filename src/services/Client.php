<?php

/**
 * Manager Connector plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

declare(strict_types=1);

namespace coyshdigital\managerconnector\services;

use Craft;
use coyshdigital\managerconnector\Plugin;
use coyshdigital\managerprotocol\CanonicalRequest;
use coyshdigital\managerprotocol\CanonicalResponse;
use coyshdigital\managerprotocol\Nonce;
use coyshdigital\managerprotocol\Protocol;
use craft\base\Component;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Sends signed requests to the Manager platform.
 *
 * Every exchange starts here. The plugin exposes nothing inbound, so this class is the entire
 * surface between a customer's website and the platform.
 *
 * Uses the Guzzle client Craft already ships. Adding an HTTP dependency to privileged code running
 * inside somebody's production site would need a much better reason than convenience.
 */
class Client extends Component
{
    /**
     * Pair with a platform.
     *
     * The one unsigned request the connector ever makes, because at this point it has no identity
     * for the platform to verify. It authenticates with a single-use enrolment code instead.
     *
     * The response **is** signed, and is verified here against the platform public key it carries.
     * That check is the connector's first proof it is talking to the right server rather than to
     * whatever intercepted the request, so a failure is fatal to pairing rather than a warning.
     *
     * @param  array{public: string, secret: string}  $keypair
     * @return array<string, mixed>
     */
    public function pair(string $platformUrl, string $enrolmentCode, array $keypair): array
    {
        $nonce = Nonce::generate();

        $body = json_encode([
            'enrolment_code' => $enrolmentCode,
            'public_key' => $keypair['public'],
            'connector_version' => Plugin::VERSION,
            'site_url' => $this->siteHost(),
            'nonce' => $nonce,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $response = $this->send($platformUrl, '/api/connector/v1/pair', $body, []);

        $decoded = $this->decode($response['body']);

        if ($response['status'] !== 200) {
            throw new RuntimeException(
                'Pairing was refused by the platform. Correlation ID: '
                .($decoded['correlation_id'] ?? 'unknown')
            );
        }

        $platformPublicKey = $decoded['platform_public_key'] ?? null;
        $signature = $this->signatureFrom($response['headers']);

        if (! is_string($platformPublicKey) || $signature === null) {
            throw new RuntimeException('The platform did not return a signed pairing response.');
        }

        $canonical = new CanonicalResponse(
            siteId: (string) ($decoded['site_id'] ?? ''),
            requestNonce: $nonce,
            status: $response['status'],
            body: $response['body'],
        );

        // Trust-on-first-use, but verified: the response has to be signed by the key it is
        // offering, and bound to the nonce this request chose.
        if (! $canonical->verify($signature, $platformPublicKey)) {
            throw new RuntimeException(
                'The pairing response failed signature verification. Someone may be intercepting this connection.'
            );
        }

        return $decoded;
    }

    /**
     * Send a signed request as this site.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload): array
    {
        $connection = Plugin::getInstance()->connection;
        $record = $connection->current();

        if ($record === null) {
            throw new RuntimeException('This site is not paired with a Manager platform.');
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = time();
        $nonce = Nonce::generate();

        $canonical = new CanonicalRequest(
            siteId: $record->siteIdentifier,
            connectorVersion: Plugin::VERSION,
            timestamp: $timestamp,
            nonce: $nonce,
            method: 'POST',
            path: $path,
            body: $body,
        );

        $secret = $connection->secretKey();

        try {
            $signature = $canonical->sign($secret);
        } finally {
            // Out of memory as soon as it has been used.
            sodium_memzero($secret);
        }

        $response = $this->send($record->platformUrl, $path, $body, [
            Protocol::HEADER_SITE => $record->siteIdentifier,
            Protocol::HEADER_TIMESTAMP => (string) $timestamp,
            Protocol::HEADER_NONCE => $nonce,
            Protocol::HEADER_CONNECTOR_VERSION => Plugin::VERSION,
            Protocol::HEADER_SIGNATURE => Protocol::SIGNATURE_SCHEME.'='.$signature,
        ]);

        $decoded = $this->decode($response['body']);

        if ($response['status'] >= 400) {
            throw new RuntimeException(sprintf(
                'The platform rejected the request (HTTP %d). Correlation ID: %s',
                $response['status'],
                $decoded['correlation_id'] ?? 'unknown',
            ));
        }

        $connection->recordSuccess();

        return $decoded;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string, headers: array<string, list<string>>}
     */
    private function send(string $platformUrl, string $path, string $body, array $headers): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $client = Craft::createGuzzleClient([
            'timeout' => $settings->timeout,
            'connect_timeout' => $settings->timeout,

            // A non-2xx response is information, not an exception: the platform's rejections carry
            // a correlation ID that an operator needs.
            'http_errors' => false,
        ]);

        try {
            $response = $client->post(rtrim($platformUrl, '/').$path, [
                'body' => $body,
                'headers' => $headers + [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'ManagerConnector/'.Plugin::VERSION.' (Craft)',
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Could not reach the Manager platform: '.$e->getMessage(), 0, $e);
        }

        return [
            'status' => $response->getStatusCode(),
            'body' => (string) $response->getBody(),
            'headers' => $response->getHeaders(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, list<string>>  $headers
     */
    private function signatureFrom(array $headers): ?string
    {
        foreach ($headers as $name => $values) {
            if (strcasecmp($name, Protocol::HEADER_SIGNATURE) !== 0) {
                continue;
            }

            $value = $values[0] ?? '';
            $prefix = Protocol::SIGNATURE_SCHEME.'=';

            return str_starts_with($value, $prefix) ? substr($value, strlen($prefix)) : null;
        }

        return null;
    }

    /**
     * The host this site actually serves from.
     *
     * Sent during pairing so the platform can compare it with the domain the operator expected. A
     * mismatch does not fail; it holds the pairing until a person has looked at both values.
     */
    private function siteHost(): string
    {
        $siteUrl = Craft::$app->getSites()->getPrimarySite()->getBaseUrl();

        return $siteUrl === null ? '' : (string) parse_url($siteUrl, PHP_URL_HOST);
    }
}
