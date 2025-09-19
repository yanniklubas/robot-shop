<?php

declare(strict_types=1);

namespace Instana\RobotShop\Ratings\Service;

use Exception;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class CircuitBreakerException extends Exception
{
}

class CircuitBreaker implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const STATE_CLOSED = 'CLOSED';
    private const STATE_OPEN = 'OPEN';
    private const STATE_HALF_OPEN = 'HALF_OPEN';

    private string $file;
    private int $failureThreshold;
    private int $timeout;
    private int $resetTimeout;
    private int $maxConcurrent;

    // Track active requests per worker
    private int $activeRequests = 0;

    public function __construct(
        string $file = '/tmp/circuitbreaker.json',
        int $failureThreshold = 10,
        int $timeout = 300,
        int $resetTimeout = 300,
        int $maxConcurrent = PHP_INT_MAX
    ) {
        $this->file = $file;
        $this->failureThreshold = $failureThreshold;
        $this->timeout = $timeout;
        $this->resetTimeout = $resetTimeout;
        $this->maxConcurrent = $maxConcurrent;

        // initialize file if it doesn't exist
        if (!file_exists($this->file)) {
            $this->saveState([
                'state' => self::STATE_CLOSED,
                'failureTimestamps' => [],
                'successTimestamps' => [],
                'nextAttempt' => time()
            ]);
        }
    }

    private function loadState(): array
    {
        $fh = fopen($this->file, 'c+');
        if (!$fh) {
            throw new Exception("Cannot open circuit breaker file");
        }
        flock($fh, LOCK_EX);
        $content = stream_get_contents($fh);
        $state = json_decode($content ?: '{}', true);
        if (!$state) {
            $state = [
                'state' => self::STATE_CLOSED,
                'failureTimestamps' => [],
                'successTimestamps' => [],
                'nextAttempt' => time()
            ];
        }

        return [$fh, $state];
    }

    private function saveState(array $state, $fh = null): void
    {

        $json = json_encode($state);
        if ($fh) {
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $json);
            fflush($fh);
            flock($fh, LOCK_UN);
            fclose($fh);
        } else {
            file_put_contents($this->file, $json, LOCK_EX);
        }
    }

    private function cleanTimestamps(array $timestamps): array
    {
        $now = time();
        return array_filter($timestamps, function ($t) use ($now) {
            return ($now - $t) <= $this->timeout;
        });
    }

    private function canRequest(): bool
    {
        $this->activeRequests++;
        list($fh, $state) = $this->loadState();

        $state['failureTimestamps'] = $this->cleanTimestamps($state['failureTimestamps']);
        $state['successTimestamps'] = $this->cleanTimestamps($state['successTimestamps']);

        // Respect max concurrent per worker
        if ($this->activeRequests > $this->maxConcurrent) {
            $this->activeRequests--;
            flock($fh, LOCK_UN);
            fclose($fh);
            return false;
        }

        // If OPEN, check if nextAttempt has passed
        if ($state['state'] === self::STATE_OPEN) {
            if (time() >= $state['nextAttempt']) {
                $state['state'] = self::STATE_HALF_OPEN;
                if ($this->logger) {
                    $this->logger->warning("CircuitBreaker: switching from OPEN to HALF_OPEN");
                }
            } else {
                $this->activeRequests--;
                flock($fh, LOCK_UN);
                fclose($fh);
                return false;
            }
        }

        // HALF_OPEN allows a single request
        if ($state['state'] === self::STATE_HALF_OPEN && $this->activeRequests >= $this->maxConcurrent) {
            $this->activeRequests--;
            flock($fh, LOCK_UN);
            fclose($fh);
            return false;
        }

        $this->saveState($state, $fh);
        return true;
    }

    private function recordSuccess(): void
    {
        $this->activeRequests = max(0, $this->activeRequests - 1);
        list($fh, $state) = $this->loadState();
        $state['successTimestamps'][] = time();

        // If HALF_OPEN, close the circuit
        if ($state['state'] === self::STATE_HALF_OPEN) {
            $state['state'] = self::STATE_CLOSED;
            $state['failureTimestamps'] = [];
            $state['successTimestamps'] = [];
            if ($this->logger) {
                $this->logger->warning("CircuitBreaker: switching from HALF_OPEN to CLOSED");
            }
        }

        $this->saveState($state, $fh);
    }

    private function recordFailure(): void
    {
        $this->activeRequests = max(0, $this->activeRequests - 1);
        list($fh, $state) = $this->loadState();
        $state['failureTimestamps'][] = time();

        $state['failureTimestamps'] = $this->cleanTimestamps($state['failureTimestamps']);
        $state['successTimestamps'] = $this->cleanTimestamps($state['successTimestamps']);

        $total = count($state['failureTimestamps']) + count($state['successTimestamps']);
        $failureRate = $total > 0 ? (count($state['failureTimestamps']) / $total) * 100 : 0;

        $current_state = $state['state'];
        if (
            ($current_state === self::STATE_CLOSED && $failureRate >= $this->failureThreshold) ||
            ($current_state === self::STATE_HALF_OPEN)
        ) {
            $state['state'] = self::STATE_OPEN;
            $state['nextAttempt'] = time() + $this->resetTimeout;
            if ($this->logger) {
                $this->logger->warning("CircuitBreaker: Switching from " . $current_state . " to OPEN");
            }
        }

        $this->saveState($state, $fh);
    }

    public function execute(callable $callback, callable $isFailure = null)
    {
        if (!$this->canRequest()) {
            throw new CircuitBreakerException("Circuit breaker is OPEN or max concurrent reached");
        }

        try {
            $result = $callback();
            $isFailureResult = $isFailure ? $isFailure($result) : false;

            if ($isFailureResult) {
                $this->recordFailure();
                return $result;
            }
            $this->recordSuccess();
            return $result;
        } catch (Exception $e) {
            $this->recordFailure();
            throw $e;
        }
    }

    public function getStatus(): array
    {
        list(, $state) = $this->loadState();
        $total = count($state['failureTimestamps']) + count($state['successTimestamps']);
        return [
            'state' => $state['state'],
            'failureCount' => count($state['failureTimestamps']),
            'successCount' => count($state['successTimestamps']),
            'totalRequests' => $total,
            'failureRate' => $total > 0 ? (count($state['failureTimestamps']) / $total) * 100 : 0
        ];
    }
}

