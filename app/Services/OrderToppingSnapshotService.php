<?php

namespace App\Services;

use PDO;
use InvalidArgumentException;

class OrderToppingSnapshotService
{
    public static function save(PDO $db, int $orderItemId, array $toppings): void
    {
        $stmt = $db->prepare("
            INSERT INTO order_item_topping_snapshots (order_item_id, toppings_json)
            VALUES (?, ?)
        ");
        $stmt->execute([
            $orderItemId,
            json_encode($toppings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    public static function attach(PDO $db, array $items): array
    {
        if ($items === []) {
            return [];
        }

        $itemIds = array_values(array_unique(array_filter(
            array_map(static fn(array $item): int => (int)($item['id'] ?? 0), $items),
            static fn(int $id): bool => $id > 0
        )));

        $snapshotsByItemId = [];
        if ($itemIds !== []) {
            $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
            $stmt = $db->prepare("
                SELECT order_item_id, toppings_json
                FROM order_item_topping_snapshots
                WHERE order_item_id IN ({$placeholders})
            ");
            $stmt->execute($itemIds);
            foreach ($stmt->fetchAll() as $row) {
                $decoded = json_decode($row['toppings_json'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new InvalidArgumentException('Invalid order topping snapshot format');
                }
                $normalized = [];
                foreach ($decoded as $entry) {
                    if (is_array($entry)) {
                        $entry['quantity'] = max(1, (int)($entry['quantity'] ?? 1));
                        $normalized[] = $entry;
                    }
                }
                $snapshotsByItemId[(int)$row['order_item_id']] = $normalized;
            }
        }

        foreach ($items as &$item) {
            $itemId = (int)($item['id'] ?? 0);
            $item['topping_details_recorded'] = array_key_exists($itemId, $snapshotsByItemId);
            $item['toppings'] = $snapshotsByItemId[$itemId] ?? [];
        }
        unset($item);

        return $items;
    }
}
