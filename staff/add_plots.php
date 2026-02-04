<?php
require_once '../includes/auth_check.php';
if ($_SESSION['role'] !== 'staff') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';

// For sidebar active state
$current_page = basename($_SERVER['PHP_SELF']);

// Initialize message variables from session (for PRG pattern)
$success_message = isset($_SESSION['add_plot_success']) ? $_SESSION['add_plot_success'] : '';
$error_message = isset($_SESSION['add_plot_error']) ? $_SESSION['add_plot_error'] : '';
$duplicate_message = isset($_SESSION['add_plot_duplicate']) ? $_SESSION['add_plot_duplicate'] : '';
$delete_success_message = isset($_SESSION['add_plot_delete_success']) ? $_SESSION['add_plot_delete_success'] : '';

// Clear session messages after reading
unset($_SESSION['add_plot_success']);
unset($_SESSION['add_plot_error']);
unset($_SESSION['add_plot_duplicate']);
unset($_SESSION['add_plot_delete_success']);

// Fetch all plot sections for dropdowns / management actions
// Only include sections that have plots with valid coordinates on the map
$all_sections = [];
$sections_query = "SELECT DISTINCT s.section_id, s.section_name 
                   FROM sections s 
                   INNER JOIN plots p ON s.section_id = p.section_id 
                   WHERE p.latitude IS NOT NULL 
                     AND p.longitude IS NOT NULL 
                     AND p.latitude != 0 
                     AND p.longitude != 0 
                   ORDER BY s.section_name";
if ($sections_result = mysqli_query($conn, $sections_query)) {
    while ($row = mysqli_fetch_assoc($sections_result)) {
        $all_sections[] = $row;
    }
}

// Handle individual plot creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'individual') {
    // Debug: Log received POST data
    error_log('Individual plot POST received - Section: ' . ($_POST['section'] ?? 'N/A') . ', Row: ' . ($_POST['row_number'] ?? 'N/A') . ', Plot: ' . ($_POST['plot_number'] ?? 'N/A') . ', Lat: ' . ($_POST['lat'] ?? 'N/A') . ', Lng: ' . ($_POST['lng'] ?? 'N/A'));
    
    $required_fields = ['section', 'row_number', 'plot_number', 'lat', 'lng'];
    $missing_fields = [];
    
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || $_POST[$field] === '') {
            $missing_fields[] = $field;
        }
    }
    
    if (empty($missing_fields)) {
        $section_name = mysqli_real_escape_string($conn, $_POST['section']);
        $row_number = intval($_POST['row_number']);
        $plot_number = mysqli_real_escape_string($conn, $_POST['plot_number']);
        $lat = floatval($_POST['lat']);
        $lng = floatval($_POST['lng']);
        $status = 'available';
        
        // Validate coordinates are set and valid
        if (empty($_POST['lat']) || empty($_POST['lng']) || trim($_POST['lat']) === '' || trim($_POST['lng']) === '') {
            $_SESSION['add_plot_error'] = "Please click on the map to set plot location before adding.";
            header('Location: add_plots.php');
            exit();
        }
        
        // Validate coordinates are valid numbers and not zero
        if ($lat == 0 || $lng == 0 || !is_numeric($lat) || !is_numeric($lng)) {
            $_SESSION['add_plot_error'] = "Invalid coordinates. Please click on the map to set plot location.";
            error_log("Invalid coordinates attempted - Lat: " . $_POST['lat'] . ", Lng: " . $_POST['lng']);
            header('Location: add_plots.php');
            exit();
        }
        
        // Multi-level support
        $enable_multi_level = isset($_POST['enable_multi_level']) && $_POST['enable_multi_level'] === '1' ? 1 : 0;
        $caskets_per_plot = isset($_POST['caskets_per_plot']) ? intval($_POST['caskets_per_plot']) : 1;
        if ($caskets_per_plot < 1) $caskets_per_plot = 1;
        if ($caskets_per_plot > 10) $caskets_per_plot = 10;
        
        // Get or create section
        $section_query = "SELECT section_id, section_code FROM sections WHERE section_name = ?";
        $stmt = mysqli_prepare($conn, $section_query);
        mysqli_stmt_bind_param($stmt, "s", $section_name);
        mysqli_stmt_execute($stmt);
        $section_result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($section_result) === 0) {
            // Create section
            $section_code = strtoupper(substr($section_name, 0, 3));
            $code_check_query = "SELECT section_code FROM sections WHERE section_code = ?";
            $stmt = mysqli_prepare($conn, $code_check_query);
            mysqli_stmt_bind_param($stmt, "s", $section_code);
            mysqli_stmt_execute($stmt);
            $code_result = mysqli_stmt_get_result($stmt);
            
            $counter = 1;
            $original_code = $section_code;
            while (mysqli_num_rows($code_result) > 0) {
                $section_code = $original_code . $counter;
                $stmt = mysqli_prepare($conn, $code_check_query);
                mysqli_stmt_bind_param($stmt, "s", $section_code);
                mysqli_stmt_execute($stmt);
                $code_result = mysqli_stmt_get_result($stmt);
                $counter++;
            }
            
            $description = "Auto-created section: " . $section_name;
            $create_section_query = "INSERT INTO sections (section_code, section_name, description) VALUES (?, ?, ?)";
            $stmt = mysqli_prepare($conn, $create_section_query);
            mysqli_stmt_bind_param($stmt, "sss", $section_code, $section_name, $description);
            mysqli_stmt_execute($stmt);
            $section_id = mysqli_insert_id($conn);
        } else {
            $section_data = mysqli_fetch_assoc($section_result);
            $section_id = $section_data['section_id'];
        }
        
        if (isset($section_id) && $section_id > 0) {
            $success_count = 0;
            $error_count = 0;
            
            if ($enable_multi_level && $caskets_per_plot > 1) {
                // Create multiple levels
                $is_multi_level = 1;
                $stored_max_levels = $caskets_per_plot;
                
                for ($level = 1; $level <= $caskets_per_plot; $level++) {
                    $level_check_query = "SELECT plot_id FROM plots WHERE section_id = ? AND `row_number` = ? AND plot_number = ? AND level_number = ?";
                    $stmt = mysqli_prepare($conn, $level_check_query);
                    mysqli_stmt_bind_param($stmt, "iisi", $section_id, $row_number, $plot_number, $level);
                    mysqli_stmt_execute($stmt);
                    $level_check_result = mysqli_stmt_get_result($stmt);
                    
                    if (mysqli_num_rows($level_check_result) > 0) {
                        continue; // Skip if level exists
                    }
                    
                    $query = "INSERT INTO plots (section_id, row_number, plot_number, latitude, longitude, status, level_number, max_levels, is_multi_level) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "iisddsiii", $section_id, $row_number, $plot_number, $lat, $lng, $status, $level, $stored_max_levels, $is_multi_level);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $success_count++;
                    } else {
                        $error_count++;
                    }
                }
            } else {
                // Create single plot
                $check_query = "SELECT plot_id FROM plots WHERE section_id = ? AND `row_number` = ? AND plot_number = ? AND level_number = 1";
                $stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($stmt, "iis", $section_id, $row_number, $plot_number);
                mysqli_stmt_execute($stmt);
                $check_result = mysqli_stmt_get_result($stmt);
                
                if (mysqli_num_rows($check_result) > 0) {
                    $_SESSION['add_plot_error'] = "Plot already exists at this location.";
                    header('Location: add_plots.php');
                    exit();
                } else {
                    // Validate coordinates are set before saving
                    if (empty($lat) || empty($lng) || $lat == 0 || $lng == 0) {
                        $_SESSION['add_plot_error'] = "Please click on the map to set plot location before adding.";
                        header('Location: add_plots.php');
                        exit();
                    }
                    
                    // Coordinates are valid, proceed with insertion
                    $is_multi_level = 0;
                    $level = 1;
                    $stored_max_levels = 1;
                    
                    $query = "INSERT INTO plots (section_id, row_number, plot_number, latitude, longitude, status, level_number, max_levels, is_multi_level) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "iisddsiii", $section_id, $row_number, $plot_number, $lat, $lng, $status, $level, $stored_max_levels, $is_multi_level);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $success_count = 1;
                        $new_plot_id = mysqli_insert_id($conn);
                        $_SESSION['add_plot_success'] = "Plot added successfully! Section: \"" . $section_name . "\", Plot ID: " . $new_plot_id . ". Please refresh the maps page to see it.";
                        error_log("Individual plot added successfully - Plot ID: $new_plot_id, Section: $section_name, Row: $row_number, Plot: $plot_number, Lat: $lat, Lng: $lng");
                    } else {
                        $error_count = 1;
                        $error_msg = "Failed to add plot: " . mysqli_error($conn);
                        $_SESSION['add_plot_error'] = $error_msg;
                        error_log("Failed to add individual plot - Section: $section_name, Error: " . mysqli_error($conn));
                    }
                }
            }
            
            if ($success_count > 0 && $enable_multi_level) {
                $_SESSION['add_plot_success'] = "Section \"" . $section_name . "\" added $success_count plot levels!";
            }
            
            // Redirect after processing to prevent form resubmission
            if (isset($_SESSION['add_plot_success']) || isset($_SESSION['add_plot_error'])) {
                header('Location: add_plots.php');
                exit();
            }
        }
    } else {
        $_SESSION['add_plot_error'] = "Missing required fields: " . implode(", ", $missing_fields);
        header('Location: add_plots.php');
        exit();
    }
}