class CatalogueService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private string $catalogueUrl;

    private CircuitBreaker $circuitBreaker;
    const FAILURE_THRESHOLD = 10;
    const TIME_WINDOW = 300; // seconds
    const SLEEP_WINDOW = 300; // seconds
    const MAX_CONCURRENT_CONNECTIONS = PHP_INT_MAX;

    public function __construct(
        string $catalogueUrl,
        ?CircuitBreaker $circuitBreaker=null
    )
    {
        $this->catalogueUrl = $catalogueUrl;
        $this->circuitBreaker = $circuitBreaker ?? new CircuitBreaker(
            "/tmp/circuitbreaker.json",
            self::FAILURE_THRESHOLD,
            self::TIME_WINDOW,
            self::SLEEP_WINDOW,
            self::MAX_CONCURRENT_CONNECTIONS
        );
    }

    public function setLogger(\Psr\Log\LoggerInterface $logger): void
    {
        $this->logger = $logger;
        $this->circuitBreaker->setLogger($logger);
    }

    public function checkSKU(string $sku): bool
    {
        try {
            return $this->circuitBreaker->execute(
                function() use ($sku) {
                    return $this->makeCatalogueRequest($sku);
                },
                function($result) {
                    return $result === false;
                }
            );
        } catch (CircuitBreakerException $e) {
            $this->logger->warning('Circuit breaker prevented catalogue request: ' . $e->getMessage());
            throw new Exception('Service temporarily unavailable due to circuit breaker');
        } catch (Exception $e) {
            // Log this at warning level too
            $this->logger->error('Catalogue service error: ' . $e->getMessage(), [
                'exception' => $e->getMessage(),
                'exception_class' => get_class($e),
                'trace' => substr($e->getTraceAsString(), 0, 1000) // Limit trace length
            ]);
            throw $e;
        }
    }

    private function makeCatalogueRequest(string $sku): bool
    {
        $url = sprintf('%s/product/%s', $this->catalogueUrl, $sku);

        $opt = [
            CURLOPT_RETURNTRANSFER => true,
        ];
        $curl = curl_init($url);
        curl_setopt_array($curl, $opt);

        $data = curl_exec($curl);
        if (!$data) {
            $this->logger->error('failed to connect to catalogue');
            throw new Exception('Failed to connect to catalogue');
        }

        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $this->logger->info("catalogue status $status");

        curl_close($curl);

        return 200 === $status;
    }
}
