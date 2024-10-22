<?php

namespace Models;

use Database\Database;

class Inventory
{
    private $db;

    private $table = 'inventory';

    private $table_plans = 'inventory_plans';

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();

    }

    public function getInventoryPlans($filter = null)
    {
        $page = $filter['page'] ?? 1;
        $pageSize = $filter['page_size'] ?? 10;

        $sql = "
            SELECT 
                ip.name AS inventory_plan_name,
                COUNT(ipp.product_id) AS product_count,
                w.name AS warehouse_name,
                ip.plan_date,
                p.status,
                i.progress
            FROM inventory i
            JOIN warehouses w ON i.warehouse_id = w.id
            LEFT JOIN inventory_plan_products ipp ON i.product_id = ipp.product_id
            LEFT JOIN products p ON ipp.product_id = p.id
            JOIN inventory_plans ip ON ipp.inventory_plan_id = ip.id
        ";

        $params = [];

        if (!empty($filter['status'])) {
            $sql .= " WHERE p.status = :filterStatus";
            $params['filterStatus'] = $filter['status'];
        }

        $sql .= "
            GROUP BY ip.id, w.name, p.status, i.progress
            LIMIT :pageSize OFFSET :offset
        ";

        $params['pageSize'] = $pageSize;
        $params['offset'] = ($page - 1) * $pageSize;

        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':pageSize', $params['pageSize'], \PDO::PARAM_INT);
        $stmt->bindParam(':offset', $params['offset'], \PDO::PARAM_INT);
        $stmt->execute($params);

        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $total = $this->countInventoryPlans($filter);

        $meta = [
            'current_page' => $page,
            'last_page' => ceil($total / $pageSize),
            'total' => $total
        ];

        return [
            'plans' => $results,
            'meta' => $meta
        ];
    }

    public function countInventoryPlans($filter = null)
    {
        $countSql = "
            SELECT COUNT(DISTINCT ip.id) AS total_count
            FROM inventory_plans ip
            JOIN inventory_plan_products ipp ON ip.id = ipp.inventory_plan_id
            LEFT JOIN inventory i ON i.product_id = ipp.product_id
            LEFT JOIN warehouses w ON i.warehouse_id = w.id
            LEFT JOIN products p ON ipp.product_id = p.id
        ";

        $params = [];

        if (!empty($filter['status'])) {
            $countSql .= " WHERE p.status = :filterStatus";
            $params['filterStatus'] = $filter['status'];
        }

        $countStmt = $this->db->prepare($countSql);
        if (!empty($filter['status'])) {
            $countStmt->bindParam(':filterStatus', $params['filterStatus']);
        }
        $countStmt->execute();

        return $countStmt->fetchColumn();
    }

    public function getInventoryTracker()
    {
        $sql = "
            SELECT 
                ip.name,
                COUNT(ipp.product_id) AS product_count,
                ip.plan_date,
                ip.status,
                ip.progress
            FROM inventory_plans ip
            LEFT JOIN inventory_plan_products ipp ON ip.id = ipp.inventory_plan_id
            LEFT JOIN products p ON ipp.product_id = p.id
            GROUP BY ip.id, p.status, ip.progress
        ";

        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function saveInventoryPlan($data, $id = null, $action = 'create')
    {
        try {
            $this->db->beginTransaction();

            $name = $data['name'];
            $planDate = $data['plan_date'];
            $products = $data['products'];

            if ($action === 'create') {
                $inventoryPlanId = $this->insertInventoryPlan($name, $planDate);
            } elseif ($action === 'update' && $id !== null) {
                $this->updateInventoryPlan($id, $name, $planDate);
                $inventoryPlanId = $id;
            }

            $this->insertOrUpdateInventoryPlanProducts($inventoryPlanId, $products);

            $this->db->commit();
            return $inventoryPlanId;

        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function insertInventoryPlan($name, $planDate)
    {
        $sql = "
		INSERT INTO inventory_plans 
		(name, plan_date) 
		VALUES (:name, :plan_date)
	";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'name' => $name,
            'plan_date' => $planDate,
        ]);

        return $this->db->lastInsertId();
    }

    private function updateInventoryPlan($id, $name, $planDate)
    {
        $sql = "
		UPDATE inventory_plans 
		SET name = :name, plan_date = :plan_date
		WHERE id = :id
	";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'name' => $name,
            'plan_date' => $planDate,
            'id' => $id
        ]);
    }

    private function insertOrUpdateInventoryPlanProducts($inventoryPlanId, $products)
    {
        $sql = "
		DELETE FROM inventory_plan_products 
		WHERE inventory_plan_id = :inventory_plan_id
	";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'inventory_plan_id' => $inventoryPlanId
        ]);

        $sql = "
		INSERT INTO inventory_plan_products 
		(inventory_plan_id, product_id) 
		VALUES (:inventory_plan_id, :product_id)
	";

        $stmt = $this->db->prepare($sql);

        foreach ($products as $product) {
            $stmt->execute([
                'inventory_plan_id' => $inventoryPlanId,
                'product_id' => $product['id'],
            ]);
        }
    }





    // Add a product to a inventory
    public function addInventory($data)
    {
        $query = "INSERT INTO " . $this->table . "
        (product_id, warehouse_id, storage_id, quantity, on_hand, to_be_delivered, to_be_ordered) 
        VALUES (:product_id, :warehouse_id, :storage_id, :quantity, :on_hand, :to_be_delivered, :to_be_ordered)";

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':product_id', $data['product_id']);
        $stmt->bindParam(':warehouse_id', $data['warehouse_id']);
        $stmt->bindParam(':storage_id', $data['storage_id']);
        $stmt->bindParam(':quantity', $data['quantity']);
        $stmt->bindParam('on_hand', $data['on_hand']);
        $stmt->bindParam(':to_be_delivered', $data['to_be_delivered']);
        $stmt->bindParam(':to_be_ordered', $data['to_be_ordered']);

        if ($stmt->execute()) {
            return $this->db->lastInsertId();
        }

        return false;
    }

    // Get the stock level for a warehouse
    public function getInventoryByWarehouse($warehouse_id)
    {
        $query = "SELECT i.*, p.name as product_name, w.name as warehouse_name 
        FROM " . $this->table . " i
        JOIN products p ON i.product_id = p.id
        JOIN warehouses w ON i.warehouse_id = w.id
        WHERE i.warehouse_id = :warehouse_id";

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':warehouse_id', $warehouse_id);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // Update product stock in warehouse
    public function updateStock($warehouse_id, $product_id, $quantity, $progress)
    {
        $query = "UPDATE " . $this->table . "
        SET quantity = :quantity, progress = :progress
        WHERE warehouse_id = :warehouse_id AND product_id = :product_id";

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':warehouse_id', $warehouse_id);
        $stmt->bindParam(':product_id', $product_id);
        $stmt->bindParam(':quantity', $quantity);
        $stmt->bindParam(':progress', $progress);

        return $stmt->execute();
    }



    //Get all inventory plans

    public function getAllInventoryPlans()
    {
        $query = "SELECT ip.*, w.name as warehouse_name FROM " . $this->table_plans . " ip JOIN warehouses w ON ip.warehouse_id = w.id";
        $stmt = $this->db->query($query);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }


    // Update an inventory plan
    public function update($id, $data)
    {
        $query = "UPDATE " . $this->table_plans . "
        SET name = :name, status = :status, inventory_date = :inventory_date, warehouse_id = :warehouse_id, progress = :progress 
        WHERE id = :id";

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':status', $data['status']);
        $stmt->bindParam(':inventory_date', $data['inventory_date']);
        $stmt->bindParam(':warehouse_id', $data['warehouse_id']);
        $stmt->bindParam(':progress', $data['progress']);
        $stmt->bindParam(':id', $id);

        return $stmt->execute();
    }

}
