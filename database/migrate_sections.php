<?php
require_once '../config/database.php';

// Start transaction
mysqli_begin_transaction($conn);

try {
    // Check if section_id column already exists
    $check_column = "SHOW COLUMNS FROM plots LIKE 'section_id'";
    $column_exists = mysqli_query($conn, $check_column);
    
    if (mysqli_num_rows($column_exists) == 0) {
        // 1. Add section_id column to plots table
        $query = "ALTER TABLE plots ADD COLUMN section_id INT AFTER plot_id";
        mysqli_query($conn, $query);

        // 2. Insert a default section
        $query = "INSERT INTO sections (section_code, section_name) VALUES ('A', 'Section A')";
        mysqli_query($conn, $query);
        $default_section_id = mysqli_insert_id($conn);

        // 3. Set all plots to the default section
        $query = "UPDATE plots SET section_id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $default_section_id);
        mysqli_stmt_execute($stmt);

        // 4. Make section_id NOT NULL
        $query = "ALTER TABLE plots MODIFY section_id INT NOT NULL";
        mysqli_query($conn, $query);

        // 5. Add foreign key constraint
        $query = "ALTER TABLE plots 
                  ADD CONSTRAINT fk_section 
                  FOREIGN KEY (section_id) 
                  REFERENCES sections(section_id)";
        mysqli_query($conn, $query);
    }

    // Commit transaction
    mysqli_commit($conn);
    echo "Migration completed successfully!";

} catch (Exception $e) {
    // Rollback transaction on error
    mysqli_rollback($conn);
    echo "Migration failed: " . $e->getMessage();
}
?> 