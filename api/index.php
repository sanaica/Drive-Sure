<?php
require_once 'db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$method = $_SERVER['REQUEST_METHOD'];
$route = isset($_GET['route']) ? rtrim($_GET['route'], '/') : '';
$parts = explode('/', $route);

$body = json_decode(file_get_contents('php://input'), true);

function sendResponse($statusCode, $data) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

try {
    if ($method === 'POST' && $route === 'auth/register') {
        $name = $body['name'] ?? '';
        $email = $body['email'] ?? '';
        $password = $body['password'] ?? '';

        if (!$name || !$email || !$password) {
            sendResponse(400, ['error' => 'Missing required fields']);
        }

        $stmt = $pdo->prepare('SELECT customer_id FROM CUSTOMER WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            sendResponse(409, ['error' => 'Email already registered']);
        }

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO CUSTOMER (name, email, password) VALUES (?, ?, ?)');
        $stmt->execute([$name, $email, $hashed_password]);

        $customer_id = $pdo->lastInsertId();
        sendResponse(201, [
            'message' => 'Registration successful',
            'user' => [
                'customer_id' => $customer_id,
                'name' => $name,
                'email' => $email
            ]
        ]);
    }

    if ($method === 'POST' && $route === 'auth/login') {
        $email = $body['email'] ?? '';
        $password = $body['password'] ?? '';

        if (!$email || !$password) {
            sendResponse(400, ['error' => 'Missing required fields']);
        }

        $stmt = $pdo->prepare('SELECT * FROM CUSTOMER WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            unset($user['password']);
            sendResponse(200, [
                'message' => 'Login successful',
                'user' => $user
            ]);
        } else {
            sendResponse(401, ['error' => 'Invalid credentials']);
        }
    }

    if ($method === 'GET' && $parts[0] === 'vehicles' && isset($parts[1])) {
        $customer_id = $parts[1];
        $stmt = $pdo->prepare('SELECT * FROM VEHICLE WHERE customer_id = ?');
        $stmt->execute([$customer_id]);
        $vehicles = $stmt->fetchAll();
        sendResponse(200, ['vehicles' => $vehicles]);
    }

    if ($method === 'POST' && $route === 'vehicles') {
        $customer_id = $body['customer_id'] ?? null;
        $vehicle_type = $body['vehicle_type'] ?? '';
        $make = $body['make'] ?? '';
        $model = $body['model'] ?? '';
        $year = $body['year'] ?? '';
        $plate_no = $body['plate_no'] ?? '';

        if (!$customer_id || !$make || !$model || !$plate_no) {
            sendResponse(400, ['error' => 'Missing required fields']);
        }

        $stmt = $pdo->prepare('INSERT INTO VEHICLE (customer_id, vehicle_type, make, model, year, plate_no) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$customer_id, $vehicle_type, $make, $model, $year, $plate_no]);
        sendResponse(201, ['message' => 'Vehicle added successfully']);
    }

    if ($method === 'GET' && $parts[0] === 'policies' && isset($parts[1])) {
        $customer_id = $parts[1];
        $stmt = $pdo->prepare('SELECT p.*, v.make, v.model, v.plate_no FROM POLICY p LEFT JOIN VEHICLE v ON p.car_id = v.car_id WHERE p.customer_id = ?');
        $stmt->execute([$customer_id]);
        $policies = $stmt->fetchAll();
        sendResponse(200, ['policies' => $policies]);
    }

    if ($method === 'POST' && $route === 'policies') {
        $customer_id = $body['customer_id'] ?? null;
        $car_id = $body['car_id'] ?? null;
        $plan_name = $body['plan_name'] ?? '';
        $coverage_type = $body['coverage_type'] ?? '';
        $premium_amount = $body['premium_amount'] ?? 0;
        $billing_cycle = $body['billing_cycle'] ?? '';
        $status = $body['status'] ?? 'Active';

        if (!$customer_id || !$car_id || !$plan_name) {
            sendResponse(400, ['error' => 'Missing required fields']);
        }

        $stmt = $pdo->prepare('INSERT INTO POLICY (customer_id, car_id, plan_name, coverage_type, premium_amount, billing_cycle, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$customer_id, $car_id, $plan_name, $coverage_type, $premium_amount, $billing_cycle, $status]);
        sendResponse(201, ['message' => 'Policy added successfully']);
    }

    if ($method === 'GET' && $parts[0] === 'payments' && isset($parts[1])) {
        $customer_id = $parts[1];
        $stmt = $pdo->prepare('SELECT pay.*, p.plan_name, p.coverage_type FROM PAYMENTS pay LEFT JOIN POLICY p ON pay.policy_id = p.policy_id WHERE pay.customer_id = ? ORDER BY pay.payment_date DESC');
        $stmt->execute([$customer_id]);
        $payments = $stmt->fetchAll();
        sendResponse(200, ['payments' => $payments]);
    }

    if ($method === 'POST' && $route === 'payments') {
        $policy_id = $body['policy_id'] ?? null;
        $amount = $body['amount'] ?? 0;
        $payment_method = $body['payment_method'] ?? '';
        $status = $body['status'] ?? 'Paid';
        $payment_date = $body['payment_date'] ?? date('Y-m-d H:i:s');

        if (!$policy_id) {
            sendResponse(400, ['error' => 'Missing policy_id']);
        }

        // We need customer_id for the PAYMENTS table
        $stmt = $pdo->prepare('SELECT customer_id FROM POLICY WHERE policy_id = ?');
        $stmt->execute([$policy_id]);
        $policy = $stmt->fetch();

        if (!$policy) {
            sendResponse(404, ['error' => 'Policy not found']);
        }

        $customer_id = $policy['customer_id'];

        $stmt = $pdo->prepare('INSERT INTO PAYMENTS (policy_id, customer_id, amount, payment_method, status, payment_date) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$policy_id, $customer_id, $amount, $payment_method, $status, $payment_date]);
        sendResponse(201, ['message' => 'Payment recorded successfully']);
    }

    if ($method === 'POST' && $route === 'claims') {
        $customer_id = $_POST['customer_id'] ?? null;
        $policy_id = $_POST['policy_id'] ?? null;
        $incident_date = $_POST['incident_date'] ?? null;
        $incident_type = $_POST['incident_type'] ?? '';
        $incident_location = $_POST['incident_location'] ?? '';
        $incident_casualty = $_POST['incident_casualty'] ?? '';
        $claim_description = $_POST['claim_description'] ?? '';

        if (!$customer_id || !$policy_id || !$incident_date) {
            sendResponse(400, ['error' => 'Missing required fields']);
        }

        // Get car_id from policy
        $stmt = $pdo->prepare('SELECT car_id FROM POLICY WHERE policy_id = ?');
        $stmt->execute([$policy_id]);
        $policy = $stmt->fetch();
        $car_id = $policy ? $policy['car_id'] : null;

        $record_no = 'INC-' . strtoupper(uniqid());

        // Insert Incident Record
        $stmt = $pdo->prepare('INSERT INTO INCIDENT_RECORD (record_no, car_id, casualty, date_of_in, type, location) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$record_no, $car_id, $incident_casualty, $incident_date, $incident_type, $incident_location]);

        // Insert Claim
        $stmt = $pdo->prepare('INSERT INTO CLAIMS (policy_id, record_no, customer_id, description, date_filed, status) VALUES (?, ?, ?, ?, ?, ?)');
        $date_filed = date('Y-m-d');
        $stmt->execute([$policy_id, $record_no, $customer_id, $claim_description, $date_filed, 'Pending Review']);
        $claim_id = $pdo->lastInsertId();

        // Handle File Uploads
        $upload_dir = 'uploads/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        // Damage Pics
        if (isset($_FILES['damage_pics'])) {
            $file_count = count($_FILES['damage_pics']['name']);
            for ($i = 0; $i < $file_count; $i++) {
                if ($_FILES['damage_pics']['error'][$i] === UPLOAD_ERR_OK) {
                    $file_name = time() . '_' . basename($_FILES['damage_pics']['name'][$i]);
                    $file_path = $upload_dir . $file_name;
                    if (move_uploaded_file($_FILES['damage_pics']['tmp_name'][$i], $file_path)) {
                        $stmt = $pdo->prepare('INSERT INTO UPLOADED_EVIDENCE (claim_id, file_path, file_category) VALUES (?, ?, ?)');
                        $stmt->execute([$claim_id, $file_path, 'Damage Picture']);
                    }
                }
            }
        }

        // Garage Invoices
        if (isset($_FILES['garage_invoices'])) {
            $file_count = count($_FILES['garage_invoices']['name']);
            for ($i = 0; $i < $file_count; $i++) {
                if ($_FILES['garage_invoices']['error'][$i] === UPLOAD_ERR_OK) {
                    $file_name = time() . '_' . basename($_FILES['garage_invoices']['name'][$i]);
                    $file_path = $upload_dir . $file_name;
                    if (move_uploaded_file($_FILES['garage_invoices']['tmp_name'][$i], $file_path)) {
                        $stmt = $pdo->prepare('INSERT INTO UPLOADED_EVIDENCE (claim_id, file_path, file_category) VALUES (?, ?, ?)');
                        $stmt->execute([$claim_id, $file_path, 'Garage Invoice']);
                    }
                }
            }
        }

        sendResponse(201, ['message' => 'Claim filed successfully']);
    }

    if ($method === 'GET' && $parts[0] === 'claims' && isset($parts[1])) {
        $customer_id = $parts[1];
        $stmt = $pdo->prepare('
            SELECT c.*, p.plan_name, ir.type as incident_type, ir.date_of_in as incident_date, v.make, v.model, v.plate_no
            FROM CLAIMS c
            LEFT JOIN POLICY p ON c.policy_id = p.policy_id
            LEFT JOIN VEHICLE v ON p.car_id = v.car_id
            LEFT JOIN INCIDENT_RECORD ir ON c.record_no = ir.record_no
            WHERE c.customer_id = ?
            ORDER BY c.date_filed DESC
        ');
        $stmt->execute([$customer_id]);
        $claims = $stmt->fetchAll();
        sendResponse(200, ['claims' => $claims]);
    }

    sendResponse(404, ['error' => 'Endpoint not found']);

} catch (PDOException $e) {
    sendResponse(500, ['error' => 'Database error', 'details' => $e->getMessage()]);
} catch (Exception $e) {
    sendResponse(500, ['error' => 'Server error', 'details' => $e->getMessage()]);
}
