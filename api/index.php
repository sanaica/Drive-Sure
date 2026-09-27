<?php
/**
 * DriveSure API
 * Simple PHP + MySQL backend
 * Routes: /auth/register, /auth/login, /admin/login,
 *         /vehicles, /policies, /payments, /claims,
 *         /admin/claims, /admin/claims/update
 */

require_once 'db.php';

// Load root .env into environment (GROQ_API_KEY etc.) – simple parser, no Composer needed
(function () {
    $envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    if (!is_readable($envFile)) {
        return;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        list($key, $val) = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val, " \t\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$val");
            $_ENV[$key] = $val;
        }
    }
})();

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

/**
 * Run Python EXIF analyzer on an uploaded file.
 * Returns associative array with confidence_score, notes, etc.
 */
function analyzeEvidenceFile($absolutePath, $incidentDate = null) {
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'analyze_evidence.py';
    if (!file_exists($script)) {
        return [
            'confidence_score' => null,
            'notes' => ['Analyzer script missing']
        ];
    }

    $pythonCandidates = ['python', 'python3', 'py'];
    $cmdBase = null;
    foreach ($pythonCandidates as $bin) {
        // Windows-friendly: just try the name
        $cmdBase = $bin;
        break;
    }

    $pathArg = escapeshellarg($absolutePath);
    $dateArg = $incidentDate ? ' ' . escapeshellarg($incidentDate) : '';
    // Prefer `py -3` on Windows if available
    $commands = [
        "py -3 " . escapeshellarg($script) . " $pathArg$dateArg 2>&1",
        "python " . escapeshellarg($script) . " $pathArg$dateArg 2>&1",
        "python3 " . escapeshellarg($script) . " $pathArg$dateArg 2>&1",
    ];

    $output = null;
    foreach ($commands as $cmd) {
        $output = shell_exec($cmd);
        if ($output && trim($output) !== '') {
            break;
        }
    }

    if (!$output) {
        return [
            'confidence_score' => null,
            'notes' => ['Could not run Python analyzer. Install Python + Pillow (pip install Pillow).']
        ];
    }

    // Find JSON object in output (ignore warnings printed before it)
    $jsonStart = strpos($output, '{');
    if ($jsonStart === false) {
        return [
            'confidence_score' => null,
            'notes' => ['Analyzer returned non-JSON: ' . substr(trim($output), 0, 200)]
        ];
    }

    $decoded = json_decode(substr($output, $jsonStart), true);
    if (!is_array($decoded)) {
        return [
            'confidence_score' => null,
            'notes' => ['Failed to parse analyzer output']
        ];
    }
    return $decoded;
}

/**
 * Free local confidence score for a claim loaded from YOUR MySQL database.
 * Combines plan rules + description detail + average EXIF score from uploads.
 */
