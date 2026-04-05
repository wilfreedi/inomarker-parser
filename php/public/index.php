<?php

declare(strict_types=1);

use App\Application;

require dirname(__DIR__) . '/bootstrap.php';

$app = Application::boot();
$controller = $app->adminController();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($method === 'GET' && $path === '/') {
    $notice = isset($_GET['notice']) ? (string) $_GET['notice'] : null;
    $error = isset($_GET['error']) ? (string) $_GET['error'] : null;
    echo $controller->dashboard($notice, $error);
    exit;
}

if ($method === 'GET' && $path === '/sites') {
    $notice = isset($_GET['notice']) ? (string) $_GET['notice'] : null;
    $error = isset($_GET['error']) ? (string) $_GET['error'] : null;
    echo $controller->sites($notice, $error);
    exit;
}

if ($method === 'GET' && $path === '/sites/new') {
    $notice = isset($_GET['notice']) ? (string) $_GET['notice'] : null;
    $error = isset($_GET['error']) ? (string) $_GET['error'] : null;
    echo $controller->newSite($notice, $error);
    exit;
}

if ($method === 'GET' && preg_match('#^/sites/(\d+)/edit$#', $path, $matches) === 1) {
    $notice = isset($_GET['notice']) ? (string) $_GET['notice'] : null;
    $error = isset($_GET['error']) ? (string) $_GET['error'] : null;
    echo $controller->editSite((int) $matches[1], $notice, $error);
    exit;
}

if ($method === 'GET' && preg_match('#^/sites/(\d+)/findings$#', $path, $matches) === 1) {
    $notice = isset($_GET['notice']) ? (string) $_GET['notice'] : null;
    $error = isset($_GET['error']) ? (string) $_GET['error'] : null;
    $legacyFindingsPage = isset($_GET['findings_page']) ? (int) $_GET['findings_page'] : 1;
    $fullFindingsPage = isset($_GET['full_page']) ? (int) $_GET['full_page'] : $legacyFindingsPage;
    $shortFindingsPage = isset($_GET['short_page']) ? (int) $_GET['short_page'] : $legacyFindingsPage;
    echo $controller->siteFindings(
        (int) $matches[1],
        $notice,
        $error,
        max(1, $fullFindingsPage),
        max(1, $shortFindingsPage)
    );
    exit;
}

if ($method === 'GET' && preg_match('#^/sites/(\d+)$#', $path, $matches) === 1) {
    $notice = isset($_GET['notice']) ? (string) $_GET['notice'] : null;
    $error = isset($_GET['error']) ? (string) $_GET['error'] : null;
    $pagesPage = isset($_GET['pages_page']) ? (int) $_GET['pages_page'] : 1;
    $findingsPage = isset($_GET['findings_page']) ? (int) $_GET['findings_page'] : 1;
    echo $controller->siteReport((int) $matches[1], $notice, $error, max(1, $pagesPage), max(1, $findingsPage));
    exit;
}

if ($method === 'GET' && $path === '/settings') {
    $notice = isset($_GET['notice']) ? (string) $_GET['notice'] : null;
    $error = isset($_GET['error']) ? (string) $_GET['error'] : null;
    echo $controller->settings($notice, $error);
    exit;
}

if ($method === 'POST' && $path === '/sites') {
    $controller->createSite($_POST);
}

if ($method === 'POST' && preg_match('#^/sites/(\d+)$#', $path, $matches) === 1) {
    $controller->updateSite((int) $matches[1], $_POST);
}

if ($method === 'POST' && preg_match('#^/sites/(\d+)/delete$#', $path, $matches) === 1) {
    $controller->deleteSite((int) $matches[1], $_POST);
}

if ($method === 'POST' && $path === '/settings') {
    $controller->updateSettings($_POST);
}

if ($method === 'POST' && preg_match('#^/sites/(\d+)/scan$#', $path, $matches) === 1) {
    $controller->requestScan((int) $matches[1], $_POST);
}

if ($method === 'POST' && preg_match('#^/sites/(\d+)/pause$#', $path, $matches) === 1) {
    $controller->pauseSite((int) $matches[1], $_POST);
}

if ($method === 'POST' && preg_match('#^/sites/(\d+)/resume$#', $path, $matches) === 1) {
    $controller->resumeSite((int) $matches[1], $_POST);
}

if ($method === 'POST' && preg_match('#^/sites/(\d+)/cancel$#', $path, $matches) === 1) {
    $controller->cancelScan((int) $matches[1], $_POST);
}

if ($method === 'POST' && preg_match('#^/sites/(\d+)/recrawl$#', $path, $matches) === 1) {
    $controller->recrawlSite((int) $matches[1], $_POST);
}

if ($method === 'POST' && $path === '/internal/crawl-progress') {
    $rawBody = file_get_contents('php://input');
    $controller->ingestCrawlProgress(is_string($rawBody) ? $rawBody : '', $_SERVER);
}

if ($method === 'GET' && $path === '/api/sites/live') {
    $controller->sitesLive($_GET);
}

if ($method === 'GET' && preg_match('#^/api/sites/(\d+)/live$#', $path, $matches) === 1) {
    $detailsRaw = isset($_GET['details']) ? mb_strtolower(trim((string) $_GET['details'])) : '';
    $withDetails = in_array($detailsRaw, ['1', 'true', 'yes', 'on'], true);
    $controller->siteLive((int) $matches[1], $withDetails);
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found';
