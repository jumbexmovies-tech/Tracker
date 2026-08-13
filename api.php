<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");

// Database credentials (Aiven MySQL)
$host = "mysql-37102bb9-jumbexmovies-e846.a.aivencloud.com";
$port = "11439";
$db   = "defaultdb";
$user = "avnadmin";
$pass = "AVNS_xB_rWXODZXqHcBO0OIu";

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::MYSQL_ATTR_SSL_CA => null, // see note below if you download Aiven's CA cert
        ]
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $action = $_GET['action'] ?? '';
    $json = file_get_contents('php://input');
    $input = json_decode($json, true);

    // --- SIGNUP ---
    if ($action === 'signup') {
        $u = $input['username'] ?? '';
        $p = password_hash($input['password'] ?? '', PASSWORD_DEFAULT);
        if ($u && $input['password']) {
            $stmt = $pdo->prepare("INSERT INTO users (username, password, balance) VALUES (?, ?, 0)");
            $stmt->execute([$u, $p]);
            echo json_encode(["success" => true]);
        }
    }

    // --- CHECK USERNAME (used by the login screen to reveal the password field) ---
    elseif ($action === 'check_username') {
        $u = $_GET['username'] ?? '';
        if ($u === '') {
            echo json_encode(["success" => true, "exists" => false]);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$u]);
            echo json_encode(["success" => true, "exists" => (bool) $stmt->fetch(PDO::FETCH_ASSOC)]);
        }
    }

    // --- LOGIN ---
    elseif ($action === 'login') {
        $u = $input['username'] ?? '';
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$u]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($input['password'], $user['password'])) {
            echo json_encode([
                "success" => true,
                "user" => ["id" => $user['id'], "username" => $user['username']]
            ]);
        } else {
            echo json_encode(["success" => false, "error" => "Invalid credentials"]);
        }
    }

    // --- SYNC ---
    elseif ($action === 'sync') {
        $user_id = $_GET['user_id'];
        $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT 50");
        $stmt->execute([$user_id]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            "success" => true, 
            "balance" => $userData['balance'] ?? 0, 
            "logs" => $logs
        ]);
    }

    // --- ADD TRANSACTION (WITH CUSTOM DATE) ---
    elseif ($action === 'add') {
        $user_id = $input['user_id'];
        $type = $input['type']; 
        $amt = floatval($input['amount']);
        $note = $input['note'] ?? 'Transaction';
        
        // Capture custom date, default to current time if missing
        $date = $input['date'] ?? date('Y-m-d H:i:s');
        if (strlen($date) === 10) {
            $date .= ' 00:00:00'; // Format HTML date picker for SQL DATETIME
        }

        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, type, amount, note, date_stamp) VALUES (?, ?, ?, ?, ?)");
        if ($stmt->execute([$user_id, $type, $amt, $note, $date])) {
            $math = ($type === 'earn' || $type === 'gain') ? "balance + ?" : "balance - ?";
            $stmt = $pdo->prepare("UPDATE users SET balance = $math WHERE id = ?");
            $stmt->execute([$amt, $user_id]);
            echo json_encode(["success" => true]);
        }
    }

    // --- EDIT TRANSACTION (note, amount, and/or date) ---
    elseif ($action === 'edit') {
        $id = $input['id'];
        $user_id = $input['user_id'] ?? null;

        // Load the existing record so we can recompute the user's balance if the amount changed
        $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmt->execute([$id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $note = array_key_exists('note', $input) ? $input['note'] : $existing['note'];
            $newAmount = (isset($input['amount']) && $input['amount'] !== '') ? floatval($input['amount']) : floatval($existing['amount']);
            $newDate = $input['date'] ?? $existing['date_stamp'];
            if (strlen($newDate) === 10) {
                $newDate .= ' 00:00:00'; // Format HTML date picker for SQL DATETIME
            }

            $stmt = $pdo->prepare("UPDATE transactions SET note = ?, amount = ?, date_stamp = ? WHERE id = ?");
            $stmt->execute([$note, $newAmount, $newDate, $id]);

            $oldAmount = floatval($existing['amount']);
            $diff = $newAmount - $oldAmount;
            if ($diff != 0 && $user_id) {
                $math = ($existing['type'] === 'earn' || $existing['type'] === 'gain') ? "balance + ?" : "balance - ?";
                $pdo->prepare("UPDATE users SET balance = $math WHERE id = ?")->execute([$diff, $user_id]);
            }
            echo json_encode(["success" => true]);
        } else {
            echo json_encode(["success" => false, "error" => "Record not found"]);
        }
    }

    // --- DELETE TRANSACTION ---
    elseif ($action === 'delete') {
        $id = $input['id'];
        $user_id = $input['user_id'];
        $stmt = $pdo->prepare("SELECT amount, type FROM transactions WHERE id = ?");
        $stmt->execute([$id]);
        $t = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($t) {
            $math = ($t['type'] === 'earn' || $t['type'] === 'gain') ? "balance - ?" : "balance + ?";
            $pdo->prepare("UPDATE users SET balance = $math WHERE id = ?")->execute([$t['amount'], $user_id]);
            $pdo->prepare("DELETE FROM transactions WHERE id = ?")->execute([$id]);
            echo json_encode(["success" => true]);
        }
    }

    // --- UPDATE PROFILE ---
    elseif ($action === 'update_profile') {
        $user_id = $input['user_id'];
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';

        if (!empty($password)) {
            $p = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET username = ?, password = ? WHERE id = ?");
            $stmt->execute([$username, $p, $user_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
            $stmt->execute([$username, $user_id]);
        }
        echo json_encode(["success" => true]);
    }

    // --- DELETE FULL ACCOUNT ---
    elseif ($action === 'delete_account') {
        $user_id = $input['user_id'] ?? null;
        if ($user_id) {
            $pdo->beginTransaction();
            try {
                $stmt1 = $pdo->prepare("DELETE FROM transactions WHERE user_id = ?");
                $stmt1->execute([$user_id]);
                $stmt2 = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt2->execute([$user_id]);
                
                $pdo->commit();
                echo json_encode(["success" => true]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(["success" => false, "error" => "Cleanup failed"]);
            }
        }
    }

} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