function scoreClaimLocal(array $claim, $avgExif = null, array $evidence = []) {
    // Start lower – without verified photo metadata, claims should not look "strong"
    $score = 35;
    $reasons = [];

    $plan = strtolower($claim['plan_name'] ?? '');
    $coverage = strtolower($claim['coverage_type'] ?? '');
    $itype = strtolower($claim['incident_type'] ?? '');
    $premium = (float)($claim['premium_amount'] ?? 0);

    if (strpos($plan, 'third') !== false || strpos($coverage, 'third') !== false) {
        if (in_array($itype, ['theft', 'weather', 'vandalism', 'other'], true)) {
            $score -= 20;
            $reasons[] = 'Third-party style cover may not pay own-damage / theft of own vehicle';
        } else {
            $score -= 6;
            $reasons[] = 'Limited cover plan – verify OD eligibility';
        }
    } elseif (strpos($plan, 'comprehensive') !== false || strpos($plan, 'fleet') !== false
        || strpos($coverage, 'comprehensive') !== false || strpos($coverage, 'fleet') !== false) {
        $score += 12;
        $reasons[] = 'Comprehensive / fleet plan supports own-damage style claims';
    }

    if ($itype === 'collision') {
        $score += 6;
        $reasons[] = 'Collision is a common covered peril';
    } elseif ($itype === 'theft') {
        $score -= 8;
        $reasons[] = 'Theft claims usually need FIR – verify documents';
    }

    $wordCount = str_word_count($claim['description'] ?? '');
    if ($wordCount >= 6) {
        $score += 4;
        $reasons[] = 'Claim description has reasonable detail';
    } else {
        $score -= 8;
        $reasons[] = 'Description is thin – may need more surveyor notes';
    }

    if (!empty($claim['incident_location']) && strlen($claim['incident_location']) > 4) {
        $score += 4;
        $reasons[] = 'Incident location provided';
    } else {
        $score -= 8;
        $reasons[] = 'Location missing or vague';
    }

    // EXIF is a major factor – missing metadata should keep score modest
    if ($avgExif !== null) {
        $score = (int)round(0.40 * $score + 0.60 * $avgExif);
        $reasons[] = "Average photo EXIF confidence: {$avgExif}/100";
        if ($avgExif < 40) {
            $score -= 10;
            $reasons[] = 'Low EXIF confidence – photos may be screenshots or edited';
        } elseif ($avgExif >= 70) {
            $reasons[] = 'Photo metadata looks consistent with a real capture';
        }
    } else {
        $score -= 22;
        $reasons[] = 'No EXIF verification on uploaded files – confidence reduced until analyzer runs on new photos';
    }

    if (count($evidence) === 0) {
        $score -= 12;
        $reasons[] = 'No uploaded evidence on this claim';
    } else {
        $score += 3;
        $reasons[] = count($evidence) . ' evidence file(s) attached';
    }

    $score = max(0, min(100, (int)$score));

    if ($score >= 75) {
        $suggested = 'Accepted';
    } elseif ($score <= 45) {
        $suggested = 'Denied';
    } else {
        $suggested = 'Review';
    }

    return [
        'confidence_score' => $score,
        'suggested_status' => $suggested,
        'reasons' => $reasons,
        'source' => 'local',
        'avg_exif_score' => $avgExif,
        'plan_name' => $claim['plan_name'] ?? null,
        'incident_type' => $claim['incident_type'] ?? null,
    ];
}

/**
 * Full Groq score: claim + customer + vehicle + EXIF + RAG past claims + vision on photos.
 */
function loadRagSimilarClaims(array $claim, $limit = 5) {
    $csv = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'claims_india_synthetic.csv';
    if (!is_readable($csv)) {
        return [];
    }
    $fh = fopen($csv, 'r');
    if (!$fh) {
        return [];
    }
    $header = fgetcsv($fh);
    if (!$header) {
        fclose($fh);
        return [];
    }
    $itype = strtolower($claim['incident_type'] ?? '');
    $vtype = strtolower($claim['vehicle_type'] ?? '');
    $plan = strtolower($claim['plan_name'] ?? '');
    $scored = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) < count($header)) {
            continue;
        }
        $r = array_combine($header, $row);
        if (!$r) {
            continue;
        }
        $s = 0;
        if ($itype && strtolower($r['incident_type'] ?? '') === $itype) {
            $s += 3;
        }
        if ($vtype && strtolower($r['vehicle_type'] ?? '') === $vtype) {
            $s += 2;
        }
        if ($plan && strpos(strtolower($r['policy_plan'] ?? ''), explode(' ', $plan)[0]) !== false) {
            $s += 1;
        }
        if ($s > 0) {
            $scored[] = ['score' => $s, 'row' => $r];
        }
    }
    fclose($fh);
    usort($scored, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });
    $out = [];
    foreach (array_slice($scored, 0, $limit) as $item) {
        $r = $item['row'];
        $out[] = sprintf(
            '[%s] %s %s %s | %s on %s at %s | damage: %s | invoice Rs.%s | premium Rs.%s | %s – %s',
            $r['claim_id'] ?? '',
            $r['vehicle_type'] ?? '',
            $r['make'] ?? '',
            $r['model'] ?? '',
            $r['incident_type'] ?? '',
            $r['incident_date'] ?? '',
            $r['location'] ?? '',
            $r['damage_done'] ?? '',
            $r['garage_invoice_amount'] ?? '',
            $r['policy_premium'] ?? '',
            $r['claim_status'] ?? '',
            $r['decision_reason'] ?? ''
        );
    }
    return $out;
}

