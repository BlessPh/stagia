<?php
declare(strict_types=1);

final class ApiTestFailure extends RuntimeException {}
final class ApiTestSkipped extends RuntimeException {}

final class ApiTestSuite
{
    private int $passed = 0;
    private int $skipped = 0;

    public function ok(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new ApiTestFailure($message);
        }
        $this->passed++;
        echo "[OK] {$message}\n";
    }

    public function skip(string $message): never
    {
        $this->skipped++;
        throw new ApiTestSkipped($message);
    }

    public function summary(): array
    {
        return ['passed' => $this->passed, 'skipped' => $this->skipped];
    }
}

final class ApiTestClient
{
    public function __construct(private readonly string $baseUrl) {}

    public function request(string $method, string $path, ?array $body = null, ?string $token = null, array $headers = []): array
    {
        if (!extension_loaded('curl')) {
            throw new ApiTestSkipped("L'extension PHP cURL est requise pour les tests HTTP.");
        }

        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $ch = curl_init($url);
        $requestHeaders = ['Accept: application/json'];
        if ($token !== null) {
            $requestHeaders[] = 'Authorization: Bearer ' . $token;
        }
        if ($body !== null) {
            $requestHeaders[] = 'Content-Type: application/json';
        }
        foreach ($headers as $name => $value) {
            $requestHeaders[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new ApiTestFailure("Erreur HTTP vers {$url}: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawBody = substr($raw, $headerSize);
        $decoded = json_decode($rawBody, true);
        return [
            'status' => $status,
            'headers' => substr($raw, 0, $headerSize),
            'raw' => $rawBody,
            'json' => is_array($decoded) ? $decoded : null,
        ];
    }

    public function login(array $account): array
    {
        $response = $this->request('POST', '/login', [
            'identifiant' => (string) ($account['identifiant'] ?? ''),
            'password' => (string) ($account['password'] ?? ''),
            'device_name' => 'tests-api-student',
        ]);
        if ($response['status'] !== 200 || empty($response['json']['data']['access_token'])) {
            throw new ApiTestFailure('Connexion de la fixture impossible: HTTP ' . $response['status'] . ' ' . $response['raw']);
        }
        return $response['json']['data'];
    }

    public function concurrentJson(array $requests): array
    {
        if (!extension_loaded('curl')) {
            throw new ApiTestSkipped("L'extension PHP cURL est requise pour le test de concurrence.");
        }
        $multi = curl_multi_init();
        $handles = [];
        foreach ($requests as $index => $request) {
            $url = rtrim($this->baseUrl, '/') . '/' . ltrim((string) $request['path'], '/');
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => strtoupper((string) ($request['method'] ?? 'POST')),
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $request['token'],
                ],
                CURLOPT_POSTFIELDS => json_encode($request['body'], JSON_THROW_ON_ERROR),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);
            $handles[$index] = $ch;
            curl_multi_add_handle($multi, $ch);
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $responses = [];
        foreach ($handles as $index => $ch) {
            $raw = (string) curl_multi_getcontent($ch);
            $responses[$index] = [
                'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'raw' => $raw,
                'json' => json_decode($raw, true),
            ];
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        ksort($responses);
        return array_values($responses);
    }
}

function apiTestConfig(): array
{
    $path = getenv('STAGIA_API_TEST_CONFIG') ?: __DIR__ . '/config.local.json';
    if (!is_file($path)) {
        throw new ApiTestSkipped("Configuration absente. Copiez config.example.json vers config.local.json.");
    }
    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

function apiTestRoot(): string
{
    return dirname(__DIR__, 2);
}

