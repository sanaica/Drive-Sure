<?php
/**
 * DriveSure API
 * Simple PHP + MySQL backend
 * Routes: /auth/register, /auth/login, /admin/login,
 *         /vehicles, /policies, /payments, /claims,
 *         /admin/claims, /admin/claims/update
 */

require_once 'db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$method = $_SERVER['REQUEST_METHOD'];
$route  = isset($_GET['route']) ? rtrim($_GET['route'], '/') : '';
$parts  = explode('/', $route);
$body   = json_decode(file_get_contents('php://input'), true);

function sendResponse($statusCode, $data) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

try {

    // =========================================================
    // CUSTOMER AUTH
    // =========================================================

    // POST /auth/register
    if ($method === 'POST' && $route === 'auth/register') {
        $name     = trim($body['name'] ?? '');
        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        if (!$name || !$email || !$password) {
            sendResponse(400, ['error' => 'Name, email and password are required']);
        }

        $stmt = $pdo->prepare('SELECT customer_id FROM CUSTOMER WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            sendResponse(409, ['error' => 'Email already registered']);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO CUSTOMER (name, email, password) VALUES (?, ?, ?)');
        $stmt->execute([$name, $email, $hash]);

        $id = $pdo->lastInsertId();
        sendResponse(201, [
            'message' => 'Registration successful',
            'user' => [
                'customer_id' => (int)$id,
                'name'  => $name,
                'email' => $email
            ]
        ]);
    }

    // POST /auth/login
    if ($method === 'POST' && $route === 'auth/login') {
        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        if (!$email || !$password) {
            sendResponse(400, ['error' => 'Email and password are required']);
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
        }
        sendResponse(401, ['error' => 'Invalid email or password']);
    }

    // =========================================================
    // ADMIN AUTH
    // =========================================================

    // POST /admin/login
    if ($method === 'POST' && $route === 'admin/login') {
        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        if (!$email || !$password) {
            sendResponse(400, ['error' => 'Email and password are required']);
        }

        $stmt = $pdo->prepare('SELECT * FROM ADMINS WHERE email = ?');
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {
            unset($admin['password']);
            sendResponse(200, [
                'message' => 'Admin login successful',
                'admin' => $admin
            ]);
        }
        sendResponse(401, ['error' => 'Invalid admin credentials']);
    }

    // =========================================================
    // VEHICLES
    // =========================================================

    // GET /vehicles/{customer_id}
    if ($method === 'GET' && $parts[0] === 'vehicles' && isset($parts[1])) {
        $customer_id = $parts[1];
        $stmt = $pdo->prepare('SELECT * FROM VEHICLE WHERE customer_id = ? ORDER BY car_id DESC');
        $stmt->execute([$customer_id]);
        sendResponse(200, ['vehicles' => $stmt->fetchAll()]);
    }

    // POST /vehicles
    if ($method === 'POST' && $route === 'vehicles') {
        $customer_id  = $body['customer_id'] ?? null;
        $vehicle_type = $body['vehicle_type'] ?? '';
        $make         = trim($body['make'] ?? '');
        $model        = trim($body['model'] ?? '');
        $year         = $body['year'] ?? null;
        $plate_no     = trim($body['plate_no'] ?? '');

        if (!$customer_id || !$make || !$model || !$plate_no) {
            sendResponse(400, ['error' => 'Customer, make, model and plate number are required']);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO VEHICLE (customer_id, vehicle_type, make, model, year, plate_no)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$customer_id, $vehicle_type, $make, $model, $year, $plate_no]);
        sendResponse(201, ['message' => 'Vehicle added successfully']);
    }

    // =========================================================
    // POLICIES
    // =========================================================

    // GET /policies/{customer_id}
    if ($method === 'GET' && $parts[0] === 'policies' && isset($parts[1])) {
        $customer_id = $parts[1];
        $stmt = $pdo->prepare(
            'SELECT p.*, v.make, v.model, v.plate_no, v.vehicle_type
             FROM POLICY p
             LEFT JOIN VEHICLE v ON p.car_id = v.car_id
             WHERE p.customer_id = ?
             ORDER BY p.policy_id DESC'
        );
        $stmt->execute([$customer_id]);
        sendResponse(200, ['policies' => $stmt->fetchAll()]);
    }

    // POST /policies
    if ($method === 'POST' && $route === 'policies') {
        $customer_id    = $body['customer_id'] ?? null;
        $car_id         = $body['car_id'] ?? null;
        $plan_name      = $body['plan_name'] ?? '';
        $coverage_type  = $body['coverage_type'] ?? '';
        $premium_amount = $body['premium_amount'] ?? 0;
        $billing_cycle  = $body['billing_cycle'] ?? 'Yearly';
        $status         = $body['status'] ?? 'Active';

        if (!$customer_id || !$car_id || !$plan_name) {
            sendResponse(400, ['error' => 'Customer, vehicle and plan name are required']);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO POLICY (customer_id, car_id, plan_name, coverage_type, premium_amount, billing_cycle, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$customer_id, $car_id, $plan_name, $coverage_type, $premium_amount, $billing_cycle, $status]);
        sendResponse(201, ['message' => 'Policy selected successfully']);
    }

    // =========================================================
    // PAYMENTS
    // =========================================================

    // GET /payments/{customer_id}
    if ($method === 'GET' && $parts[0] === 'payments' && isset($parts[1])) {
        $customer_id = $parts[1];
        $stmt = $pdo->prepare(
            'SELECT pay.*, p.plan_name, p.coverage_type
             FROM PAYMENTS pay
             LEFT JOIN POLICY p ON pay.policy_id = p.policy_id
             WHERE pay.customer_id = ?
             ORDER BY pay.payment_date DESC'
        );
        $stmt->execute([$customer_id]);
        sendResponse(200, ['payments' => $stmt->fetchAll()]);
    }

    // POST /payments
    if ($method === 'POST' && $route === 'payments') {
        $policy_id      = $body['policy_id'] ?? null;
        $amount         = $body['amount'] ?? 0;
        $payment_method = $body['payment_method'] ?? 'Card';
        $status         = $body['status'] ?? 'Paid';
        $payment_date   = $body['payment_date'] ?? date('Y-m-d H:i:s');

        if (!$policy_id) {
            sendResponse(400, ['error' => 'Policy is required']);
        }

        $stmt = $pdo->prepare('SELECT customer_id FROM POLICY WHERE policy_id = ?');
        $stmt->execute([$policy_id]);
        $policy = $stmt->fetch();
        if (!$policy) {
            sendResponse(404, ['error' => 'Policy not found']);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO PAYMENTS (policy_id, customer_id, amount, payment_method, status, payment_date)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$policy_id, $policy['customer_id'], $amount, $payment_method, $status, $payment_date]);
        sendResponse(201, ['message' => 'Payment recorded successfully']);
    }

    // =========================================================
    // CLAIMS (customer side)
    // =========================================================

    // POST /claims  (multipart form-data)
    if ($method === 'POST' && $route === 'claims') {
        $customer_id       = $_POST['customer_id'] ?? null;
        $policy_id         = $_POST['policy_id'] ?? null;
        $incident_date     = $_POST['incident_date'] ?? null;
        $incident_type     = $_POST['incident_type'] ?? '';
        $incident_location = $_POST['incident_location'] ?? '';
        $incident_casualty = $_POST['incident_casualty'] ?? '';
        $claim_description = $_POST['claim_description'] ?? '';

        if (!$customer_id || !$policy_id || !$incident_date) {
            sendResponse(400, ['error' => 'Customer, policy and incident date are required']);
        }

        // Get car from policy
        $stmt = $pdo->prepare('SELECT car_id FROM POLICY WHERE policy_id = ?');
        $stmt->execute([$policy_id]);
        $policy = $stmt->fetch();
        $car_id = $policy ? $policy['car_id'] : null;

        $record_no = 'INC-' . strtoupper(uniqid());

        // Incident
        $stmt = $pdo->prepare(
            'INSERT INTO INCIDENT_RECORD (record_no, car_id, casualty, date_of_in, type, location)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$record_no, $car_id, $incident_casualty, $incident_date, $incident_type, $incident_location]);

        // Claim
        $stmt = $pdo->prepare(
            'INSERT INTO CLAIMS (policy_id, record_no, customer_id, description, date_filed, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $date_filed = date('Y-m-d');
        $stmt->execute([$policy_id, $record_no, $customer_id, $claim_description, $date_filed, 'Pending Review']);
        $claim_id = $pdo->lastInsertId();

        // Upload folder (relative to api/)
        $upload_dir = '../uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        // Damage pictures
        if (isset($_FILES['damage_pics'])) {
            $names = $_FILES['damage_pics']['name'];
            $tmps  = $_FILES['damage_pics']['tmp_name'];
            $errs  = $_FILES['damage_pics']['error'];
            $count = is_array($names) ? count($names) : 0;

            for ($i = 0; $i < $count; $i++) {
                if ($errs[$i] === UPLOAD_ERR_OK) {
                    $safe = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($names[$i]));
                    $path = $upload_dir . $safe;
                    if (move_uploaded_file($tmps[$i], $path)) {
                        $stmt = $pdo->prepare(
                            'INSERT INTO UPLOADED_EVIDENCE (claim_id, file_path, file_category) VALUES (?, ?, ?)'
                        );
                        $stmt->execute([$claim_id, 'uploads/' . $safe, 'Damage Picture']);
                    }
                }
            }
        }

        // Garage invoices
        if (isset($_FILES['garage_invoices'])) {
            $names = $_FILES['garage_invoices']['name'];
            $tmps  = $_FILES['garage_invoices']['tmp_name'];
            $errs  = $_FILES['garage_invoices']['error'];
            $count = is_array($names) ? count($names) : 0;

            for ($i = 0; $i < $count; $i++) {
                if ($errs[$i] === UPLOAD_ERR_OK) {
                    $safe = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($names[$i]));
                    $path = $upload_dir . $safe;
                    if (move_uploaded_file($tmps[$i], $path)) {
                        $stmt = $pdo->prepare(
                            'INSERT INTO UPLOADED_EVIDENCE (claim_id, file_path, file_category) VALUES (?, ?, ?)'
                        );
                        $stmt->execute([$claim_id, 'uploads/' . $safe, 'Garage Invoice']);
                    }
                }
            }
        }

        sendResponse(201, ['message' => 'Claim filed successfully. Status: Pending Review']);
    }

    // GET /claims/{customer_id}
    if ($method === 'GET' && $parts[0] === 'claims' && isset($parts[1]) && $parts[1] !== 'all') {
        $customer_id = $parts[1];
        $stmt = $pdo->prepare(
            'SELECT c.*, p.plan_name,
                    ir.type AS incident_type, ir.date_of_in AS incident_date,
                    v.make, v.model, v.plate_no
             FROM CLAIMS c
             LEFT JOIN POLICY p ON c.policy_id = p.policy_id
             LEFT JOIN VEHICLE v ON p.car_id = v.car_id
             LEFT JOIN INCIDENT_RECORD ir ON c.record_no = ir.record_no
             WHERE c.customer_id = ?
             ORDER BY c.date_filed DESC'
        );
        $stmt->execute([$customer_id]);
        sendResponse(200, ['claims' => $stmt->fetchAll()]);
    }

    // =========================================================
    // ADMIN CLAIMS
    // =========================================================

    // GET /admin/claims  → all claims for admin dashboard (includes uploaded evidence)
    if ($method === 'GET' && $route === 'admin/claims') {
        $stmt = $pdo->query(
            'SELECT c.*, p.plan_name, cu.name AS customer_name, cu.email AS customer_email,
                    ir.type AS incident_type, ir.date_of_in AS incident_date, ir.location AS incident_location,
                    v.make, v.model, v.plate_no
             FROM CLAIMS c
             LEFT JOIN POLICY p ON c.policy_id = p.policy_id
             LEFT JOIN CUSTOMER cu ON c.customer_id = cu.customer_id
             LEFT JOIN VEHICLE v ON p.car_id = v.car_id
             LEFT JOIN INCIDENT_RECORD ir ON c.record_no = ir.record_no
             ORDER BY
                CASE c.status
                    WHEN "Pending Review" THEN 1
                    WHEN "Approved" THEN 2
                    WHEN "Rejected" THEN 3
                    ELSE 4
                END,
                c.date_filed DESC'
        );
        $claims = $stmt->fetchAll();

        // Attach evidence files for each claim
        $evStmt = $pdo->prepare(
            'SELECT image_id, file_path, file_category FROM UPLOADED_EVIDENCE WHERE claim_id = ?'
        );
        foreach ($claims as &$claim) {
            $evStmt->execute([$claim['claim_id']]);
            $claim['evidence'] = $evStmt->fetchAll();
        }
        unset($claim);

        sendResponse(200, ['claims' => $claims]);
    }

    // POST /admin/claims/update  → approve or reject
    if ($method === 'POST' && $route === 'admin/claims/update') {
        $claim_id   = $body['claim_id'] ?? null;
        $status     = $body['status'] ?? '';
        $admin_note = trim($body['admin_note'] ?? '');

        $allowed = ['Approved', 'Rejected', 'Pending Review'];
        if (!$claim_id || !in_array($status, $allowed)) {
            sendResponse(400, ['error' => 'Valid claim_id and status (Approved / Rejected) are required']);
        }

        $stmt = $pdo->prepare('UPDATE CLAIMS SET status = ?, admin_note = ? WHERE claim_id = ?');
        $stmt->execute([$status, $admin_note, $claim_id]);

        if ($stmt->rowCount() === 0) {
            sendResponse(404, ['error' => 'Claim not found']);
        }

        sendResponse(200, ['message' => "Claim marked as $status"]);
    }

    // Fallback
    sendResponse(404, ['error' => 'Endpoint not found', 'route' => $route]);

} catch (PDOException $e) {
    sendResponse(500, ['error' => 'Database error', 'details' => $e->getMessage()]);
} catch (Exception $e) {
    sendResponse(500, ['error' => 'Server error', 'details' => $e->getMessage()]);
}
?>
