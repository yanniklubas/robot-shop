<?php

declare(strict_types=1);

namespace Instana\RobotShop\Ratings\Service;

use Exception;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class CircuitBreakerException extends Exception
{
}

class CircuitBreaker
{
    private const STATE_CLOSED = 'CLOSED';
    private const STATE_OPEN = 'OPEN';
    private const STATE_HALF_OPEN = 'HALF_OPEN';

    private string $state;
    private array $successTimestamps;
    private array $failureTimestamps;
    private int $nextAttempt;
    private int $lastCleanTime;

    // Configuration
    private int $failureThreshold;
    private int $timeout; // time window in seconds
    private int $resetTimeout;
    private int $maxConcurrent;
    private int $maxTimestamps;

    // Concurrency tracking
    private int $activeRequests;


    public function __construct(
        int $failureThreshold = 10, // percentage
        int $timeout = 300, // time window in seconds
        int $resetTimeout = 300, // seconds
        int $maxConcurrent = PHP_INT_MAX,
        int $maxTimestamps = 1000,
    ) {
        $this->maxConcurrent = $maxConcurrent;
        $this->failureThreshold = $failureThreshold;
        $this->timeout = $timeout;
        $this->resetTimeout = $resetTimeout;
        $this->maxTimestamps = $maxTimestamps;

        $this->state = self::STATE_CLOSED;
        $this->successTimestamps = [];
        $this->nextAttempt = time();
        $this->lastCleanTime = time();
        $this->activeRequests = 0;
        $this->failureTimestamps = [];

    }

    private function canRequest(): bool
    {
        // Check concurrent limit
        if ($this->activeRequests >= $this->maxConcurrent) {
            return false;
        }

        // If circuit is open, check if we should move to half-open
        if ($this->state === self::STATE_OPEN) {
            if (time() >= $this->nextAttempt) {
                error_log("CircuitBreaker: switching from " . $this->state . " to HALF_OPEN");
                $this->state = self::STATE_HALF_OPEN;
                return true;
            }
            return false;
        }

        // In HALF_OPEN, only allow one request
        if ($this->state === self::STATE_HALF_OPEN) {
            return $this->activeRequests === 0;
        }

        return true;
    }

    private function cleanOldTimestamps(array $timestamps, int $now): array
    {
        $i = 0;
        while ($i < count($timestamps) && ($now - $timestamps[$i]) > $this->timeout) {
            $i++;
        }
        return array_slice($timestamps, $i);
    }

    private function cleanTimestampsIfNeeded(int $now): void
    {
        $shouldUpdateCleanTime = false;

        // Force cleanup if arrays are too large
        if (count($this->successTimestamps) > $this->maxTimestamps) {
            $this->successTimestamps = $this->cleanOldTimestamps($this->successTimestamps, $now);
            $shouldUpdateCleanTime = true;
        }
        if (count($this->failureTimestamps) > $this->maxTimestamps) {
            $this->failureTimestamps = $this->cleanOldTimestamps($this->failureTimestamps, $now);
            $shouldUpdateCleanTime = true;
        }

        // Only clean if it's been a while
        if (($now - $this->lastCleanTime) > max(intdiv($this->timeout, 50), 1)) {
            $this->successTimestamps = $this->cleanOldTimestamps($this->successTimestamps, $now);
            $this->failureTimestamps = $this->cleanOldTimestamps($this->failureTimestamps, $now);
            $shouldUpdateCleanTime = true;
        }

        if ($shouldUpdateCleanTime) {
            $this->lastCleanTime = time();
        }
    }

    private function recordSuccess(): void
    {
        $this->activeRequests = max(0, $this->activeRequests - 1);
        $now = time();
        array_push($this->successTimestamps, $now);

        $this->cleanTimestampsIfNeeded($now);

        if ($this->state === self::STATE_HALF_OPEN) {
            error_log("CircuitBreaker: switching from " . $this->state . " to CLOSED");
            $this->state = self::STATE_CLOSED;
            $this->resetMetrics();
        }
    }

    private function recordFailure(): void
    {
        $this->activeRequests = max(0, $this->activeRequests - 1);
        $now = time();
        array_push($this->failureTimestamps, $now);

        // Always clean timestamps on failure since we calculate failure rate
        $this->failureTimestamps = $this->cleanOldTimestamps($this->failureTimestamps, $now);
        $this->successTimestamps = $this->cleanOldTimestamps($this->successTimestamps, $now);

        // Calculate failure rate within the time window
        $recentFailures = count($this->failureTimestamps);
        $recentTotal = count($this->failureTimestamps) + count($this->successTimestamps);

        $failureRate = $recentTotal > 0 ? ($recentFailures / $recentTotal) * 100 : 0;

        // Check if we should open the circuit
        if ($this->state === self::STATE_HALF_OPEN) {
            $this->state = self::STATE_OPEN;
            $this->nextAttempt = time() + $this->resetTimeout;
            // $this->logger->warning("CircuitBreaker OPENED: " . json_encode($this->getStatus()));
            error_log("CircuitBreaker OPENED: " . json_encode($this->getStatus()));
        } elseif (
            $this->state === self::STATE_CLOSED &&
            $failureRate >= $this->failureThreshold
        ) {
            $this->state = self::STATE_OPEN;
            $this->nextAttempt = time() + $this->resetTimeout;
            // $this->logger->warning("CircuitBreaker OPENED: " . json_encode($this->getStatus()));
            error_log("CircuitBreaker OPENED: " . json_encode($this->getStatus()));
        }
    }

    private function resetMetrics(): void
    {
        $this->successTimestamps = [];
        $this->failureTimestamps = [];
    }

    public function execute(callable $callback, callable $isFailure = null)
    {
        if (!$this->canRequest()) {
            throw new CircuitBreakerException("Circuit breaker is {$this->state} or max concurrent requests reached");
        }

        $this->activeRequests++;

        try {
            $result = $callback();
            $isFailureResult = $isFailure ? $isFailure($result) : false;

            if($isFailureResult) {
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
        $totalRequests = count($this->failureTimestamps) + count($this->successTimestamps);
        return [
            'state' => $this->state,
            'failureCount' => count($this->failureTimestamps),
            'successCount' => count($this->successTimestamps),
            'totalRequests' => $totalRequests,
            'activeRequests' => $this->activeRequests,
            'failureRate' => $totalRequests > 0 ? (count($this->failureTimestamps) / $totalRequests) * 100 : 0,
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
        ?CircuitBreaker $circuitBreaker=null,
    )
    {
        $this->catalogueUrl = $catalogueUrl;
        $this->circuitBreaker = $circuitBreaker ?? new CircuitBreaker(
            self::FAILURE_THRESHOLD,
            self::TIME_WINDOW,
            self::SLEEP_WINDOW,
            self::MAX_CONCURRENT_CONNECTIONS,
        );
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
