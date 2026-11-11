<?php

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

class SharedCartService
{
    /** @var array<string, array> In-memory cache for the current request execution */
    private static array $memoryCarts = [];

    /**
     * Get base directory for file storage of carts
     */
    private static function getStorageDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dineflow_carts';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }

    /**
     * Get the file path for a session cart
     */
    private static function getFilePath(string $sessionToken): string
    {
        $safeToken = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionToken);
        return self::getStorageDir() . DIRECTORY_SEPARATOR . 'cart_' . md5($safeToken) . '.json';
    }

    /**
     * Retrieve the shared cart for a given table session token
     */
    public static function getCart(string $sessionToken): array
    {
        $cleanToken = trim($sessionToken);
        if ($cleanToken === '') {
            return self::buildEmptyCart('');
        }

        if (isset(self::$memoryCarts[$cleanToken])) {
            return self::$memoryCarts[$cleanToken];
        }

        // 1. Try file storage with shared lock
        $file = self::getFilePath($cleanToken);
        if (file_exists($file)) {
            $fp = @fopen($file, 'rb');
            if ($fp) {
                @flock($fp, LOCK_SH);
                $content = @stream_get_contents($fp);
                @flock($fp, LOCK_UN);
                @fclose($fp);

                if ($content !== false && $content !== '') {
                    $decoded = json_decode($content, true);
                    if (is_array($decoded) && isset($decoded['items'])) {
                        $cart = self::normalizeCart($cleanToken, $decoded['items']);
                        self::$memoryCarts[$cleanToken] = $cart;
                        return $cart;
                    }
                }
            }
        }

        // 2. Try DB storage if table exists
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT cart_data FROM shared_carts WHERE session_token = ?");
            $stmt->execute([$cleanToken]);
            $row = $stmt->fetch();
            if ($row && !empty($row['cart_data'])) {
                $decoded = json_decode($row['cart_data'], true);
                if (is_array($decoded)) {
                    $cart = self::normalizeCart($cleanToken, $decoded['items'] ?? $decoded);
                    self::$memoryCarts[$cleanToken] = $cart;
                    return $cart;
                }
            }
        } catch (Throwable $e) {
            // DB table might not exist yet; gracefully fallback
        }

        $emptyCart = self::buildEmptyCart($cleanToken);
        self::$memoryCarts[$cleanToken] = $emptyCart;
        return $emptyCart;
    }

    /**
     * Get just the list of items for convenient use in live operations
     */
    public static function getItems(string $sessionToken): array
    {
        $cart = self::getCart($sessionToken);
        return $cart['items'] ?? [];
    }

    /**
     * Save the cart state atomically
     */
    public static function saveCart(string $sessionToken, array $cart): void
    {
        $cleanToken = trim($sessionToken);
        $normalized = self::normalizeCart($cleanToken, $cart['items'] ?? $cart);
        self::$memoryCarts[$cleanToken] = $normalized;

        $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // 1. Save to file atomically using temp file and rename
        $file = self::getFilePath($cleanToken);
        $tmpFile = $file . '.' . uniqid('tmp_', true);
        if (@file_put_contents($tmpFile, $json, LOCK_EX) !== false) {
            @rename($tmpFile, $file);
        }

        // 2. Sync to DB if possible
        try {
            $db = Database::getConnection();
            // Ensure table exists
            $db->exec("CREATE TABLE IF NOT EXISTS shared_carts (
                session_token VARCHAR(64) PRIMARY KEY,
                cart_data TEXT NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            $stmt = $db->prepare("
                INSERT INTO shared_carts (session_token, cart_data, updated_at)
                VALUES (?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT(session_token) DO UPDATE SET cart_data = excluded.cart_data, updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$cleanToken, $json]);
        } catch (Throwable $e) {
            // If SQLite version does not support ON CONFLICT, try REPLACE
            try {
                $db = Database::getConnection();
                $stmt = $db->prepare("REPLACE INTO shared_carts (session_token, cart_data, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)");
                $stmt->execute([$cleanToken, $json]);
            } catch (Throwable $e2) {
                // Ignore DB error, file persistence is active
            }
        }
    }

    /**
     * Add an item to the shared cart or increase quantity if already present
     */
    public static function addItem(string $sessionToken, array $item): array
    {
        $cart = self::getCart($sessionToken);
        $menuItemId = (int)($item['menu_item_id'] ?? $item['id'] ?? 0);
        if ($menuItemId <= 0) {
            return $cart;
        }

        $quantity = (int)($item['quantity'] ?? 1);
        if ($quantity <= 0) {
            $quantity = 1;
        }

        $toppings = is_array($item['toppings'] ?? null) ? $item['toppings'] : [];
        sort($toppings);

        $name = trim((string)($item['name'] ?? $item['dish_name'] ?? 'Món ăn'));
        $price = (float)($item['price'] ?? $item['unit_price'] ?? 0.0);
        $notes = trim((string)($item['notes'] ?? ''));

        // Query official menu price and name if available to enforce server-side accuracy
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT name, price FROM menu_items WHERE id = ?");
            $stmt->execute([$menuItemId]);
            $realItem = $stmt->fetch();
            if ($realItem) {
                $name = $realItem['name'];
                $price = (float)$realItem['price'];
            }
        } catch (Throwable $e) {
            // Keep passed values if DB query is not available
        }

        $found = false;
        foreach ($cart['items'] as &$ci) {
            $existingToppings = is_array($ci['toppings'] ?? null) ? $ci['toppings'] : [];
            sort($existingToppings);

            if ((int)$ci['menu_item_id'] === $menuItemId && $existingToppings == $toppings) {
                $ci['quantity'] += $quantity;
                if ($notes !== '') {
                    $ci['notes'] = $notes;
                }
                $found = true;
                break;
            }
        }
        unset($ci);

        if (!$found) {
            $cart['items'][] = [
                'menu_item_id' => $menuItemId,
                'name' => $name,
                'price' => $price,
                'unit_price' => $price,
                'quantity' => $quantity,
                'toppings' => $toppings,
                'notes' => $notes,
            ];
        }

        self::saveCart($sessionToken, $cart);
        return self::getCart($sessionToken);
    }

    /**
     * Update quantity of an item by delta (+1 or -1)
     */
    public static function updateItem(string $sessionToken, int $menuItemId, int $delta, array $toppings = []): array
    {
        $cart = self::getCart($sessionToken);
        sort($toppings);

        foreach ($cart['items'] as $index => &$ci) {
            $existingToppings = is_array($ci['toppings'] ?? null) ? $ci['toppings'] : [];
            sort($existingToppings);

            if ((int)$ci['menu_item_id'] === $menuItemId && $existingToppings == $toppings) {
                $ci['quantity'] += $delta;
                if ($ci['quantity'] <= 0) {
                    array_splice($cart['items'], $index, 1);
                }
                break;
            }
        }
        unset($ci);

        self::saveCart($sessionToken, $cart);
        return self::getCart($sessionToken);
    }

    /**
     * Remove an item completely from the cart
     */
    public static function removeItem(string $sessionToken, int $menuItemId, array $toppings = []): array
    {
        $cart = self::getCart($sessionToken);
        sort($toppings);

        foreach ($cart['items'] as $index => $ci) {
            $existingToppings = is_array($ci['toppings'] ?? null) ? $ci['toppings'] : [];
            sort($existingToppings);

            if ((int)$ci['menu_item_id'] === $menuItemId && $existingToppings == $toppings) {
                array_splice($cart['items'], $index, 1);
                break;
            }
        }

        self::saveCart($sessionToken, $cart);
        return self::getCart($sessionToken);
    }

    /**
     * Completely replace cart items (e.g. from mobile frontend bulk sync)
     */
    public static function setCart(string $sessionToken, array $items): array
    {
        $cleanItems = [];
        foreach ($items as $it) {
            $mId = (int)($it['menu_item_id'] ?? $it['id'] ?? 0);
            if ($mId <= 0) continue;
            $qty = max(1, (int)($it['quantity'] ?? 1));
            $cleanItems[] = [
                'menu_item_id' => $mId,
                'name' => (string)($it['name'] ?? $it['dish_name'] ?? "Món #{$mId}"),
                'price' => (float)($it['price'] ?? $it['unit_price'] ?? 0),
                'unit_price' => (float)($it['price'] ?? $it['unit_price'] ?? 0),
                'quantity' => $qty,
                'toppings' => is_array($it['toppings'] ?? null) ? $it['toppings'] : [],
                'notes' => (string)($it['notes'] ?? ''),
            ];
        }

        $cart = self::normalizeCart($sessionToken, $cleanItems);
        self::saveCart($sessionToken, $cart);
        return $cart;
    }

    /**
     * Clear all items in the shared cart
     */
    public static function clearCart(string $sessionToken): void
    {
        $cleanToken = trim($sessionToken);
        unset(self::$memoryCarts[$cleanToken]);

        $file = self::getFilePath($cleanToken);
        if (file_exists($file)) {
            @unlink($file);
        }

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("DELETE FROM shared_carts WHERE session_token = ?");
            $stmt->execute([$cleanToken]);
        } catch (Throwable $e) {
            // Ignore DB error
        }
    }

    /**
     * Normalize cart array structure with totals
     */
    private static function normalizeCart(string $sessionToken, array $rawItems): array
    {
        $items = [];
        $totalQty = 0;
        $totalAmount = 0.0;

        foreach ($rawItems as $raw) {
            $id = (int)($raw['menu_item_id'] ?? $raw['id'] ?? 0);
            if ($id <= 0) continue;

            $qty = (int)($raw['quantity'] ?? 1);
            if ($qty <= 0) continue;

            $price = (float)($raw['price'] ?? $raw['unit_price'] ?? 0.0);
            $toppings = is_array($raw['toppings'] ?? null) ? $raw['toppings'] : [];
            $notes = (string)($raw['notes'] ?? '');
            $name = (string)($raw['name'] ?? $raw['dish_name'] ?? "Món #{$id}");

            $items[] = [
                'menu_item_id' => $id,
                'name' => $name,
                'price' => $price,
                'unit_price' => $price,
                'quantity' => $qty,
                'toppings' => $toppings,
                'notes' => $notes,
            ];

            $totalQty += $qty;
            $totalAmount += ($price * $qty);
        }

        return [
            'session_token' => $sessionToken,
            'items' => $items,
            'total_quantity' => $totalQty,
            'total_amount' => $totalAmount,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Build an empty cart structure
     */
    private static function buildEmptyCart(string $sessionToken): array
    {
        return [
            'session_token' => $sessionToken,
            'items' => [],
            'total_quantity' => 0,
            'total_amount' => 0.0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }
}