// Handle plot section deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_section') {
    $section_id = isset($_POST['section_id']) ? intval($_POST['section_id']) : 0;
    
    if ($section_id > 0) {
        // Check if section exists
        $section_name = null;
        $section_check_query = "SELECT section_name FROM sections WHERE section_id = ?";
        $stmt = mysqli_prepare($conn, $section_check_query);
        mysqli_stmt_bind_param($stmt, "i", $section_id);
        mysqli_stmt_execute($stmt);
        $section_result = mysqli_stmt_get_result($stmt);
        
        if ($section_result && mysqli_num_rows($section_result) > 0) {
            $section_row = mysqli_fetch_assoc($section_result);
            $section_name = $section_row['section_name'];
        } else {
            $_SESSION['add_plot_error'] = "Selected section does not exist.";
            header('Location: add_plots.php');
            exit();
        }
        
        if ($section_name !== null) {
            // Prevent deleting sections that have occupied plots
            $occupied_check_query = "SELECT COUNT(*) AS cnt FROM plots WHERE section_id = ? AND status = 'occupied'";
            $stmt = mysqli_prepare($conn, $occupied_check_query);
            mysqli_stmt_bind_param($stmt, "i", $section_id);
            mysqli_stmt_execute($stmt);
            $occupied_result = mysqli_stmt_get_result($stmt);
            $occupied_row = $occupied_result ? mysqli_fetch_assoc($occupied_result) : ['cnt' => 0];
            
            if ($occupied_row['cnt'] > 0) {
                $_SESSION['add_plot_error'] = "Cannot delete section '" . htmlspecialchars($section_name, ENT_QUOTES) . "' because it has occupied plots.";
                header('Location: add_plots.php');
                exit();
            } else {
                // First, delete any exhumation_requests that reference plots in this section
                // This is necessary because of foreign key constraints
                // Delete requests where source_plot_id is in this section
                $delete_exhumations_source_query = "DELETE FROM exhumation_requests 
                                                    WHERE source_plot_id IN (SELECT plot_id FROM plots WHERE section_id = ?)";
                $stmt = mysqli_prepare($conn, $delete_exhumations_source_query);
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, "i", $section_id);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
                
                // Delete requests where target_plot_id is in this section
                $delete_exhumations_target_query = "DELETE FROM exhumation_requests 
                                                    WHERE target_plot_id IN (SELECT plot_id FROM plots WHERE section_id = ?)";
                $stmt = mysqli_prepare($conn, $delete_exhumations_target_query);
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, "i", $section_id);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
                
                // Delete all plots in this section
                $delete_plots_query = "DELETE FROM plots WHERE section_id = ?";
                $stmt = mysqli_prepare($conn, $delete_plots_query);
                mysqli_stmt_bind_param($stmt, "i", $section_id);
                
                if (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    
                    // Delete the section itself
                    $delete_section_query = "DELETE FROM sections WHERE section_id = ?";
                    $stmt = mysqli_prepare($conn, $delete_section_query);
                    mysqli_stmt_bind_param($stmt, "i", $section_id);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        // Use a plain text success message; JS will show a styled notification bubble
                        $_SESSION['add_plot_delete_success'] = "Section \"" . htmlspecialchars($section_name, ENT_QUOTES) . "\" and all of its plots have been deleted.";
                    } else {
                        $_SESSION['add_plot_error'] = "Failed to delete section '" . htmlspecialchars($section_name, ENT_QUOTES) . "': " . mysqli_error($conn);
                    }
                    mysqli_stmt_close($stmt);
                } else {
                    $_SESSION['add_plot_error'] = "Failed to delete plots from section '" . htmlspecialchars($section_name, ENT_QUOTES) . "': " . mysqli_error($conn);
                    mysqli_stmt_close($stmt);
                }
                
                // Redirect after processing to prevent form resubmission
                header('Location: add_plots.php');
                exit();
            }
        }
    } else {
        $error_message = "Please select a valid section to delete.";
    }
}

// Handle new plot creation (batch mode)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    // Check if all required fields are present
    $required_fields = ['section', 'plots_per_row', 'max_levels', 'start_lat', 'start_lng', 'end_lat', 'end_lng'];
    $missing_fields = [];
    
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || $_POST[$field] === '') {
            $missing_fields[] = $field;
        }
    }
    
    if (empty($missing_fields)) {
        $section_name = mysqli_real_escape_string($conn, $_POST['section']);
        $plots_per_row = intval($_POST['plots_per_row']);
        $max_levels = intval($_POST['max_levels']);
        $start_lat = floatval($_POST['start_lat']);
        $start_lng = floatval($_POST['start_lng']);
        $end_lat = floatval($_POST['end_lat']);
        $end_lng = floatval($_POST['end_lng']);
        $status = 'available'; // Fixed status for new plots
        
        // Multi-level support for lawn lots (multiple caskets per tombstone)
        $enable_multi_level = isset($_POST['enable_multi_level']) && $_POST['enable_multi_level'] === '1' ? 1 : 0;
        $caskets_per_plot = isset($_POST['caskets_per_plot']) ? intval($_POST['caskets_per_plot']) : 1;
        if ($caskets_per_plot < 1) $caskets_per_plot = 1;
        if ($caskets_per_plot > 10) $caskets_per_plot = 10;
        
        // Use max_levels as the number of rows
        $start_row = 1;
        $end_row = $max_levels;

        // Get section_id and section_code from section_name
        $section_query = "SELECT section_id, section_code FROM sections WHERE section_name = ?";
        $stmt = mysqli_prepare($conn, $section_query);
        mysqli_stmt_bind_param($stmt, "s", $section_name);
        mysqli_stmt_execute($stmt);
        $section_result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($section_result) === 0) {
            // Section doesn't exist, create it automatically
            $section_code = strtoupper(substr($section_name, 0, 3)); // Use first 3 characters as code
            
            // Check if section_code already exists and generate a unique one if needed
            $code_check_query = "SELECT section_code FROM sections WHERE section_code = ?";
            $stmt = mysqli_prepare($conn, $code_check_query);
            mysqli_stmt_bind_param($stmt, "s", $section_code);
            mysqli_stmt_execute($stmt);
            $code_result = mysqli_stmt_get_result($stmt);
            
            $counter = 1;
            $original_code = $section_code;
            while (mysqli_num_rows($code_result) > 0) {
                $section_code = $original_code . $counter;
                $stmt = mysqli_prepare($conn, $code_check_query);
                mysqli_stmt_bind_param($stmt, "s", $section_code);
                mysqli_stmt_execute($stmt);
                $code_result = mysqli_stmt_get_result($stmt);
                $counter++;
            }
            
            $description = "Auto-created section: " . $section_name;
            
            $create_section_query = "INSERT INTO sections (section_code, section_name, description) VALUES (?, ?, ?)";
            $stmt = mysqli_prepare($conn, $create_section_query);
            mysqli_stmt_bind_param($stmt, "sss", $section_code, $section_name, $description);
            
            if (mysqli_stmt_execute($stmt)) {
                $section_id = mysqli_insert_id($conn);
                $success_message = "Section '$section_name' (Code: $section_code) was automatically created and plots were added successfully!";
            } else {
                $error_message = "Failed to create section '$section_name': " . mysqli_error($conn);
            }
        } else {
            $section_data = mysqli_fetch_assoc($section_result);
            $section_id = $section_data['section_id'];
            $section_code = $section_data['section_code'];
        }
        
        // Only proceed with plot creation if we have a valid section_id
        if (isset($section_id) && $section_id > 0) {
            // Update section multi-level status based on enable_multi_level
            // Note: max_levels in sections table represents vertical levels, not rows
            $section_max_levels = $enable_multi_level ? $caskets_per_plot : 1;
            $update_section_query = "UPDATE sections SET has_multi_level = ?, max_levels = ? WHERE section_id = ?";
            $stmt = mysqli_prepare($conn, $update_section_query);
            mysqli_stmt_bind_param($stmt, "iii", $enable_multi_level, $section_max_levels, $section_id);
            mysqli_stmt_execute($stmt);

            // Calculate consistent spacing for plots
            $column_divisor = max(1, $plots_per_row - 1);
            $col_lat_step = $plots_per_row > 1 ? ($end_lat - $start_lat) / $column_divisor : 0;
            $col_lng_step = $plots_per_row > 1 ? ($end_lng - $start_lng) / $column_divisor : 0;

            // Calculate the distance between start and end points
            $total_distance = sqrt(pow($end_lat - $start_lat, 2) + pow($end_lng - $start_lng, 2));
            // Ensure rows look aligned across sections sharing the same start/end line,
            // even when plots_per_row differs (e.g., ARTEMIS has 82). We minimize row spacing
            // for lower plot counts by using a reference divisor for the perpendicular spacing.
            $reference_plots_per_row = 100; // visual baseline to keep row spacing consistent
            $reference_divisor = max(1, $reference_plots_per_row - 1);
            $effective_divisor = max($column_divisor, $reference_divisor);
            $target_spacing = $total_distance > 0 ? $total_distance / $effective_divisor : 0.00003;

            // Calculate perpendicular direction for rows
            // Get the normalized direction vector of the column line
            $col_direction_lat = $col_lat_step;
            $col_direction_lng = $col_lng_step;
            $col_magnitude = sqrt($col_direction_lat * $col_direction_lat + $col_direction_lng * $col_direction_lng);
            
            if ($col_magnitude > 0) {
                $col_direction_lat /= $col_magnitude;
                $col_direction_lng /= $col_magnitude;
            }

            // Calculate perpendicular direction (rotate 90 degrees counterclockwise)
            $row_direction_lat = -$col_direction_lng;
            $row_direction_lng = $col_direction_lat;

            // Apply the target spacing to the perpendicular direction
            $row_lat_step = $row_direction_lat * $target_spacing;
            $row_lng_step = $row_direction_lng * $target_spacing;

            // Ensure rows progress in a consistent direction (prefer northward)
            if ($row_lat_step < 0) {
                $row_lat_step *= -1;
                $row_lng_step *= -1;
            }

            $success_count = 0;
            $error_count = 0;
            $duplicate_count = 0;
            $duplicate_plots = array();

            // Generate plots in grid pattern with multiple levels
            for ($row = $start_row; $row <= $end_row; $row++) {
                for ($col = 1; $col <= $plots_per_row; $col++) {
                    // Calculate row letter (A=1, B=2, etc.)
                    $row_letter = chr(64 + $row);
                    
                    // Calculate latitude and longitude ensuring consistent spacing
                    $lat = $start_lat + ($col - 1) * $col_lat_step + ($row - 1) * $row_lat_step;
                    $lng = $start_lng + ($col - 1) * $col_lng_step + ($row - 1) * $row_lng_step;
                    
                    // Store canonical plot number as numeric index within the row for consistency
                    $plot_number = (string)$col;

                    // Check if a plot already exists for this section, row and plot index
                    $check_query = "SELECT plot_number FROM plots WHERE section_id = ? AND `row_number` = ? AND plot_number = ?";
                    $stmt = mysqli_prepare($conn, $check_query);
                    mysqli_stmt_bind_param($stmt, "iis", $section_id, $row, $plot_number);
                    mysqli_stmt_execute($stmt);
                    $check_result = mysqli_stmt_get_result($stmt);
                    
                    // Check if plot already exists (considering all levels if multi-level)
                    $check_query = "SELECT plot_number FROM plots WHERE section_id = ? AND `row_number` = ? AND plot_number = ?";
                    $stmt = mysqli_prepare($conn, $check_query);
                    mysqli_stmt_bind_param($stmt, "iis", $section_id, $row, $plot_number);
                    mysqli_stmt_execute($stmt);
                    $check_result = mysqli_stmt_get_result($stmt);
                    
                    if (mysqli_num_rows($check_result) > 0 && !$enable_multi_level) {
                        // Only skip if it's a duplicate and NOT multi-level (multi-level allows multiple entries)
                        $duplicate_count++;
                        $duplicate_plots[] = $plot_number;
                        continue;
                    }

                    // Create plots with multi-level support if enabled
                    if ($enable_multi_level && $caskets_per_plot > 1) {
                        // Multi-level: create multiple level entries for the same plot position
                        $is_multi_level = 1;
                        $stored_max_levels = $caskets_per_plot;
                        
                        for ($level = 1; $level <= $caskets_per_plot; $level++) {
                            // Check if this specific level already exists
                            $level_check_query = "SELECT plot_id FROM plots WHERE section_id = ? AND `row_number` = ? AND plot_number = ? AND level_number = ?";
                            $stmt = mysqli_prepare($conn, $level_check_query);
                            mysqli_stmt_bind_param($stmt, "iisi", $section_id, $row, $plot_number, $level);
                            mysqli_stmt_execute($stmt);
                            $level_check_result = mysqli_stmt_get_result($stmt);
                            
                            if (mysqli_num_rows($level_check_result) > 0) {
                                continue; // Skip this level if it already exists
                            }
                            
                            $query = "INSERT INTO plots (section_id, row_number, plot_number, latitude, longitude, status, level_number, max_levels, is_multi_level) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            $stmt = mysqli_prepare($conn, $query);
                            mysqli_stmt_bind_param($stmt, "iisddsiii", $section_id, $row, $plot_number, $lat, $lng, $status, $level, $stored_max_levels, $is_multi_level);
                            
                            if (mysqli_stmt_execute($stmt)) {
                                $success_count++;
                            } else {
                                $error_count++;
                            }
                        }
                    } else {
                        // Single-level: create one plot entry
                        $is_multi_level = 0;
                        $level = 1; // Ground level only
                        $stored_max_levels = 1;
                        
                        $query = "INSERT INTO plots (section_id, row_number, plot_number, latitude, longitude, status, level_number, max_levels, is_multi_level) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $stmt = mysqli_prepare($conn, $query);
                        mysqli_stmt_bind_param($stmt, "iisddsiii", $section_id, $row, $plot_number, $lat, $lng, $status, $level, $stored_max_levels, $is_multi_level);
                        
                        if (mysqli_stmt_execute($stmt)) {
                            $success_count++;
                        } else {
                            $error_count++;
                        }
                    }
                }
            }

            if ($success_count > 0) {
                $_SESSION['add_plot_success'] = "Section \"" . $section_name . "\" added $success_count plots!";
            }
            if ($duplicate_count > 0) {
                $_SESSION['add_plot_duplicate'] = "Skipped $duplicate_count duplicate plots: " . implode(", ", $duplicate_plots);
            }
            if ($error_count > 0) {
                $_SESSION['add_plot_error'] = "Failed to add $error_count plots.";
            }
            
            // Redirect after processing to prevent form resubmission
            header('Location: add_plots.php');
            exit();
        } // End of section_id check
    } else {
        $_SESSION['add_plot_error'] = "Missing required fields: " . implode(", ", $missing_fields);
        header('Location: add_plots.php');
        exit();
    }
}

