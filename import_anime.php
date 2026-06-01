<?php
// import_anime.php
require_once 'Database.php';
require_once 'MediaManager.php';

$pdo = Database::connect();
$mediaManager = new MediaManager($pdo);

$jsonFile = 'top_anime.ndjson';
$mediaDir = 'medias';

if (!is_dir($mediaDir)) {
    mkdir($mediaDir, 0777, true);
}

/**
 * Get or create a category based on MAL type (TV, Movie, OVA, etc.)
 */
function getCategoryId($pdo, $name)
{
    if (empty($name))
        $name = 'Unknown';

    $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();

    if (!$id) {
        $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt->execute([$name]);
        $id = $pdo->lastInsertId();
        echo "Created new category: $name (ID: $id)\n";
    }
    return $id;
}

/**
 * Get or create a rating based on MAL rating (PG, PG-13, R, etc.)
 */
function getRatingId($pdo, $name)
{
    if (empty($name))
        $name = 'Unknown';

    // Normalize the rating name
    $name = strtoupper(trim($name));

    $stmt = $pdo->prepare("SELECT id FROM ratings WHERE name = ?");
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();

    if (!$id) {
        // Create with both name and label
        $stmt = $pdo->prepare("INSERT INTO ratings (name, label) VALUES (?, ?)");
        $stmt->execute([$name, $name]);
        $id = $pdo->lastInsertId();
        echo "Created new rating: $name (ID: $id)\n";
    }
    return $id;
}

$handle = fopen($jsonFile, "r");
if ($handle) {

    $count = 0;
    $processed = 0;
    while (($line = fgets($handle)) !== false) {
        $processed++;
        $data = json_decode($line, true);
        if (!$data)
            continue;
        $title = $data['name'] ?? 'Unknown';
        $scoreMal = isset($data['score']) ? (float) $data['score'] : 0;
        $type = $data['type'] ?? 'Unknown';
        $rated = $data['rated'] ?? 'Unknown';
        $thumbnail_link = $data['thumbnail_link'] ?? '';

        // Check if exists
        $existingId = $mediaManager->existsByTitle($title);

        // Map MAL type to category and MAL rating to rating
        $categoryId = getCategoryId($pdo, $type);
        $ratingId = getRatingId($pdo, $rated);

        // Handle Image Download
        $imageName = null;
        if (!empty($thumbnail_link)) {
            $ext = pathinfo(parse_url($thumbnail_link, PHP_URL_PATH), PATHINFO_EXTENSION);
            if (!$ext)
                $ext = 'jpg';
            $sanitizedTitle = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $title);
            $imageName = $sanitizedTitle . '.' . $ext;
            $imagePath = $mediaDir . '/' . $imageName;

            // Optional: Skip download if image already exists to save time/bandwidth
            if (!file_exists($imagePath)) {
                try {
                    $imgContent = @file_get_contents($thumbnail_link);
                    if ($imgContent) {
                        file_put_contents($imagePath, $imgContent);
                        echo "Downloaded image for: $title\n";
                    } else {
                        echo "Failed to download image for: $title\n";
                        $imageName = null;
                    }
                } catch (Exception $e) {
                    echo "Error downloading image for: $title: " . $e->getMessage() . "\n";
                    $imageName = null;
                }
            } else {
                // If it exists, we still use the name for the DB update
            }
        }

        // Use default year if not in JSON
        $year = $data['year'] ?? date('Y');
        $score = $scoreMal;

        try {
            if ($existingId) {
                $existing = $mediaManager->getMediaById($existingId);
                // Update media but keep existing status and user score
                $mediaManager->updateMedia(
                    $existingId,
                    $title,
                    $imageName ?? $existing['image'],
                    $categoryId,
                    $ratingId,
                    $year,
                    $existing['score'], // Keep existing score
                    $scoreMal,
                    $existing['status'], // Keep existing status
                    $existing['infos']   // Keep existing infos
                );
                echo "Updated: $title\n";
            } else {
                $mediaManager->createMedia($title, $imageName, $categoryId, $ratingId, $year, $score, $scoreMal);
                echo "Imported: $title\n";
            }
            $count++;
        } catch (Exception $e) {
            echo "Error processing $title: " . $e->getMessage() . "\n";
        }
    }
    fclose($handle);
    echo "Total processed in this run: $count\n";
} else {
    echo "Error opening JSON file.\n";
}
