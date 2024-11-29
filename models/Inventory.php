<?php

namespace Models;

use Database\Database;

class Inventory
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();

    }

    private function getItemCurrentQuantity($itemId)
    {
        $sql = "
            SELECT COALESCE(SUM(quantity), 0) AS total_quantity
            FROM item_stocks
            WHERE item_id = :itemId
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':itemId', $itemId, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn();
    }

    public function getInventory($filter = null)
    {
        $page = $filter['page'] ?? 1;
        $pageSize = $filter['page_size'] ?? 10;
        $order = $filter['order'] ?? 'i.id';
        $sort = $filter['sort'] ?? 'DESC';

        $sql = "
            SELECT
                i.id, 
                i.name, 
                CONCAT(COALESCE(SUM(item_stocks.quantity), 0), ' ', u.abbreviation) AS quantity,
                CONCAT(i.threshold_value, ' ', u.abbreviation) AS threshold_value,
                i.price AS buying_price, 
                MAX(item_stocks.expiry_date) AS expiry_date,
                i.sku, 
                i.availability, 
                i.media
            FROM item_stocks
            JOIN items i ON item_stocks.item_id = i.id
            LEFT JOIN units u ON i.unit_id = u.id
        ";

        $params = [];

        if (!empty($filter['availability'])) {
            $sql .= " WHERE i.availability = :filterAvailability";
            $params['filterAvailability'] = $filter['availability'];
        }

        $sql .= "
            GROUP BY 
                i.id, i.name, i.threshold_value, i.price,
                i.sku, i.availability, i.media, u.abbreviation
            ORDER BY $order $sort
            LIMIT :pageSize OFFSET :offset
        ";

        $params['pageSize'] = $pageSize;
        $params['offset'] = ($page - 1) * $pageSize;

        $stmt = $this->db->prepare($sql);

        $stmt->bindValue(':pageSize', $params['pageSize'], \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $params['offset'], \PDO::PARAM_INT);

        if (!empty($filter['availability'])) {
            $stmt->bindValue(
                ':filterAvailability',
                $params['filterAvailability'],
                \PDO::PARAM_STR
            );
        }

        $stmt->execute();

        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($results as &$item) {
            $item['media'] = !empty($item['media']) ? json_decode($item['media'], true) : null;
        }

        $total = $this->countInventory($filter);

        $meta = [
            'current_page' => $page,
            'last_page' => ceil($total / $pageSize),
            'total' => $total
        ];

        return [
            'inventory' => $results,
            'meta' => $meta
        ];
    }

    private function countInventory($filter = null)
    {
        $countSql = "
            SELECT COUNT(DISTINCT i.id) AS total_count
            FROM item_stocks
            JOIN items i ON item_stocks.item_id = i.id
            LEFT JOIN units u ON i.unit_id = u.id
        ";

        $params = [];

        if (!empty($filter['availability'])) {
            $countSql .= " WHERE i.availability = :filterAvailability";
            $params['filterAvailability'] = $filter['availability'];
        }

        $countStmt = $this->db->prepare($countSql);

        if (!empty($filter['availability'])) {
            $countStmt->bindValue(':filterAvailability', $params['filterAvailability'], \PDO::PARAM_STR);
        }

        $countStmt->execute();

        return $countStmt->fetchColumn();
    }

    public function getItem($itemId)
    {
        $sql = "
            SELECT 
                i.id AS item_id, 
                i.name AS item_name, 
                ic.name AS category, 
                d.name AS department,
                i.threshold_value, 
                its.expiry_date, 
                i.opening_stock, 
                COALESCE(SUM(its.quantity), 0) AS remaining_stock,
                i.media
            FROM items i
            LEFT JOIN item_stocks its ON i.id = its.item_id
            LEFT JOIN item_stock_departments isd ON its.id = isd.stock_id
            LEFT JOIN departments d ON isd.department_id = d.id
            LEFT JOIN item_categories ic ON i.category_id = ic.id
            WHERE i.id = :itemId
            GROUP BY i.id, i.name, ic.name, d.name, i.threshold_value,
            its.expiry_date, i.opening_stock, i.media
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':itemId', $itemId, \PDO::PARAM_INT);

        try {
            $stmt->execute();
            $item = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($item) {
                $item['media'] = $item['media'] ? json_decode($item['media'], true) : [];
                return $item;
            }

            return null;
        } catch (\PDOException $e) {
            error_log($e->getMessage());
            return null;
        }
    }

    public function deleteItem($itemId)
    {
        $sql = "
            DELETE FROM items
            WHERE id = :itemId
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->bindParam(':itemId', $itemId, \PDO::PARAM_INT);

        return $stmt->execute();
    }


    public function createItem($data, $mediaLinks = [])
    {
        try {
            $this->db->beginTransaction();

            $sql = "
                INSERT INTO items
                (name, description, unit_id, category_id,
                price, threshold_value, media, opening_stock)
                VALUES (:name, :description, :unitId, :categoryId,
                :price, :threshold, :media, :openingStock)
            ";

            $mediaLinks = json_encode($mediaLinks);

            $stmt = $this->db->prepare($sql);

            $stmt->bindParam(':name', $data['name']);
            $stmt->bindParam(':description', $data['description']);
            $stmt->bindParam(':unitId', $data['unit_id']);
            $stmt->bindParam(':categoryId', $data['category_id']);
            $stmt->bindParam(':price', $data['price']);
            $stmt->bindParam(':threshold', $data['threshold_value']);
            $stmt->bindParam(':media', $mediaLinks);
            $stmt->bindParam(':openingStock', $data['quantity']);

            if (!$stmt->execute()) {
                throw new \Exception('Failed to insert item.');
            }

            $itemId = $this->db->lastInsertId();

            if (!$this->createItemStock($itemId, $data)) {
                throw new \Exception('Failed to insert item stock.');
                return false;
            }

            $this->db->commit();

            return $itemId;

        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log($e->getMessage());
            return false;
        }
    }

    private function createItemStock($itemId, $data)
    {
        $dateReceived = $data['date_received'] ?? date('Y-m-d');

        try {
            $stockSql = "
                INSERT INTO item_stocks (item_id, quantity, expiry_date, date_received)
                VALUES (:itemId, :quantity, :expiryDate, :dateReceived)
                RETURNING id
            ";

            $stockStmt = $this->db->prepare($stockSql);
            $stockStmt->bindParam(':itemId', $itemId, \PDO::PARAM_INT);
            $stockStmt->bindParam(':quantity', $data['quantity'], \PDO::PARAM_INT);
            $stockStmt->bindParam(':expiryDate', $data['expiry_date'], \PDO::PARAM_STR);
            $stockStmt->bindParam(':dateReceived', $dateReceived, \PDO::PARAM_STR);

            if (!$stockStmt->execute()) {
                throw new \Exception('Failed to insert item stock.');
            }

            $stockId = $stockStmt->fetchColumn();

            if (!$this->upsertItemRelationships([$stockId], $data)) {
                throw new \Exception('Failed to insert item relationships.');
            }

            return $stockId;
        } catch (\Exception $e) {
            error_log($e->getMessage());
            return false;
        }
    }

    public function updateItem($itemId, $data, $mediaLinks = [])
    {
        try {
            $sql = "
                UPDATE items
                SET name = :name, description = :description, unit_id = :unitId,
                    category_id = :categoryId, price = :price,
                    threshold_value = :thresholdValue, media = :media,
                    opening_stock = :openingStock 
                WHERE id = :itemId
            ";

            $mediaLinks = json_encode($mediaLinks);
            $stmt = $this->db->prepare($sql);

            $stmt->bindParam(':itemId', $itemId);
            $stmt->bindParam(':name', $data['name']);
            $stmt->bindParam(':description', $data['description']);
            $stmt->bindParam(':unitId', $data['unit_id']);
            $stmt->bindParam(':categoryId', $data['category_id']);
            $stmt->bindParam(':price', $data['price']);
            $stmt->bindParam(':thresholdValue', $data['threshold_value']);
            $stmt->bindParam(':media', $mediaLinks);
            $stmt->bindParam(':openingStock', $data['quantity']);

            if (!$stmt->execute()) {
                throw new \Exception('Failed to update item.');
            }

            $current_quantity = $this->getItemCurrentQuantity($itemId);
            $difference = $data['quantity'] - $current_quantity;

            if ($difference < 0) {
                error_log("Subtracting stock");
                $stockIds = $this->adjustStock($itemId, [
                    'quantity' => abs($difference),
                    'adjustment_type' => 'subtraction',
                    'description' => 'Edit item',
                    'user_id' => $data['user_id'],
                    'user_department_id' => $data['user_department_id']
                ]);
            } elseif ($difference > 0) {
                error_log("Adding stock");
                $stockIds = $this->adjustStock($itemId, [
                    'quantity' => $difference,
                    'adjustment_type' => 'addition',
                    'description' => 'Edit item',
                    'user_id' => $data['user_id'],
                    'user_department_id' => $data['user_department_id']
                ]);
            } else {
                error_log("No stock change required");
            }

            if ($difference !== 0) {
                if (!$this->upsertItemRelationships($stockIds, $data)) {
                    throw new \Exception('Failed to update or insert item relationships.');
                }
            }

            return true;

        } catch (\Exception $e) {
            error_log($e->getMessage());
            return false;
        }
    }

    private function upsertItemRelationships(array $stockIds, $data)
    {
        foreach ($stockIds as $stockId) {
            if (!empty($data['vendor_id'])) {
                if (!$this->upsertItemStockVendor($stockId, $data['vendor_id'])) {
                    return false;
                }
            }

            if (!empty($data['department_id'])) {
                if (!$this->upsertItemStockDepartment($stockId, $data['department_id'])) {
                    return false;
                }
            }

            if (!empty($data['manufacturer_id'])) {
                if (!$this->upsertItemStockManufacturer($stockId, $data['manufacturer_id'])) {
                    return false;
                }
            }
        }

        return true;
    }

    private function upsertItemStockVendor($stockId, $vendorId)
    {
        $vendorSql = "
            INSERT INTO item_stock_vendors (stock_id, vendor_id)
            VALUES (:stockId, :vendorId)
            ON CONFLICT (stock_id, vendor_id) 
            DO UPDATE SET vendor_id = EXCLUDED.vendor_id
        ";

        $vendorStmt = $this->db->prepare($vendorSql);
        $vendorStmt->bindParam(':stockId', $stockId);
        $vendorStmt->bindParam(':vendorId', $vendorId);

        return $vendorStmt->execute();
    }

    private function upsertItemStockDepartment($stockId, $departmentId)
    {
        $departmentSql = "
            INSERT INTO item_stock_departments (stock_id, department_id)
            VALUES (:stockId, :departmentId)
            ON CONFLICT (stock_id, department_id) 
            DO UPDATE SET department_id = EXCLUDED.department_id
        ";

        $departmentStmt = $this->db->prepare($departmentSql);
        $departmentStmt->bindParam(':stockId', $stockId);
        $departmentStmt->bindParam(':departmentId', $departmentId);

        return $departmentStmt->execute();
    }

    private function upsertItemStockManufacturer($stockId, $manufacturerId)
    {
        $manufacturerSql = "
            INSERT INTO item_stock_manufacturers (stock_id, manufacturer_id)
            VALUES (:stockId, :manufacturerId)
            ON CONFLICT (stock_id, manufacturer_id) 
            DO UPDATE SET manufacturer_id = EXCLUDED.manufacturer_id
        ";

        $manufacturerStmt = $this->db->prepare($manufacturerSql);
        $manufacturerStmt->bindParam(':stockId', $stockId);
        $manufacturerStmt->bindParam(':manufacturerId', $manufacturerId);

        return $manufacturerStmt->execute();
    }



    public function adjustStock($itemId, $data)
    {
        if (!in_array($data['adjustment_type'], ['addition', 'subtraction'])) {
            throw new \Exception('Invalid operation, must be add or subtract');
        }

        $quantity = $data['quantity'];
        $affectedStockIds = [];

        try {
            $this->db->beginTransaction();

            if ($data['adjustment_type'] === 'subtraction') {
                $affectedStockIds = $this->subtractStockFIFO($itemId, $quantity, $data);
            }

            if ($data['adjustment_type'] === 'addition') {
                $newStockId = $this->createItemStock($itemId, $data);

                $query = "
                    INSERT INTO item_stock_adjustments
                    (stock_id, quantity, adjustment_type,
                    description, user_id, user_department_id)
                    VALUES (:stockId, :quantity, :adjustmentType,
                    :description, :user_id, :user_department_id)
                ";
                $stmt = $this->db->prepare($query);

                $stmt->bindValue(':stockId', $newStockId, \PDO::PARAM_INT);
                $stmt->bindValue(':quantity', $quantity, \PDO::PARAM_INT);
                $stmt->bindValue(':adjustmentType', $data['adjustment_type'], \PDO::PARAM_STR);
                $stmt->bindValue(':description', $data['description'], \PDO::PARAM_STR);
                $stmt->bindValue(':user_id', $data['user_id'], \PDO::PARAM_INT);
                $stmt->bindValue(':user_department_id', $data['user_department_id'], \PDO::PARAM_INT);

                if (!$stmt->execute()) {
                    throw new \Exception('Failed to log stock adjustment for addition.');
                }

                $affectedStockIds[] = $newStockId;
            }

            $this->db->commit();
            return $affectedStockIds;
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log($e->getMessage());
            throw $e;
        }
    }

    private function subtractStockFIFO($itemId, $quantity, $data)
    {
        $affectedStockIds = [];
        $stocks = $this->getStocksByItemId($itemId);

        foreach ($stocks as $stock) {
            error_log("Stock: " . json_encode($stock));
            if ($quantity <= 0) {
                break;
            }

            $stockId = $stock['id'];
            $stockQuantity = $stock['quantity'];

            if ($stockQuantity > 0) {
                $subtractAmount = min($quantity, $stockQuantity);
                $quantity -= $subtractAmount;

                $updateSql = "
                    UPDATE item_stocks
                    SET quantity = quantity - :subtractAmount
                    WHERE id = :stockId
                ";
                $updateStmt = $this->db->prepare($updateSql);
                $updateStmt->bindValue(':subtractAmount', $subtractAmount, \PDO::PARAM_INT);
                $updateStmt->bindValue(':stockId', $stockId, \PDO::PARAM_INT);

                if ($updateStmt->execute()) {
                    $affectedStockIds[] = $stockId;

                    $logSql = "
                        INSERT INTO item_stock_adjustments
                        (stock_id, quantity, adjustment_type,
                        description, user_id, user_department_id)
                        VALUES (:stockId, :quantity, :adjustmentType,
                        :description, :user_id, :user_department_id)
                    ";
                    $logStmt = $this->db->prepare($logSql);
                    $logStmt->bindValue(':stockId', $stockId, \PDO::PARAM_INT);
                    $logStmt->bindValue(':quantity', $subtractAmount, \PDO::PARAM_INT);
                    $logStmt->bindValue(':adjustmentType', $data['adjustment_type'], \PDO::PARAM_STR);
                    $logStmt->bindValue(':description', $data['description'], \PDO::PARAM_STR);
                    $logStmt->bindValue(':user_id', $data['user_id'], \PDO::PARAM_INT);
                    $logStmt->bindValue(':user_department_id', $data['user_department_id'], \PDO::PARAM_INT);

                    if (!$logStmt->execute()) {
                        throw new \Exception('Failed to log stock adjustment.');
                    }
                }
            }
        }

        if ($quantity > 0) {
            throw new \Exception('Insufficient stock to complete the operation.');
        }

        return $affectedStockIds;
    }

    private function getStocksByItemId($itemId)
    {
        $sql = "
            SELECT id, quantity, stock_code
            FROM item_stocks
            WHERE item_id = :itemId
            AND quantity > 0
            ORDER BY date_received ASC, id ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':itemId', $itemId, \PDO::PARAM_INT);
        $stmt->execute();

        error_log("getStocksByItemId: $itemId");

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }


}
