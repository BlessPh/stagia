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
$profileFields = [
    'nom','postnom','prenom','sexe','date_naissance','adresse','ville','province',
    'avatar_url','telephone','email','matricule'
];
foreach ($profileFields as $field) {
    $suite->ok(
        array_key_exists($field, $me['json']['data']['user'] ?? []),
        "Le profil /me expose {$field}"
    );
}

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

$enrollmentsResponse = $client->request('GET', '/student/enrollments', null, $access);
$enrollments = $enrollmentsResponse['json']['data']['items'] ?? [];
if ($enrollments) {
    $firstEnrollment = $enrollments[0];
    $suite->ok(!empty($firstEnrollment['university']['name']), 'Le rattachement expose le nom de l universite');
    $suite->ok(isset($firstEnrollment['university']['contact']), 'Les coordonnees de l universite sont structurees');
    $suite->ok(isset($firstEnrollment['university']['location']), 'La localisation de l universite est structuree');
    $suite->ok(isset($firstEnrollment['academic_structure']), 'La structure academique est presente');
    foreach (['academic_unit', 'department', 'program', 'specialty'] as $level) {
        $suite->ok(
            array_key_exists($level, $firstEnrollment['academic_structure']),
            "Le niveau academique {$level} est explicite"
        );
    }
    $suite->ok(array_key_exists('current_academic', $firstEnrollment), 'La situation academique courante est presente');

    $enrollmentId = (int) $enrollments[0]['enrollment_id'];
    $academicPath = $client->request(
        'GET',
        '/student/academic-path?enrollment_id=' . $enrollmentId,
        null,
        $access
    );
    $academicData = $academicPath['json']['data'] ?? [];
    $suite->ok($academicPath['status'] === 200, 'Le parcours academique est accessible');
    $suite->ok(isset($academicData['enrollment']), 'Le rattachement est isole du parcours annuel');
    $suite->ok(isset($academicData['university']), 'L universite est retournee une seule fois');
    $suite->ok(array_key_exists('curriculum', $academicData), 'Le cursus fixe est retourne une seule fois');
    $suite->ok(is_array($academicData['academic_years'] ?? null), 'L historique est structure par annee academique');
    foreach ($academicData['academic_years'] ?? [] as $academicYear) {
        $suite->ok(isset($academicYear['academic_year']), 'Chaque parcours contient son annee academique');
        $suite->ok(array_key_exists('level', $academicYear), 'Chaque annee contient son niveau');
        $suite->ok(isset($academicYear['promotion']), 'Chaque annee contient sa promotion');
        $suite->ok(isset($academicYear['academic_enrollment_id']), 'Chaque annee reste utilisable pour charger ses notes');
    }
}

$stageOptions = $client->request('GET', '/student/stage-options', null, $access);
$suite->ok(
    $stageOptions['status'] === 200 && ($stageOptions['json']['success'] ?? false),
    'Les campagnes éligibles sont accessibles'
);
$d4Campaigns = $stageOptions['json']['data']['campaigns'] ?? null;
$managedCampaigns = $stageOptions['json']['data']['university_managed_campaigns'] ?? null;
$suite->ok(is_array($d4Campaigns), 'La collection campaigns est présente');
$suite->ok(is_array($managedCampaigns), 'La collection university_managed_campaigns est présente');

foreach (array_merge($d4Campaigns, $managedCampaigns) as $campaign) {
    $hospitals = $campaign['hospitals'] ?? [];
    $suite->ok(
        is_array($hospitals) && count($hospitals) > 0,
        'La campagne ' . ($campaign['code'] ?? '?') . ' possède un accueil hospitalier valide'
    );
    foreach ($hospitals as $hospital) {
        $suite->ok(
            (int) ($hospital['capacity'] ?? 0) > 0,
            'La capacité hospitalière exposée est positive'
        );
        $suite->ok(
            array_key_exists('phone', $hospital['hospital'] ?? []),
            'Le numéro de contact hospitalier est exposé'
        );
        $suite->ok(
            is_array($hospital['hospital']['services'] ?? null),
            'La liste des services hospitaliers est exposée'
        );
    }
    $suite->ok(
        ($campaign['mode']['self_reservation_allowed'] ?? false) === true,
        'La campagne ' . ($campaign['code'] ?? '?') . ' permet une demande étudiante'
    );
}

$reservationsResponse = $client->request('GET', '/student/reservations', null, $access);
foreach ($reservationsResponse['json']['data']['items'] ?? [] as $reservation) {
    $suite->ok(
        !empty($reservation['workflow_status']) && !empty($reservation['workflow_message']),
        'La réservation possède un statut et un message affichables'
    );
}

$stagesResponse = $client->request(
    'GET',
    '/student/stages?status%5B%5D=PLANIFIEE&status%5B%5D=ACTIVE&include%5B%5D=rotations&include%5B%5D=supervisors&include%5B%5D=summary',
    null,
    $access
);
$stagesData = $stagesResponse['json']['data'] ?? [];
$suite->ok($stagesResponse['status'] === 200, 'Les affectations filtrées sont accessibles');
$suite->ok(is_array($stagesData['items'] ?? null), 'Les affectations sont retournées comme une collection');
$suite->ok(
    ($stagesData['filters']['statuses'] ?? null) === ['PLANIFIEE', 'ACTIVE'],
    'Plusieurs statuts d’affectation sont acceptés et retournés comme une collection'
);
$suite->ok(is_array($stagesData['filters']['includes'] ?? null), 'Les inclusions sont retournées comme une collection');
$suite->ok(is_array($stagesData['summary']['by_status'] ?? null), 'Le résumé est ventilé par statut');

foreach ($stagesData['items'] ?? [] as $assignment) {
    $suite->ok(array_key_exists('department', $assignment), 'Le département d’affectation est explicite');
    $suite->ok(isset($assignment['department_integrity']['valid']), 'L’intégrité du département est exposée');
    $suite->ok(is_array($assignment['planning']['rotations'] ?? null), 'La planification contient une collection de rotations');

    $previous = null;
    foreach ($assignment['planning']['rotations'] as $rotation) {
        $suite->ok(isset($rotation['position'], $rotation['sequence']), 'La position et la séquence de rotation sont explicites');
        $suite->ok(isset($rotation['service']['id']), 'Chaque rotation expose son service');
        $suite->ok(array_key_exists('unit', $rotation), 'L’unité facultative est explicitement représentée');
        $current = sprintf('%010d|%s|%010d', (int) $rotation['sequence'], $rotation['period']['start_date'], (int) $rotation['id']);
        if ($previous !== null) {
            $suite->ok(strcmp($previous, $current) <= 0, 'Les rotations respectent séquence, date puis identifiant');
        }
        $previous = $current;
    }
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