// Get existing plots for map display only
$plots_query = "SELECT p.*, 
                COALESCE(s.section_name, p.section, 'Unknown Section') as section_name,
                s.section_id,
                COALESCE(s.label_lat_offset, 0) as label_lat_offset,
                COALESCE(s.label_lng_offset, 0) as label_lng_offset
                FROM plots p 
                LEFT JOIN sections s ON p.section_id = s.section_id 
                ORDER BY COALESCE(s.section_name, p.section, 'Unknown Section'), p.row_number ASC, CAST(p.plot_number AS UNSIGNED) ASC, p.level_number ASC";
$plots_result = mysqli_query($conn, $plots_query);

// Group plots by section for map display
$sections = [];
while ($plot = mysqli_fetch_assoc($plots_result)) {
    $section_name = !empty($plot['section_name']) ? $plot['section_name'] : 'Unknown Section';
    $sections[$section_name][] = $plot;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Trece Martires Memorial Park</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="../assets/images/tmmp-logo.png">
    <link rel="shortcut icon" type="image/png" href="../assets/images/tmmp-logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <style>
        body { margin: 0; padding: 0; font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; }
        .main {
            padding: 48px 40px 32px 40px;
            background: #f5f5f5;
            max-width: 1400px;
            margin: 0 auto;
        }
        .page-header {
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            margin-bottom: 24px;
        }
        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: #222;
            margin: 0;
            text-align: center;
        }
        .back-inline-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            color: #555;
            text-decoration: none;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid #e0e0e0;
            background: #ffffff;
            transition: background 0.15s ease, box-shadow 0.15s ease, transform 0.1s ease;
        }
        .back-inline-btn:hover {
            background: #f3f4f6;
            color: #111;
            box-shadow: 0 2px 6px rgba(15,23,42,0.12);
            text-decoration: none;
            transform: translateY(-1px);
        }
        .back-inline-btn i {
            font-size: 20px;
        }
        /* Page-specific styles */
        .form-container {
            display: grid;
            flex-direction: column;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 16px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(1, 1fr);
        }
        .form-grid-inline {
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
        .form-grid-full {
            grid-column: 1 / -1;
        }
        @media (max-width: 992px) {
            .form-grid-inline {
                grid-template-columns: repeat(1, 1fr);
            }
        }
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0;
            margin-top: 0;
            padding-top: 0;
        }
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
        .coordinates-display {
            background: #f8f9fa;
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .map-container {
            position: relative;
            margin-bottom: 20px;
        }
        #map {
            height: 500px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            position: relative;
        }
        #map.move-mode {
            cursor: move !important;
        }
        #map.move-mode * {
            cursor: move !important;
        }
        .map-refresh-btn {
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 8px 12px;
            color: #2b4c7e;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .map-refresh-btn:hover {
            background: #ffffff;
            color: #1f3659;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
            transform: translateY(-1px);
            cursor: pointer;
        }
        .map-refresh-btn i {
            font-size: 16px;
        }
        /* Notification Bubble Styles (reused from staff settings page) */
        .notification-bubble {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 20px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 15px;
            font-weight: 500;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
            z-index: 9999;
            transform: translateX(400px);
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            max-width: 400px;
            word-wrap: break-word;
        }
        .notification-bubble.show {
            transform: translateX(0);
            opacity: 1;
        }
        .notification-bubble.hide {
            transform: translateX(400px);
            opacity: 0;
        }
        .success-notification {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border-left: 4px solid #065f46;
        }
        .error-notification {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border-left: 4px solid #991b1b;
        }
        .notification-bubble i {
            font-size: 18px;
            flex-shrink: 0;
        }
        .notification-bubble span {
            flex: 1;
        }

        /* Simple confirmation modal for deleting plot sections */
        .confirm-modal-bg {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.4);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .confirm-modal-bg.active {
            display: flex;
        }
        .confirm-modal-content {
            background: #fff;
            border-radius: 16px;
            padding: 24px 24px 20px 24px;
            max-width: 480px;
            width: 100%;
            box-shadow: 0 4px 24px rgba(0,0,0,0.18);
            display: flex;
            gap: 16px;
        }
        .confirm-modal-icon {
            width: 42px;
            height: 42px;
            border-radius: 999px;
            background: #fee2e2;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #dc2626;
            flex-shrink: 0;
        }
        .confirm-modal-body {
            flex: 1;
        }
        .confirm-modal-title {
            font-weight: 600;
            font-size: 18px;
            margin-bottom: 4px;
            color: #111827;
        }
        .confirm-modal-text {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 16px;
        }
        .confirm-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
        .confirm-modal-actions .btn {
            min-width: 90px;
        }
        .form-label {
            font-weight: 500;
            margin-bottom: 4px;
        }
        .form-control, .form-select {
            border-radius: 6px;
            border: 1px solid #e0e0e0;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        .form-control:focus, .form-select:focus {
            border-color: #2b4c7e;
            box-shadow: 0 0 0 0.2rem rgba(43, 76, 126, 0.25);
            outline: none;
        }
        .btn-primary {
            background-color: #2b4c7e;
            border-color: #2b4c7e;
            color: white;
        }
        .btn-primary:hover,
        .btn-primary:focus,
        .btn-primary:active {
            background-color: #1f3659;
            border-color: #1f3659;
            color: white;
        }
        .btn-outline-primary {
            color: #2b4c7e;
            border-color: #2b4c7e;
            background-color: transparent;
        }
        .btn-outline-primary:hover,
        .btn-outline-primary:focus,
        .btn-outline-primary:active {
            background-color: #2b4c7e;
            border-color: #2b4c7e;
            color: white;
        }
        .btn-check:checked + .btn-outline-primary,
        .btn-check:active + .btn-outline-primary {
            background-color: #2b4c7e;
            border-color: #2b4c7e;
            color: white;
        }
        .btn-secondary {
            background-color: #f8f9fa;
            border-color: #ddd;
            color: #222;
        }
        .btn-secondary:hover,
        .btn-secondary:focus,
        .btn-secondary:active {
            background-color: #e9ecef;
            border-color: #adb5bd;
            color: #222;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 16px;
        }
        .alert-success {
            background: #d1edff;
            color: #0d6efd;
            border: 1px solid #b8daff;
        }
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }
        .alert-danger {
            background: #fee;
            color: #c00;
            border: 1px solid #fcc;
        }
        .alert-success {
            transition: opacity 0.5s ease-out;
        }
        .alert-success.fade-out {
            opacity: 0;
            pointer-events: none;
        }
        /* Responsive Design - Standard Breakpoints */
        
        /* Tablet and below (768px) */
        @media (max-width: 768px) {
            .main {
                padding: 24px 20px !important;
                margin-left: 0 !important;
                width: 100% !important;
            }
            
            .sidebar.collapsed + .main {
                margin-left: 0 !important;
            }
            
            .form-card {
                padding: 18px 16px;
            }
        }
        
        /* Mobile (480px and below) */
        @media (max-width: 480px) {
            .layout {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100vw;
                min-height: 60px;
                height: auto;
                position: relative;
                border-right: none;
                border-bottom: 1px solid #e0e0e0;
            }
            
            .sidebar.collapsed {
                width: 100vw;
                min-height: 60px;
            }
            
            .main {
                padding: 16px 12px !important;
                margin-left: 0 !important;
                width: 100% !important;
            }
            
            .sidebar.collapsed + .main {
                margin-left: 0 !important;
            }
            
            .form-card {
                padding: 12px 10px;
            }
            
            /* Prevent horizontal scroll */
            body, html {
                overflow-x: hidden;
                max-width: 100vw;
            }
            
            .layout {
                overflow-x: hidden;
                max-width: 100vw;
            }
        }
        .delete-plots-btn:hover {
            background: #c82333;
        }
        
        .delete-plots-btn:disabled {
            background: #e9ecef;
            cursor: not-allowed;
        }
        
        .section-filter-tabs {
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 16px;
        }
        
        .section-filter-tabs .btn-group {
            flex-wrap: wrap;
            gap: 4px;
        }
        
        .section-filter-tabs .btn {
            border-radius: 20px;
            font-size: 0.875rem;
            padding: 6px 16px;
            transition: all 0.2s ease;
            position: relative;
        }
        
        .section-filter-tabs .btn.active {
            background: #2b4c7e;
            border-color: #2b4c7e;
            color: white;
            box-shadow: 0 2px 4px rgba(43, 76, 126, 0.3);
        }
        
        .section-filter-tabs .btn:hover:not(.active) {
            background: #f8f9fa;
            border-color: #2b4c7e;
            color: #2b4c7e;
        }
        
        .section-filter-tabs .badge {
            font-size: 0.7rem;
            padding: 2px 6px;
        }
        
        .plot-controls {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        .section-block.hidden {
            display: none !important;
        }
        
        /* Plot Type Filter */
        .plot-type-filter {
            margin-top: 0;
            padding-top: 0;
        }
        
        .plot-type-filter-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #64748b;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .plot-type-buttons {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        
        .plot-type-btn {
            padding: 8px 12px;
            border: 1px solid #ddd;
            background: #f8f9fa;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.875rem;
            text-align: left;
            font-weight: 500;
            color: #333;
        }
        
        .plot-type-btn:hover {
            background: #e9ecef;
            border-color: #adb5bd;
        }
        
        .plot-type-btn.active {
            background: #2b4c7e;
            color: white;
            border-color: #2b4c7e;
        }
        
        /* Map type toggle (Default / Satellite) */
        .map-type-toggle {
            position: absolute;
            bottom: 20px;
            left: 20px;
            z-index: 1001;
        }

        .map-type-btn {
            position: relative;
            border: none;
            width: 80px;
            height: 90px;
            border-radius: 18px;
            cursor: pointer;
            padding: 0;
            overflow: hidden;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.55);
            border: 2px solid #ffffff;
            background: transparent;
        }

        #mapTypeMiniMap {
            position: absolute;
            inset: 0;
            border-radius: 16px;
            overflow: hidden;
            z-index: 0;
            pointer-events: none;
        }

        .map-type-label {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            padding: 4px 6px;
            font-size: 0.75rem;
            font-weight: 600;
            color: #ffffff;
            text-align: center;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.9);
            background: linear-gradient(to top, rgba(0, 0, 0, 0.8), transparent);
            pointer-events: none;
            z-index: 1;
        }

        .map-type-btn.active {
            border-color: #2b4c7e;
            box-shadow: 0 0 0 2px rgba(43, 76, 126, 0.9), 0 6px 16px rgba(15, 23, 42, 0.7);
        }

        @media (max-width: 768px) {
            .map-type-toggle {
                bottom: 10px;
                left: 10px;
            }
            .map-type-btn {
                width: 90px;
                height: 90px;
            }
        }
    </style>
