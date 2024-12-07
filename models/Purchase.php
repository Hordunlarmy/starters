<?php

namespace Models;

use Database\Database;

class Purchase
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getPurchaseOrders($filters = [])
    {
        $page = $filters['page'] ?? 1;
        $pageSize = $filters['page_size'] ?? 10;
        $query = "
            SELECT
                po.id, 
                po.purchase_order_number, 
                CONCAT_WS(' ', v.salutation, v.first_name, v.last_name) AS vendor_name, 
                po.created_at::DATE AS order_date, 
                po.delivery_date, 
                po.total, 
                po.status
            FROM 
                purchase_orders po
            LEFT JOIN 
                vendors v 
                ON po.vendor_id = v.id
        ";

        $conditions = [];
        $params = [];

        if (!empty($filters['status'])) {
            $conditions[] = "po.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $conditions[] = "po.created_at::DATE BETWEEN :start_date AND :end_date";
            $params['start_date'] = $filters['start_date'];
            $params['end_date'] = $filters['end_date'];
        } elseif (!empty($filters['start_date'])) {
            $conditions[] = "po.created_at::DATE >= :start_date";
            $params['start_date'] = $filters['start_date'];
        } elseif (!empty($filters['end_date'])) {
            $conditions[] = "po.created_at::DATE <= :end_date";
            $params['end_date'] = $filters['end_date'];
        }

        if ($conditions) {
            $query .= " WHERE " . implode(' AND ', $conditions);
        }

        $query .= " ORDER BY po.created_at DESC LIMIT :limit OFFSET :offset";

        $params['limit'] = $pageSize;
        $params['offset'] = ($page - 1) * $pageSize;

        $stmt = $this->db->prepare($query);

        foreach ($params as $key => $value) {
            $type = is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $type);
        }

        $stmt->execute();

        $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $total = $this->getPurchaseOrdersCount($filters);

        $meta = [
            'current_page' => (int) $page,
            'next_page' => (int) $page + 1,
            'page_size' => $pageSize,
            'total_data' => $total,
            'total_pages' => ceil($total / $pageSize),
        ];

        return ['data' => $data, 'meta' => $meta];
    }

    private function getPurchaseOrdersCount($filters = [])
    {
        $query = "
            SELECT COUNT(*) AS count
            FROM purchase_orders po
            LEFT JOIN vendors v 
            ON po.vendor_id = v.id
        ";

        $conditions = [];
        $params = [];

        if (!empty($filters['status'])) {
            $conditions[] = "po.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $conditions[] = "po.order_date BETWEEN :date_from AND :date_to";
            $params['date_from'] = $filters['date_from'];
            $params['date_to'] = $filters['date_to'];
        } elseif (!empty($filters['date_from'])) {
            $conditions[] = "po.order_date >= :date_from";
            $params['date_from'] = $filters['date_from'];
        } elseif (!empty($filters['date_to'])) {
            $conditions[] = "po.order_date <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        if ($conditions) {
            $query .= " WHERE " . implode(' AND ', $conditions);
        }

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function createPurchase($data)
    {
        $this->db->beginTransaction();

        try {
            $purchaseOrderId = $this->insertPurchaseOrder($data);

            $this->insertPurchaseOrderItems($purchaseOrderId, $data['items']);

            $this->db->commit();
            return $this->getInvoiceDetails($purchaseOrderId);
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function insertPurchaseOrder($data)
    {
        $query = "
            INSERT INTO purchase_orders (delivery_date, vendor_id, 
                branch_id, payment_term_id, subject, notes,
                terms_and_conditions, discount, shipping_charge, total) 
            VALUES (:delivery_date, :vendor_id, :branch_id,
                :payment_term_id, :subject, :notes, :terms_and_conditions, 
                :discount, :shipping_charge, :total)
            RETURNING id;
        ";

        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':delivery_date' => $data['delivery_date'],
            ':vendor_id' => $data['vendor_id'],
            ':branch_id' => $data['branch_id'],
            ':payment_term_id' => $data['payment_term_id'],
            ':subject' => $data['subject'],
            ':notes' => $data['notes'],
            ':terms_and_conditions' => $data['terms_and_conditions'],
            ':discount' => $data['discount'],
            ':shipping_charge' => $data['shipping_charge'],
            ':total' => $data['total'],
        ]);

        return $stmt->fetchColumn();
    }

    private function insertPurchaseOrderItems($purchaseOrderId, $items)
    {
        $query = "
            INSERT INTO purchase_order_items (purchase_order_id, item_id, 
                quantity, price, tax_id)
            VALUES (:purchase_order_id, :item_id, :quantity, :price, :tax_id);
        ";

        $stmt = $this->db->prepare($query);

        foreach ($items as $item) {
            $stmt->execute([
                ':purchase_order_id' => $purchaseOrderId,
                ':item_id' => $item['item_id'],
                ':quantity' => $item['quantity'],
                ':price' => $item['price'],
                ':tax_id' => $item['tax_id'] ?? null,
            ]);
        }
    }

    public function getInvoiceDetails($purchaseOrderId)
    {
        $query = "
        SELECT po.id,
            po.subject,
            po.invoice_number,
            po.purchase_order_number, 
            po.reference_number,
            po.discount,
            po.shipping_charge,
            po.total,
            po.created_at::DATE AS order_date,
            po.delivery_date,
            json_agg(
                json_build_object(
                    'item_id', poi.item_id,
                    'quantity', poi.quantity,
                    'price', poi.price,
                    'tax_id', poi.tax_id
                )
            ) AS items
        FROM purchase_orders po
        LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
        WHERE po.id = :purchase_order_id
        GROUP BY po.id, po.purchase_order_number, po.reference_number;
    ";

        $stmt = $this->db->prepare($query);
        $stmt->execute([':purchase_order_id' => $purchaseOrderId]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!empty($result['items'])) {
            $result['items'] = json_decode($result['items'], true);
        }

        return $result;
    }

}
