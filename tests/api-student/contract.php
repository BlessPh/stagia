<?php
declare(strict_types=1);
require_once __DIR__ . '/ApiTestSupport.php';

$suite = $suite ?? new ApiTestSuite();
$root = apiTestRoot();
$documentation = dirname($root) . '/api/documentation/temp-doc';
$files = glob($documentation . '/*.openapi.json') ?: [];
$documented = [];

foreach ($files as $file) {
    $document = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $suite->ok(($document['openapi'] ?? '') === '3.1.0', basename($file) . ' utilise OpenAPI 3.1.0');
    foreach (array_keys($document['paths'] ?? []) as $path) {
        $documented[$path] = true;
    }
}

$htaccess = (string) file_get_contents($root . '/api/v1/.htaccess');
preg_match_all('~RewriteRule \^([^ ]+)~', $htaccess, $matches);
$canonical = [];
foreach ($matches[1] as $rule) {
    $path = '/' . preg_replace(['~/\?$~', '~\(\[A-Fa-f0-9-\]\+\)~', '~\(\[0-9A-HJKMNP-TV-Z\]\{26\}\)~', '~\(\[0-9\]\+\)~', '~\(([^)]+)\)~'], ['', '{uuid}', '{uuid}', '{id}', '{$1}'], $rule);
    $path = str_replace(['{start|comment|complete}', '{read|archive}'], ['{action}', '{action}'], $path);
    $canonical[$path] = true;
}

$required = ['/login', '/logout', '/refresh-token', '/me', '/forgot-password', '/reset-password', '/student/dashboard'];
foreach ($required as $path) {
    $suite->ok(isset($documented[$path]), "Route documentée: {$path}");
}

$suite->ok(is_file($documentation . '/stagia-mobile.openapi.json'), 'Contrat OpenAPI consolidé présent');
$suite->ok(is_file($documentation . '/stagia-mobile.postman_collection.json'), 'Collection Postman présente');
$suite->ok(is_file($documentation . '/matrice-web-api-etudiant.md'), 'Matrice Web ↔ API présente');

$masterPath = $documentation . '/stagia-mobile.openapi.json';
$master = json_decode((string) file_get_contents($masterPath), true, 512, JSON_THROW_ON_ERROR);
$suite->ok(count($master['paths'] ?? []) === 61, 'Les 61 chemins canoniques sont consolidés');
foreach ($master['paths'] as $path => $pathItem) {
    $reference = (string) ($pathItem['$ref'] ?? '');
    $suite->ok($reference !== '' && str_contains($reference, '#/paths/'), "Référence externe présente: {$path}");
    [$relativeFile, $pointer] = explode('#', $reference, 2);
    $targetFile = $documentation . '/' . ltrim($relativeFile, './');
    $target = json_decode((string) file_get_contents($targetFile), true, 512, JSON_THROW_ON_ERROR);
    $segments = array_map(
        static fn(string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment),
        array_slice(explode('/', $pointer), 1)
    );
    $resolved = $target;
    foreach ($segments as $segment) {
        if (!is_array($resolved) || !array_key_exists($segment, $resolved)) {
            throw new ApiTestFailure("Référence OpenAPI introuvable pour {$path}: {$reference}");
        }
        $resolved = $resolved[$segment];
    }
}

$postman = json_decode((string) file_get_contents($documentation . '/stagia-mobile.postman_collection.json'), true, 512, JSON_THROW_ON_ERROR);
$suite->ok(($postman['info']['schema'] ?? '') === 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json', 'Collection Postman v2.1 valide');
$postmanPaths = [];
$collectRequests = static function (array $items) use (&$collectRequests, &$postmanPaths): void {
    foreach ($items as $item) {
        if (isset($item['request']['url']) && is_string($item['request']['url'])) {
            $url = preg_replace('~^\{\{base_url\}\}~', '', $item['request']['url']);
            $url = preg_replace('~\?.*$~', '', (string) $url);
            $postmanPaths[preg_replace('~\{\{[^}]+\}\}~', '{param}', $url)] = true;
        }
        if (!empty($item['item']) && is_array($item['item'])) {
            $collectRequests($item['item']);
        }
    }
};
$collectRequests($postman['item'] ?? []);
foreach (array_keys($master['paths']) as $path) {
    if ($path === '/student/execution-context') {
        continue; // alias déprécié, volontairement absent de la collection cliente
    }
    $postmanPath = preg_replace('~\{[^}]+\}~', '{param}', $path);
    $suite->ok(isset($postmanPaths[$postmanPath]), "Route disponible dans Postman: {$path}");
}

return $suite->summary();
