<?php

namespace Sharryy\Docker;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Sharryy\Docker\Exceptions\BadRequestException;
use Sharryy\Docker\Exceptions\ConnectionException;
use Sharryy\Docker\Exceptions\DockerException;

final class DockerClient
{
    private bool $negotiated = false;

    public function __construct(
        protected Client $client,
        protected string $apiVersion = 'v1.41',
        protected bool $autoNegotiateVersion = true,
    ) {}

    public function get(string $path, array $options = []): ResponseInterface
    {
        return $this->request('get', $path, $options);
    }

    public function post(string $path, array $options = []): ResponseInterface
    {
        return $this->request('post', $path, $options);
    }

    public function put(string $path, array $options = []): ResponseInterface
    {
        return $this->request('put', $path, $options);
    }

    public function delete(string $path, array $options = []): ResponseInterface
    {
        return $this->request('delete', $path, $options);
    }

    public function patch(string $path, array $options = []): ResponseInterface
    {
        return $this->request('patch', $path, $options);
    }

    private function request(string $method, string $path, array $options): ResponseInterface
    {
        $this->negotiateVersion();

        try {
            return $this->client->request(strtoupper($method), $this->buildPath($path), $options);
        } catch (ConnectException $e) {
            throw new ConnectionException("Could not connect to the Docker daemon: {$e->getMessage()}", 0, $e);
        } catch (ClientException $e) {
            throw new BadRequestException($this->describeError($e), $e->getCode(), $e);
        } catch (GuzzleException $e) {
            if ($this->isTimeout($e)) {
                throw new ConnectionException("Could not connect to the Docker daemon: {$e->getMessage()}", 0, $e);
            }

            throw new DockerException("Docker API request failed: {$e->getMessage()}", 0, $e);
        }
    }

    private function isTimeout(GuzzleException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'timed out')
            || str_contains($message, 'cURL error 28');
    }

    /**
     * Ask the daemon which API version it speaks and adopt it.
     *
     * Docker daemons drop support for old API versions over time (e.g. v1.41
     * is rejected by Docker 25+/Colima). Negotiating once per client keeps the
     * library working without the caller pinning a version by hand.
     */
    private function negotiateVersion(): void
    {
        if ($this->negotiated || ! $this->autoNegotiateVersion) {
            return;
        }

        // Set first so the /version call below (and any failure) does not recurse.
        $this->negotiated = true;

        try {
            $response = $this->client->get('/version');
            $data = json_decode((string) $response->getBody(), true);

            if (is_array($data) && isset($data['ApiVersion']) && is_string($data['ApiVersion'])) {
                $this->apiVersion = 'v'.ltrim($data['ApiVersion'], 'v');
            }
        } catch (\Throwable) {
            // Keep the configured version; the real request will surface any error.
        }
    }

    private function buildPath(string $path): string
    {
        $path = ltrim($path, '/');

        return "/{$this->apiVersion}/{$path}";
    }

    private function describeError(ClientException $e): string
    {
        $body = (string) $e->getResponse()->getBody();
        $decoded = json_decode($body, true);

        if (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])) {
            return $decoded['message'];
        }

        return $e->getMessage();
    }

    public function getApiVersion(): string
    {
        return $this->apiVersion;
    }
}
