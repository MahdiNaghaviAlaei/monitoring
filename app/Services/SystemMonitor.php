<?php

namespace App\Services;

use App\Support\JalaliDate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SystemMonitor
{
    public function fetch(string $path, array $query = [], bool $fresh = false): ?array
    {
        $url = rtrim((string) config('system-monitor.url'), '/');
        if ($url === '') {
            return null;
        }

        $cacheKey = 'system-monitor:data:'.sha1($url.$path.json_encode($query)).':'.$this->cacheRevision($url);
        if ($fresh) {
            Cache::forget($cacheKey);
        }

        $cached = Cache::remember($cacheKey, 10, function () use ($url, $path, $query): array {
            try {
                return ['data' => $this->json(Http::acceptJson()->connectTimeout(3)->timeout(8)->get($url.$path, $query))];
            } catch (ConnectionException) {
                return ['data' => null];
            }
        });

        return $cached['data'];
    }

    public function history(string $path, array $query = []): ?array
    {
        return $this->historyRows($this->fetch($path, $query));
    }

    private function historyRows(?array $data): ?array
    {
        if (! is_array($data['columns'] ?? null) || ! is_array($data['rows'] ?? null)) {
            return null;
        }

        $rows = [];
        foreach ($data['rows'] as $row) {
            if (is_array($row) && count($row) === count($data['columns']) && count($row) > 0) {
                $rows[] = array_combine($data['columns'], $row);
            }
        }

        return $rows;
    }

    /** Single-day ranges use hourly means; longer ranges use daily means. */
    public function containerHistory(string $id, string $from, string $to, int $pageLimit = 100): array
    {
        $url = rtrim((string) config('system-monitor.url'), '/');
        $pageLimit = min(1000, max(1, $pageLimit));
        $key = 'system-monitor:history-v2:'.sha1($url.'|'.$id.'|'.$from.'|'.$to.'|'.$pageLimit).':'.$this->cacheRevision($url);

        return Cache::remember($key, 30, fn () => $this->readAggregatedContainerHistory($id, $from, $to, $pageLimit));
    }

    private function readAggregatedContainerHistory(string $id, string $from, string $to, int $pageLimit): array
    {
        $buckets = [];
        $hourly = $from === $to;
        $complete = false;
        $failed = false;
        $oldestBucket = null;
        $samples = 0;
        $previousPageKeys = [];

        for ($page = 1; $page <= $pageLimit; $page++) {
            $payload = $this->fetch('/api/metrics/containers/history', ['container_id' => $id, 'page' => $page, 'page_size' => 500]);
            $rows = $this->historyRows($payload);
            if ($rows === null) {
                $failed = true;
                break;
            }

            $passedStart = false;
            $pageKeys = [];
            foreach ($rows as $row) {
                $stamp = JalaliDate::apiTimestamp($row['timestamp'] ?? null);
                if ($stamp === null) {
                    continue;
                }
                $day = substr($stamp, 0, 10);
                $bucket = $hourly ? substr($stamp, 0, 13).':00:00' : $day;
                $oldestBucket = $oldestBucket === null ? $bucket : min($oldestBucket, $bucket);
                $passedStart = $passedStart || $day < $from;
                // New samples can shift offset pagination. Do not count an overlapping sample twice.
                $sampleKey = $row['timestamp'];
                if (isset($previousPageKeys[$sampleKey]) || isset($pageKeys[$sampleKey])) {
                    continue;
                }
                $pageKeys[$sampleKey] = true;
                if ($day < $from || $day > $to) {
                    continue;
                }

                $buckets[$bucket] ??= ['samples' => 0, 'cpu_count' => 0, 'cpu_mean' => 0.0, 'mem_count' => 0, 'mem_mean' => 0.0];
                $buckets[$bucket]['samples']++;
                $samples++;
                foreach (['cpu' => 'cpu_percent', 'mem' => 'mem_percent'] as $metric => $field) {
                    $value = $row[$field] ?? null;
                    if (! is_numeric($value) || ! is_finite((float) $value) || $value < 0 || ($metric === 'mem' && $value > 100)) {
                        continue;
                    }
                    $count = ++$buckets[$bucket][$metric.'_count'];
                    $buckets[$bucket][$metric.'_mean'] += ((float) $value - $buckets[$bucket][$metric.'_mean']) / $count;
                }
            }
            $previousPageKeys = $pageKeys;

            $totalPages = $payload['total_pages'] ?? null;
            $lastPage = is_numeric($totalPages) ? $page >= (int) $totalPages : count($payload['rows']) < 500;
            if ($passedStart || $lastPage || $payload['rows'] === []) {
                $complete = true;
                break;
            }
        }

        ksort($buckets);
        $aggregatedRows = [];
        foreach ($buckets as $bucket => $values) {
            // The last scanned hour/day may continue on another page. Hide its partial mean.
            $bucketComplete = $complete || ($oldestBucket !== null && $bucket > $oldestBucket);
            $aggregatedRows[] = [
                'date' => $bucket,
                'cpu_percent' => $bucketComplete && $values['cpu_count'] > 0 ? round($values['cpu_mean'], 2) : null,
                'mem_percent' => $bucketComplete && $values['mem_count'] > 0 ? round($values['mem_mean'], 2) : null,
                'samples' => $values['samples'],
                'complete' => $bucketComplete,
            ];
        }

        return ['rows' => $aggregatedRows, 'samples' => $samples, 'complete' => $complete, 'failed' => $failed, 'pages' => min($page, $pageLimit)];
    }

    public function snapshot(): array
    {
        $url = rtrim((string) config('system-monitor.url'), '/');

        return Cache::remember('system-monitor:'.sha1($url).':'.$this->cacheRevision($url), 10, function () use ($url): array {
            $responses = $url === '' ? [] : Http::pool(fn (Pool $pool): array => [
                $pool->as('health')->acceptJson()->connectTimeout(3)->timeout(8)->get($url.'/health'),
                $pool->as('summary')->acceptJson()->connectTimeout(3)->timeout(8)->get($url.'/api/summary'),
            ]);

            return [
                'health' => $this->json($responses['health'] ?? null),
                'summary' => $this->json($responses['summary'] ?? null),
                'checked_at' => now()->timezone('Asia/Tehran')->format('H:i:s'),
            ];
        });
    }

    public function control(string $id, string $operation): array
    {
        $url = rtrim((string) config('system-monitor.url'), '/');
        if ($url === '' || ! preg_match('/^[a-f0-9]{12,64}$/i', $id) || ! in_array($operation, ['start', 'stop', 'restart'], true)) {
            return ['ok' => false, 'message' => __('monitoring.control.invalid_request')];
        }

        try {
            // Do not retry a command: a lost response does not mean it was not executed.
            $response = Http::acceptJson()->connectTimeout(3)->timeout(20)->post($url.'/api/containers/'.$id.'/'.$operation);
        } catch (ConnectionException) {
            $this->invalidate($url);

            return ['ok' => false, 'message' => __('monitoring.control.timeout')];
        }

        $this->invalidate($url);
        $data = $this->json($response);
        if (! $response->successful() || ($data === null && ! $response->noContent()) || ($data['success'] ?? null) === false || ($data['status'] ?? null) === 'error') {
            $detail = $response->json('detail');

            return ['ok' => false, 'message' => __('monitoring.control.api_rejected', ['status' => $response->status()]).(is_string($detail) ? ' '.mb_substr(strip_tags($detail), 0, 400) : '')];
        }

        return ['ok' => true, 'message' => __('monitoring.control.api_accepted', ['operation' => __('monitoring.actions.'.$operation)])];
    }

    private function cacheRevision(string $url): string
    {
        return (string) Cache::get('system-monitor:revision:'.sha1($url), '0');
    }

    private function invalidate(string $url): void
    {
        // Invalidate only this monitor's cached reads, including all table pages.
        Cache::put('system-monitor:revision:'.sha1($url), bin2hex(random_bytes(8)), 60);
    }

    private function json(mixed $response): ?array
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return is_array($data) ? $data : null;
    }
}
