<?php

if (!function_exists('ensure_password_reset_table')) {
    function ensure_password_reset_table($conn) {
        $create_table_sql = "
            CREATE TABLE IF NOT EXISTS password_reset_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(255) NOT NULL,
                full_name VARCHAR(255) NOT NULL,
                reason TEXT NULL,
                status ENUM('pending','completed') NOT NULL DEFAULT 'pending',
                admin_id INT NULL,
                temp_password_hash VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_username (username),
                CONSTRAINT fk_password_reset_admin
                    FOREIGN KEY (admin_id) REFERENCES users(user_id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        mysqli_query($conn, $create_table_sql);
    }
}

if (!function_exists('get_pending_password_reset_requests')) {
    function get_pending_password_reset_requests($conn) {
        ensure_password_reset_table($conn);
        $requests = [];
        $result = mysqli_query($conn, "SELECT id, username, full_name, reason, status, created_at FROM password_reset_requests WHERE status = 'pending' ORDER BY created_at DESC");
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $requests[] = $row;
            }
        }
        return $requests;
    }
}

if (!function_exists('has_pending_reset_request')) {
    function has_pending_reset_request($conn, $username) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM password_reset_requests WHERE username = ? AND status = 'pending'");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result && $result['total'] > 0;
    }
}

if (!function_exists('create_password_reset_request')) {
    function create_password_reset_request($conn, $username, $full_name, $reason) {
        ensure_password_reset_table($conn);
        $stmt = $conn->prepare("INSERT INTO password_reset_requests (username, full_name, reason) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $username, $full_name, $reason);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }
}

if (!function_exists('generate_temporary_password')) {
    function generate_temporary_password($length = 8) {
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = 'Temp';
        for ($i = 0; $i < $length - 4; $i++) {
            $password .= $characters[random_int(0, strlen($characters) - 1)];
        }
        return $password;
    }
}


