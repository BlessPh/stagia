<?php
declare(strict_types=1);
require_once __DIR__ . '/ApiTestSupport.php';

$suite = $suite ?? new ApiTestSuite();
$config = apiTestConfig();
$client = new ApiTestClient((string) $config['base_url']);
$tokens = $client->login($config['students']['a']);
$access = (string) $tokens['access_token'];

$me = $client->request('GET', '/me', null, $access);
$suite->ok($me['status'] === 200 && ($me['json']['success'] ?? false), 'login → me fonctionne');
$suite->ok(in_array('STAGIAIRE', $me['json']['data']['user']['roles'] ?? [], true), 'Le token appartient à un étudiant');

foreach ([
    '/student/dashboard', '/student/profile', '/student/enrollments',
    '/student/stage-options', '/student/applications', '/student/reservations',
    '/student/payments', '/student/admissions', '/student/stages',
    '/student/attendance', '/student/logbook', '/student/tasks',
    '/student/feedbacks', '/student/evaluations', '/student/notes',
    '/student/documents', '/student/conventions', '/student/academic-documents',
    '/student/personal-documents', '/student/notifications/counts',
    '/student/notifications', '/student/notifications/preferences',
    '/student/conversations', '/student/communications', '/student/calendar',
] as $path) {
    $response = $client->request('GET', $path, null, $access);
    $suite->ok($response['status'] === 200, "Scénario de lecture {$path}");
}

$refresh = $client->request('POST', '/refresh-token', ['refresh_token' => $tokens['refresh_token']]);
$suite->ok($refresh['status'] === 200 && !empty($refresh['json']['data']['access_token']), 'Rotation du refresh token');
$newAccess = (string) $refresh['json']['data']['access_token'];
$old = $client->request('GET', '/me', null, $access);
$suite->ok($old['status'] === 401, "L'ancien access token est révoqué");
$logout = $client->request('POST', '/logout', null, $newAccess);
$suite->ok($logout['status'] === 200, 'Déconnexion mobile');
$afterLogout = $client->request('GET', '/me', null, $newAccess);
$suite->ok($afterLogout['status'] === 401, 'Le token déconnecté est refusé');

return $suite->summary();
