<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'Database.php';
require_once 'MediaManager.php';

$pdo     = Database::connect();
$manager = new MediaManager($pdo);
$method  = $_SERVER['REQUEST_METHOD'];
$action  = $_GET['action'] ?? '';
$id      = isset($_GET['id']) ? (int) $_GET['id'] : null;

try {
    switch ($method) {
        case 'GET':    handleGet($manager, $action, $id);    break;
        case 'POST':   handlePost($manager, $action, $id);   break;
        case 'DELETE': handleDelete($manager, $id);          break;
        default:
            http_response_code(405);
            echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}

// ─── GET ─────────────────────────────────────────────────────────────────────

function handleGet($manager, $action, $id)
{
    if ($action === 'categories') {
        echo json_encode(["status" => "success", "data" => $manager->getCategories()]);
        return;
    }

    if ($action === 'ratings') {
        echo json_encode(["status" => "success", "data" => $manager->getRatings()]);
        return;
    }

    if ($action === 'random') {
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
        echo json_encode(["status" => "success", "data" => $manager->getRandomMedia($limit)]);
        return;
    }

    if ($id !== null) {
        $item = $manager->getMediaById($id);
        if ($item) {
            echo json_encode(["status" => "success", "data" => $item]);
        } else {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Media not found"]);
        }
        return;
    }

    // Paginated / filtered list
    $limit   = isset($_GET['limit'])    ? (int) $_GET['limit'] : 20;
    $page    = isset($_GET['page'])     ? (int) $_GET['page']  : 1;
    $offset  = ($page - 1) * $limit;
    $orderBy = $_GET['order_by'] ?? 'm.created_at DESC';

    $keyword     = trim($_GET['keyword'] ?? '');
    $categoryIds = !empty($_GET['category_ids']) ? explode(',', $_GET['category_ids']) : [];
    $ratingIds   = !empty($_GET['rating_ids'])   ? explode(',', $_GET['rating_ids'])   : [];
    $statuses    = !empty($_GET['statuses'])      ? explode(',', $_GET['statuses'])     : [];

    $results = $manager->filterMedia($keyword, $categoryIds, $ratingIds, $statuses, $limit, $offset, $orderBy);
    $total   = $manager->countFilteredMedia($keyword, $categoryIds, $ratingIds, $statuses);

    echo json_encode([
        "status" => "success",
        "data"   => $results,
        "pagination" => [
            "total" => $total,
            "page"  => $page,
            "limit" => $limit,
            "pages" => $total > 0 ? (int) ceil($total / $limit) : 1,
        ],
    ]);
}

// ─── POST ─────────────────────────────────────────────────────────────────────

function handlePost($manager, $action, $id)
{
    // Try JSON body first (for fetch with Content-Type: application/json),
    // then fall back to $_POST (for FormData submissions).
    $data = json_decode(file_get_contents("php://input"), true) ?? $_POST;

    if ($action === 'update_status') {
        if ($id === null) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "ID is required"]);
            return;
        }
        $manager->updateStatus($id, $data['status'] ?? null);
        echo json_encode(["status" => "success", "message" => "Status updated"]);
        return;
    }

    if ($action === 'update_score') {
        if ($id === null || !isset($data['score'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "ID and score are required"]);
            return;
        }
        $manager->updateScore($id, $data['score']);
        echo json_encode(["status" => "success", "message" => "Score updated"]);
        return;
    }

    // Dedicated action for saving the infos/notes field only — avoids
    // accidentally overwriting category/rating when only notes are edited.
    if ($action === 'update_infos') {
        if ($id === null) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "ID is required"]);
            return;
        }
        $manager->updateInfos($id, $data['infos'] ?? '');
        echo json_encode(["status" => "success", "message" => "Infos updated"]);
        return;
    }

    // ── Create or full update ─────────────────────────────────────────────────

    if (empty($data['title'])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Title is required"]);
        return;
    }

    // Handle optional file upload; preserve existing image name if no new file.
    $imageName = $data['image'] ?? '';
    if (!empty($_FILES['image_file']['name'])) {
        $uploadDir = 'medias/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $ext       = strtolower(pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION));
        $imageName = bin2hex(random_bytes(10)) . '.' . $ext;
        move_uploaded_file($_FILES['image_file']['tmp_name'], $uploadDir . $imageName);
    }

    // Accept both singular (category_id) and plural (category_ids) field names
    // so FormData and JSON payloads are handled consistently.
    $categoryId = resolveId($data, 'category_id', 'category_ids');
    $ratingId   = resolveId($data, 'rating_id',   'rating_ids');

    $year     = !empty($data['year'])     ? $data['year']          : date('Y');
    $score    = isset($data['score'])     ? (float) $data['score']     : 0.0;
    $scoreMal = isset($data['score_mal']) ? (float) $data['score_mal'] : 0.0;
    $status   = sanitizeStatus($data['status'] ?? null);
    $infos    = $data['infos'] ?? '';

    if ($id !== null) {
        $manager->updateMedia($id, $data['title'], $imageName, $categoryId, $ratingId, $year, $score, $scoreMal, $status, $infos);
        echo json_encode(["status" => "success", "message" => "Media updated"]);
    } else {
        $newId = $manager->createMedia($data['title'], $imageName, $categoryId, $ratingId, $year, $score, $scoreMal, $status, $infos);
        echo json_encode(["status" => "success", "message" => "Media created", "id" => $newId]);
    }
}

// ─── DELETE ──────────────────────────────────────────────────────────────────

function handleDelete($manager, $id)
{
    if ($id === null) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "ID is required for deletion"]);
        return;
    }
    $manager->deleteMedia($id);
    echo json_encode(["status" => "success", "message" => "Media deleted"]);
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

// Resolve an ID from either the singular or plural key in the request data.
function resolveId($data, $singular, $plural)
{
    if (!empty($data[$plural])) {
        $val = is_array($data[$plural]) ? $data[$plural][0] : $data[$plural];
        return $val ?: null;
    }
    if (!empty($data[$singular])) {
        return $data[$singular] ?: null;
    }
    return null;
}

function sanitizeStatus($status)
{
    return ($status === '' || $status === 'null' || $status === null) ? null : $status;
}
