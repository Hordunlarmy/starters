<?php

namespace Models;

use Database\Database;

class Sale
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getSalesOrders($filters = [])
    {
        $page = $filters['page'] ?? 1;
        $pageSize = $filters['page_size'] ?? 10;
        $query = "
            SELECT 
                so.order_id, 
                so.order_title, 
                COALESCE(SUM(soi.quantity), 0) AS quantity, 
                CONCAT_WS(' ', c.salutation, c.first_name, c.last_name) AS customer_name, 
                so.created_at::DATE AS date, 
                so.order_type, 
                so.total AS amount, 
                so.status
            FROM 
                sales_orders so
            LEFT JOIN 
                sales_order_items soi 
                ON so.id = soi.sales_order_id
            LEFT JOIN 
                customers c 
                ON so.customer_id = c.id
        ";

        $conditions = [];
        $params = [];

        if (!empty($filters['status'])) {
            $conditions[] = "so.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $conditions[] = "so.created_at::DATE BETWEEN :start_date AND :end_date";
            $params['start_date'] = $filters['start_date'];
            $params['end_date'] = $filters['end_date'];
        } elseif (!empty($filters['start_date'])) {
            $conditions[] = "so.created_at::DATE >= :start_date";
            $params['start_date'] = $filters['start_date'];
        } elseif (!empty($filters['end_date'])) {
            $conditions[] = "so.created_at::DATE <= :end_date";
            $params['end_date'] = $filters['end_date'];
        }

        if (!empty($filters['order_type'])) {
            $conditions[] = "so.order_type = :order_type";
            $params['order_type'] = $filters['order_type'];
        }

        if ($conditions) {
            $query .= " WHERE " . implode(' AND ', $conditions);
        }

        $query .= "
            GROUP BY 
                so.order_id, so.order_title, c.salutation, c.first_name, 
                c.last_name, so.created_at, so.order_type, so.total, so.status
            ORDER BY 
                so.created_at DESC 
            LIMIT :limit OFFSET :offset
        ";

        $params['limit'] = $pageSize;
        $params['offset'] = ($page - 1) * $pageSize;

        $stmt = $this->db->prepare($query);

        foreach ($params as $key => $value) {
            $type = is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $type);
        }

        $stmt->execute();

        $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $total = $this->getSalesOrdersCount($filters);

        $meta = [
            'current_page' => (int) $page,
            'next_page' => (int) $page + 1,
            'page_size' => (int) $pageSize,
            'total_data' => $total,
            'total_pages' => ceil($total / $pageSize),
        ];

        return ['data' => $data, 'meta' => $meta];
    }

    public function createSale($data)
    {

        $this->db->beginTransaction();

        try {
            $orderId = $this->insertSalesOrder($data);

            $this->insertSalesOrderItem($orderId, $data['items']);

            $this->db->commit();

            return $orderId;
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function getSalesOrdersCount($filters = [])
    {
        $query = "
            SELECT COUNT(DISTINCT so.order_id) AS count
            FROM sales_orders so
            LEFT JOIN sales_order_items soi 
            ON so.id = soi.sales_order_id
            LEFT JOIN customers c 
            ON so.customer_id = c.id
        ";

        $conditions = [];
        $params = [];

        if (!empty($filters['status'])) {
            $conditions[] = "so.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $conditions[] = "so.created_at::DATE BETWEEN :start_date AND :end_date";
            $params['start_date'] = $filters['start_date'];
            $params['end_date'] = $filters['end_date'];
        } elseif (!empty($filters['start_date'])) {
            $conditions[] = "so.created_at::DATE >= :start_date";
            $params['start_date'] = $filters['start_date'];
        } elseif (!empty($filters['end_date'])) {
            $conditions[] = "so.created_at::DATE <= :end_date";
            $params['end_date'] = $filters['end_date'];
        }

        if (!empty($filters['order_type'])) {
            $conditions[] = "so.order_type = :order_type";
            $params['order_type'] = $filters['order_type'];
        }

        if ($conditions) {
            $query .= " WHERE " . implode(' AND ', $conditions);
        }

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }


    private function insertSalesOrder($data)
    {
        $query = "
            INSERT INTO sales_orders (
                order_type, order_title, payment_term_id, customer_id,
                payment_method_id, delivery_option, 
                assigned_driver_id, delivery_date, additional_note,
                customer_note, discount, delivery_charge, total
            ) 
            VALUES (
                :order_type, :order_title, :payment_term_id, :customer_id,
                :payment_method_id, :delivery_option, 
                :assigned_driver_id, :delivery_date, :additional_note,
                :customer_note, :discount, :delivery_charge,
                :total 
            ) 
            RETURNING id;
        ";

        try {
            $stmt = $this->db->prepare($query);

            $stmt->execute([
                'order_type' => $data['order_type'],
                'order_title' => $data['order_title'],
                'payment_term_id' => $data['payment_term_id'],
                'payment_method_id' => $data['payment_method_id'],
                'customer_id' => $data['customer_id'] ?? null,
                'delivery_option' => $data['delivery_option'],
                'assigned_driver_id' => $data['assigned_driver_id'],
                'delivery_date' => $data['delivery_date'],
                'additional_note' => $data['additional_note'],
                'customer_note' => $data['customer_note'],
                'discount' => $data['discount'],
                'delivery_charge' => $data['delivery_charge'],
                'total' => $data['total']
            ]);

            return $stmt->fetchColumn();
        } catch (\PDOException $e) {
            throw new \Exception("Failed to insert sales order: " . $e->getMessage());
        }
    }

    private function insertSalesOrderItem($salesOrderId, $items)
    {
        $query = "
            INSERT INTO sales_order_items (sales_order_id, item_id, quantity, price) 
            VALUES (:sales_order_id, :item_id, :quantity, :price);
        ";

        $stmt = $this->db->prepare($query);

        foreach ($items as $item) {
            $stmt->execute([
                ':sales_order_id' => $salesOrderId,
                ':item_id' => $item['item_id'],
                ':quantity' => $item['quantity'],
                ':price' => $item['price']
            ]);
        }
    }

    public function getInvoiceDetails($salesOrderId)
    {
        $query = "
            SELECT so.id,
                so.invoice_number,
                so.order_title,
                so.order_type,
                c.name AS customer_name,
                so.discount,
                so.delivery_charge,
                so.total,
                json_agg(
                    json_build_object(
                        'item_id', soi.item_id,
                        'quantity', soi.quantity,
                        'price', soi.price,
                        'total', soi.total
                    )
                ) AS items
            FROM sales_orders so
            LEFT JOIN customers c ON so.customer_id = c.id
            LEFT JOIN sales_order_items soi ON soi.sales_order_id = so.id
            WHERE so.id = :sales_order_id
            GROUP BY so.id, c.name, so.invoice_number, so.order_title, so.order_type, 
                     so.discount, so.delivery_charge, so.total;
        ";

        $stmt = $this->db->prepare($query);

        try {
            $stmt->execute([':sales_order_id' => $salesOrderId]);

            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($result) {
                $result['items'] = json_decode($result['items'], true);
                return $result;
            }

        } catch (\Exception $e) {
            throw new \Exception("Failed to fetch invoice details: " . $e->getMessage());
        }
    }

}
