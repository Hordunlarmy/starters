<?php

namespace Controllers;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Database\Database;
use Exception;

class BaseController
{
    protected $db;
    protected $secret_key;
    protected $algorithm;
    protected $exp_time;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->secret_key = getenv('SECRET_KEY');
        $this->algorithm = getenv('ALGORITHM');
        $this->exp_time = getenv('ACCESS_TOKEN_EXPIRE_MINUTES');
    }

    protected function getRequestData()
    {
        // Check if the request is JSON
        if (strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
            $json = file_get_contents('php://input');
            $data = json_decode($json, true);

            // Check for JSON decoding errors
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->sendResponse('Invalid JSON format', 400);
            }

            return $data;
        }

        // If not JSON, return $_POST and handle multiple file uploads
        $files = [];
        foreach ($_FILES as $key => $fileArray) {
            if (is_array($fileArray['name'])) {
                foreach ($fileArray['name'] as $index => $fileName) {
                    $files[$key][] = [
                        'name' => $fileName,
                        'type' => $fileArray['type'][$index],
                        'tmp_name' => $fileArray['tmp_name'][$index],
                        'error' => $fileArray['error'][$index],
                        'size' => $fileArray['size'][$index],
                    ];
                }
            } else {
                $files[$key] = $fileArray; // Single file upload
            }
        }

        return [
            'form_data' => $_POST,
            'files' => $files
        ];
    }

    public function authorizeRequest()
    {
        if (!isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $this->sendResponse('Authorization header not found', 401);
            return;
        }

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        list($bearer, $token) = explode(' ', $authHeader, 2);

        if (strcasecmp($bearer, 'Bearer') !== 0) {
            $this->sendResponse('Invalid authorization format', 401);
            return;
        }

        try {
            $decoded = JWT::decode($token, new Key($this->secret_key, $this->algorithm));

            if (!isset($decoded->data->id)) {
                $this->sendResponse('Invalid token structure', 401);
                return;
            }

            $_SESSION['user_id'] = $decoded->data->id;

        } catch (Exception $e) {
            $this->sendResponse($e->getMessage(), 401);
            return;
        }
    }


    protected function validateFields(...$fields)
    {
        foreach ($fields as $field) {
            if (empty($field)) {
                return false;
            }
        }
        return true;
    }

    protected function sendResponse($message, $statusCode, $data = [], $meta = [])
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');

        $response = [
            'message' => $message,
        ];

        if (!empty($data)) {
            $response['data'] = $data;
        }

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        echo json_encode($response);
        exit;
    }

    protected function fetchData(string $table, array $columns = ['*'])
    {
        $columnsList = implode(', ', $columns);
        $query = "SELECT $columnsList FROM $table";
        $stmt = $this->db->query($query);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getRoles()
    {
        return $this->sendResponse('success', 200, $this->fetchData('roles'));

    }

    public function getUnits()
    {
        return $this->sendResponse('success', 200, $this->fetchData('units', ['id', 'name', 'abbreviation']));
    }

    public function getVendors()
    {
        return $this->sendResponse('success', 200, $this->fetchData('vendors'));
    }

    public function getCurrencies()
    {
        return $this->sendResponse('success', 200, $this->fetchData('currencies', ['id', 'name', 'symbol']));
    }

    public function getPaymentMethods()
    {
        return $this->sendResponse('success', 200, $this->fetchData('payment_methods', ['id', 'name', 'description']));
    }

    public function getDepartments()
    {
        return $this->sendResponse('success', 200, $this->fetchData('departments', ['id', 'name', 'description']));
    }

    public function getItemCategories()
    {
        return $this->sendResponse('success', 200, $this->fetchData('item_categories', ['id', 'name', 'description']));
    }

    public function getItemManufacturers()
    {
        return $this->sendResponse('success', 200, $this->fetchData('item_manufacturers', ['id', 'name', 'website']));
    }
}
