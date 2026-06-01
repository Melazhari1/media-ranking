<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

require_once 'Database.php';
require_once 'MediaManager.php';

$pdo = Database::connect();
$manager = new MediaManager($pdo);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id = $_GET['id'] ?? null;

// Handle CORS Preflight
if ($method == "OPTIONS") {
    http_response_code(200);
    exit();
}

try {
    switch ($method) {
        case 'GET':
            $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
            $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
            $offset = ($page - 1) * $limit;
            $orderBy = $_GET['order_by'] ?? 'm.created_at DESC';

            if ($action === 'categories') {
                $categories = $manager->getCategories();
                echo json_encode(["status" => "success", "data" => $categories]);
            } elseif ($action === 'ratings') {
                $ratings = $manager->getRatings();
                echo json_encode(["status" => "success", "data" => $ratings]);
            } elseif ($action === 'random') {
                $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
                $results = $manager->getRandomMedia($limit);
                echo json_encode(["status" => "success", "data" => $results]);
            } elseif ($id) {
                $item = $manager->getMediaById($id);
                if ($item) {
                    echo json_encode(["status" => "success", "data" => $item]);
                } else {
                    http_response_code(404);
                    echo json_encode(["status" => "error", "message" => "Media not found"]);
                }
            } else {
                // List view (either filtered or default)
                $keyword = $_GET['keyword'] ?? '';
                $categoryIds = !empty($_GET['category_ids']) ? explode(',', $_GET['category_ids']) : [];
                $ratingIds = !empty($_GET['rating_ids']) ? explode(',', $_GET['rating_ids']) : [];
                $statuses = !empty($_GET['statuses']) ? explode(',', $_GET['statuses']) : [];

                $results = $manager->filterMedia($keyword, $categoryIds, $ratingIds, $statuses, $limit, $offset, $orderBy);
                $total = $manager->countFilteredMedia($keyword, $categoryIds, $ratingIds, $statuses);

                echo json_encode([
                    "status" => "success",
                    "data" => $results,
                    "pagination" => [
                        "total" => $total,
                        "page" => $page,
                        "limit" => $limit,
                        "pages" => ceil($total / $limit)
                    ]
                ]);
            }
            break;

        case 'POST':
            $id = $_GET['id'] ?? null;
            $data = json_decode(file_get_contents("php://input"), true);
            if (!$data) {
                $data = $_POST;
            }

            // Status/Score updates remain unchanged
            if ($action === 'update_status') {
                if (!$id) {
                    http_response_code(400);
                    echo json_encode(["status" => "error", "message" => "ID is required"]);
                    break;
                }
                $manager->updateStatus($id, $data['status']);
                echo json_encode(["status" => "success", "message" => "Status updated"]);
                break;
            }

            if ($action === 'update_score') {
                if (!$id || !isset($data['score'])) {
                    http_response_code(400);
                    echo json_encode(["status" => "error", "message" => "ID and score are required"]);
                    break;
                }
                $manager->updateScore($id, $data['score']);
                echo json_encode(["status" => "success", "message" => "Score updated"]);
                break;
            }

            // Image processing
            $imageName = $data['image'] ?? '';
            if (!empty($_FILES['image_file']['name'])) {
                $uploadDir = 'medias/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $fileInfo = pathinfo($_FILES['image_file']['name']);
                $extension = strtolower($fileInfo['extension']);
                $imageName = bin2hex(random_bytes(10)) . '.' . $extension;
                move_uploaded_file($_FILES['image_file']['tmp_name'], $uploadDir . $imageName);
            }

            if (empty($data['title'])) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Title is required"]);
                break;
            }

            $categoryId = null;
            if (!empty($data['category_ids'])) {
                $categoryId = is_array($data['category_ids']) ? $data['category_ids'][0] : $data['category_ids'];
            }

            $ratingId = null;
            if (!empty($data['rating_ids'])) {
                $ratingId = is_array($data['rating_ids']) ? $data['rating_ids'][0] : $data['rating_ids'];
            }

            if ($id) {
                // Update
                $manager->updateMedia(
                    $id,
                    $data['title'],
                    $imageName,
                    $categoryId,
                    $ratingId,
                    $data['year'] ?? date('Y'),
                    $data['score'] ?? 0,
                    $data['score_mal'] ?? 0,
                    $data['status'] ?? null,
                    $data['infos'] ?? ''
                );
                echo json_encode(["status" => "success", "message" => "Media updated"]);
            } else {
                // Create
                $newId = $manager->createMedia(
                    $data['title'],
                    $imageName,
                    $categoryId,
                    $ratingId,
                    $data['year'] ?? date('Y'),
                    $data['score'] ?? 0,
                    $data['score_mal'] ?? 0,
                    $data['status'] ?? null,
                    $data['infos'] ?? ''
                );
                echo json_encode(["status" => "success", "message" => "Media created", "id" => $newId]);
            }
            break;

        case 'PUT':
            // PUT is now handled by POST for FormData consistency
            http_response_code(405);
            echo json_encode(["status" => "error", "message" => "Please use POST for updates with file uploads"]);
            break;

        case 'DELETE':
            if (!$id) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "ID is required for deletion"]);
                break;
            }

            $manager->deleteMedia($id);
            echo json_encode(["status" => "success", "message" => "Media deleted"]);
            break;

        default:
            http_response_code(405);
            echo json_encode(["status" => "error", "message" => "Method not allowed"]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
