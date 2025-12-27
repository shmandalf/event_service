<?php

declare(strict_types=1);

namespace App\Services;

use Prometheus\CollectorRegistry;
use Prometheus\Storage\Redis;
use Prometheus\RenderTextFormat;

class MetricsService
{
    private CollectorRegistry $registry;

    public function __construct()
    {
        $adapter = new Redis([
            'host' => config('database.redis.default.host', 'redis'),
            'port' => config('database.redis.default.port', 6379),
            'password' => config('database.redis.default.password'),
            'timeout' => 0.1,
            'read_timeout' => 10,
            'persistent_connections' => false,
        ]);

        $this->registry = new CollectorRegistry($adapter);
    }

    public function increment(string $name, array $labels = [], int $value = 1): void
    {
        $counter = $this->registry->getOrRegisterCounter(
            config('prometheus.namespace', 'event_service'),
            $name,
            'Counter for ' . $name,
            array_keys($labels)
        );

        $counter->incBy($value, array_values($labels));
    }

    public function gauge(string $name, array $labels = [], float $value): void
    {
        $gauge = $this->registry->getOrRegisterGauge(
            config('prometheus.namespace', 'event_service'),
            $name,
            'Gauge for ' . $name,
            array_keys($labels)
        );

        $gauge->set($value, array_values($labels));
    }

    public function histogram(string $name, float $value, array $labels = []): void
    {
        // если вдруг вызовем без меток
        if (empty($labels)) {
            $labels = ['default' => 'default'];
            \Log::warning("Histogram {$name} called without labels, added default label");
        }

        $histogram = $this->registry->getOrRegisterHistogram(
            config('prometheus.namespace', 'event_service'),
            $name,
            'Histogram for ' . $name,
            array_keys($labels),
            [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1.0, 2.5, 5.0, 7.5, 10.0]
        );

        $histogram->observe($value, array_values($labels));
    }

    public function render(): string
    {
        $renderer = new RenderTextFormat();
        return $renderer->render($this->registry->getMetricFamilySamples());
    }

    private function debugMetrics(): void
    {
        $samples = $this->registry->getMetricFamilySamples();

        foreach ($samples as $metric) {
            echo "=== Metric: " . $metric->getName() . " ===\n";
            echo "Help: " . $metric->getHelp() . "\n";
            echo "Type: " . $metric->getType() . "\n";

            foreach ($metric->getSamples() as $sample) {
                echo "  Sample: " . $sample->getName() . "\n";
                echo "    Value: " . $sample->getValue() . "\n";

                $labelNames = $sample->getLabelNames();
                $labelValues = $sample->getLabelValues();

                echo "    Label names (" . count($labelNames) . "): " . implode(', ', $labelNames) . "\n";
                echo "    Label values (" . count($labelValues) . "): " . implode(', ', $labelValues) . "\n";

                if (count($labelNames) !== count($labelValues)) {
                    echo "    ⚠️ ERROR: Label count mismatch!\n";
                    echo "    Names: " . json_encode($labelNames) . "\n";
                    echo "    Values: " . json_encode($labelValues) . "\n";
                }
            }
        }
    }

    // Методы для конкретных метрик
    public function recordEventReceived(string $type, int $priority): void
    {
        $this->increment('events_received_total', [
            'type' => $type,
            'priority' => (string) $priority,
        ]);
    }

    public function recordProcessingTime(float $seconds, string $worker): void
    {
        $this->histogram('worker_processing_duration_seconds', $seconds, ['worker' => $worker]);
    }

    public function recordQueueSize(string $queue, int $size): void
    {
        $this->gauge('queue_size', ['queue' => $queue], $size);
    }
}
