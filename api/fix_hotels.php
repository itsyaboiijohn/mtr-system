<?php
$file = 'admin-content.php';
$content = file_get_contents($file);

// Add hotels to the JSON response - simpler match
$content = str_replace(
    "'payments' => $payments",
    "'payments' => $payments",
    $content
);

// Find the position of the closing bracket and add hotels before it
$pos = strpos($content, "'payments' => $payments");
if ($pos !== false) {
    // Find the next line break
    $endPos = strpos($content, "\n", $pos);
    if ($endPos !== false) {
        $before = substr($content, 0, $endPos);
        $after = substr($content, $endPos);
        $content = $before . ",\n            'hotels' => $hotels" . $after;
    }
}

// Add hotel handling
$hotelCode = '

    if ($type === "hotel") {
        $hotelId = filter_var($payload["id"] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string) ($payload["name"] ?? ""));
        $location = trim((string) ($payload["location"] ?? ""));
        $region = trim((string) ($payload["region"] ?? "Mindanao"));
        $description = trim((string) ($payload["description"] ?? ""));
        $pricePerNight = filter_var($payload["price_per_night"] ?? null, FILTER_VALIDATE_FLOAT);
        $amenities = trim((string) ($payload["amenities"] ?? ""));
        $rating = filter_var($payload["rating"] ?? null, FILTER_VALIDATE_FLOAT);
        $status = in_array($payload["status"] ?? "", ["active", "inactive"], true) ? $payload["status"] : "active";

        if ($name === "" || $location === "" || $pricePerNight === false || $pricePerNight < 0) {
            throw new InvalidArgumentException("Hotel name, location, and valid price are required.");
        }

        $database->beginTransaction();
        $isEditing = $hotelId !== false && $hotelId > 0;

        if ($isEditing) {
            $statement = $database->prepare("UPDATE hotels SET name = ?, location = ?, region = ?, description = ?, price_per_night = ?, amenities = ?, rating = ?, status = ? WHERE Hotel_ID = ?");
            $statement->execute([$name, $location, $region ?: null, $description ?: null, $pricePerNight, $amenities ?: null, $rating ?: 0, $status, $hotelId]);
            $exists = $database->prepare("SELECT Hotel_ID FROM hotels WHERE Hotel_ID = ?");
            $exists->execute([$hotelId]);
            if (!$exists->fetch()) {
                throw new InvalidArgumentException("Hotel was not found.");
            }
        } else {
            $statement = $database->prepare("INSERT INTO hotels (name, location, region, description, price_per_night, amenities, rating, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $statement->execute([$name, $location, $region ?: null, $description ?: null, $pricePerNight, $amenities ?: null, $rating ?: 0, $status]);
            $hotelId = (int) $database->lastInsertId();
        }

        $database->commit();
        echo json_encode(["success" => true, "id" => $hotelId, "message" => $isEditing ? "Hotel updated." : "Hotel added."]);
        exit;
    }

    if ($type === "delete_hotel") {
        $hotelId = filter_var($payload["id"] ?? null, FILTER_VALIDATE_INT);
        if ($hotelId === false || $hotelId < 1) {
            throw new InvalidArgumentException("A valid hotel is required.");
        }

        $statement = $database->prepare("DELETE FROM hotels WHERE Hotel_ID = ?");
        $statement->execute([$hotelId]);
        if ($statement->rowCount() === 0) {
            throw new InvalidArgumentException("Hotel was not found.");
        }
        echo json_encode(["success" => true, "message" => "Hotel deleted."]);
        exit;
    }';

$content = str_replace(
    "throw new InvalidArgumentException('Unsupported content type.');",
    $hotelCode . "\n    throw new InvalidArgumentException('Unsupported content type.');",
    $content
);

file_put_contents($file, $content);
echo "File updated successfully";