function evidenceImageDataUrls(array $evidenceRows, $maxImages = 3) {
    $root = dirname(__DIR__);
    $urls = [];
    // Prefer one damage + one invoice
    $ordered = [];
    foreach ($evidenceRows as $e) {
        $cat = strtolower($e['file_category'] ?? '');
        if (strpos($cat, 'damage') !== false || strpos($cat, 'photo') !== false) {
            array_unshift($ordered, $e);
        } else {
            $ordered[] = $e;
        }
    }
    foreach ($ordered as $e) {
        if (count($urls) >= $maxImages) {
            break;
        }
        $rel = $e['file_path'] ?? '';
        if ($rel === '') {
            continue;
        }
        // file_path may be uploads/xxx or ../uploads/xxx
        $rel = ltrim(str_replace(['\\', '../'], ['/', ''], $rel), '/');
        $abs = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_readable($abs)) {
            // try uploads/basename
            $abs = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . basename($rel);
        }
        if (!is_readable($abs)) {
            continue;
        }
        $size = filesize($abs);
        if ($size === false || $size > 4 * 1024 * 1024) {
            // skip huge files for API payload
            continue;
        }
        $bin = file_get_contents($abs);
        if ($bin === false) {
            continue;
        }
        $mime = 'image/jpeg';
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        if ($ext === 'png') {
            $mime = 'image/png';
        } elseif ($ext === 'webp') {
            $mime = 'image/webp';
        } elseif ($ext === 'gif') {
            $mime = 'image/gif';
        }
        $urls[] = [
            'category' => $e['file_category'] ?? 'evidence',
            'data_url' => 'data:' . $mime . ';base64,' . base64_encode($bin),
            'exif_score' => $e['confidence_score'] ?? null,
            'notes' => $e['analysis_notes'] ?? '',
        ];
    }
    return $urls;
}

