<?php
// api.php — JSON API entry point. Handlers live in src/Api/ApiController.php.

header("Content-Type: application/json; charset=UTF-8");
header("X-Content-Type-Options: nosniff");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

require_once __DIR__ . '/bootstrap.php';

use App\Api\ApiController;
use App\Api\HttpError;
use App\Services;

$method = $_SERVER['REQUEST_METHOD'];
$action = (string) ($_GET['action'] ?? '');
$id     = isset($_GET['id']) && $_GET['id'] !== '' ? (int) $_GET['id'] : null;

// JSON body first (fetch with Content-Type: application/json), then form data (FormData uploads).
$raw  = file_get_contents('php://input');
$body = json_decode($raw ?: 'null', true);
$body = is_array($body) ? $body : $_POST;

try {
    $result = (new ApiController(new Services(null, __DIR__)))->handle($method, $action, $id, $_GET, $body, $_FILES);
    if (empty($result['__raw'])) {
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }
} catch (HttpError $e) {
    http_response_code($e->status);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
} catch (App\Http\HttpException $e) {
    // Upstream metadata provider (Jikan/TMDB) unreachable or erroring
    http_response_code(502);
    echo json_encode(["status" => "error", "message" => "Metadata provider unavailable: " . $e->getMessage()]);
} catch (InvalidArgumentException $e) {
    // Validation errors (unknown status, bad date, unsupported file…)
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[MMR api] ' . $e);
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Server error"]);
}
