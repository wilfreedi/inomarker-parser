<?php

declare(strict_types=1);

use App\Application;

require dirname(__DIR__) . '/bootstrap.php';

/**
 * @param array<string, string> $params
 */
function parserBuildUrl(string $path, array $params = []): string
{
    if ($params === []) {
        return $path;
    }
    $separator = str_contains($path, '?') ? '&' : '?';

    return $path . $separator . http_build_query($params);
}

function parserRedirect(string $location): void
{
    header('Location: ' . $location, true, 302);
    exit;
}

function parserIsAllowedReturnPath(string $path): bool
{
    return preg_match(
        '#^/$|^/settings$|^/sites$|^/sites/new$|^/sites/\d+(?:\?(?:(?:pages_page|findings_page)=\d+)(?:&(?:(?:pages_page|findings_page)=\d+))*)?$|^/sites/\d+/edit$|^/sites/\d+/findings(?:\?(?:(?:full_page|short_page|findings_page)=\d+|report=(?:full|short))(?:&(?:(?:full_page|short_page|findings_page)=\d+|report=(?:full|short)))*)?$#',
        $path
    ) === 1;
}

function parserResolveReturnPath(?string $candidate): string
{
    $normalized = trim((string) $candidate);
    if ($normalized === '') {
        return '/';
    }

    return parserIsAllowedReturnPath($normalized) ? $normalized : '/';
}

$sessionLifetimeSeconds = 86400;
ini_set('session.gc_maxlifetime', (string) $sessionLifetimeSeconds);
$sessionCookieSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'lifetime' => $sessionLifetimeSeconds,
    'path' => '/',
    'secure' => $sessionCookieSecure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$app = Application::boot();
$controller = $app->adminController();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$queryString = (string) ($_SERVER['QUERY_STRING'] ?? '');
$requestPathWithQuery = $path . ($queryString !== '' ? ('?' . $queryString) : '');
$isApiRequest = str_starts_with($path, '/api/');
$isLoginRoute = $path === '/login';
$isLogoutRoute = $path === '/logout';
$isInternalCrawlProgress = $path === '/internal/crawl-progress' && $method === 'POST';

$authExpiresAt = (int) ($_SESSION['admin_auth_expires_at'] ?? 0);
$isAuthenticated = $authExpiresAt > time();
if (!$isAuthenticated) {
    unset($_SESSION['admin_auth_expires_at']);
}

if ($method === 'GET' && $isLoginRoute) {
    if ($isAuthenticated) {
        parserRedirect('/');
    }
    $notice = isset($_GET['notice']) ? (string) $_GET['notice'] : null;
    $error = isset($_GET['error']) ? (string) $_GET['error'] : null;
    $returnTo = parserResolveReturnPath(isset($_GET['return_to']) ? (string) $_GET['return_to'] : '/');
    echo $controller->login($notice, $error, $returnTo);
    exit;
}

if ($method === 'POST' && $isLoginRoute) {
    if ($isAuthenticated) {
        parserRedirect('/');
    }
    $password = trim((string) ($_POST['password'] ?? ''));
    $returnTo = parserResolveReturnPath(isset($_POST['return_to']) ? (string) $_POST['return_to'] : '/');
    if (!$controller->isValidAdminPassword($password)) {
        parserRedirect(parserBuildUrl('/login', [
            'error' => 'Неверный пароль',
            'return_to' => $returnTo,
        ]));
    }

    session_regenerate_id(true);
    $_SESSION['admin_auth_expires_at'] = time() + $sessionLifetimeSeconds;
    parserRedirect($returnTo);
}

if ($method === 'POST' && $isLogoutRoute) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            (string) ($params['path'] ?? '/'),
            (string) ($params['domain'] ?? ''),
            (bool) ($params['secure'] ?? false),
            (bool) ($params['httponly'] ?? true),
        );
    }
    session_destroy();
    parserRedirect(parserBuildUrl('/login', ['notice' => 'Вы вышли из системы']));
}

if (!$isAuthenticated && !$isInternalCrawlProgress) {
    if ($isApiRequest) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'auth_required'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    $redirectParams = [];
    if ($method === 'GET' && parserIsAllowedReturnPath($requestPathWithQuery)) {
        $redirectParams['return_to'] = $requestPathWithQuery;
    }
    parserRedirect(parserBuildUrl('/login', $redirectParams));
}

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
    $activeReport = isset($_GET['report']) ? (string) $_GET['report'] : 'full';
    echo $controller->siteFindings(
        (int) $matches[1],
        $notice,
        $error,
        max(1, $fullFindingsPage),
        max(1, $shortFindingsPage),
        $activeReport
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

if ($method === 'GET' && $path === '/settings/regex-json') {
    $controller->currentRegexJson();
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

if ($method === 'POST' && $path === '/settings/regex-refresh') {
    $controller->refreshRegexes();
}

if ($method === 'POST' && preg_match('#^/sites/(\d+)/scan$#', $path, $matches) === 1) {
    $controller->requestScan((int) $matches[1], $_POST);
}

if ($method === 'POST' && preg_match('#^/sites/(\d+)/findings/revalidate$#', $path, $matches) === 1) {
    $controller->requestFindingsRevalidation((int) $matches[1], $_POST);
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

if ($method === 'DELETE' && preg_match('#^/api/sites/(\d+)/findings/(\d+)$#', $path, $matches) === 1) {
    $controller->deleteFindingApi((int) $matches[1], (int) $matches[2]);
}

if ($method === 'GET' && preg_match('#^/api/sites/(\d+)/findings/revalidation-status$#', $path, $matches) === 1) {
    $report = isset($_GET['report']) ? (string) $_GET['report'] : 'full';
    $controller->findingRevalidationStatusApi((int) $matches[1], $report);
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found';