function scoreClaimGroq(array $claim, $avgExif = null, array $evidence = [], array $ragSnippets = [], array $imagePayloads = []) {
    if (!function_exists('curl_init')) {
        return ['error' => 'PHP curl extension is not enabled in XAMPP. Enable extension=curl in php.ini'];
    }

    $apiKey = getenv('GROQ_API_KEY') ?: ($_ENV['GROQ_API_KEY'] ?? '');
    $apiKey = trim($apiKey);
    if ($apiKey === '' || strpos($apiKey, 'gsk_your') === 0) {
        return ['error' => 'GROQ_API_KEY missing or still placeholder. Edit Drive-Sure/.env with your real gsk_ key.'];
    }

    $textModel = trim(getenv('GROQ_MODEL') ?: ($_ENV['GROQ_MODEL'] ?? 'openai/gpt-oss-20b')) ?: 'openai/gpt-oss-20b';
    $visionModel = trim(getenv('GROQ_VISION_MODEL') ?: ($_ENV['GROQ_VISION_MODEL'] ?? 'qwen/qwen3.8-27b')) ?: 'qwen/qwen3.8-27b';

    $exifBlock = "Average EXIF confidence: " . ($avgExif === null ? 'N/A (not analyzed yet)' : $avgExif . '/100') . "\n";
    foreach ($evidence as $e) {
        $exifBlock .= sprintf(
            "- file=%s category=%s exif_score=%s notes=%s\n",
            basename($e['file_path'] ?? ''),
            $e['file_category'] ?? '',
            $e['confidence_score'] ?? 'N/A',
            $e['analysis_notes'] ?? ''
        );
    }

    $ragBlock = count($ragSnippets)
        ? implode("\n", array_map(function ($s, $i) {
            return ($i + 1) . '. ' . $s;
        }, $ragSnippets, array_keys($ragSnippets)))
        : 'No similar past claims found in synthetic RAG dataset.';

    $textPrompt = "You are an Indian motor insurance claims adjudicator.\n"
        . "Use ALL of the following: customer/vehicle/policy facts, EXIF metadata scores, "
        . "similar past claims (RAG), and any images attached (damage + garage bill).\n"
        . "Return ONLY a JSON object with keys:\n"
        . "  confidence_score (integer 0-100),\n"
        . "  suggested_status (Accepted|Denied|Review),\n"
        . "  reasons (array of short strings),\n"
        . "  vision_summary (short string about what you see in photos/bill, or 'no images').\n\n"
        . "=== CURRENT CLAIM (from live database) ===\n"
        . "Claim ID: " . ($claim['claim_id'] ?? '') . "\n"
        . "Customer: " . ($claim['customer_name'] ?? '') . " <" . ($claim['customer_email'] ?? '') . ">\n"
        . "Vehicle: " . trim(($claim['vehicle_type'] ?? '') . ' ' . ($claim['make'] ?? '') . ' ' . ($claim['model'] ?? '') . ' ' . ($claim['year'] ?? '')) . " plate " . ($claim['plate_no'] ?? '') . "\n"
        . "Plan: " . ($claim['plan_name'] ?? '') . " | coverage: " . ($claim['coverage_type'] ?? '') . " | premium Rs: " . ($claim['premium_amount'] ?? '') . "\n"
        . "Incident type: " . ($claim['incident_type'] ?? '') . " | date: " . ($claim['incident_date'] ?? '') . "\n"
        . "Location: " . ($claim['incident_location'] ?? '') . "\n"
        . "Description: " . ($claim['description'] ?? '') . "\n"
        . "Status on file: " . ($claim['status'] ?? '') . " | filed: " . ($claim['date_filed'] ?? '') . "\n\n"
        . "=== EXIF / PHOTO METADATA ===\n" . $exifBlock . "\n"
        . "=== SIMILAR PAST CLAIMS (RAG) ===\n" . $ragBlock . "\n\n"
        . "Be conservative. Prefer Review when photos/EXIF are missing or inconsistent with the story.";

    $useVision = count($imagePayloads) > 0;
    $model = $useVision ? $visionModel : $textModel;

    if ($useVision) {
        $content = [['type' => 'text', 'text' => $textPrompt]];
        foreach ($imagePayloads as $img) {
            $content[] = [
                'type' => 'text',
                'text' => 'Image category: ' . ($img['category'] ?? 'evidence')
                    . ' | EXIF score: ' . ($img['exif_score'] ?? 'N/A'),
            ];
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $img['data_url']],
            ];
        }
        $messages = [
            ['role' => 'system', 'content' => 'You output only valid JSON objects. Never use markdown fences.'],
            ['role' => 'user', 'content' => $content],
        ];
    } else {
        $messages = [
            ['role' => 'system', 'content' => 'You output only valid JSON objects. Never use markdown fences.'],
            ['role' => 'user', 'content' => $textPrompt],
        ];
    }

    $payloadArr = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => 0.1,
        'max_tokens' => 800,
        'response_format' => ['type' => 'json_object'],
    ];
    $payload = json_encode($payloadArr);

    $call = function ($payloadJson) use ($apiKey) {
        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
        ]);
        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [$raw, $http, $err];
    };

    list($raw, $http, $err) = $call($payload);

    // If vision model fails (404 etc.), retry text-only with same prompt
    if ($useVision && ($raw === false || $http >= 400)) {
        $payloadArr['model'] = $textModel;
        $payloadArr['messages'] = [
            ['role' => 'system', 'content' => 'You output only valid JSON objects. Never use markdown fences.'],
            ['role' => 'user', 'content' => $textPrompt . "\n\n(Note: images could not be sent; rely on EXIF notes and RAG.)"],
        ];
        list($raw, $http, $err) = $call(json_encode($payloadArr));
        $useVision = false;
        $model = $textModel;
    }

    if ($raw === false) {
        return ['error' => 'Groq network error: ' . $err];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['error' => 'Groq HTTP ' . $http . ' – body not JSON', 'raw' => substr($raw, 0, 400)];
    }
    if (isset($data['error'])) {
        $msg = is_array($data['error'])
            ? ($data['error']['message'] ?? json_encode($data['error']))
            : (string)$data['error'];
        return ['error' => 'Groq API error (HTTP ' . $http . '): ' . $msg];
    }

    $text = $data['choices'][0]['message']['content'] ?? '';
    if ($text === '') {
        return ['error' => 'Groq returned empty content (HTTP ' . $http . ')', 'raw' => substr($raw, 0, 400)];
    }

    $clean = trim($text);
    $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
    $clean = preg_replace('/\s*```$/', '', $clean);
    $clean = trim($clean);
    $startJ = strpos($clean, '{');
    $endJ = strrpos($clean, '}');
    if ($startJ === false || $endJ === false || $endJ <= $startJ) {
        return ['error' => 'Groq content had no JSON object', 'raw' => substr($clean, 0, 400)];
    }
    $parsed = json_decode(substr($clean, $startJ, $endJ - $startJ + 1), true);
    if (!is_array($parsed)) {
        return ['error' => 'Could not parse model JSON', 'raw' => substr($clean, 0, 400)];
    }

    if (!isset($parsed['confidence_score']) && isset($parsed['score'])) {
        $parsed['confidence_score'] = $parsed['score'];
    }
    if (isset($parsed['confidence_score'])) {
        $parsed['confidence_score'] = (int)$parsed['confidence_score'];
    }
    if (!isset($parsed['reasons']) || !is_array($parsed['reasons'])) {
        $parsed['reasons'] = isset($parsed['reason']) ? [(string)$parsed['reason']] : ['No detailed reasons returned'];
    }

    $parsed['source'] = 'groq';
    $parsed['model_used'] = $model;
    $parsed['vision_used'] = $useVision && count($imagePayloads) > 0;
    $parsed['rag_count'] = count($ragSnippets);
    $parsed['images_sent'] = count($imagePayloads);
    $parsed['avg_exif_score'] = $avgExif;
    return $parsed;
}

