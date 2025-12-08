<?php
require_once '../config/database.php';

// Start transaction
mysqli_begin_transaction($conn);

try {
    // Array of default sections
    $sections = [
        ['A', 'Garden Section', 'Located near the main entrance with beautiful landscaping'],
        ['B', 'Memorial Section', 'Dedicated to veterans and community leaders'],
        ['C', 'Family Section', 'Spacious plots for family burials'],
        ['D', 'Children\'s Section', 'Peaceful area dedicated to children'],
        ['E', 'Historical Section', 'Contains graves from the early 1900s']
    ];

    // Insert each section
    $query = "INSERT INTO sections (section_code, section_name, description) VALUES (?, ?, ?)";
    $stmt = mysqli_prepare($conn, $query);

    foreach ($sections as $section) {
        mysqli_stmt_bind_param($stmt, "sss", $section[0], $section[1], $section[2]);
        mysqli_stmt_execute($stmt);
    }

    // Commit transaction
    mysqli_commit($conn);
    echo "Default sections added successfully!";

} catch (Exception $e) {
    // Rollback transaction on error
    mysqli_rollback($conn);
    echo "Failed to add default sections: " . $e->getMessage();
}
?> 