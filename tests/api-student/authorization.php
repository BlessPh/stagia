<?php
declare(strict_types=1);
require_once __DIR__ . '/ApiTestSupport.php';

$suite = $suite ?? new ApiTestSuite();
$config = apiTestConfig();
if (empty($config['authorization_cases'])) {
    $suite->skip('Aucun authorization_case configuré.');
}
$client = new ApiTestClient((string) $config['base_url']);
$a = $client->login($config['students']['a']);
$b = $client->login($config['students']['b']);

foreach ($config['authorization_cases'] as $case) {
    $owner = ($case['owner'] ?? 'b') === 'a' ? $a : $b;
    $attacker = ($case['attacker'] ?? 'a') === 'b' ? $b : $a;
    $response = $client->request(
        (string) ($case['method'] ?? 'GET'),
        (string) $case['path'],
        isset($case['body']) ? (array) $case['body'] : null,
        (string) $attacker['access_token']
    );
    $allowed = array_map('intval', $case['expected_statuses'] ?? [403, 404]);
    $suite->ok(in_array($response['status'], $allowed, true), 'Isolation inter-étudiants: ' . ($case['name'] ?? $case['path']));

    if (!empty($case['owner_must_access'])) {
        $ownerResponse = $client->request((string) ($case['method'] ?? 'GET'), (string) $case['path'], isset($case['body']) ? (array) $case['body'] : null, (string) $owner['access_token']);
        $suite->ok($ownerResponse['status'] >= 200 && $ownerResponse['status'] < 300, 'Le propriétaire conserve son accès: ' . ($case['name'] ?? $case['path']));
    }
}

return $suite->summary();