function saveEvidenceWithAnalysis($pdo, $claimId, $relativePath, $category, $absolutePath, $incidentDate) {
    $analysis = analyzeEvidenceFile($absolutePath, $incidentDate);

    $score   = isset($analysis['confidence_score']) ? (int)$analysis['confidence_score'] : null;
    $blurry  = array_key_exists('is_blurry', $analysis) && $analysis['is_blurry'] !== null
        ? ($analysis['is_blurry'] ? 1 : 0) : null;
    $hasExif = array_key_exists('has_exif', $analysis) && $analysis['has_exif'] !== null
        ? ($analysis['has_exif'] ? 1 : 0) : null;
    $make    = $analysis['camera_make'] ?? null;
    $model   = $analysis['camera_model'] ?? null;
    $soft    = $analysis['software'] ?? null;
    $ts      = $analysis['exif_timestamp'] ?? null;
    $lat     = $analysis['exif_latitude'] ?? null;
    $lon     = $analysis['exif_longitude'] ?? null;
    $notes   = isset($analysis['notes']) && is_array($analysis['notes'])
        ? implode('; ', $analysis['notes'])
        : ($analysis['notes'] ?? null);

    $stmt = $pdo->prepare(
        'INSERT INTO UPLOADED_EVIDENCE
         (claim_id, file_path, file_category, confidence_score, is_blurry, has_exif,
          camera_make, camera_model, software, exif_timestamp, exif_latitude, exif_longitude, analysis_notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $claimId, $relativePath, $category, $score, $blurry, $hasExif,
        $make, $model, $soft, $ts, $lat, $lon, $notes
    ]);
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
                        $abs = realpath($path) ?: $path;
                        saveEvidenceWithAnalysis($pdo, $claim_id, 'uploads/' . $safe, 'Damage Picture', $abs, $incident_date);
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
                        $abs = realpath($path) ?: $path;
                        saveEvidenceWithAnalysis($pdo, $claim_id, 'uploads/' . $safe, 'Garage Invoice', $abs, $incident_date);
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
            'SELECT c.*, p.plan_name, p.premium_amount, p.coverage_type,
                    cu.name AS customer_name, cu.email AS customer_email,
                    ir.type AS incident_type, ir.date_of_in AS incident_date, ir.location AS incident_location,
                    v.make, v.model, v.plate_no, v.vehicle_type, v.year
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
            'SELECT image_id, file_path, file_category, confidence_score, is_blurry, has_exif,
                    camera_make, camera_model, software, exif_timestamp, exif_latitude, exif_longitude, analysis_notes
             FROM UPLOADED_EVIDENCE WHERE claim_id = ?'
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

    // POST /admin/claims/score  → on-demand confidence (admin only, when confused)
    // Uses claim_id from YOUR live database. Free local rules by default.
    // Optional: set use_groq=true + GROQ_API_KEY in server env for AI opinion.
    if ($method === 'POST' && $route === 'admin/claims/score') {
        $claim_id = $body['claim_id'] ?? null;
        $use_groq = !empty($body['use_groq']);

        if (!$claim_id) {
            sendResponse(400, ['error' => 'claim_id is required']);
        }

        $stmt = $pdo->prepare(
            'SELECT c.claim_id, c.description, c.date_filed, c.status, c.customer_id,
                    p.plan_name, p.premium_amount, p.coverage_type,
                    cu.name AS customer_name, cu.email AS customer_email,
                    ir.type AS incident_type, ir.date_of_in AS incident_date, ir.location AS incident_location,
                    v.vehicle_type, v.make, v.model, v.year, v.plate_no
             FROM CLAIMS c
             LEFT JOIN POLICY p ON c.policy_id = p.policy_id
             LEFT JOIN CUSTOMER cu ON c.customer_id = cu.customer_id
             LEFT JOIN VEHICLE v ON p.car_id = v.car_id
             LEFT JOIN INCIDENT_RECORD ir ON c.record_no = ir.record_no
             WHERE c.claim_id = ?'
        );
        $stmt->execute([$claim_id]);
        $claim = $stmt->fetch();
        if (!$claim) {
            sendResponse(404, ['error' => 'Claim not found in database']);
        }

        // Full evidence rows (paths + EXIF) for RAG/vision scoring
        $evStmt = $pdo->prepare(
            'SELECT image_id, file_path, file_category, confidence_score, is_blurry, has_exif,
                    camera_make, camera_model, software, exif_timestamp, analysis_notes
             FROM UPLOADED_EVIDENCE WHERE claim_id = ?'
        );
        $evStmt->execute([$claim_id]);
        $evidence = $evStmt->fetchAll();
        $exifScores = array_values(array_filter(array_map(function ($e) {
            return $e['confidence_score'] !== null ? (int)$e['confidence_score'] : null;
        }, $evidence), function ($v) {
            return $v !== null;
        }));
        $avgExif = count($exifScores) ? (int)round(array_sum($exifScores) / count($exifScores)) : null;

        $result = scoreClaimLocal($claim, $avgExif, $evidence);

        if ($use_groq) {
            $rag = loadRagSimilarClaims($claim, 5);
            $images = evidenceImageDataUrls($evidence, 3);
            $groq = scoreClaimGroq($claim, $avgExif, $evidence, $rag, $images);
            if (empty($groq['error'])) {
                $result = $groq;
            } else {
                $result['groq_error'] = $groq['error'];
                if (!empty($groq['raw'])) {
                    $result['groq_raw'] = $groq['raw'];
                }
                $result['note'] = 'Fell back to local score because Groq failed or is not configured.';
            }
        }

        $result['claim_id'] = (int)$claim_id;
        $result['from_database'] = true;
        sendResponse(200, $result);
    }

    // Fallback
    sendResponse(404, ['error' => 'Endpoint not found', 'route' => $route]);

} catch (PDOException $e) {
    sendResponse(500, ['error' => 'Database error', 'details' => $e->getMessage()]);
} catch (Exception $e) {
    sendResponse(500, ['error' => 'Server error', 'details' => $e->getMessage()]);
}
?>