</head>
<body>
        <div class="main">
        <div class="page-header">
            <a href="plots.php" class="back-inline-btn">
                <i class="bi bi-arrow-left"></i>
            </a>
            <div class="page-title">Add New Plots</div>
            <button type="button" class="map-refresh-btn" onclick="refreshPage();">
                <i class="bi bi-arrow-counterclockwise"></i>
                <span>Refresh</span>
            </button>
        </div>

        <!-- Notification bubble for plot section actions -->
        <div id="sectionNotification" class="notification-bubble success-notification" style="display:none;">
            <i class="bi bi-check-circle"></i>
            <span id="sectionNotificationText"></span>
        </div>

        <!-- Confirm delete plot section modal -->
        <div id="deleteSectionModal" class="confirm-modal-bg">
            <div class="confirm-modal-content">
                <div class="confirm-modal-icon">
                    <i class="bi bi-trash-fill"></i>
                </div>
                <div class="confirm-modal-body">
                    <div class="confirm-modal-title">Delete this plot section?</div>
                    <div class="confirm-modal-text">
                        This action cannot be undone.
                    </div>
                    <div class="confirm-modal-actions">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="cancelDeleteSectionBtn">Cancel</button>
                        <button type="button" class="btn btn-danger btn-sm" id="confirmDeleteSectionBtn">Delete</button>
                    </div>
                </div>
            </div>
        </div>

            <?php if (!empty($success_message)): ?>
            <div id="addPlotSuccess" data-message="<?php echo htmlspecialchars($success_message, ENT_QUOTES); ?>" style="display:none;"></div>
            <?php endif; ?>

            <?php if (!empty($duplicate_message)): ?>
            <div id="addPlotDuplicate" data-message="<?php echo htmlspecialchars($duplicate_message, ENT_QUOTES); ?>" style="display:none;"></div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
            <div id="addPlotError" data-message="<?php echo htmlspecialchars($error_message, ENT_QUOTES); ?>" style="display:none;"></div>
            <?php endif; ?>

            <?php if (!empty($delete_success_message)): ?>
            <div id="addPlotDeleteSuccess" data-message="<?php echo htmlspecialchars($delete_success_message, ENT_QUOTES); ?>" style="display:none;"></div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-8">
                    <div class="map-container">
                        <div id="map">
                        </div>
                        <div class="map-type-toggle">
                            <button class="map-type-btn" data-map-type="satellite">
                                <div id="mapTypeMiniMap"></div>
                                <span class="map-type-label">Satellite</span>
                            </button>
                        </div>
                    </div>
                    <div class="form-container" id="batchFormContainer" style="margin-top: 16px;">
                        <form method="POST" action="" id="plotForm">
                            <div class="form-grid form-grid-inline">
                                <div class="mb-4">
                                    <label for="section" class="form-label">Section Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="section" name="section" required 
                                           placeholder="Enter section name (e.g., ZEUS)">
                                </div>

                                <div class="mb-4">
                                    <label for="max_levels" class="form-label">Number of Rows</label>
                                    <select class="form-select" id="max_levels" name="max_levels">
                                        <option value="1">1 Row (A)</option>
                                        <option value="2">2 Rows (A-B)</option>
                                        <option value="3">3 Rows (A-C)</option>
                                        <option value="4">4 Rows (A-D)</option>
                                        <option value="5">5 Rows (A-E)</option>
                                    </select>
                                    <small class="text-muted">Select the number of rows for this plot area (A, B, C, etc.)</small>
                                </div>

                                <div class="mb-4">
                                    <label for="plots_per_row" class="form-label">Plots per Row <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" id="plots_per_row" name="plots_per_row" min="1" required>
                                </div>
                            </div>

                            <input type="hidden" id="start_lat" name="start_lat" required>
                            <input type="hidden" id="start_lng" name="start_lng" required>
                            <input type="hidden" id="end_lat" name="end_lat" required>
                            <input type="hidden" id="end_lng" name="end_lng" required>

                            <div class="form-actions" style="margin-top: 0; padding-top: 0;">
                                <button type="submit" class="btn btn-primary">Add Plots</button>
                            </div>
                        </form>
                    </div>
                    <div class="form-container" id="individualFormContainer" style="display: none; margin-top: 16px;">
                        <form method="POST" action="" id="individualPlotForm">
                            <input type="hidden" name="action" value="individual">
                            <div class="form-grid form-grid-inline">
                                <div class="mb-4">
                                    <label for="individual_section" class="form-label">Section Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="individual_section" name="section" required 
                                           placeholder="Enter section name (e.g., ZEUS)">
                                </div>

                                <div class="mb-4">
                                    <label for="individual_row" class="form-label">Row <span class="text-danger">*</span></label>
                                    <select class="form-select" id="individual_row" name="row_number" required>
                                        <option value="">Select Row</option>
                                        <option value="1">Row A</option>
                                        <option value="2">Row B</option>
                                        <option value="3">Row C</option>
                                        <option value="4">Row D</option>
                                        <option value="5">Row E</option>
                                        <option value="6">Row F</option>
                                        <option value="7">Row G</option>
                                        <option value="8">Row H</option>
                                        <option value="9">Row I</option>
                                        <option value="10">Row J</option>
                                    </select>
                                </div>

                                <div class="mb-4">
                                    <label for="individual_plot_number" class="form-label">Plot Number <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="individual_plot_number" name="plot_number" required 
                                           placeholder="e.g., 1, 2, 15">
                                    <small class="text-muted">Enter the plot number within the row</small>
                                </div>
                            </div>
                            <input type="hidden" id="individual_lat" name="lat" required>
                            <input type="hidden" id="individual_lng" name="lng" required>

                            <div class="form-actions" style="margin-top: 0; padding-top: 0;">
                                <button type="submit" class="btn btn-primary">Add Plot</button>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-container">
                        <div class="coordinates-display" id="coordinatesDisplay">
                            <div class="mb-3" style="margin-bottom: 20px !important;">
                                <label class="form-label" style="font-size: 16px;"><strong>Creation Mode</strong></label>
                                <div class="btn-group w-100" role="group">
                                    <input type="radio" class="btn-check" name="creation_mode" id="mode_batch" value="batch" checked>
                                    <label class="btn btn-outline-primary" for="mode_batch">
                                        <i class="bi bi-grid-3x3-gap"></i> Batch Mode
                                    </label>
                                    
                                    <input type="radio" class="btn-check" name="creation_mode" id="mode_individual" value="individual">
                                    <label class="btn btn-outline-primary" for="mode_individual">
                                        <i class="bi bi-plus-circle"></i> Individual Mode
                                    </label>
                                </div>
                                <div class="text-muted d-block mt-2" style="font-size: 12px;">
                                    <strong>Batch Mode:</strong> Create multiple plots in a grid pattern<br>
                                    <strong>Individual Mode:</strong> Add plots on at a time
                                </div>
                            </div>
                            <div id="batchCoordinates">
                                <strong style="font-size: 16px;">Grid Coordinates:</strong><br>
                                Start: <span id="start-coords">-</span><br>
                                End: <span id="end-coords">-</span>
                                <button type="button" class="btn btn-secondary btn-sm mt-2" onclick="removePlotPoints()" id="removeBtn" style="display: none;">
                                    <i class="bi bi-x-lg"></i> Cancel
                                </button>
                            </div>
                            <div id="individualCoordinates" style="display: none;">
                                <strong style="font-size: 16px;">Plot Location:</strong><br>
                                <span id="individual_coords">Click on the map to set location</span>
                                <button type="button" class="btn btn-secondary btn-sm mt-2" onclick="removeIndividualPlot()" id="removeIndividualBtn" style="display: none;">
                                    <i class="bi bi-x-lg"></i> Cancel
                                </button>
                                <small class="text-muted d-block mt-2">Click anywhere on the map to place this plot</small>
                            </div>
                        </div>
                        
                        <div class="plot-type-filter" style="margin-top: 16px; margin-bottom: 16px;">
                            <div class="plot-type-filter-title">Plot Type</div>
                            <div class="plot-type-buttons">
                                <button type="button" class="plot-type-btn active" data-plot-type="all">All Types</button>
                                <button type="button" class="plot-type-btn" data-plot-type="lawn">Lawn Lot (Ground Level)</button>
                                <button type="button" class="plot-type-btn" data-plot-type="niche">Apartment-Type Niches</button>
                            </div>
                        </div>

                        <div class="form-check form-switch" style="margin-top: 16px; margin-bottom: 0;">
                            <input class="form-check-input" type="checkbox" id="toggleMoveMode">
                            <label class="form-check-label" for="toggleMoveMode">
                                <i class="bi bi-arrows-move"></i> Move Section
                            </label>
                            <small class="text-muted d-block mt-1">
                                Enable to drag and reposition an entire section (all its plots) on the map
                            </small>
                        </div>
                    </div>
                    <div class="form-container">
                        <form method="POST" action="" id="deleteSectionForm">
                            <input type="hidden" name="action" value="delete_section">
                            <label for="delete_section_id" class="form-label"><strong>Delete Plot Section</strong></label>
                            <select class="form-select" id="delete_section_id" name="section_id" required>
                                <option value="">Select section to delete</option>
                                <?php foreach ($all_sections as $section): ?>
                                    <option value="<?php echo (int)$section['section_id']; ?>">
                                        <?php echo htmlspecialchars($section['section_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted d-block mt-1">
                                Deleting a section will remove all of its plots across all pages. Sections with occupied plots cannot be deleted.
                            </small>
                            <button type="submit" class="btn btn-danger btn-sm w-100 mt-2">
                                <i class="bi bi-trash"></i> Delete Plot Section
                            </button>
                        </form>
                    </div>
                </div>
                </div>
            </div>
        </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
	<script src="../assets/js/offline-map.js?v=<?php echo time(); ?>"></script>
    <script>
        // Initialize map
        let map;
        let startMarker = null;
        let endMarker = null;
        let plotLine = null;
        let isSelectingStart = true;
		let existingLayerGroup; // holds existing plots and section labels
        let currentMode = 'batch'; // 'batch' or 'individual'
        let individualPlotMarker = null; // Marker for individual plot placement

        function removePlotPoints() {
            // Remove markers and line
            if (startMarker) map.removeLayer(startMarker);
            if (endMarker) map.removeLayer(endMarker);
            if (plotLine) map.removeLayer(plotLine);
            
            // Reset markers and line
            startMarker = null;
            endMarker = null;
            plotLine = null;
            
            // Reset coordinates display
            document.getElementById('start-coords').textContent = '-';
            document.getElementById('end-coords').textContent = '-';
            
            // Reset hidden inputs
            document.getElementById('start_lat').value = '';
            document.getElementById('start_lng').value = '';
            document.getElementById('end_lat').value = '';
            document.getElementById('end_lng').value = '';
            
            // Reset selection state
            isSelectingStart = true;
            
            // Hide remove button
            document.getElementById('removeBtn').style.display = 'none';
        }

        function removeIndividualPlot() {
            // Remove individual plot marker
            if (individualPlotMarker) {
                map.removeLayer(individualPlotMarker);
                individualPlotMarker = null;
            }
            
            // Reset coordinates display
            document.getElementById('individual_coords').textContent = 'Click on the map to set location';
            
            // Reset hidden inputs
            document.getElementById('individual_lat').value = '';
            document.getElementById('individual_lng').value = '';
            
            // Hide remove button
            document.getElementById('removeIndividualBtn').style.display = 'none';
        }

        // Refresh page while preserving map view
        function refreshPage() {
            if (map) {
                // Save current map view before reloading
                const view = {
                    center: map.getCenter(),
                    zoom: map.getZoom()
                };
                sessionStorage.setItem('addPlotMapView', JSON.stringify(view));
            }
            // Reload the page
            window.location.reload();
        }

        // Refresh map view (adapted from maps.php)
        function refreshMapView() {
            // Close any open popups
            map.closePopup();
            
            // Clear batch mode markers if they exist
            if (currentMode === 'batch') {
                removePlotPoints();
            }
            
            // Clear individual mode marker if it exists
            if (currentMode === 'individual' && individualPlotMarker) {
                map.removeLayer(individualPlotMarker);
                individualPlotMarker = null;
                document.getElementById('individual_lat').value = '';
                document.getElementById('individual_lng').value = '';
                document.getElementById('individual_coords').textContent = 'Click on the map to set location';
                document.getElementById('removeIndividualBtn').style.display = 'none';
            }
            
            // Reset map view to show all existing plots/sections
            // If we have existing markers, fit bounds to them
            if (existingLayerGroup && existingLayerGroup.getLayers().length > 0) {
                const bounds = L.latLngBounds();
                let hasValidBounds = false;
                
                existingLayerGroup.eachLayer(function(layer) {
                    let latlng = null;
                    if (layer.getLatLng) {
                        latlng = layer.getLatLng();
                    } else if (layer._latlng) {
                        latlng = layer._latlng;
                    } else if (layer.getBounds) {
                        latlng = layer.getBounds().getCenter();
                    }
                    
                    if (latlng && latlng.lat && latlng.lng) {
                        bounds.extend(latlng);
                        hasValidBounds = true;
                    }
                });
                
                if (hasValidBounds && bounds.isValid()) {
                    map.fitBounds(bounds, { padding: [50, 50], maxZoom: 19 });
                } else {
                    // Fallback: reset to default view
                    map.setView([14.265243, 120.864874], 19);
                }
            } else {
                // No existing markers, reset to default view
                map.setView([14.265243, 120.864874], 19);
            }
        }

        // Initialize map when page loads
        document.addEventListener('DOMContentLoaded', function() {
			// Satellite basemap with offline support
            const MIN_TILE_ZOOM = 18; // do not allow zooming out beyond this level
			const MAX_TILE_ZOOM = 22; // allow smooth zoom beyond native z19

            // Get saved view or use default
            const lastView = sessionStorage.getItem('addPlotMapView');
            const defaultView = {
                center: [14.265243, 120.864874],
				zoom: 19
            };
            const initialViewRaw = lastView ? JSON.parse(lastView) : defaultView;
            const normalizedCenter = Array.isArray(initialViewRaw.center)
                ? initialViewRaw.center
                : [initialViewRaw.center.lat, initialViewRaw.center.lng];
            const initialView = {
                center: normalizedCenter,
				zoom: Math.min(initialViewRaw.zoom ?? defaultView.zoom, MAX_TILE_ZOOM)
            };

			// Initialize map using offline-enabled satellite layers
			map = offlineMap.initializeMap('map', initialView.center, initialView.zoom);
			map.setMinZoom(MIN_TILE_ZOOM);
			map.setMaxZoom(MAX_TILE_ZOOM);
			// Layer group for existing plots/labels
			existingLayerGroup = L.layerGroup().addTo(map);
			
			// Remove base tile layers added by offlineMap so we can control map types
			map.eachLayer(function(layer) {
				if (layer instanceof L.TileLayer) {
					map.removeLayer(layer);
				}
			});
			
			// Create default and satellite layers
			const defaultLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
				maxZoom: MAX_TILE_ZOOM,
				maxNativeZoom: 19,
                minZoom: MIN_TILE_ZOOM,
				attribution: '&copy; OpenStreetMap contributors'
			});
			const satelliteLayer = offlineMap.getSatelliteLayer();
			const labelsLayer = offlineMap.getLabelsOverlay();
			
			// Start with default map view
			let currentMapType = 'default';
			defaultLayer.addTo(map);
			
			// Mini-map inside the map-type tile
			const miniMapEl = document.getElementById('mapTypeMiniMap');
			let miniMap = null;
			let miniDefaultLayer = null;
			let miniSatelliteLayer = null;
			let miniLabelsLayer = null;
			
			if (miniMapEl) {
				miniMap = L.map(miniMapEl, {
					center: map.getCenter(),
					zoom: map.getZoom(),
					zoomControl: false,
					attributionControl: false,
					interactive: false,
					boxZoom: false,
					doubleClickZoom: false,
					dragging: false,
					keyboard: false,
					scrollWheelZoom: false,
					tap: false,
					touchZoom: false,
					minZoom: MIN_TILE_ZOOM,
					maxZoom: MAX_TILE_ZOOM
				});
				miniDefaultLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
					maxZoom: MAX_TILE_ZOOM,
					attribution: ''
				});
				miniSatelliteLayer = offlineMap.getSatelliteLayer();
				miniLabelsLayer = offlineMap.getLabelsOverlay();
				
				// Initial state: main map is DEFAULT, so mini-map shows SATELLITE (opposite preview)
				miniSatelliteLayer.addTo(miniMap);
				miniLabelsLayer.addTo(miniMap);
			}
			
			// Keep mini-map synced with main map
			function syncMiniMapView() {
				if (!miniMap) return;
				miniMap.setView(map.getCenter(), map.getZoom());
			}
			
			map.on('move zoom', syncMiniMapView);
			
			// Switch the underlying map tiles (main map + mini-map)
			function setMapType(type) {
				if (type === currentMapType) return;
				currentMapType = type;
				
				if (type === 'default') {
					// MAIN: default tiles
					if (!map.hasLayer(defaultLayer)) {
						defaultLayer.addTo(map);
					}
					if (map.hasLayer(satelliteLayer)) {
						map.removeLayer(satelliteLayer);
					}
					if (map.hasLayer(labelsLayer)) {
						map.removeLayer(labelsLayer);
					}
					
					// MINI: show SATELLITE preview (opposite of main)
					if (miniMap) {
						if (miniMap.hasLayer(miniDefaultLayer)) {
							miniMap.removeLayer(miniDefaultLayer);
						}
						if (!miniMap.hasLayer(miniSatelliteLayer)) {
							miniSatelliteLayer.addTo(miniMap);
						}
						if (!miniMap.hasLayer(miniLabelsLayer)) {
							miniLabelsLayer.addTo(miniMap);
						}
					}
				} else if (type === 'satellite') {
					// MAIN: satellite tiles
					if (map.hasLayer(defaultLayer)) {
						map.removeLayer(defaultLayer);
					}
					if (!map.hasLayer(satelliteLayer)) {
						satelliteLayer.addTo(map);
					}
					if (!map.hasLayer(labelsLayer)) {
						labelsLayer.addTo(map);
					}
					
					// MINI: show DEFAULT preview (opposite of main)
					if (miniMap) {
						if (!miniMap.hasLayer(miniDefaultLayer)) {
							miniDefaultLayer.addTo(miniMap);
						}
						if (miniMap.hasLayer(miniSatelliteLayer)) {
							miniMap.removeLayer(miniSatelliteLayer);
						}
						if (miniMap.hasLayer(miniLabelsLayer)) {
							miniMap.removeLayer(miniLabelsLayer);
						}
					}
				}
			}
			
			// Update the single map-type button label based on current map type
			const mapTypeBtn = document.querySelector('.map-type-btn');
			const mapTypeLabel = document.querySelector('.map-type-label');
			
			function updateMapTypeButton() {
				if (!mapTypeBtn || !mapTypeLabel) return;
				if (currentMapType === 'default') {
					// Map is default → tile shows "Satellite"
					mapTypeBtn.dataset.mapType = 'satellite';
					mapTypeLabel.textContent = 'Satellite';
				} else {
					// Map is satellite → tile shows "Default"
					mapTypeBtn.dataset.mapType = 'default';
					mapTypeLabel.textContent = 'Default';
				}
			}
			
			if (mapTypeBtn) {
				mapTypeBtn.addEventListener('click', function () {
					const targetType = this.dataset.mapType || (currentMapType === 'default' ? 'satellite' : 'default');
					setMapType(targetType);
					updateMapTypeButton();
				});
			}
			
			updateMapTypeButton();

            // Ensure proper rendering after refresh and layout shifts
            map.whenReady(() => {
                map.invalidateSize();
                requestAnimationFrame(() => map.invalidateSize());
                setTimeout(() => map.invalidateSize(), 200);
            });

			// Plot Type filter now handles showing/hiding existing plots
			// The filter is initialized below and will show all plots by default

			// Hook up toggle for move section mode (drag handle to move whole section)
			const moveModeToggle = document.getElementById('toggleMoveMode');
			if (moveModeToggle) {
				moveModeToggle.addEventListener('change', () => {
					moveModeEnabled = moveModeToggle.checked;
					// Enable/disable dragging on all section label markers (handles)
					Object.values(sectionLabelMarkers).forEach(sectionInfo => {
						const marker = sectionInfo.marker;
					if (moveModeEnabled) {
						marker.draggable = true;
						marker.dragging.enable();
						if (marker.getElement()) {
							marker.getElement().style.cursor = 'move';
							marker.getElement().classList.add('draggable');
							// Also set cursor on the section-name element
							const sectionNameEl = marker.getElement().querySelector('.section-name');
							if (sectionNameEl) {
								sectionNameEl.style.cursor = 'move';
							}
						}
					} else {
						marker.draggable = false;
						marker.dragging.disable();
						if (marker.getElement()) {
							marker.getElement().style.cursor = 'default';
							marker.getElement().classList.remove('draggable');
							// Reset cursor on the section-name element
							const sectionNameEl = marker.getElement().querySelector('.section-name');
							if (sectionNameEl) {
								sectionNameEl.style.cursor = 'default';
							}
						}
					}
					});
					
					// Update map cursor
					const mapContainer = document.getElementById('map');
					if (moveModeEnabled) {
						mapContainer.classList.add('move-mode');
						map.getContainer().style.cursor = 'move';
					} else {
						mapContainer.classList.remove('move-mode');
						map.getContainer().style.cursor = '';
					}
				});
			}

			// Function to move all plots in a section by an offset and persist it
			function moveSectionPlots(sectionId, latOffset, lngOffset) {
				fetch('../api/update_section_label_position.php', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						section_id: sectionId,
						lat_offset: latOffset,
						lng_offset: lngOffset
					})
				})
				.then(response => response.json())
				.then(data => {
					if (data.success) {
						console.log('Section moved successfully');
                        showSectionNotification('Section location updated successfully. Other maps will reflect this change.', 'success');
					} else {
						console.error('Failed to save position:', data.message);
						showSectionNotification('Failed to move section: ' + data.message, 'error');
					}
				})
				.catch(error => {
					console.error('Error saving position:', error);
					showSectionNotification('Error moving section', 'error');
				});
			}

            // Also invalidate after full page load
            window.addEventListener('load', () => {
                if (map) map.invalidateSize();
            });


            // Save view state on move/zoom
            map.on('moveend zoomend', () => {
                const view = {
                    center: map.getCenter(),
                    zoom: map.getZoom()
                };
                sessionStorage.setItem('addPlotMapView', JSON.stringify(view));
            });

            // Handle map resize to prevent zoom issues
            map.on('resize', () => {
                map.invalidateSize();
            });

            // Handle window resize to fix map display issues
            window.addEventListener('resize', () => {
                setTimeout(() => {
                    if (map) {
                        map.invalidateSize();
                    }
                }, 100);
            });

            // Fetch existing plots and add them to the map
            <?php
            // Get all plots with their section information
            $existing_plots_query = "SELECT p.*, 
                                    COALESCE(s.section_name, p.section, 'Unknown Section') as section_name,
                                    s.section_id, 
                                    COALESCE(s.label_lat_offset, 0) as label_lat_offset,
                                    COALESCE(s.label_lng_offset, 0) as label_lng_offset
                                    FROM plots p 
                                    LEFT JOIN sections s ON p.section_id = s.section_id 
                                    ORDER BY COALESCE(s.section_name, p.section, 'Unknown Section')";
            $existing_plots_result = mysqli_query($conn, $existing_plots_query);
            
            // Group plots by section
            $plot_sections = [];
            $section_offsets = []; // Store metadata per section (section_id and label offsets)
            while ($plot = mysqli_fetch_assoc($existing_plots_result)) {
                $section_name = !empty($plot['section_name']) ? $plot['section_name'] : 'Unknown Section';
                if (!isset($plot_sections[$section_name])) {
                    $plot_sections[$section_name] = [];
                    $section_offsets[$section_name] = [
                        'section_id' => $plot['section_id'] ?? null,
                        'label_lat_offset' => floatval($plot['label_lat_offset'] ?? 0),
                        'label_lng_offset' => floatval($plot['label_lng_offset'] ?? 0)
                    ];
                }
                $plot_sections[$section_name][] = $plot;
            }
            
            // Debug: Log sections that were found
            error_log('Sections with plots: ' . implode(', ', array_keys($plot_sections)));
            ?>

            // Store section label markers for movement
            let sectionLabelMarkers = {};
            let moveModeEnabled = false;
            let currentPlotTypeFilter = 'all';
            
            // Store all plots and markers for filtering
            let allPlotMarkers = [];
            let allSectionLabels = [];
            
            // Filter plots by plot type (lawn lot vs apartment-type niches)
            // Apartment-type niches sections: AION, APHRODITE, ATHENA, etc.
            // Note: 'ARIES' is intentionally excluded because it is a lawn lot section, not an apartment-type niche.
            const apartmentTypeSections = ['AION', 'APHRODITE', 'ATHENA', 'ATLAS', 'ARTEMIS', 'APOLLO', 'AURA', 'ASTREA', 'ARIA', 'ARIES'];
            
            function filterPlotsByType(plotTypeFilter) {
                currentPlotTypeFilter = plotTypeFilter;
                
                // Hide all markers first
                allPlotMarkers.forEach(marker => {
                    if (existingLayerGroup.hasLayer(marker)) {
                        existingLayerGroup.removeLayer(marker);
                    }
                });
                allSectionLabels.forEach(label => {
                    if (existingLayerGroup.hasLayer(label)) {
                        existingLayerGroup.removeLayer(label);
                    }
                });
                
                if (plotTypeFilter === 'all') {
                    // Show all markers
                    allPlotMarkers.forEach(marker => {
                        existingLayerGroup.addLayer(marker);
                    });
                    allSectionLabels.forEach(label => {
                        existingLayerGroup.addLayer(label);
                    });
                } else {
                    // Filter based on section type
                    allPlotMarkers.forEach(marker => {
                        const sectionName = marker.options.sectionName || '';
                        const sectionNameUpper = sectionName.toUpperCase();
                        const isApartmentType = apartmentTypeSections.some(aptSection => 
                            sectionNameUpper.includes(aptSection) || sectionNameUpper === aptSection
                        );
                        
                        let shouldShow = false;
                        if (plotTypeFilter === 'niche' && isApartmentType) {
                            shouldShow = true;
                        } else if (plotTypeFilter === 'lawn' && !isApartmentType) {
                            shouldShow = true;
                        }
                        
                        if (shouldShow) {
                            existingLayerGroup.addLayer(marker);
                        }
                    });
                    
                    // Show section labels for visible sections
                    allSectionLabels.forEach(label => {
                        const sectionName = label.options.sectionName || '';
                        const sectionNameUpper = sectionName.toUpperCase().trim();
                        
                        // Check for exact match first, then partial match
                        const isApartmentType = apartmentTypeSections.some(aptSection => {
                            const aptSectionUpper = aptSection.toUpperCase().trim();
                            return sectionNameUpper === aptSectionUpper || 
                                   sectionNameUpper.includes(aptSectionUpper) ||
                                   aptSectionUpper.includes(sectionNameUpper);
                        });
                        
                        let shouldShow = false;
                        if (plotTypeFilter === 'niche' && isApartmentType) {
                            shouldShow = true;
                        } else if (plotTypeFilter === 'lawn' && !isApartmentType) {
                            shouldShow = true;
                        }
                        
                        if (shouldShow) {
                            existingLayerGroup.addLayer(label);
                        }
                    });
                }
            }
            
            // Add existing plots to map with section labels
            <?php foreach ($plot_sections as $section_name => $plots): ?>
            {
                // Calculate center point of the section using only valid coordinate pairs
                let sectionCoords = <?php echo json_encode(array_map(function($p) {
                    return [
                        'lat' => floatval($p['latitude']),
                        'lng' => floatval($p['longitude'])
                    ];
                }, $plots)); ?>;
                
                let validCoords = sectionCoords.filter(coord => 
                    coord !== null &&
                    coord.lat !== null && coord.lng !== null &&
                    !isNaN(coord.lat) && !isNaN(coord.lng) &&
                    isFinite(coord.lat) && isFinite(coord.lng) &&
                    coord.lat >= -90 && coord.lat <= 90 &&
                    coord.lng >= -180 && coord.lng <= 180
                );
                
                // Only create label if we have at least one valid coordinate pair
                if (validCoords.length > 0) {
                    let centerLat = validCoords.reduce((sum, coord) => sum + coord.lat, 0) / validCoords.length;
                    let centerLng = validCoords.reduce((sum, coord) => sum + coord.lng, 0) / validCoords.length;
                    
                    // Validate the calculated center
                    if (isNaN(centerLat) || isNaN(centerLng) || !isFinite(centerLat) || !isFinite(centerLng)) {
                        console.warn('Invalid center calculated for section <?php echo htmlspecialchars($section_name); ?>');
                        // Skip this section if center is invalid
                    } else {

                    // Use the current section center as the handle position, with offsets if available
                    let sectionMeta = <?php echo json_encode(isset($section_offsets[$section_name]) ? $section_offsets[$section_name] : ['section_id' => null, 'label_lat_offset' => 0, 'label_lng_offset' => 0]); ?>;
                    let labelLat = centerLat + (sectionMeta.label_lat_offset || 0);
                    let labelLng = centerLng + (sectionMeta.label_lng_offset || 0);

                    // Add section label
                    let sectionLabel = L.divIcon({
                        className: 'section-label',
                        html: '<div class="section-name"><?php echo htmlspecialchars($section_name); ?></div>',
                        iconSize: [100, 30],
                        iconAnchor: [50, 15]
                    });

                    let labelMarker = L.marker([labelLat, labelLng], {
                        icon: sectionLabel,
                        interactive: true,
                        draggable: false,
                        sectionName: '<?php echo htmlspecialchars($section_name); ?>'
                    });
                    
                    // Add to array for filtering
                    allSectionLabels.push(labelMarker);
                    
                    // Always add label to map initially - filter will handle visibility
                    // This ensures all labels are in the layer group and can be filtered properly
                    labelMarker.addTo(existingLayerGroup);
                    
                    // Log label creation for debugging
                    console.log('Created section label:', {
                        section: '<?php echo htmlspecialchars($section_name); ?>',
                        lat: labelLat,
                        lng: labelLng,
                        hasOffset: (sectionMeta.label_lat_offset || 0) !== 0 || (sectionMeta.label_lng_offset || 0) !== 0
                    });

                    // Store marker reference with section info
                    sectionLabelMarkers['<?php echo htmlspecialchars($section_name); ?>'] = {
                        marker: labelMarker,
                        sectionId: sectionMeta.section_id,
                        originalCenter: { lat: centerLat, lng: centerLng }
                    };

                    // Add drag event to save position
                    labelMarker.on('dragend', function(e) {
                        if (moveModeEnabled) {
                            const newLat = e.target.getLatLng().lat;
                            const newLng = e.target.getLatLng().lng;
                            const sectionInfo = sectionLabelMarkers['<?php echo htmlspecialchars($section_name); ?>'];
                            
                            // Calculate how far the section was moved
                            const latOffset = newLat - sectionInfo.originalCenter.lat;
                            const lngOffset = newLng - sectionInfo.originalCenter.lng;

                            // Persist the movement so all plots in this section are shifted
                            moveSectionPlots(sectionInfo.sectionId, latOffset, lngOffset);
                        }
                    });
                    } // End of valid center check
                } else {
                    console.warn('Section <?php echo htmlspecialchars($section_name); ?> has no valid coordinates for label placement');
                }

                // Add plot markers for this section
                <?php foreach ($plots as $plot): ?>
                try {
                    // Validate coordinates before creating marker
                    const plotLat = <?php echo is_numeric($plot['latitude']) ? floatval($plot['latitude']) : 'null'; ?>;
                    const plotLng = <?php echo is_numeric($plot['longitude']) ? floatval($plot['longitude']) : 'null'; ?>;
                    
                    if (plotLat !== null && plotLng !== null && !isNaN(plotLat) && !isNaN(plotLng) && 
                        isFinite(plotLat) && isFinite(plotLng) && 
                        plotLat >= -90 && plotLat <= 90 && plotLng >= -180 && plotLng <= 180) {
                        
                        const plotMarker = L.circleMarker([plotLat, plotLng], {
                            radius: 5,
                            fillColor: getStatusColor('<?php echo $plot['status']; ?>'),
                            color: '#fff',
                            weight: 1,
                            opacity: 1,
                            fillOpacity: 0.8,
                            sectionName: '<?php echo htmlspecialchars($section_name); ?>',
                            interactive: false
                        });
                        
                        // Add to array for filtering
                        allPlotMarkers.push(plotMarker);
                        
                        // Add to map if filter allows
                        if (currentPlotTypeFilter === 'all') {
                            plotMarker.addTo(existingLayerGroup);
                        } else {
                            const sectionNameUpper = '<?php echo strtoupper($section_name); ?>';
                            const isApartmentType = apartmentTypeSections.some(aptSection => 
                                sectionNameUpper.includes(aptSection) || sectionNameUpper === aptSection
                            );
                            if ((currentPlotTypeFilter === 'niche' && isApartmentType) || 
                                (currentPlotTypeFilter === 'lawn' && !isApartmentType)) {
                                plotMarker.addTo(existingLayerGroup);
                            }
                        }
                    } else {
                        console.warn('Invalid coordinates for plot:', {
                            section: '<?php echo htmlspecialchars($section_name); ?>',
                            lat: plotLat,
                            lng: plotLng
                        });
                    }
                } catch (e) {
                    console.warn('Failed to add plot marker:', e);
                }
                <?php endforeach; ?>
            }
            <?php endforeach; ?>

            // Function to get color based on plot status
            function getStatusColor(status) {
                switch(status) {
                    case 'available': return '#4caf50';
                    case 'reserved': return '#ff9800';
                    case 'occupied': return '#f44336';
                    default: return '#808080';
                }
            }
            
            // Update section label scale based on zoom level
            function updateSectionLabelScale() {
                const currentZoom = map.getZoom();
                const labels = document.querySelectorAll('.section-name');
                labels.forEach(label => {
                    if (currentZoom <= 18) {
                        label.classList.add('small');
                    } else {
                        label.classList.remove('small');
                    }
                });
            }
            
            // Update section labels when zoom changes
            map.on('zoomend', function() {
                updateSectionLabelScale();
            });
            
            // Initial update
            updateSectionLabelScale();
            
            // Initialize plot type filter - show all plots by default
            // Note: All labels should already be added to the map, but we call filter to ensure consistency
            filterPlotsByType('all');
            
            // Debug: Log all section labels that were created
            console.log('Total section labels created:', allSectionLabels.length);
            console.log('Section labels:', allSectionLabels.map(l => l.options.sectionName || 'unknown'));
            
            // Handle plot type filter buttons - ensure they're clickable
            function setupPlotTypeFilterButtons() {
                const plotTypeButtons = document.querySelectorAll('.plot-type-btn');
                if (plotTypeButtons.length === 0) {
                    console.warn('Plot type filter buttons not found, retrying...');
                    setTimeout(setupPlotTypeFilterButtons, 100);
                    return;
                }
                
                plotTypeButtons.forEach(btn => {
                    // Ensure button is clickable
                    btn.style.pointerEvents = 'auto';
                    btn.style.cursor = 'pointer';
                    btn.disabled = false;
                    
                    // Add click event listener
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        // Update active button
                        document.querySelectorAll('.plot-type-btn').forEach(b => b.classList.remove('active'));
                        this.classList.add('active');
                        
                        // Update plot type filter
                        const plotType = this.getAttribute('data-plot-type') || this.dataset.plotType || 'all';
                        filterPlotsByType(plotType);
                    }, { capture: false, passive: false });
                });
                
                console.log('Plot type filter buttons initialized:', plotTypeButtons.length);
            }
            
            // Setup buttons - ensure DOM is ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', setupPlotTypeFilterButtons);
            } else {
                // DOM is already loaded, but wait a bit to ensure everything is rendered
                setTimeout(setupPlotTypeFilterButtons, 50);
            }

            // Handle mode switching
            document.querySelectorAll('input[name="creation_mode"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    currentMode = this.value;
                    const batchContainer = document.getElementById('batchFormContainer');
                    const individualContainer = document.getElementById('individualFormContainer');
                    const batchCoordinates = document.getElementById('batchCoordinates');
                    const individualCoordinates = document.getElementById('individualCoordinates');
                    
                    if (currentMode === 'batch') {
                        batchContainer.style.display = 'block';
                        individualContainer.style.display = 'none';
                        batchCoordinates.style.display = 'block';
                        individualCoordinates.style.display = 'none';
                        // Clear individual plot marker
                        if (individualPlotMarker) {
                            map.removeLayer(individualPlotMarker);
                            individualPlotMarker = null;
                        }
                        // Reset individual coordinates display
                        document.getElementById('individual_coords').textContent = 'Click on the map to set location';
                        document.getElementById('removeIndividualBtn').style.display = 'none';
                    } else {
                        batchContainer.style.display = 'none';
                        individualContainer.style.display = 'block';
                        batchCoordinates.style.display = 'none';
                        individualCoordinates.style.display = 'block';
                        // Clear batch markers
                        removePlotPoints();
                    }
                });
            });
            
            // Add click event to map
            map.on('click', function(e) {
                const lat = e.latlng.lat;
                const lng = e.latlng.lng;
                
                if (currentMode === 'individual') {
                    // Individual mode: place single plot marker
                    if (individualPlotMarker) {
                        map.removeLayer(individualPlotMarker);
                    }
                    
                    const plotPinIcon = L.divIcon({
                        className: 'custom-pin',
                        html: '<div class="pin-marker pin-plot"><div class="pin-pin"></div><div class="pin-label">PLOT</div></div>',
                        iconSize: [30, 40],
                        iconAnchor: [15, 40]
                    });
                    
                    individualPlotMarker = L.marker(e.latlng, { icon: plotPinIcon, draggable: true }).addTo(map);
                    document.getElementById('individual_lat').value = lat;
                    document.getElementById('individual_lng').value = lng;
                    document.getElementById('individual_coords').textContent = `Lat: ${lat.toFixed(6)}, Lng: ${lng.toFixed(6)}`;
                    
                    // Show cancel button
                    document.getElementById('removeIndividualBtn').style.display = 'block';
                    
                    // Make marker draggable
                    individualPlotMarker.on('drag', function(e) {
                        const newLat = e.target.getLatLng().lat;
                        const newLng = e.target.getLatLng().lng;
                        document.getElementById('individual_lat').value = newLat;
                        document.getElementById('individual_lng').value = newLng;
                        document.getElementById('individual_coords').textContent = `Lat: ${newLat.toFixed(6)}, Lng: ${newLng.toFixed(6)}`;
                    });
                    
                    return; // Exit early for individual mode
                }
                
                // Batch mode: handle start/end points
                // Remove invalid class from coordinate fields when points are placed
                document.getElementById('start_lat').classList.remove('is-invalid');
                document.getElementById('start_lng').classList.remove('is-invalid');
                document.getElementById('end_lat').classList.remove('is-invalid');
                document.getElementById('end_lng').classList.remove('is-invalid');
                
                if (isSelectingStart) {
                    document.getElementById('start_lat').value = lat;
                    document.getElementById('start_lng').value = lng;
                    if (startMarker) map.removeLayer(startMarker);
                    
                    // Create custom pin icon for start point
                    const startPinIcon = L.divIcon({
                        className: 'custom-pin',
                        html: '<div class="pin-marker pin-start"><div class="pin-pin"></div><div class="pin-label">START</div></div>',
                        iconSize: [30, 40],
                        iconAnchor: [15, 40]
                    });
                    
                    startMarker = L.marker(e.latlng, { icon: startPinIcon, draggable: true }).addTo(map);
                    document.getElementById('start-coords').textContent = `Lat: ${lat.toFixed(6)}, Lng: ${lng.toFixed(6)}`;
                    isSelectingStart = false;
                    // Show remove button when first point is placed
                    document.getElementById('removeBtn').style.display = 'block';
                } else {
                    document.getElementById('end_lat').value = lat;
                    document.getElementById('end_lng').value = lng;
                    if (endMarker) map.removeLayer(endMarker);
                    
                    // Create custom pin icon for end point
                    const endPinIcon = L.divIcon({
                        className: 'custom-pin',
                        html: '<div class="pin-marker pin-end"><div class="pin-pin"></div><div class="pin-label">END</div></div>',
                        iconSize: [30, 40],
                        iconAnchor: [15, 40]
                    });
                    
                    endMarker = L.marker(e.latlng, { icon: endPinIcon, draggable: true }).addTo(map);
                    document.getElementById('end-coords').textContent = `Lat: ${lat.toFixed(6)}, Lng: ${lng.toFixed(6)}`;
                    
                    // Draw line between start and end points
                    if (plotLine) map.removeLayer(plotLine);
                    plotLine = L.polyline([
                        [startMarker.getLatLng().lat, startMarker.getLatLng().lng],
                        [endMarker.getLatLng().lat, endMarker.getLatLng().lng]
                    ], {
                        color: '#ff7800',
                        weight: 3,
                        opacity: 0.8,
                        dashArray: '10, 5'
                    }).addTo(map);
                    
                    // Add drag events to start marker
                    startMarker.on('drag', function(e) {
                        const newLat = e.target.getLatLng().lat;
                        const newLng = e.target.getLatLng().lng;
                        document.getElementById('start_lat').value = newLat;
                        document.getElementById('start_lng').value = newLng;
                        document.getElementById('start-coords').textContent = `Lat: ${newLat.toFixed(6)}, Lng: ${newLng.toFixed(6)}`;
                        
                        // Update line
                        if (plotLine && endMarker) {
                            plotLine.setLatLngs([
                                [newLat, newLng],
                                [endMarker.getLatLng().lat, endMarker.getLatLng().lng]
                            ]);
                        }
                    });
                    
                    // Add drag events to end marker
                    endMarker.on('drag', function(e) {
                        const newLat = e.target.getLatLng().lat;
                        const newLng = e.target.getLatLng().lng;
                        document.getElementById('end_lat').value = newLat;
                        document.getElementById('end_lng').value = newLng;
                        document.getElementById('end-coords').textContent = `Lat: ${newLat.toFixed(6)}, Lng: ${newLng.toFixed(6)}`;
                        
                        // Update line
                        if (plotLine && startMarker) {
                            plotLine.setLatLngs([
                                [startMarker.getLatLng().lat, startMarker.getLatLng().lng],
                                [newLat, newLng]
                            ]);
                        }
                    });
                    
                    isSelectingStart = true;
                }
            });
        });

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const plotForm = document.getElementById('plotForm');
            const individualPlotForm = document.getElementById('individualPlotForm');
            const deletePlotsForm = document.getElementById('deletePlotsForm');
            const deleteSectionForm = document.getElementById('deleteSectionForm');
            
            // Form validation for individual plot creation
            if (individualPlotForm) {
                individualPlotForm.addEventListener('submit', function(e) {
                    const requiredFields = {
                        'individual_section': 'Section Name',
                        'individual_row': 'Row',
                        'individual_plot_number': 'Plot Number',
                        'individual_lat': 'Plot Location on Map',
                        'individual_lng': 'Plot Location on Map'
                    };

                    const missingFields = [];
                    
                    // Check all required fields
                    for (const [fieldId, fieldName] of Object.entries(requiredFields)) {
                        const field = document.getElementById(fieldId);
                        if (!field || !field.value) {
                            missingFields.push(fieldName);
                            if (field) field.classList.add('is-invalid');
                        } else {
                            if (field) field.classList.remove('is-invalid');
                        }
                    }

                    if (missingFields.length > 0) {
                        e.preventDefault();
                        const errorMessage = 'Please fill in all required fields:\n' + missingFields.join('\n');
                        showSectionNotification(errorMessage, 'error');
                        return;
                    }
                    
                    // Debug: Log form data before submission
                    console.log('Submitting individual plot form with data:', {
                        section: document.getElementById('individual_section').value,
                        row_number: document.getElementById('individual_row').value,
                        plot_number: document.getElementById('individual_plot_number').value,
                        lat: document.getElementById('individual_lat').value,
                        lng: document.getElementById('individual_lng').value
                    });
                    
                    // Verify coordinates are set
                    const lat = document.getElementById('individual_lat').value;
                    const lng = document.getElementById('individual_lng').value;
                    if (!lat || !lng || lat == '0' || lng == '0') {
                        e.preventDefault();
                        showSectionNotification('Please click on the map to set the plot location before submitting.', 'error');
                        return;
                    }
                });
            }
            
            // Form validation for plot creation (batch mode)
            if (plotForm) {
                plotForm.addEventListener('submit', function(e) {
                const requiredFields = {
                    'section': 'Section Name',
                    'plots_per_row': 'Plots per Row',
                    'max_levels': 'Number of Rows',
                    'start_lat': 'Start Point on Map',
                    'start_lng': 'Start Point on Map',
                    'end_lat': 'End Point on Map',
                    'end_lng': 'End Point on Map'
                };

                const missingFields = [];
                
                // Check all required fields
                for (const [fieldId, fieldName] of Object.entries(requiredFields)) {
                    const field = document.getElementById(fieldId);
                    if (!field.value) {
                        missingFields.push(fieldName);
                        field.classList.add('is-invalid');
                    } else {
                        field.classList.remove('is-invalid');
                    }
                }

                // Additional validation for coordinates
                if (!startMarker || !endMarker) {
                    if (!missingFields.includes('Start Point on Map')) {
                        missingFields.push('Plot points on map');
                    }
                }

                if (missingFields.length > 0) {
                    e.preventDefault();
                    const errorMessage = 'Please fill in the required fields:\nStarting Point on Map - End Point on Map';
                    showSectionNotification(errorMessage, 'error');
                }
                });
            }

            // Form validation for plot deletion (used on existing_plots.php)
            if (deletePlotsForm) {
                deletePlotsForm.addEventListener('submit', function(e) {
                    const checkedBoxes = document.querySelectorAll('.plot-checkbox:checked');
                    if (checkedBoxes.length === 0) {
                        e.preventDefault();
                        alert('Please select at least one plot to delete.');
                        return;
                    }
                    
                    if (!confirm('Are you sure you want to delete the selected plots? This action cannot be undone.')) {
                        e.preventDefault();
                    }
                });
            }

            // Form validation + modal confirmation for section deletion on add_plots.php
            if (deleteSectionForm) {
                const deleteSectionModal = document.getElementById('deleteSectionModal');
                const cancelDeleteSectionBtn = document.getElementById('cancelDeleteSectionBtn');
                const confirmDeleteSectionBtn = document.getElementById('confirmDeleteSectionBtn');
                const sectionSelect = document.getElementById('delete_section_id');
                let pendingDelete = false;

                deleteSectionForm.addEventListener('submit', function(e) {
                    // If we already confirmed, allow normal submit
                    if (pendingDelete) {
                        pendingDelete = false;
                        return;
                    }

                    e.preventDefault();
                    if (!sectionSelect || !sectionSelect.value) {
                        showSectionNotification('Please select a section to delete.', 'error');
                        return;
                    }

                    if (deleteSectionModal) {
                        deleteSectionModal.classList.add('active');
                    }
                });

                if (cancelDeleteSectionBtn && deleteSectionModal) {
                    cancelDeleteSectionBtn.addEventListener('click', function() {
                        deleteSectionModal.classList.remove('active');
                    });
                }

                if (confirmDeleteSectionBtn && deleteSectionModal) {
                    confirmDeleteSectionBtn.addEventListener('click', function() {
                        deleteSectionModal.classList.remove('active');
                        pendingDelete = true;
                        deleteSectionForm.submit();
                    });
                }
            }
        });

        // Auto-show notification bubbles for any PHP flash messages (add plots success/duplicate/error)
        document.addEventListener('DOMContentLoaded', function() {
            const addSuccessEl = document.getElementById('addPlotSuccess');
            if (addSuccessEl) {
                const msg = addSuccessEl.dataset.message || addSuccessEl.textContent.trim();
                if (msg) {
                    showSectionNotification(msg, 'success');
                    // Remove the element after displaying to prevent it from showing on refresh
                    addSuccessEl.remove();
                }
            }
            const addDuplicateEl = document.getElementById('addPlotDuplicate');
            if (addDuplicateEl) {
                const msg = addDuplicateEl.dataset.message || addDuplicateEl.textContent.trim();
                if (msg) {
                    showSectionNotification(msg, 'error');
                    // Remove the element after displaying to prevent it from showing on refresh
                    addDuplicateEl.remove();
                }
            }
            const addErrorEl = document.getElementById('addPlotError');
            if (addErrorEl) {
                const msg = addErrorEl.dataset.message || addErrorEl.textContent.trim();
                if (msg) {
                    showSectionNotification(msg, 'error');
                    // Remove the element after displaying to prevent it from showing on refresh
                    addErrorEl.remove();
                }
            }
            const addDeleteSuccessEl = document.getElementById('addPlotDeleteSuccess');
            if (addDeleteSuccessEl) {
                const msg = addDeleteSuccessEl.dataset.message || addDeleteSuccessEl.textContent.trim();
                if (msg) {
                    showSectionNotification(msg, 'success');
                    // Remove the element after displaying to prevent it from showing on refresh
                    addDeleteSuccessEl.remove();
                }
            }

            // Fallback: convert any remaining alerts into notification bubbles
            const alerts = document.querySelectorAll('.alert-success, .alert-danger, .alert-warning');
            alerts.forEach(alert => {
                const message = alert.textContent.trim();
                if (message) {
                    let type = 'success';
                    if (alert.classList.contains('alert-danger') || alert.classList.contains('alert-warning')) {
                        type = 'error';
                    }
                    showSectionNotification(message, type);
                }
                setTimeout(() => {
                    alert.classList.add('fade-out');
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 500); // Wait for fade-out animation to complete
                }, 500);
            });
        });

        // Add styles for invalid fields
        const styleSheet = document.createElement('style');
        styleSheet.textContent = `
            .is-invalid {
                border-color: #dc3545 !important;
                background-color: #fff8f8 !important;
            }
            .text-danger {
                color: #dc3545 !important;
            }
        `;
        document.head.appendChild(styleSheet);

        // Helper to show notification bubble on this page
        function showSectionNotification(message, type = 'success') {
            const notification = document.getElementById('sectionNotification');
            const text = document.getElementById('sectionNotificationText');
            if (!notification || !text) return;

            text.textContent = message;
            notification.classList.remove('success-notification', 'error-notification', 'show', 'hide');
            notification.style.display = 'flex';

            if (type === 'error') {
                notification.classList.add('error-notification');
            } else {
                notification.classList.add('success-notification');
            }

            // Trigger show state
            requestAnimationFrame(() => {
                notification.classList.add('show');
            });

            // Hide after 5 seconds
            setTimeout(() => {
                notification.classList.remove('show');
                notification.classList.add('hide');
                setTimeout(() => {
                    notification.style.display = 'none';
                }, 400);
            }, 5000);
        }

        // Add these styles to your existing stylesheet
        const mapStyles = document.createElement('style');
        mapStyles.textContent = `
            .section-label {
                background: none;
                border: none;
                z-index: 1000 !important;
            }
            .leaflet-marker-icon.section-label {
                z-index: 1000 !important;
            }
            .section-name {
                background: rgba(255, 255, 255, 0.75);
                padding: 2px 6px;
                border-radius: 4px;
                border: 2px solid rgba(51, 51, 51, 0.7);
                font-weight: bold;
                font-size: 10px;
                text-align: center;
                white-space: nowrap;
                box-shadow: 0 2px 4px rgba(0,0,0,0.15);
                transition: all 0.2s ease;
                z-index: 1001 !important;
                position: relative;
                max-width: 100px;
                width: 100%;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .section-name.small {
                font-size: 9px;
                max-width: 80px;
                padding: 1px 4px;
            }
            
            .section-label.draggable .section-name {
                border-color: #2b4c7e;
                box-shadow: 0 2px 8px rgba(43, 76, 126, 0.4);
                cursor: move !important;
            }
            
            .section-label.draggable .section-name:hover {
                background: rgba(43, 76, 126, 0.1);
                transform: scale(1.05);
                cursor: move !important;
            }
            
            .section-label.draggable {
                cursor: move !important;
            }
            
            .custom-pin {
                background: none;
                border: none;
            }
            
            .pin-marker {
                position: relative;
                display: flex;
                flex-direction: column;
                align-items: center;
            }
            
            .pin-pin {
                width: 20px;
                height: 20px;
                border-radius: 50% 50% 50% 0;
                transform: rotate(-45deg);
                border: 3px solid #fff;
                box-shadow: 0 2px 6px rgba(0,0,0,0.3);
                margin-bottom: 2px;
            }
            
            .pin-start .pin-pin {
                background: #28a745;
            }
            
            .pin-end .pin-pin {
                background: #dc3545;
            }
            
            .pin-plot .pin-pin {
                background: #2b4c7e;
            }
            
            .pin-label {
                background: rgba(0, 0, 0, 0.8);
                color: white;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 10px;
                font-weight: bold;
                white-space: nowrap;
                transform: translateY(-5px);
            }
            
            .pin-marker:hover .pin-pin {
                transform: rotate(-45deg) scale(1.1);
                transition: transform 0.2s ease;
            }
        `;
        document.head.appendChild(mapStyles);
    </script>
</body>
</html> 
</html> 