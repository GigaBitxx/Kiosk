<?php
require_once '../includes/auth_check.php';
if ($_SESSION['role'] !== 'staff') {
    header('Location: ../login.php');
    exit();
}
require_once '../config/database.php';

// For sidebar active state
$current_page = basename($_SERVER['PHP_SELF']);

// Initialize messages
$success_message = '';
$error_message = '';

// Handle success messages from URL parameters
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'record_deleted') {
        $success_message = "Record deleted successfully!";
    } elseif ($_GET['success'] === 'bulk_deleted_section') {
        $count = isset($_GET['count']) ? (int)$_GET['count'] : 0;
        $success_message = "Successfully deleted $count records from the selected section!";
    } elseif ($_GET['success'] === 'bulk_deleted_rows') {
        $count = isset($_GET['count']) ? (int)$_GET['count'] : 0;
        $label = isset($_GET['label']) ? urldecode($_GET['label']) : 'the selected rows';
        $success_message = "Successfully deleted $count records from {$label} in the selected section.";
    }
}

// Handle bulk import results from URL parameters
if (isset($_GET['success_count'])) {
    $success_count = (int)$_GET['success_count'];
    if ($success_count > 0) {
        $success_message = "Successfully imported $success_count records!";
    }
}
if (isset($_GET['error_count'])) {
    $error_count = (int)$_GET['error_count'];
    if ($error_count > 0) {
        $errors_text = isset($_GET['errors']) ? urldecode($_GET['errors']) : '';
        $error_message = "Skipped $error_count records due to errors. " . $errors_text;
    }
}

/**
 * Normalize stored date strings (multiple formats) into a single display format.
 * Format: Month-Day-Year (e.g., Sept-26-2022)
 */
function formatDisplayDate($value) {
    if (empty($value) || $value === '0000-00-00') {
        return '—';
    }

    $value = trim($value);
    $knownFormats = [
        'Y-m-d',
        'm/d/Y',
        'd/m/Y',
        'm-d-Y',
        'd-m-Y',
        'd.m.Y',
        'Y/m/d'
    ];

    foreach ($knownFormats as $format) {
        $dt = DateTime::createFromFormat($format, $value);
        if ($dt && $dt->format($format) === $value) {
            $formatted = $dt->format('M-j-Y');
            return str_replace('Sep-', 'Sept-', $formatted);
        }
    }

    $timestamp = strtotime($value);
    if ($timestamp !== false) {
        $formatted = date('M-j-Y', $timestamp);
        return str_replace('Sep-', 'Sept-', $formatted);
    }

                return '—';
}

/**
 * Convert a numeric row index (1 = A) into its letter representation.
 * Supports numbers beyond 26 (27 = AA, 28 = AB, etc.)
 */
function rowNumberToLetter($rowNumber) {
    if (!is_numeric($rowNumber)) {
        return '';
    }

    $rowNumber = (int)$rowNumber;
    if ($rowNumber < 1) {
        return '';
    }

    $letter = '';
    while ($rowNumber > 0) {
        $remainder = ($rowNumber - 1) % 26;
        $letter = chr(65 + $remainder) . $letter;
        $rowNumber = intval(($rowNumber - 1) / 26);
    }
    return $letter;
}

/**
 * Convert a row letter (A = 1, AA = 27, etc.) into its numeric representation.
 */
function rowLetterToNumber($letter) {
    if (empty($letter)) {
        return null;
    }

    $letter = strtoupper(trim($letter));
    if (!preg_match('/^[A-Z]+$/', $letter)) {
        return null;
    }

    $number = 0;
    $length = strlen($letter);
    for ($i = 0; $i < $length; $i++) {
        $number = $number * 26 + (ord($letter[$i]) - 64);
    }
    return $number;
}

// Fetch only sections that already have plots for the Bulk Import dropdown
$sections_import_query = "SELECT s.section_id, s.section_name
                          FROM sections s
                          JOIN plots p ON p.section_id = s.section_id
                          GROUP BY s.section_id, s.section_name
                          ORDER BY s.section_name";
$sections_import_result = mysqli_query($conn, $sections_import_query);

// Get archived records for Backup & Restore
$archive_query = "SELECT ar.*, p.plot_number, p.row_number, s.section_name, u.username as archived_by_user 
                 FROM archived_deceased_records ar 
                 JOIN plots p ON ar.plot_id = p.plot_id 
                 LEFT JOIN sections s ON p.section_id = s.section_id
                 LEFT JOIN users u ON ar.archived_by = u.user_id 
                 ORDER BY ar.archived_at DESC";
$archive_result = mysqli_query($conn, $archive_query);

// Handle single record deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_record') {
    $record_id = intval($_POST['record_id']);
    
    if ($record_id > 0) {
        // First get the plot_id to update plot status (if needed)
        $plot_query = "SELECT plot_id FROM deceased_records WHERE record_id = ?";
        $stmt = mysqli_prepare($conn, $plot_query);
        mysqli_stmt_bind_param($stmt, "i", $record_id);
        mysqli_stmt_execute($stmt);
        $plot_result = mysqli_stmt_get_result($stmt);
        $plot_data = mysqli_fetch_assoc($plot_result);
        
        if ($plot_data) {
            $plot_id = (int)$plot_data['plot_id'];
            
            // Delete the deceased record
            $delete_query = "DELETE FROM deceased_records WHERE record_id = ?";
            $stmt = mysqli_prepare($conn, $delete_query);
            mysqli_stmt_bind_param($stmt, "i", $record_id);
            
            if (mysqli_stmt_execute($stmt)) {
                // Only mark plot as available if there are no more deceased records for this plot
                $count_query = "SELECT COUNT(*) AS cnt FROM deceased_records WHERE plot_id = ?";
                $count_stmt = mysqli_prepare($conn, $count_query);
                mysqli_stmt_bind_param($count_stmt, "i", $plot_id);
                mysqli_stmt_execute($count_stmt);
                $count_result = mysqli_stmt_get_result($count_stmt);
                $count_row = mysqli_fetch_assoc($count_result);
                $remaining = (int)($count_row['cnt'] ?? 0);

                if ($remaining === 0) {
                    $update_plot = "UPDATE plots SET status = 'available' WHERE plot_id = ?";
                    $stmt = mysqli_prepare($conn, $update_plot);
                    mysqli_stmt_bind_param($stmt, "i", $plot_id);
                    mysqli_stmt_execute($stmt);
                }
                
                // After deleting a single record, return to the Delete Records section
                header('Location: deceased_records.php?success=record_deleted&mode=delete');
                exit();
            } else {
                $error_message = "Error deleting record: " . mysqli_error($conn);
            }
        } else {
            $error_message = "Record not found.";
        }
    } else {
        $error_message = "Invalid record ID.";
    }
}

// Handle bulk deletion by section
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_delete_section') {
    $section_id = intval($_POST['section_id']);
    
    if ($section_id > 0) {
        // Get all records in the section to update plot statuses
        $records_query = "SELECT d.record_id, d.plot_id, d.full_name 
                         FROM deceased_records d 
                         JOIN plots p ON d.plot_id = p.plot_id 
                         WHERE p.section_id = ?";
        $stmt = mysqli_prepare($conn, $records_query);
        mysqli_stmt_bind_param($stmt, "i", $section_id);
        mysqli_stmt_execute($stmt);
        $records_result = mysqli_stmt_get_result($stmt);
        
        $deleted_count = 0;
        $plot_ids = [];
        
        while ($record = mysqli_fetch_assoc($records_result)) {
            $plot_ids[] = $record['plot_id'];
        }
        
        if (!empty($plot_ids)) {
            // Delete all deceased records in the section
            $delete_query = "DELETE d FROM deceased_records d 
                           JOIN plots p ON d.plot_id = p.plot_id 
                           WHERE p.section_id = ?";
            $stmt = mysqli_prepare($conn, $delete_query);
            mysqli_stmt_bind_param($stmt, "i", $section_id);
            
            if (mysqli_stmt_execute($stmt)) {
                $deleted_count = mysqli_stmt_affected_rows($stmt);
                
                // Update all plot statuses to available
                $plot_ids_str = implode(',', array_map('intval', $plot_ids));
                $update_plots = "UPDATE plots SET status = 'available' WHERE plot_id IN ($plot_ids_str)";
                mysqli_query($conn, $update_plots);
                
                // After bulk deleting by section, return to the Delete Records section
                header('Location: deceased_records.php?success=bulk_deleted_section&count=' . urlencode($deleted_count) . '&mode=delete');
                exit();
            } else {
                $error_message = "Error deleting records: " . mysqli_error($conn);
            }
        } else {
            $error_message = "No records found in the selected section.";
        }
    } else {
        $error_message = "Invalid section selected.";
    }
}

// Handle bulk deletion by a specific row within a section
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_delete_rows') {
    $section_id_rows = intval($_POST['section_id_rows'] ?? 0);
    $row_value = $_POST['row_value'] ?? '';
    $row_selection_input = $row_value !== '' ? [(string)$row_value] : [];
    
    if ($section_id_rows <= 0) {
        $error_message = "Please select a section for the row-based deletion.";
    } elseif (empty($row_selection_input)) {
        $error_message = "Please choose at least one row to delete.";
    } else {
        $row_numbers = [];
        foreach ($row_selection_input as $value) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            if (is_numeric($value)) {
                $rowNumber = (int)$value;
            } else {
                $rowNumber = rowLetterToNumber($value);
            }
            if ($rowNumber !== null) {
                $row_numbers[] = $rowNumber;
            }
        }
        $row_numbers = array_unique($row_numbers);
        
        if (empty($row_numbers)) {
            $error_message = "No valid rows were selected for deletion.";
        } else {
            $placeholders = implode(',', array_fill(0, count($row_numbers), '?'));
            $params = array_merge([$section_id_rows], $row_numbers);
            $types = str_repeat('i', count($params));
            
            // Fetch affected records first (for plot updates and validation)
            $select_sql = "SELECT d.record_id, d.plot_id 
                           FROM deceased_records d 
                           JOIN plots p ON d.plot_id = p.plot_id 
                           WHERE p.section_id = ? AND p.row_number IN ($placeholders)";
            $stmt = mysqli_prepare($conn, $select_sql);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, $types, ...$params);
                mysqli_stmt_execute($stmt);
                $records_result = mysqli_stmt_get_result($stmt);
                
                $records_to_delete = [];
                while ($record = mysqli_fetch_assoc($records_result)) {
                    $records_to_delete[] = $record;
                }
                mysqli_stmt_close($stmt);
                
                if (empty($records_to_delete)) {
                    $error_message = "No records found for the selected section and rows.";
                } else {
                    $delete_sql = "DELETE d 
                                   FROM deceased_records d 
                                   JOIN plots p ON d.plot_id = p.plot_id 
                                   WHERE p.section_id = ? AND p.row_number IN ($placeholders)";
                    $stmt = mysqli_prepare($conn, $delete_sql);
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, $types, ...$params);
                        if (mysqli_stmt_execute($stmt)) {
                            $deleted_count = mysqli_stmt_affected_rows($stmt);
                            
                            // Update affected plots to available
                            $plot_ids = array_unique(array_column($records_to_delete, 'plot_id'));
                            if (!empty($plot_ids)) {
                                $plot_placeholders = implode(',', array_fill(0, count($plot_ids), '?'));
                                $plot_types = str_repeat('i', count($plot_ids));
                                
                                $update_sql = "UPDATE plots SET status = 'available' WHERE plot_id IN ($plot_placeholders)";
                                $update_stmt = mysqli_prepare($conn, $update_sql);
                                if ($update_stmt) {
                                    mysqli_stmt_bind_param($update_stmt, $plot_types, ...$plot_ids);
                                    mysqli_stmt_execute($update_stmt);
                                    mysqli_stmt_close($update_stmt);
                                }
                            }
                            
                            $selected_labels = array_map(function($value) {
                                $value = trim((string)$value);
                                if ($value === '') {
                                    return '';
                                }
                                if (is_numeric($value)) {
                                    $letter = rowNumberToLetter((int)$value);
                                    return $letter ? 'ROW ' . $letter : 'Row ' . $value;
                                }
                                $letterNumber = rowLetterToNumber($value);
                                if ($letterNumber !== null) {
                                    return 'ROW ' . strtoupper($value);
                                }
                                return 'Row ' . strtoupper($value);
                            }, $row_selection_input);
                            $selected_labels = array_filter($selected_labels);
                            $label_text = !empty($selected_labels) ? implode(', ', $selected_labels) : 'the selected rows';
                            
                            // After bulk deleting by rows, return to the Delete Records section
                            header('Location: deceased_records.php?success=bulk_deleted_rows&count=' . urlencode($deleted_count) . '&label=' . urlencode($label_text) . '&mode=delete');
                            exit();
                        } else {
                            $error_message = "Error deleting records by rows: " . mysqli_error($conn);
                        }
                        mysqli_stmt_close($stmt);
                    } else {
                        $error_message = "Unable to prepare deletion query for the selected rows.";
                    }
                }
            } else {
                $error_message = "Unable to prepare validation query for the selected rows.";
            }
        }
    }
}

// Fetch all deceased records for individual deletion (including row for filtering)
$records_query = "SELECT d.record_id, d.full_name, p.plot_number, p.row_number, s.section_name, s.section_id 
                  FROM deceased_records d 
                  JOIN plots p ON d.plot_id = p.plot_id 
                  LEFT JOIN sections s ON p.section_id = s.section_id
                  ORDER BY d.full_name";
$records_result = mysqli_query($conn, $records_query);

// Fetch sections that currently have at least one deceased record (for bulk delete dropdown)
$sections_with_records_query = "SELECT s.section_id, s.section_name
                               FROM sections s
                               JOIN plots p ON p.section_id = s.section_id
                               JOIN deceased_records d ON d.plot_id = p.plot_id
                               GROUP BY s.section_id, s.section_name
                               ORDER BY s.section_name";
$sections_with_records_result = mysqli_query($conn, $sections_with_records_query);

// Fetch all sections for delete filters
$all_sections_query = "SELECT section_id, section_name FROM sections ORDER BY section_name";
$all_sections_result = mysqli_query($conn, $all_sections_query);

// Handle bulk import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_import') {
    $success_count = 0;
    $error_count = 0;
    $errors = [];
    $selected_section_id = isset($_POST['section_id']) ? intval($_POST['section_id']) : 0;
    $selected_row = isset($_POST['row_number']) ? intval($_POST['row_number']) : 0;
    
    // Validate section and row selection
    if ($selected_section_id <= 0) {
        $error_message = "Please select a section for the bulk import.";
    } else if ($selected_row <= 0) {
        $error_message = "Please select a row for the bulk import.";
    } else if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $tmp_name = $_FILES['csv_file']['tmp_name'];
        
        // Function to validate and parse dates (supports multiple formats and Excel serials)
        function validateImportDate($dateStr, $fieldName, $rowNumber) {
            if ($dateStr === null) {
                return null; // Empty date is acceptable
            }

            $dateStr = trim((string)$dateStr);
            if ($dateStr === '') {
                return null;
            }
            
            // Handle raw Excel serial numbers (e.g., 45123)
            if (is_numeric($dateStr)) {
                $serial = (int)$dateStr;
                // Reasonable Excel serial range (approx years 1900-2100)
                if ($serial > 0 && $serial < 600000) {
                    // Excel's day 1 is 1899-12-31, but due to the 1900 leap year bug,
                    // PHP conversion commonly uses 1899-12-30 as the base.
                    $base = new DateTime('1899-12-30');
                    $base->modify("+{$serial} days");
                    $year = (int)$base->format('Y');
                    if ($year >= 1800 && $year <= (int)date('Y') + 10) {
                        return $base->format('Y-m-d');
                    }
                }
            }
            
            // Common date formats to try (day-first, month-first, ISO, dotted, etc.)
            $formats = [
                'd/m/Y', 'm/d/Y',
                'Y-m-d',
                'd-m-Y', 'm-d-Y',
                'd.m.Y', 'm.d.Y',
                'Y/m/d',
                // 2-digit year variants
                'd/m/y', 'm/d/y',
                'd-m-y', 'm-d-y',
                'd.m.y', 'm.d.y',
            ];
            
            foreach ($formats as $format) {
                $date = DateTime::createFromFormat($format, $dateStr);
                if ($date && $date->format($format) === $dateStr) {
                    $year = (int)$date->format('Y');
                    if ($year >= 1800 && $year <= (int)date('Y') + 10) {
                        return $date->format('Y-m-d');
                    }
                }
            }
            
            // Fallback to strtotime for other "text dates" (e.g., "Sep 9, 2023")
            $timestamp = strtotime($dateStr);
            if ($timestamp !== false) {
                $year = (int)date('Y', $timestamp);
                if ($year >= 1800 && $year <= (int)date('Y') + 10) {
                    return date('Y-m-d', $timestamp);
                }
            }
            
            return false; // Invalid/unrecognized date format
        }
        
        if (($handle = fopen($tmp_name, "r")) !== FALSE) {
            // Skip header row (length 0 lets PHP handle long lines / long text fields)
            $header = fgetcsv($handle, 0, ",");
            $csv_row_number = 1; // Track actual CSV row number (starting after header)
            
            while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
                $csv_row_number++; // Increment for each CSV row read
                
                // Enhanced blank row detection
                $row_data = array_map('trim', $data);
                
                // Check if all fields are empty
                $has_any_data = false;
                foreach ($row_data as $field) {
                    if (!empty($field) && $field !== '' && $field !== null) {
                        $has_any_data = true;
                        break;
                    }
                }
                
                // If no data at all, skip this row
                if (!$has_any_data) {
                    continue; // Skip completely blank rows
                }
                
                // If only the NO. column (A) has data and all other columns are blank, skip this row
                $has_meaningful_data = false;
                for ($i = 1; $i < count($row_data); $i++) { // check columns B onwards
                    if (isset($row_data[$i]) && $row_data[$i] !== '') {
                        $has_meaningful_data = true;
                        break;
                    }
                }
                if (!$has_meaningful_data) {
                    continue; // Skip rows where only the NO. column is filled
                }
                
                // Check if this row has meaningful data beyond just a row number
                // (deceased name is in column 2, which is index 2)
                $deceased_name_field = isset($row_data[2]) ? $row_data[2] : '';
                $lessee_name_field = isset($row_data[1]) ? $row_data[1] : '';
                
                // If both deceased name and lessee name are empty, this is likely a blank data row
                if (empty($deceased_name_field) && empty($lessee_name_field)) {
                    continue; // Skip rows without essential person data
                }
                
                // Row number for error reporting (only count non-empty rows)
                $row_number = $csv_row_number;
                
                // Map CSV columns to database fields based on actual Excel structure:
                // Column A: NO., B: NAME OF LESSEE, C: NAME OF DECEASED, D: DATE OF DEATH, E: DATE OF BIRTH, F: DATE ACQUIRED, G: DUE DATE, H: ADDRESS
                $row_no = isset($data[0]) ? trim($data[0]) : '';           // Column A: NO.
                $lessee_name = isset($data[1]) ? trim($data[1]) : '';      // Column B: NAME OF LESSEE
                $deceased_name = isset($data[2]) ? trim($data[2]) : '';    // Column C: NAME OF DECEASED
                $date_death = isset($data[3]) ? trim($data[3]) : '';       // Column D: DATE OF DEATH
                $date_of_birth = isset($data[4]) ? trim($data[4]) : '';    // Column E: DATE OF BIRTH
                $date_acquired = isset($data[5]) ? trim($data[5]) : '';    // Column F: DATE ACQUIRED
                $due_date = isset($data[6]) ? trim($data[6]) : '';         // Column G: DUE DATE
                $address = isset($data[7]) ? trim($data[7]) : '';          // Column H: ADDRESS
                
                // Final check - if deceased name is still empty after all our filtering, skip silently
                if (empty($deceased_name)) {
                    continue; // Skip rows without deceased names (likely blank rows that passed initial filter)
                }
                
                // Validate NO. column (intended plot number within the selected row)
                if (empty($row_no) || !preg_match('/^\d+$/', $row_no)) {
                    $errors[] = "Row $row_number ($deceased_name): Missing or invalid NO. value. It must be a numeric plot number. Skipping this record.";
                    $error_count++;
                    continue;
                }
                $plot_number = intval($row_no);
                
                // Parse and validate dates using the helper function
                $parsed_date_of_birth = validateImportDate($date_of_birth, 'date of birth', $row_number);
                if ($parsed_date_of_birth === false && !empty($date_of_birth)) {
                    $errors[] = "Row $row_number ($deceased_name): Invalid date of birth format '$date_of_birth'. Please use DD/MM/YYYY, MM/DD/YYYY, or YYYY-MM-DD format. Skipping this record.";
                    $error_count++;
                    continue;
                }
                // If date of birth is missing, leave it as NULL in the database
                if ($parsed_date_of_birth === null) {
                    $parsed_date_of_birth = null;
                }
                
                $parsed_date_of_death = validateImportDate($date_death, 'date of death', $row_number);
                if ($parsed_date_of_death === false && !empty($date_death)) {
                    $errors[] = "Row $row_number ($deceased_name): Invalid date of death format '$date_death'. Please use DD/MM/YYYY, MM/DD/YYYY, or YYYY-MM-DD format. Skipping this record.";
                    $error_count++;
                    continue;
                }
                // If date of death is missing, leave it as NULL in the database
                if ($parsed_date_of_death === null) {
                    $parsed_date_of_death = null;
                }
                
                // Date acquired (stored separately and also used to derive burial_date)
                $parsed_date_acquired = validateImportDate($date_acquired, 'date acquired', $row_number);
                if ($parsed_date_acquired === false && !empty($date_acquired)) {
                    // Non-critical field; log warning but do not skip the entire record
                    $errors[] = "Row $row_number ($deceased_name): Invalid date acquired format '$date_acquired'. Field will be left blank.";
                    $parsed_date_acquired = null;
                }

                $burial_date = $parsed_date_acquired;
                // If burial date is missing, but we have a valid date of death, use that as a fallback
                if ($burial_date === null && $parsed_date_of_death !== null) {
                    $burial_date = $parsed_date_of_death;
                }

                // Due date (e.g., contract/lease due); do not fail the entire row if invalid
                $parsed_due_date = validateImportDate($due_date, 'due date', $row_number);
                if ($parsed_due_date === false && !empty($due_date)) {
                    $errors[] = "Row $row_number ($deceased_name): Invalid due date format '$due_date'. Field will be left blank.";
                    $parsed_due_date = null;
                }
                
                // Logical date validation - birth date should be before death date when both are present
                if ($parsed_date_of_birth !== null && $parsed_date_of_death !== null && $parsed_date_of_birth > $parsed_date_of_death) {
                    $errors[] = "Row $row_number ($deceased_name): Date of birth ($date_of_birth) cannot be after date of death ($date_death). Skipping this record.";
                    $error_count++;
                    continue;
                }
                
                // Handle burial date logic - if Date Acquired is before death date,
                // it likely represents plot acquisition date, not burial date
                if ($burial_date !== null && $parsed_date_of_death !== null && $burial_date < $parsed_date_of_death) {
                    // Use death date as burial date when Date Acquired is before death date
                    $burial_date = $parsed_date_of_death;
                    // Note: This is common when Date Acquired represents plot purchase date, not burial date
                }
                
                // Try to find or create a plot for this record
                $plot_id = null;
                
                // First, try to find the specific plot number in the selected section and row
                $plot_query = "SELECT plot_id, status FROM plots WHERE section_id = ? AND row_number = ? AND plot_number = ? LIMIT 1";
                $stmt = mysqli_prepare($conn, $plot_query);
                mysqli_stmt_bind_param($stmt, "iii", $selected_section_id, $selected_row, $plot_number);
                mysqli_stmt_execute($stmt);
                $plot_result = mysqli_stmt_get_result($stmt);
                
                if ($plot_result && mysqli_num_rows($plot_result) > 0) {
                    $plot_row = mysqli_fetch_assoc($plot_result);
                    $plot_id = $plot_row['plot_id'];
                    if ($plot_row['status'] === 'available') {
                        // Update plot status to occupied for a brand new interment
                        $update_plot = "UPDATE plots SET status = 'occupied' WHERE plot_id = ?";
                        $stmt = mysqli_prepare($conn, $update_plot);
                        mysqli_stmt_bind_param($stmt, "i", $plot_id);
                        mysqli_stmt_execute($stmt);
                    } else {
                        // Plot already has an occupant – update the existing deceased record instead of skipping
                        $existing_record_q = "SELECT record_id FROM deceased_records WHERE plot_id = ? ORDER BY record_id DESC LIMIT 1";
                        $stmt = mysqli_prepare($conn, $existing_record_q);
                        mysqli_stmt_bind_param($stmt, "i", $plot_id);
                        mysqli_stmt_execute($stmt);
                        $existing_result = mysqli_stmt_get_result($stmt);
                        if ($existing_result && mysqli_num_rows($existing_result) > 0) {
                            $existing = mysqli_fetch_assoc($existing_result);
                            $existing_record_id = (int)$existing['record_id'];

                            // Update the existing record with the new CSV data (acts like "overwrite")
                            $update_deceased_q = "UPDATE deceased_records 
                                                  SET full_name = ?, 
                                                      date_of_birth = ?, 
                                                      date_of_death = ?, 
                                                      burial_date = ?, 
                                                      date_acquired = ?, 
                                                      due_date = ?, 
                                                      address = ?, 
                                                      next_of_kin = ?, 
                                                      contact_number = ?
                                                  WHERE record_id = ?";
                            $stmt = mysqli_prepare($conn, $update_deceased_q);
                            $contact_number = null;
                            mysqli_stmt_bind_param(
                                $stmt,
                                "ssssssssi",
                                $deceased_name,
                                $parsed_date_of_birth,
                                $parsed_date_of_death,
                                $burial_date,
                                $parsed_date_acquired,
                                $parsed_due_date,
                                $address,
                                $lessee_name,
                                $contact_number,
                                $existing_record_id
                            );
                            if (mysqli_stmt_execute($stmt)) {
                                // Treat as a successful import/update
                                $success_count++;
                                continue; // Go to next CSV row; no need to insert a new record
                            } else {
                                $errors[] = "Row $row_number ($deceased_name): Failed to update existing record for occupied plot. Skipping this record.";
                                $error_count++;
                                continue;
                            }
                        } else {
                            // No deceased record found even though plot is not available.
                            // This likely means the plot status was set manually earlier.
                            // In this case, proceed to insert a new deceased record below
                            // and keep the plot status as-is (usually 'occupied').
                            // (Do not treat this as an error; fall through to the insert logic.)
                        }
                    }
                } else {
                    // Plot does not exist: create it with occupied status to keep numbering accurate
                    $insert_plot = "INSERT INTO plots (section_id, row_number, plot_number, latitude, longitude, status, level_number, max_levels, is_multi_level) 
                                    VALUES (?, ?, ?, NULL, NULL, 'occupied', 1, 1, 0)";
                    $stmt = mysqli_prepare($conn, $insert_plot);
                    mysqli_stmt_bind_param($stmt, "iii", $selected_section_id, $selected_row, $plot_number);
                    if (mysqli_stmt_execute($stmt)) {
                        $plot_id = mysqli_insert_id($conn);
                    } else {
                        $errors[] = "Row $row_number ($deceased_name): Failed to create plot number $plot_number. Skipping this record.";
                        $error_count++;
                        continue;
                    }
                }
                
                // Insert deceased record (including date_acquired, due_date, and address)
                $insert_query = "INSERT INTO deceased_records (full_name, date_of_birth, date_of_death, burial_date, date_acquired, due_date, address, plot_id, next_of_kin, contact_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = mysqli_prepare($conn, $insert_query);
                
                // Set contact_number to NULL (blank) as requested for future input
                $contact_number = null;
                
                mysqli_stmt_bind_param($stmt, "sssssssiss", 
                    $deceased_name, 
                    $parsed_date_of_birth, 
                    $parsed_date_of_death, 
                    $burial_date, 
                    $parsed_date_acquired,
                    $parsed_due_date,
                    $address,
                    $plot_id, 
                    $lessee_name, 
                    $contact_number
                );
                
                if (mysqli_stmt_execute($stmt)) {
                    $success_count++;
                } else {
                    $errors[] = "Row $row_number ($deceased_name): Failed to insert record - " . mysqli_error($conn) . ". Skipping this record.";
                    $error_count++;
                    
                    // Revert plot status if record insertion failed - plot will be skipped for this import
                    if ($plot_id) {
                        $revert_plot = "UPDATE plots SET status = 'available' WHERE plot_id = ?";
                        $stmt = mysqli_prepare($conn, $revert_plot);
                        mysqli_stmt_bind_param($stmt, "i", $plot_id);
                        mysqli_stmt_execute($stmt);
                    }
                    // Continue to next record instead of stopping
                }
            }
            fclose($handle);
        }
    }
    
    // Build redirect URL with results and ensure we return to the Bulk Import section
    $redirect_params = [];
    if ($success_count > 0) {
        $redirect_params[] = 'success_count=' . urlencode($success_count);
    }
    if ($error_count > 0) {
        $redirect_params[] = 'error_count=' . urlencode($error_count);
        $error_text = implode("; ", array_slice($errors, 0, 5));
        if (count($errors) > 5) {
            $error_text .= " (and " . (count($errors) - 5) . " more errors)";
        }
        $redirect_params[] = 'errors=' . urlencode($error_text);
    }
    // Always include mode=import so the Bulk Import card is opened on reload
    $redirect_url = 'deceased_records.php?mode=import';
    if (!empty($redirect_params)) {
        $redirect_url .= '&' . implode('&', $redirect_params);
    }
    header('Location: ' . $redirect_url);
    exit();
}

// Handle search parameters
$search_name = $_GET['search_name'] ?? '';
$search_plot = $_GET['search_plot'] ?? '';
$search_section = $_GET['search_section'] ?? '';
$search_row = $_GET['search_row'] ?? '';

// Handle sorting parameters
$sort_by = $_GET['sort_by'] ?? '';
$sort_order = $_GET['sort_order'] ?? 'asc';

// Check plot status based on search criteria
$plot_status_notice = '';
$has_search_criteria = !empty($search_name) || !empty($search_plot) || !empty($search_section) || !empty($search_row);

if ($has_search_criteria) {
    // If searching by plot number specifically, check that plot
    if (!empty($search_plot)) {
        $plot_check_query = "SELECT p.plot_id, p.plot_number, p.status, s.section_name 
                             FROM plots p 
                             LEFT JOIN sections s ON p.section_id = s.section_id 
                             WHERE p.plot_number = ?";
        
        $plot_params = [$search_plot];
        $plot_types = 's';
        
        // If section filter is provided, also filter by section
        if (!empty($search_section)) {
            $plot_check_query .= " AND s.section_id = ?";
            $plot_params[] = $search_section;
            $plot_types .= 'i';
        }
        
        $plot_check_query .= " LIMIT 1";
        
        $stmt = mysqli_prepare($conn, $plot_check_query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, $plot_types, ...$plot_params);
            mysqli_stmt_execute($stmt);
            $plot_result = mysqli_stmt_get_result($stmt);
            $plot_data = mysqli_fetch_assoc($plot_result);
            
            if ($plot_data) {
                if ($plot_data['status'] === 'occupied' || $plot_data['status'] === 'reserved') {
                    $plot_status_notice = "Notice: The following plots in your search results are already occupied";
                }
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        // For other search criteria (name, section, row), check plots based on the filters
        // Build query to check plots directly, not just those with deceased records
        $plot_status_query = "SELECT DISTINCT p.plot_id, p.plot_number, p.status, s.section_name 
                              FROM plots p 
                              LEFT JOIN sections s ON p.section_id = s.section_id 
                              WHERE (p.status = 'occupied' OR p.status = 'reserved')";
        
        $status_params = [];
        $status_types = '';
        
        if (!empty($search_section)) {
            $plot_status_query .= " AND s.section_id = ?";
            $status_params[] = $search_section;
            $status_types .= 'i';
        }
        
        if (!empty($search_row)) {
            $plot_status_query .= " AND p.row_number = ?";
            $status_params[] = $search_row;
            $status_types .= 'i';
        }
        
        // If searching by name, we need to join with deceased_records to filter by name
        if (!empty($search_name)) {
            $plot_status_query .= " AND EXISTS (
                SELECT 1 FROM deceased_records d 
                WHERE d.plot_id = p.plot_id 
                AND d.full_name LIKE ?
            )";
            $status_params[] = "%$search_name%";
            $status_types .= 's';
        }
        
        $plot_status_query .= " LIMIT 5";
        
        if (!empty($status_params)) {
            $stmt = mysqli_prepare($conn, $plot_status_query);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, $status_types, ...$status_params);
                mysqli_stmt_execute($stmt);
                $status_result = mysqli_stmt_get_result($stmt);
                $occupied_plots = [];
                
                while ($plot = mysqli_fetch_assoc($status_result)) {
                    $occupied_plots[] = $plot;
                }
                
                if (!empty($occupied_plots)) {
                    $plot_status_notice = "Notice: The following plots in your search results are already occupied";
                }
                
                mysqli_stmt_close($stmt);
            }
        }
    }
}

// Fetch all sections for dropdown
// Get sections for search form (consistent with plots.php)
$sections_query = "SELECT s.section_id, s.section_name, s.section_code 
                   FROM sections s
                   JOIN plots p ON p.section_id = s.section_id
                   JOIN deceased_records d ON d.plot_id = p.plot_id
                   WHERE LOWER(s.section_name) NOT LIKE '%test%' 
                   AND LOWER(s.section_code) NOT LIKE '%test%'
                   AND UPPER(TRIM(s.section_name)) != 'AP'
                   AND UPPER(TRIM(s.section_code)) != 'AP'
                   AND s.section_name NOT REGEXP '^BLK[[:space:]]*[1-4]$'
                   AND s.section_code NOT REGEXP '^BLK[[:space:]]*[1-4]$'
                   GROUP BY s.section_id, s.section_name, s.section_code
                   HAVING COUNT(d.record_id) > 0
                   ORDER BY s.section_code";
$sections_result = mysqli_query($conn, $sections_query);

// Fetch rows for the selected section (if a section is selected)
$rows_result = null;
$rows_data = [];
if (!empty($search_section)) {
    $rows_query = "SELECT DISTINCT row_number 
                   FROM plots 
                   WHERE section_id = ? 
                   ORDER BY row_number";
    $stmt = mysqli_prepare($conn, $rows_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $search_section);
        mysqli_stmt_execute($stmt);
        $rows_result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($rows_result)) {
            $rows_data[] = [
                'row_number' => $row['row_number'],
                'row_letter' => rowNumberToLetter($row['row_number'])
            ];
        }
        mysqli_stmt_close($stmt);
    }
}

// Add pagination
$records_per_page = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Check if table exists
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'deceased_records'");
if (mysqli_num_rows($table_check) == 0) {
    // Table doesn't exist, show empty table
    $result = false;
} else {
    // Build search query
    // lot_number is a display-only, row-based sequence:
    // it restarts at 1 for each (section, row_number) combination.
    $query = "SELECT d.*, p.plot_number, s.section_name, p.row_number,
                     (SELECT COUNT(*) FROM plots p2 
                      WHERE p2.section_id = p.section_id 
                      AND p2.row_number = p.row_number
                      AND p2.plot_id <= p.plot_id) as lot_number
              FROM deceased_records d 
              JOIN plots p ON d.plot_id = p.plot_id 
              LEFT JOIN sections s ON p.section_id = s.section_id
              WHERE 1=1";
    
    $params = [];
    $types = '';
    
    if (!empty($search_name)) {
        $query .= " AND d.full_name LIKE ?";
        $params[] = "%$search_name%";
        $types .= 's';
    }
    
    if (!empty($search_plot)) {
        $query .= " AND p.plot_number LIKE ?";
        $params[] = "%$search_plot%";
        $types .= 's';
    }
    
    if (!empty($search_section)) {
        $query .= " AND s.section_id = ?";
        $params[] = $search_section;
        $types .= 'i';
    }
    
    if (!empty($search_row)) {
        $query .= " AND p.row_number = ?";
        $params[] = $search_row;
        $types .= 'i';
    }
    
    
    // Handle sorting
    if ($sort_by === 'plot_location') {
        $order_direction = ($sort_order === 'desc') ? 'DESC' : 'ASC';
        // Sort by section, then row, then row-based lot number
        $query .= " ORDER BY s.section_name $order_direction, p.row_number $order_direction, lot_number $order_direction";
    } else {
        $query .= " ORDER BY s.section_name ASC, p.row_number ASC, lot_number ASC";
    }
    
    // Get total count for pagination (remove ORDER BY for count query)
    $count_query = str_replace("SELECT d.*, p.plot_number, s.section_name, p.row_number,
                     (SELECT COUNT(*) FROM plots p2 
                      WHERE p2.section_id = p.section_id 
                      AND p2.row_number = p.row_number
                      AND p2.plot_id <= p.plot_id) as lot_number", "SELECT COUNT(*) as total", $query);
    
    // Remove ORDER BY clause from count query since lot_number won't exist
    $count_query = preg_replace('/ORDER BY.*$/', '', $count_query);
    
    if (!empty($params)) {
        $stmt = mysqli_prepare($conn, $count_query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
            mysqli_stmt_execute($stmt);
            $count_result = mysqli_stmt_get_result($stmt);
            $total_records = mysqli_fetch_assoc($count_result)['total'];
        } else {
            $total_records = 0;
        }
    } else {
        $count_result = mysqli_query($conn, $count_query);
        $total_records = mysqli_fetch_assoc($count_result)['total'];
    }
    
    $total_pages = ceil($total_records / $records_per_page);
    
    // Clear notice if no search results found
    if ($total_records == 0) {
        $plot_status_notice = '';
    }
    
    // Add limit and offset to main query
    $query .= " LIMIT $records_per_page OFFSET $offset";
    
    if (!empty($params)) {
        $stmt = mysqli_prepare($conn, $query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
        } else {
            $result = false;
        }
    } else {
        $result = mysqli_query($conn, $query);
    }
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <!-- Add Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Add Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/ui-settings.css">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <style>
        body { 
            margin: 0; 
            padding: 0; 
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; 
            background: #f5f5f5;
            overflow-x: hidden;
        }
        html {
            overflow-x: hidden;
        }
        /* Main content container */
        .main-content-wrapper {
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .page-title {
            font-size: 2.25rem;
            font-weight: 700;
            margin: 0;
            letter-spacing: 1px;
            color: #000000;
        }
        
        .page-subtitle {
            color: #6b7280;
            font-size: 1rem;
            margin-top: 0.5rem;
            margin-bottom: 0;
        }
        
        /* Actions Dropdown Styles: Replaces separate Import and Backup buttons */
        .actions-dropdown-wrapper {
            position: relative;
            display: inline-block;
        }
        
        .actions-dropdown-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 8px;
            background: transparent;
            color: #1f3659;
            text-decoration: none;
            border: none;
            box-shadow: none;
            cursor: pointer;
            transition: color 0.2s ease, opacity 0.2s ease;
            min-width: auto;
            position: relative;
        }
        
        .actions-dropdown-btn:hover {
            color: #2b4c7e;
            opacity: 0.8;
        }
        
        .actions-dropdown-btn:focus-visible {
            outline: 2px solid rgba(79, 109, 167, 0.4);
            outline-offset: 4px;
            border-radius: 4px;
        }
        
        .actions-dropdown-btn.active {
            color: #2b4c7e;
        }
        
        .actions-dropdown-btn i {
            font-size: 2rem;
        }
        
        .actions-label {
            display: none;
        }
        
        .dropdown-arrow {
            font-size: 0.85rem;
            transition: transform 0.2s ease;
            color: #1f3659;
        }
        
        .actions-dropdown-btn.active .dropdown-arrow {
            transform: rotate(180deg);
        }
        
        .actions-dropdown-menu {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 20px 45px rgba(15, 23, 43, 0.18);
            min-width: 240px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.2s ease;
            z-index: 1000;
            overflow: hidden;
            border: 1px solid rgba(15, 23, 43, 0.08);
        }
        
        .actions-dropdown-menu.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            padding: 14px 20px;
            background: transparent;
            border: none;
            text-align: left;
            cursor: pointer;
            transition: background 0.2s ease;
            font-size: 0.95rem;
            font-weight: 500;
            color: #1d2a38;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        
        .dropdown-item:first-child {
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
        }
        
        .dropdown-item:last-child {
            border-bottom-left-radius: 12px;
            border-bottom-right-radius: 12px;
        }
        
        .dropdown-item:hover {
            background: #f8f9fa;
        }
        
        .dropdown-item:active {
            background: #e9ecef;
        }
        
        .dropdown-item i {
            font-size: 1.1rem;
            color: #2b4c7e;
            width: 20px;
            text-align: center;
        }
        
        .dropdown-item span {
            flex: 1;
        }
        
        /* Delete Records option styling - danger color */
        .dropdown-item-danger {
            color: #dc3545 !important;
        }
        
        .dropdown-item-danger:hover {
            background: #fee;
        }
        
        .dropdown-item-danger i {
            color: #dc3545;
        }
        
        /* Bulk Import Card Styles */
        .bulk-import-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            padding: 32px;
            margin: 24px 0;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .bulk-import-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .bulk-import-header h3 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
            color: #1d2a38;
        }
        
        .close-import-btn {
            background: transparent;
            border: none;
            color: #666;
            font-size: 24px;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
        }
        
        .close-import-btn:hover {
            background: #f5f5f5;
            color: #222;
        }
        
        .bulk-import-card .form-group {
            margin-bottom: 20px;
        }
        
        .bulk-import-card .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
            font-size: 14px;
        }
        
        .bulk-import-card .form-group select,
        .bulk-import-card .form-group input[type="file"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
            transition: border-color 0.2s, box-shadow 0.2s;
            box-sizing: border-box;
        }
        
        .bulk-import-card .form-group select:focus,
        .bulk-import-card .form-group input[type="file"]:focus {
            outline: none;
            border-color: #2b4c7e;
            box-shadow: 0 0 0 3px rgba(43, 76, 126, 0.1);
        }
        
        .bulk-import-card .form-group select:disabled {
            background: #f5f5f5;
            cursor: not-allowed;
        }
        
        .import-form-buttons {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            justify-content: flex-end;
        }
        
        .btn-import-submit {
            background: linear-gradient(135deg, #2b4c7e 0%, #1f3659 100%);
            color: #fff;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-import-submit:hover {
            background: linear-gradient(135deg, #1f3659 0%, #152542 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(43, 76, 126, 0.3);
        }
        
        .btn-import-cancel {
            background: #6c757d;
            color: #fff;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-import-cancel:hover {
            background: #5a6268;
        }
        
        .bulk-import-card .alert {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .bulk-import-card .alert-success {
            background: #d1edff;
            color: #0d6efd;
            border: 1px solid #b8daff;
        }
        
        .bulk-import-card .alert-error {
            background: #f8d7da;
            color: #dc3545;
            border: 1px solid #f5c6cb;
        }
        
        /* Delete Records Card Styles */
        .delete-records-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            padding: 32px;
            margin: 24px 0;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            animation: slideDown 0.3s ease;
        }
        
        .delete-records-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .delete-records-header h3 {
            margin: 0 0 8px 0;
            font-size: 1.5rem;
            font-weight: 600;
            color: #1d2a38;
        }
        
        .delete-info {
            color: #666;
            font-size: 14px;
            margin: 0;
            line-height: 1.6;
        }
        
        .close-delete-btn {
            background: transparent;
            border: none;
            color: #666;
            font-size: 24px;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            flex-shrink: 0;
        }
        
        .close-delete-btn:hover {
            background: #f5f5f5;
            color: #222;
        }
        
        .delete-section-item {
            margin-bottom: 24px;
        }
        
        .delete-section-item h4 {
            margin: 0 0 12px 0;
            font-size: 1.2rem;
            font-weight: 600;
            color: #1d2a38;
        }
        
        .delete-warning {
            color: #dc3545;
            font-size: 14px;
            margin: 0 0 20px 0;
            padding: 12px;
            background: #fff5f5;
            border-left: 4px solid #dc3545;
            border-radius: 4px;
        }
        
        .delete-filters-row {
            display: flex;
            gap: 12px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        
        .delete-filter-group {
            flex: 1;
            min-width: 150px;
        }
        
        .delete-filter-group select,
        .delete-filter-group input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .btn-delete-record,
        .btn-delete-bulk {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: #fff;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
        }
        
        .btn-delete-record:hover,
        .btn-delete-bulk:hover {
            background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
        }
        
        .btn-delete-record i,
        .btn-delete-bulk i {
            font-size: 16px;
        }
        
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            padding: 12px 16px;
            border-radius: 8px;
        }
        
        /* Modern Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.35rem 0.9rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            border: none;
            line-height: 1;
        }

        /* Plot status (available / reserved / occupied) */
        .status-available {
            background: #ecfdf3;
            color: #047857;
        }

        .status-reserved {
            background: #fffbeb;
            color: #b45309;
        }

        .status-occupied {
            background: #fee2e2;
            color: #b91c1c;
        }

        /* Contract status (active / expired / renewal_needed / cancelled) */
        .status-active {
            background: #ecfdf3;
            color: #16a34a;
        }

        .status-expired {
            background: #fee2e2;
            color: #b91c1c;
        }

        .status-renewal_needed {
            background: #fffbeb;
            color: #b45309;
        }

        .status-cancelled {
            background: #f3f4f6;
            color: #6b7280;
        }
        
        /* Backup & Restore Card Styles */
        .backup-restore-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            padding: 32px;
            margin: 24px 0;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            animation: slideDown 0.3s ease;
        }
        
        .backup-restore-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .backup-restore-header h3 {
            margin: 0 0 8px 0;
            font-size: 1.5rem;
            font-weight: 600;
            color: #1d2a38;
        }
        
        .backup-info {
            color: #666;
            font-size: 14px;
            margin: 0;
            line-height: 1.6;
        }
        
        .close-backup-btn {
            background: transparent;
            border: none;
            color: #666;
            font-size: 24px;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            flex-shrink: 0;
        }
        
        .close-backup-btn:hover {
            background: #f5f5f5;
            color: #222;
        }
        
        .backup-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .btn-view-archived {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #fff;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-view-archived:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(16, 185, 129, 0.3);
        }
        
        .btn-view-archived i {
            font-size: 16px;
        }
        
        /* Archive Modals */
        .archive-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
        }
        
        .archive-modal.show {
            display: flex;
        }
        
        .archive-modal-content {
            background: #fff;
            border-radius: 16px;
            max-width: 90%;
            width: 1200px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
            position: relative;
        }
        
        .archive-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 24px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .archive-modal-header h3 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
            color: #1d2a38;
        }
        
        .archive-modal-close {
            background: transparent;
            border: none;
            color: #666;
            font-size: 24px;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            transition: all 0.2s ease;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .archive-modal-close:hover {
            background: #f5f5f5;
            color: #222;
        }
        
        .archive-modal-body {
            padding: 24px;
        }
        
        .archive-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }
        
        .archive-table th,
        .archive-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
            font-size: 14px;
        }
        
        .archive-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #1d2a38;
        }
        
        .archive-table tr:hover {
            background: #f8f9fa;
        }
        
        .archive-table tr {
            cursor: pointer;
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
        
        .detail-item {
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .detail-label {
            font-size: 13px;
            color: #666;
            margin-bottom: 4px;
        }
        
        .detail-value {
            font-size: 15px;
            color: #222;
            font-weight: 500;
        }
        
        /* Import Tab Styles */
        .import-tab-btn {
            padding: 12px 24px;
            border: none;
            background: transparent;
            color: #666;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .import-tab-btn:hover {
            color: #333;
            background: #f5f5f5;
        }
        
        .import-tab-btn.active {
            color: #2b4c7e;
            border-bottom-color: #2b4c7e;
            background: transparent;
        }
        
        .import-tab-content {
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @media (max-width: 768px) {
            .bulk-import-card,
            .bulk-import-header h3,
            .delete-records-header h3 {
                font-size: 1.25rem;
            }
            
            .delete-records-card {
                padding: 24px;
            }
            
            .delete-records-header {
                flex-direction: column;
                gap: 12px;
            }
            
            .delete-filters-row {
                flex-direction: column;
            }
            
            .delete-filter-group {
                min-width: 100%;
            }
            
            .import-form-buttons {
                flex-direction: column;
            }
            
            .btn-import-submit,
            .btn-import-cancel {
                width: 100%;
            }
            
            .backup-restore-header {
                flex-direction: column;
                gap: 12px;
            }
            
            .archive-modal-content {
                width: 95%;
                max-height: 95vh;
            }
            
            .detail-grid {
                grid-template-columns: 1fr;
            }
        }
        .table-card {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e0e0e0;
            padding: 32px 24px 24px 24px;
            margin-bottom: 32px;
            box-shadow: none;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }
        .table-title {
            font-size: 18px;
            font-weight: 500;
            margin-bottom: 18px;
            color: #222;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0;
            table-layout: auto;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        
        /* Responsive table wrapper */
        .table-wrapper {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        @media (max-width: 768px) {
            .table-wrapper {
                display: block;
                width: 100%;
            }
            
            table {
                min-width: 600px;
            }
        }
        th, td {
            padding: 8px 10px;
            text-align: left;
            font-size: 15px;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        /* Center-align all cells in the main "All Deceased Records" table */
        .deceased-records-table th,
        .deceased-records-table td {
            text-align: center;
        }
        /* Date cells styling - ensure consistent font */
        .deceased-records-table td:nth-child(3),
        .deceased-records-table td:nth-child(4),
        .deceased-records-table td:nth-child(5),
        .deceased-records-table td:nth-child(6) {
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-weight: 400;
        }
        th { background: #fafafa; color: #333; border-bottom: 1px solid #e0e0e0; }
        tr { background: #fff; }
        tr:not(:last-child) { border-bottom: 1px solid #f0f0f0; }
        .action-btn {
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 500;
            margin-right: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 2px;
            min-width: 60px;
            width: 120px;
            text-decoration: none;
            box-sizing: border-box;
        }
        
        .action-btn i {
            font-size: 14px;
        }
        /* Action buttons – aligned with other staff pages (neutral view, primary edit, success contract) */
        .action-btn.view { 
            background: #f3f4f6; 
            color: #374151; 
            border: 1px solid #e5e7eb;
        }
        .action-btn.edit { 
            background: #2b4c7e; 
            color: #ffffff; 
            border: 1px solid #2b4c7e;
        }
        .action-btn.contract { 
            background: #e0f2f1; 
            color: #047857; 
            border: 1px solid #a7f3d0;
        }
        .action-btn.view:hover { 
            background: #e5e7eb; 
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .action-btn.edit:hover { 
            background: #1f3659; 
            border-color: #1f3659;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .action-btn.contract:hover { 
            background: #ccfbf1; 
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .action-buttons {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        /* Table action column alignment */
        table td:last-child .action-buttons,
        table th:last-child {
            text-align: center;
        }
        .search-form {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e0e0e0;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: none;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }
        .search-form form {
            display: flex;
            flex-direction: column;
            min-height: 100%;
        }
        .search-row {
            display: flex;
            gap: 16px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .search-group {
            flex: 1;
            min-width: 200px;
        }
        .search-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #333;
            font-size: 14px;
        }
        .search-group input, .search-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        .search-group input:focus, .search-group select:focus {
            outline: none;
            border-color: #0d6efd;
            box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.1);
        }
        .search-buttons {
            display: flex;
            gap: 12px;
            margin-top: auto;
            justify-content: space-between;
            align-items: center;
            padding-top: 16px;
        }
        .btn-search {
            background: #2b4c7e;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.2s;
        }
        .btn-search:hover {
            background: #1f3659;
        }
        .btn-clear {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.2s;
        }
        .btn-clear:hover {
            background: #5c636a;
        }
        /* Responsive breakpoints */
        @media (max-width: 1400px) {
            .main {
                padding: 32px 20px;
            }
        }
        
        @media (max-width: 1200px) {
            .main {
                padding: 28px 20px;
            }
            .table-card,
            .search-form {
                padding: 24px 20px;
            }
        }
        
        @media (max-width: 1024px) {
            .main {
                padding: 24px 16px;
            }
            .table-card,
            .search-form {
                padding: 20px 16px;
            }
        }
        
        /* Responsive Design - Standard Breakpoints */
        
        /* Tablet and below (768px) */
        @media (max-width: 768px) {
            .main {
                padding: 20px 12px !important;
                margin-left: 0 !important;
                width: 100% !important;
            }
            
            .sidebar.collapsed + .main {
                margin-left: 0 !important;
                width: 100% !important;
            }
            
            .table-card,
            .search-form {
                padding: 16px 12px;
                border-radius: 12px;
            }
            
            .page-title {
                font-size: 1.75rem;
            }
            
            .search-row {
                flex-direction: column;
            }
            
            .search-group {
                min-width: 100%;
            }
            
            table {
                font-size: 14px;
                font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
            }
            
            th, td {
                padding: 6px 8px;
                font-size: 13px;
                font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
            }
        }
        
        /* Mobile (480px and below) */
        @media (max-width: 480px) {
            .layout { 
                flex-direction: column; 
            }
            
            
            .table-card { 
                padding: 12px 8px;
                overflow-x: auto;
            }
            
            .search-form {
                padding: 16px 12px;
            }
            
            table {
                font-size: 12px;
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
            
            th, td {
                padding: 4px 6px;
                font-size: 11px;
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
        
        @media (max-width: 480px) {
            .main {
                padding: 12px 8px;
            }
            .table-card,
            .search-form {
                padding: 12px 8px;
            }
            .page-title {
                font-size: 1.5rem;
            }
            /* Responsive dropdown styles for mobile */
            .actions-dropdown-btn {
                padding: 6px;
            }
            .actions-dropdown-btn i {
                font-size: 1.75rem;
            }
            .actions-dropdown-menu {
                min-width: 180px;
                right: 0;
            }
            .dropdown-item {
                padding: 12px 16px;
                font-size: 0.9rem;
            }
            .action-buttons {
                flex-direction: column;
                gap: 4px;
            }
            .action-btn {
                width: 100%;
                margin-right: 0;
            }
            th, td {
                padding: 4px 6px;
                font-size: 12px;
            }
        }
        
        /* Modal styles */
        .modal-bg {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-bg.active { display: flex; }
        .modal-content {
            background: #fff;
            border-radius: 16px;
            padding: 32px 24px 24px 24px;
            min-width: 500px;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 4px 24px rgba(0,0,0,0.15);
            position: relative;
        }
        .modal-close {
            position: absolute;
            top: 12px;
            right: 18px;
            font-size: 24px;
            color: #888;
            background: none;
            border: none;
            cursor: pointer;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.2s;
        }
        .modal-close:hover {
            background: #f5f5f5;
        }
        .modal-edit-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            border-radius: 0.75rem;
            border: none;
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            background: #2b4c7e;
            color: #ffffff;
            white-space: nowrap;
        }
        .modal-edit-btn i {
            font-size: 20px;
        }
        .modal-edit-btn:hover {
            background: #1f3659;
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
        }
        .modal-edit-btn:active {
            transform: translateY(0);
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }
        .modal-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 24px;
            color: #222;
        }
        .record-info {
            background: #f8f9fa;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
        }
        .record-info h3 {
            margin: 0 0 12px 0;
            color: #333;
            font-size: 16px;
        }
        .record-info p {
            margin: 4px 0;
            color: #666;
            font-size: 14px;
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }
        .record-info p strong {
            display: inline-block;
            min-width: 140px; /* keeps labels aligned in a column */
            font-weight: 600;
            color: #333;
        }
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .form-group {
            flex: 1;
            min-width: 200px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #333;
            font-size: 14px;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
            transition: border-color 0.2s;
            box-sizing: border-box;
            min-height: 40px;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: #0d6efd;
            box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.1);
        }
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        .form-buttons {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }
        .btn-save {
            background: #2b4c7e;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.2s;
        }
        .btn-save:hover {
            background: #1f3659;
        }
        .btn-cancel {
            background: #6c757d;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.2s;
        }
        .btn-cancel:hover {
            background: #5c636a;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-success {
            background: #d1edff;
            color: #0d6efd;
            border: 1px solid #b8daff;
        }
        .alert-error {
            background: #f8d7da;
            color: #dc3545;
            border: 1px solid #f5c6cb;
        }
        @media (max-width: 600px) {
            .modal-content {
                min-width: 90vw;
                padding: 20px 16px;
            }
            .form-row {
                flex-direction: column;
                gap: 16px;
            }
        }
        
        /* Modal Styles */
        .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px 12px 0 0;
            border-bottom: none;
        }
        .modal-header .btn-close {
            filter: invert(1);
        }
        .modal-body {
            padding: 24px;
        }
        .modal-footer {
            border-top: 1px solid #e9ecef;
            padding: 16px 24px;
        }
        .badge {
            font-size: 0.75em;
            padding: 0.375em 0.75em;
        }
    </style>
    <script>
        // Unified date formatting function: Month-Day-Year (e.g., Sept-26-2022)
        function formatDateDisplay(dateStr) {
            if (!dateStr || dateStr === '0000-00-00' || dateStr === '—' || dateStr === '') {
                return '—';
            }
            
            const date = new Date(dateStr);
            if (isNaN(date.getTime())) {
                return '—';
            }
            
            const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sept', 'Oct', 'Nov', 'Dec'];
            const month = monthNames[date.getMonth()];
            const day = date.getDate();
            const year = date.getFullYear();
            
            return `${month}-${day}-${year}`;
        }
        
        // Define functions immediately - they will handle DOM readiness internally
        window.showViewRecordModal = function(recordId) {
                console.log('showViewRecordModal called with ID:', recordId);
                
                // Check if modal elements exist
                const modal = document.getElementById('viewRecordModal');
                const modalTitle = document.getElementById('viewRecordModalTitle');
                const modalBody = document.getElementById('viewRecordModalBody');
                
                console.log('Modal elements check:', {
                    modal: !!modal,
                    modalTitle: !!modalTitle,
                    modalBody: !!modalBody
                });
                
                if (!modal || !modalTitle || !modalBody) {
                    console.error('Modal elements not found - trying to create them');
                    // Try to wait a bit and retry
                    setTimeout(function() {
                        const retryModal = document.getElementById('viewRecordModal');
                        const retryModalTitle = document.getElementById('viewRecordModalTitle');
                        const retryModalBody = document.getElementById('viewRecordModalBody');
                        
                        if (!retryModal || !retryModalTitle || !retryModalBody) {
                            alert('Error: Modal not available. Please refresh the page.');
                            return;
                        }
                        
                        // Retry the modal opening
                        window.showViewRecordModal(recordId);
                    }, 500);
                    return;
                }
            
            // Store record ID for edit functionality
            modal.dataset.recordId = recordId;
            
            // Fetch record details via AJAX
            fetch(`get_record_details.php?id=${recordId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const record = data.record;
                        modalTitle.textContent = `Record: ${record.full_name}`;
                        modalBody.innerHTML = `
                            <div class="record-info">
                                <h3>Deceased Information</h3>
                                <p><strong>Name:</strong> ${record.full_name}</p>
                                <p><strong>Date of Birth:</strong> ${formatDateDisplay(record.date_of_birth)}</p>
                                <p><strong>Date of Death:</strong> ${formatDateDisplay(record.date_of_death)}</p>
                                <p><strong>Date of Burial:</strong> ${formatDateDisplay(record.burial_date)}</p>
                            </div>
                            <div class="record-info">
                                <h3>Plot Information</h3>
                                ${(() => {
                                    const rowNumber = record.row_number || 1;
                                    let rowLetter = '';
                                    let n = parseInt(rowNumber);
                                    if (n > 0) {
                                        while (n > 0) {
                                            const remainder = (n - 1) % 26;
                                            rowLetter = String.fromCharCode(65 + remainder) + rowLetter;
                                            n = Math.floor((n - 1) / 26);
                                        }
                                    }
                                    const toOrdinal = (num) => {
                                        const nInt = parseInt(num, 10);
                                        if (!nInt || nInt <= 0) return '';
                                        const rem10 = nInt % 10;
                                        const rem100 = nInt % 100;
                                        let suffix = 'th';
                                        if (rem100 < 11 || rem100 > 13) {
                                            if (rem10 === 1) suffix = '1st';
                                            else if (rem10 === 2) suffix = '2nd';
                                            else if (rem10 === 3) suffix = '3rd';
                                        } else {
                                            suffix = nInt + 'th';
                                        }
                                        if (suffix === 'th') {
                                            suffix = nInt + 'th';
                                        }
                                        return suffix;
                                    };
                                    const ordinal = toOrdinal(rowNumber);
                                    const rowDisplay = rowLetter && ordinal ? `${rowLetter} (${ordinal})` : (rowLetter || '—');
                                    return `<p><strong>Section:</strong> ${record.section_name || '—'}</p>
                                            <p><strong>Row:</strong> ${rowDisplay}</p>
                                            <p><strong>Plot Number:</strong> ${record.plot_number || '—'}</p>`;
                                })()}
                            </div>
                        `;
                        // Show custom modal
                        modal.classList.add('active');
                    } else {
                        alert('Error loading record details: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading record details');
                });
            };

            window.showEditRecordModal = function(recordId) {
                console.log('showEditRecordModal called with ID:', recordId);
                // Build return URL with current filter parameters
                const urlParams = new URLSearchParams(window.location.search);
                const returnParams = new URLSearchParams();
                returnParams.set('id', recordId);
                
                // Preserve all filter and pagination parameters
                ['search_name', 'search_plot', 'search_section', 'search_row', 'sort_by', 'sort_order', 'page'].forEach(param => {
                    const value = urlParams.get(param);
                    if (value) returnParams.set(param, value);
                });
                
                window.location.href = `edit_record.php?${returnParams.toString()}`;
            };

            window.showContractModal = function(recordId, plotId) {
                console.log('showContractModal called with ID:', recordId, 'Plot ID:', plotId);
                
                // Check if contract modal elements exist
                const contractModal = document.getElementById('contractModal');
                const recordInfo = document.getElementById('recordInfo');
                
                console.log('Contract modal elements check:', {
                    contractModal: !!contractModal,
                    recordInfo: !!recordInfo
                });
                
                if (!contractModal || !recordInfo) {
                    console.error('Contract modal elements not found - trying retry');
                    // Try to wait a bit and retry
                    setTimeout(function() {
                        const retryModal = document.getElementById('contractModal');
                        const retryInfo = document.getElementById('recordInfo');
                        
                        if (!retryModal || !retryInfo) {
                            alert('Error: Contract modal not available. Please refresh the page.');
                            return;
                        }
                        
                        // Retry the modal opening
                        window.showContractModal(recordId, plotId);
                    }, 500);
                    return;
                }
            
            // Show loading state
            recordInfo.innerHTML = '<p>Loading...</p>';
            contractModal.classList.add('active');
            
            // Fetch record data
            fetch(`get_contract_data.php?record_id=${recordId}&plot_id=${plotId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Populate record info
                        recordInfo.innerHTML = `
                            <h3>Deceased Information</h3>
                            <p><strong>Name:</strong> ${data.record.full_name}</p>
                            <p><strong>Date of Birth:</strong> ${formatDateDisplay(data.record.date_of_birth)}</p>
                            <p><strong>Date of Death:</strong> ${formatDateDisplay(data.record.date_of_death)}</p>
                            <p><strong>Date of Burial:</strong> ${formatDateDisplay(data.record.burial_date)}</p>
                            <p><strong>Plot:</strong> ${(() => {
                                const rowNumber = data.record.row_number || 1;
                                let rowLetter = '';
                                let n = parseInt(rowNumber);
                                if (n > 0) {
                                    while (n > 0) {
                                        const remainder = (n - 1) % 26;
                                        rowLetter = String.fromCharCode(65 + remainder) + rowLetter;
                                        n = Math.floor((n - 1) / 26);
                                    }
                                }
                                return (data.record.section_name || '') + '-' + rowLetter + (data.record.plot_number || '');
                            })()}</p>
                            <p><strong>Address:</strong> ${data.record.address || '—'}</p>
                            <p><strong>Name of Lessee:</strong> ${data.record.next_of_kin || '—'}</p>
                            <p><strong>Contact:</strong> ${data.record.contact_number || '—'}</p>
                        `;
                        
                        // Populate form fields
                        document.getElementById('recordId').value = recordId;
                        document.getElementById('plotId').value = plotId;
                        const statusHidden = document.getElementById('contract_status');
                        const statusDisplay = document.getElementById('contract_status_display');
                        const initialStatus = data.record.contract_status || 'active';
                        if (statusHidden) statusHidden.value = initialStatus;
                        if (statusDisplay) {
                            const label = initialStatus
                                .split('_')
                                .map(word => word.charAt(0).toUpperCase() + word.slice(1))
                                .join(' ');
                            statusDisplay.textContent = label;
                            statusDisplay.className = 'status-badge status-' + initialStatus;
                        }
                        
                        // Handle dates: show formatted date text (MM/DD/YYYY) and no picker
                        const formatDateForDisplay = (dateStr) => {
                            const src = (dateStr && dateStr !== '0000-00-00') ? new Date(dateStr) : new Date();
                            if (isNaN(src.getTime())) return '';
                            const mm = String(src.getMonth() + 1).padStart(2, '0');
                            const dd = String(src.getDate()).padStart(2, '0');
                            const yyyy = src.getFullYear();
                            return `${mm}/${dd}/${yyyy}`;
                        };
                        
                        document.getElementById('contract_start_date').value = formatDateForDisplay(data.record.contract_start_date);
                        document.getElementById('contract_end_date').value = formatDateForDisplay(data.record.contract_end_date);
                        document.getElementById('renewal_reminder_date').value = formatDateForDisplay(data.record.renewal_reminder_date);
                        document.getElementById('contract_notes').value = data.record.contract_notes || '';

                        // Auto-set status based on end date
                        const endRaw = data.record.contract_end_date;
                        if (endRaw && endRaw !== '0000-00-00') {
                            const endDate = new Date(endRaw);
                            const today = new Date();
                            const statusHidden2 = document.getElementById('contract_status');
                            const statusDisplay2 = document.getElementById('contract_status_display');
                            if (!isNaN(endDate.getTime())) {
                                let computedStatus = 'active';
                                if (endDate < today) {
                                    computedStatus = 'expired';
                                } else if (endDate.getTime() - today.getTime() <= 30 * 24 * 60 * 60 * 1000) {
                                    computedStatus = 'renewal_needed';
                                }
                                if (statusHidden2) statusHidden2.value = computedStatus;
                                if (statusDisplay2) {
                                    const label = computedStatus
                                        .split('_')
                                        .map(word => word.charAt(0).toUpperCase() + word.slice(1))
                                        .join(' ');
                                    statusDisplay2.textContent = label;
                                    statusDisplay2.className = 'status-badge status-' + computedStatus;
                                }
                            }
                        }
                    } else {
                        showAlert('Error loading contract data: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('Error loading contract data', 'error');
                });
            };
            
            window.closeContractModal = function() {
                const contractModal = document.getElementById('contractModal');
                const alertContainer = document.getElementById('alertContainer');
                const contractForm = document.getElementById('contractForm');
                
                if (contractModal) contractModal.classList.remove('active');
                if (alertContainer) alertContainer.innerHTML = '';
                if (contractForm) contractForm.reset();
            };
            
            window.showAlert = function(message, type) {
                const alertContainer = document.getElementById('alertContainer');
                if (alertContainer) {
                    const alertClass = type === 'success' ? 'alert-success' : 'alert-error';
                    alertContainer.innerHTML = `<div class="alert ${alertClass}">${message}</div>`;
                }
            };

            window.clearSearch = function() {
                document.getElementById('search_name').value = '';
                document.getElementById('search_plot').value = '';
                document.getElementById('search_section').value = '';
                const rowSelect = document.getElementById('search_row');
                if (rowSelect) {
                    rowSelect.value = '';
                    rowSelect.disabled = true;
                }
                window.location.href = window.location.pathname;
            };
            
            window.toggleSort = function(column) {
                const urlParams = new URLSearchParams(window.location.search);
                const currentSort = urlParams.get('sort_by');
                const currentOrder = urlParams.get('sort_order') || 'asc';
                
                if (currentSort === column) {
                    // Toggle order
                    urlParams.set('sort_order', currentOrder === 'asc' ? 'desc' : 'asc');
                } else {
                    // New column, start with asc
                    urlParams.set('sort_by', column);
                    urlParams.set('sort_order', 'asc');
                }
                
                window.location.search = urlParams.toString();
            };
            
            window.closeViewRecordModal = function() {
                const modal = document.getElementById('viewRecordModal');
                const modalBody = document.getElementById('viewRecordModalBody');
                
                if (modal) modal.classList.remove('active');
                if (modalBody) modalBody.innerHTML = '';
            };
            
            window.editFromViewRecord = function() {
                // Get the record ID from the current view
                const modal = document.getElementById('viewRecordModal');
                const recordId = modal ? modal.dataset.recordId : null;
                if (recordId) {
                    // Build return URL with current filter parameters
                    const urlParams = new URLSearchParams(window.location.search);
                    const returnParams = new URLSearchParams();
                    returnParams.set('id', recordId);
                    
                    // Preserve all filter and pagination parameters
                    ['search_name', 'search_plot', 'search_section', 'search_row', 'sort_by', 'sort_order', 'page'].forEach(param => {
                        const value = urlParams.get(param);
                        if (value) returnParams.set(param, value);
                    });
                    
                    window.location.href = `edit_record.php?${returnParams.toString()}`;
                }
            };
            
            // Toggle Import Section (Updated to work with dropdown)
            window.toggleImportSection = function() {
                const importSection = document.getElementById('bulkImportSection');
                const backupModal = document.getElementById('backupRestoreModal');
                const deleteSection = document.getElementById('deleteRecordsSection');
                
                if (importSection) {
                    // Close other sections if open
                    if (backupModal && backupModal.classList.contains('show')) {
                        backupModal.classList.remove('show');
                    }
                    if (deleteSection && deleteSection.style.display !== 'none') {
                        deleteSection.style.display = 'none';
                    }
                    
                    if (importSection.style.display === 'none' || !importSection.style.display) {
                        importSection.style.display = 'block';
                        // Scroll to import section
                        importSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    } else {
                        importSection.style.display = 'none';
                    }
                }
            };
            
            // Handle section selection change to populate row dropdown for bulk import
            document.addEventListener('DOMContentLoaded', function() {
                const importSectionSelect = document.getElementById('import_section_id');
                const importRowSelect = document.getElementById('import_row_number');
                
                if (importSectionSelect && importRowSelect) {
                    importSectionSelect.addEventListener('change', function() {
                        const sectionId = this.value;
                        
                        // Reset row dropdown
                        importRowSelect.innerHTML = '<option value="">Loading rows...</option>';
                        importRowSelect.disabled = true;
                        
                        if (sectionId) {
                            // Fetch available rows for the selected section
                            fetch(`get_section_rows.php?section_id=${sectionId}`)
                                .then(response => response.json())
                                .then(data => {
                                    if (data.success && data.rows.length > 0) {
                                        // Function to convert row number to letter (1=A, 2=B, ..., 26=Z, 27=AA, etc.)
                                        function rowNumberToLetter(rowNum) {
                                            const num = parseInt(rowNum, 10);
                                            if (Number.isNaN(num) || num < 1) {
                                                return rowNum;
                                            }
                                            let letter = '';
                                            let n = num;
                                            while (n > 0) {
                                                const remainder = (n - 1) % 26;
                                                letter = String.fromCharCode(65 + remainder) + letter;
                                                n = Math.floor((n - 1) / 26);
                                            }
                                            return letter;
                                        }
                                        
                                        // Populate row dropdown with available rows
                                        importRowSelect.innerHTML = '<option value="">Choose a row...</option>';
                                        data.rows.forEach(row => {
                                            const option = document.createElement('option');
                                            option.value = row.row_number;
                                            option.textContent = 'ROW ' + rowNumberToLetter(row.row_number);
                                            importRowSelect.appendChild(option);
                                        });
                                        importRowSelect.disabled = false;
                                    } else {
                                        // No available rows found
                                        importRowSelect.innerHTML = '<option value="">No rows found in this section</option>';
                                        importRowSelect.disabled = true;
                                    }
                                })
                                .catch(error => {
                                    console.error('Error fetching rows:', error);
                                    importRowSelect.innerHTML = '<option value="">Error loading rows</option>';
                                    importRowSelect.disabled = true;
                                });
                        } else {
                            // No section selected
                            importRowSelect.innerHTML = '<option value="">First select a section...</option>';
                            importRowSelect.disabled = true;
                        }
                    });
                }
                
                // Auto-open the appropriate section when returning with a success/error message
                <?php
                $mode = isset($_GET['mode']) ? $_GET['mode'] : '';
                if (($mode === 'import') && (!empty($success_message) || !empty($error_message))): ?>
                const importSection = document.getElementById('bulkImportSection');
                if (importSection) {
                    importSection.style.display = 'block';
                    setTimeout(() => {
                        importSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }, 100);
                }
                <?php elseif (($mode === 'delete') && (!empty($success_message) || !empty($error_message))): ?>
                const deleteSection = document.getElementById('deleteRecordsSection');
                if (deleteSection) {
                    deleteSection.style.display = 'block';
                    setTimeout(() => {
                        deleteSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }, 100);
                }
                <?php endif; ?>
            });
    </script>
    <script src="../assets/js/flash_clean_query.js"></script>
</head>
<body>
<div class="layout">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
        <div class="main-content-wrapper">
        <div class="page-header">
            <div>
            <div class="page-title">Deceased Records</div>
                <p class="page-subtitle">Search, view, and manage deceased records and plot information</p>
            </div>
            <!-- Actions Dropdown Menu: Replaces separate Import Records and Backup & Restore buttons -->
            <div class="actions-dropdown-wrapper">
                <button type="button" class="actions-dropdown-btn" id="actionsDropdownBtn" onclick="toggleActionsDropdown()" aria-expanded="false" aria-haspopup="true" title="Settings">
                    <i class="bi bi-gear"></i>
                </button>
                <div class="actions-dropdown-menu" id="actionsDropdownMenu">
                    <button type="button" class="dropdown-item" onclick="selectAction('import')" id="dropdownImportBtn">
                <i class="bi bi-upload"></i>
                        <span>Import Records</span>
                    </button>
                    <button type="button" class="dropdown-item" onclick="selectAction('backup')" id="dropdownBackupBtn">
                        <i class="bi bi-archive"></i>
                        <span>Backup & Restore</span>
                    </button>
                    <button type="button" class="dropdown-item dropdown-item-danger" onclick="selectAction('delete')" id="dropdownDeleteBtn">
                        <i class="bi bi-trash"></i>
                        <span>Delete Records</span>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Bulk Import Section -->
        <div id="bulkImportSection" class="bulk-import-card" style="display: none;">
            <div class="bulk-import-header">
                <h3>Bulk Import Deceased Records</h3>
                <button type="button" class="close-import-btn" onclick="toggleImportSection()" aria-label="Close">
                    <i class="bi bi-x"></i>
                </button>
            </div>
            
            <?php if (!empty($success_message)): ?>
            <div class="alert alert-success" style="margin-bottom: 20px;">
                <i class="bi bi-check-circle-fill" style="margin-right: 8px;"></i>
                <?php echo htmlspecialchars($success_message); ?>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
            <div class="alert alert-error" style="margin-bottom: 20px;">
            <i class="bi bi-exclamation-triangle-fill" style="margin-right: 8px;"></i>
                <?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>
            
            <p style="margin-bottom: 16px; color: #666; font-size: 14px; line-height: 1.6;">
                Upload a CSV file with the following columns: NO., NAME OF LESSEE, <strong>NAME OF DECEASED (required)</strong>, DATE OF DEATH, DATE OF BIRTH, DATE ACQUIRED (used as burial date if after death date), DUE DATE, ADDRESS<br>
                <strong>Supported date formats:</strong> DD/MM/YYYY, MM/DD/YYYY, YYYY-MM-DD, DD-MM-YYYY, DD.MM.YYYY<br>
                <em>Note: If Date Acquired is before Date of Death, the system will use Date of Death as the burial date.</em>
            </p>
            
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="action" value="bulk_import">
                <div class="form-group">
                    <label for="import_section_id">Select Section</label>
                    <select id="import_section_id" name="section_id" required>
                        <option value="">Choose a section...</option>
                        <?php 
                        // Use only sections that have plots for bulk import
                        if ($sections_import_result && mysqli_num_rows($sections_import_result) > 0):
                            mysqli_data_seek($sections_import_result, 0);
                            while ($section = mysqli_fetch_assoc($sections_import_result)): 
                        ?>
                            <option value="<?php echo $section['section_id']; ?>">
                                <?php echo htmlspecialchars($section['section_name']); ?>
                            </option>
                        <?php 
                            endwhile;
                        else:
                        ?>
                            <option value="" disabled>No sections with plots</option>
                        <?php
                        endif;
                        ?>
                    </select>
                    <small style="display: block; margin-top: 4px; color: #888; font-size: 13px;">
                        Select the section where you want to import the records
                    </small>
                </div>
                <div class="form-group">
                    <label for="import_row_number">Select Row</label>
                    <select id="import_row_number" name="row_number" required disabled>
                        <option value="">First select a section...</option>
                    </select>
                    <small style="display: block; margin-top: 4px; color: #888; font-size: 13px;">
                        All imported records will be assigned to available plots in the selected row
                    </small>
                </div>
                <div class="form-group">
                    <label for="csv_file">Select CSV File</label>
                    <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
                    <small style="display: block; margin-top: 4px; color: #888; font-size: 13px;">
                        Export your Excel file as CSV before uploading. Column order: NO., Name of Lessee, <strong>Name of Deceased (required)</strong>, Date of Death, Date of Birth, Date Acquired (burial date), Due Date, Address. 
                        <br><strong>Date formats supported:</strong> 24/09/2023, 09/24/2023, 2023-09-24, 24-09-2023, 24.09.2023
                        <br><em>Smart date handling: If Date Acquired is before Date of Death, Date of Death will be used as burial date.</em>
                    </small>
                </div>
                <div class="import-form-buttons">
                    <button type="submit" class="btn-import-submit">Import Records</button>
                    <button type="button" class="btn-import-cancel" onclick="toggleImportSection()">Cancel</button>
                </div>
            </form>
        </div>
        
        <!-- Backup & Restore Modal -->
        <div class="archive-modal" id="backupRestoreModal">
            <div class="archive-modal-content" style="max-width: 700px;">
                <div class="archive-modal-header">
                    <h3>Backup and Restore</h3>
                    <button type="button" class="archive-modal-close" onclick="closeBackupRestoreModal()" aria-label="Close">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
                <div class="archive-modal-body">
                    <div style="margin-bottom: 24px;">
                        <p style="color: #666; font-size: 14px; margin-bottom: 24px; line-height: 1.6;">
                            Download CSV backup files of deceased records. Select sections and optionally filter by rows to export specific data.
                        </p>
                        
                        <!-- CSV Download Form -->
                        <form method="get" action="backup_download.php" id="backupForm" style="margin-bottom: 24px;">
                            <div style="margin-bottom: 20px;">
                                <label style="display: flex; align-items: center; gap: 8px; font-weight: 500; margin-bottom: 8px; color: #333;">
                                    <input type="checkbox" id="backup_all_sections" name="all_sections" value="1" style="width: 18px; height: 18px; cursor: pointer;">
                                    <span>All Sections</span>
                                </label>
                                <label for="backup_sections" style="display: block; font-weight: 500; margin-bottom: 8px; color: #333;">
                                    Select Sections <span style="color: #dc3545;">*</span> 
                                    <span id="backup-section-count" style="color: #666; font-weight: normal; font-size: 13px;"></span>
                                </label>
                                <select id="backup_sections" name="sections[]" multiple style="width: 100%; min-height: 120px; padding: 8px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; box-sizing: border-box;">
                                    <?php
                                    // Fetch only sections that have deceased records for backup
                                    $backup_sections_query = "SELECT s.section_id, s.section_name
                                                              FROM sections s
                                                              JOIN plots p ON p.section_id = s.section_id
                                                              JOIN deceased_records d ON d.plot_id = p.plot_id
                                                              GROUP BY s.section_id, s.section_name
                                                              ORDER BY s.section_name";
                                    $backup_sections_result = mysqli_query($conn, $backup_sections_query);
                                    if ($backup_sections_result && mysqli_num_rows($backup_sections_result) > 0) {
                                        while ($section = mysqli_fetch_assoc($backup_sections_result)) {
                                            echo '<option value="' . htmlspecialchars($section['section_id']) . '">' . htmlspecialchars($section['section_name']) . '</option>';
                                        }
                                    }
                                    ?>
                                </select>
                                <small style="color: #666; font-size: 12px; margin-top: 4px; display: block;">
                                    Hold Ctrl (Windows) or Cmd (Mac) to select multiple sections, or check "All Sections" to export all
                                </small>
                            </div>
                            <div style="margin-bottom: 20px;">
                                <label for="backup_row_filter" style="display: block; font-weight: 500; margin-bottom: 8px; color: #333;">
                                    Filter by Row (Optional)
                                </label>
                                <select id="backup_row_filter" name="row_filter" style="width: 100%; padding: 8px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; box-sizing: border-box;">
                                    <option value="">All rows</option>
                                </select>
                                <small style="color: #666; font-size: 12px; margin-top: 4px; display: block;">
                                    Select a section first to see available rows, or leave as "All rows" to export all
                                </small>
                            </div>
                            <button type="submit" class="btn-view-archived" style="width: 100%; justify-content: center;">
                                <i class="bi bi-download"></i>
                                Download CSV Backup
                            </button>
                        </form>
                        
                        <div style="border-top: 1px solid #e0e0e0; padding-top: 24px; margin-top: 24px;">
                            <p style="color: #666; font-size: 14px; margin-bottom: 16px;">
                                Restore deceased records by importing CSV files. Choose between single record entry or bulk CSV import.
                            </p>
                            <button type="button" class="btn-view-archived" onclick="closeBackupRestoreModal(); showCsvImportModal();" style="width: 100%; justify-content: center; margin-bottom: 12px;">
                                <i class="bi bi-upload"></i>
                                Restore / Import CSV
                            </button>
                            <p style="color: #666; font-size: 14px; margin-bottom: 16px; margin-top: 24px;">
                                View and manage archived deceased records. These records were archived when their plot status was changed from occupied to available.
                            </p>
                            <button type="button" class="btn-view-archived" onclick="closeBackupRestoreModal(); showArchiveModal();" style="width: 100%; justify-content: center;">
                                <i class="bi bi-archive"></i>
                                View Archived Records
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- CSV Import Modal -->
        <div class="archive-modal" id="csvImportModal">
            <div class="archive-modal-content" style="max-width: 800px;">
                <div class="archive-modal-header">
                    <h3>Restore / Import CSV</h3>
                    <button type="button" class="archive-modal-close" onclick="closeCsvImportModal()" aria-label="Close">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
                <div class="archive-modal-body">
                    <!-- Import Type Tabs -->
                    <div style="display: flex; gap: 12px; margin-bottom: 24px; border-bottom: 2px solid #e0e0e0;">
                        <button type="button" class="import-tab-btn active" onclick="switchImportTab('single')" id="tab-single-btn">
                            <i class="bi bi-person-plus"></i> Single Record
                        </button>
                        <button type="button" class="import-tab-btn" onclick="switchImportTab('bulk')" id="tab-bulk-btn">
                            <i class="bi bi-upload"></i> Bulk CSV Import
                        </button>
                    </div>
                    
                    <!-- Single Record Import Tab -->
                    <div id="single-import-tab" class="import-tab-content">
                        <p style="color: #666; font-size: 14px; margin-bottom: 24px; line-height: 1.6;">
                            Add a single deceased record manually. Select an available plot and you'll be redirected to the record entry form.
                        </p>
                        
                        <form method="GET" action="add_deceased_record.php" id="singleImportForm">
                            <div style="margin-bottom: 20px;">
                                <label for="single_plot_id" style="display: block; font-weight: 500; margin-bottom: 8px; color: #333;">
                                    Select Plot <span style="color: #dc3545;">*</span>
                                </label>
                                <select id="single_plot_id" name="plot_id" required style="width: 100%; padding: 8px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; box-sizing: border-box;">
                                    <option value="">Select a plot...</option>
                                    <?php
                                    // Fetch available plots with section and location info
                                    $plots_query = "SELECT p.plot_id, p.plot_number, p.row_number, s.section_name 
                                                   FROM plots p 
                                                   LEFT JOIN sections s ON p.section_id = s.section_id 
                                                   WHERE p.status = 'available' 
                                                   ORDER BY s.section_name, p.row_number, p.plot_number";
                                    $plots_result = mysqli_query($conn, $plots_query);
                                    if ($plots_result && mysqli_num_rows($plots_result) > 0) {
                                        while ($plot = mysqli_fetch_assoc($plots_result)) {
                                            $rowLetter = rowNumberToLetter($plot['row_number'] ?? 1);
                                            $location = ($plot['section_name'] ?? '') . '-' . $rowLetter . ($plot['plot_number'] ?? '');
                                            echo '<option value="' . htmlspecialchars($plot['plot_id']) . '">' . htmlspecialchars($location) . '</option>';
                                        }
                                    }
                                    ?>
                                </select>
                                <small style="color: #666; font-size: 12px; margin-top: 4px; display: block;">
                                    Select an available plot for this deceased record
                                </small>
                            </div>
                            
                            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                                <button type="button" class="btn-view-archived" onclick="closeCsvImportModal()" style="background: #6c757d;">
                                    Cancel
                                </button>
                                <button type="submit" class="btn-view-archived" style="background: #28a745;">
                                    <i class="bi bi-arrow-right"></i> Continue to Form
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Bulk CSV Import Tab -->
                    <div id="bulk-import-tab" class="import-tab-content" style="display: none;">
                        <p style="color: #666; font-size: 14px; margin-bottom: 24px; line-height: 1.6;">
                            Upload a CSV file with the following columns: NO., NAME OF LESSEE, <strong>NAME OF DECEASED (required)</strong>, DATE OF DEATH, DATE OF BIRTH, DATE ACQUIRED (used as burial date if after death date), DUE DATE, ADDRESS<br>
                            <strong>Supported date formats:</strong> DD/MM/YYYY, MM/DD/YYYY, YYYY-MM-DD, DD-MM-YYYY, DD.MM.YYYY<br>
                            <em>Note: If Date Acquired is before Date of Death, the system will use Date of Death as the burial date.</em>
                        </p>
                        
                        <form method="POST" action="" enctype="multipart/form-data" id="bulkImportForm">
                            <input type="hidden" name="action" value="bulk_import">
                            <div style="margin-bottom: 20px;">
                                <label for="bulk_import_section_id" style="display: block; font-weight: 500; margin-bottom: 8px; color: #333;">
                                    Select Section <span style="color: #dc3545;">*</span>
                                </label>
                                <select id="bulk_import_section_id" name="section_id" required style="width: 100%; padding: 8px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; box-sizing: border-box;">
                                    <option value="">Choose a section...</option>
                                    <?php
                                    if ($sections_import_result && mysqli_num_rows($sections_import_result) > 0) {
                                        mysqli_data_seek($sections_import_result, 0);
                                        while ($section = mysqli_fetch_assoc($sections_import_result)): 
                                    ?>
                                        <option value="<?php echo $section['section_id']; ?>">
                                            <?php echo htmlspecialchars($section['section_name']); ?>
                                        </option>
                                    <?php 
                                        endwhile;
                                    }
                                    ?>
                                </select>
                                <small style="color: #666; font-size: 12px; margin-top: 4px; display: block;">
                                    Select the section where you want to import the records
                                </small>
                            </div>
                            
                            <div style="margin-bottom: 20px;">
                                <label for="bulk_import_row_number" style="display: block; font-weight: 500; margin-bottom: 8px; color: #333;">
                                    Select Row <span style="color: #dc3545;">*</span>
                                </label>
                                <select id="bulk_import_row_number" name="row_number" required disabled style="width: 100%; padding: 8px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; box-sizing: border-box;">
                                    <option value="">First select a section...</option>
                                </select>
                                <small style="color: #666; font-size: 12px; margin-top: 4px; display: block;">
                                    All imported records will be assigned to available plots in the selected row
                                </small>
                            </div>
                            
                            <div style="margin-bottom: 20px;">
                                <label for="bulk_csv_file" style="display: block; font-weight: 500; margin-bottom: 8px; color: #333;">
                                    Select CSV File <span style="color: #dc3545;">*</span>
                                </label>
                                <input type="file" id="bulk_csv_file" name="csv_file" accept=".csv" required style="width: 100%; padding: 8px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; box-sizing: border-box;">
                                <small style="color: #666; font-size: 12px; margin-top: 4px; display: block;">
                                    Export your Excel file as CSV before uploading. Column order: NO., Name of Lessee, <strong>Name of Deceased (required)</strong>, Date of Death, Date of Birth, Date Acquired (burial date), Due Date, Address.
                                </small>
                            </div>
                            
                            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                                <button type="button" class="btn-view-archived" onclick="closeCsvImportModal()" style="background: #6c757d;">
                                    Cancel
                                </button>
                                <button type="submit" class="btn-view-archived" style="background: #28a745;">
                                    <i class="bi bi-upload"></i> Import Records
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Delete Records Section -->
        <div id="deleteRecordsSection" class="delete-records-card" style="display: none;">
            <div class="delete-records-header">
                <div>
                    <h3>Delete Records</h3>
                    <p class="delete-info">Permanently delete deceased records. This action cannot be undone and will make the associated plots available.</p>
                </div>
                <button type="button" class="close-delete-btn" onclick="toggleDeleteSection()" aria-label="Close">
                    <i class="bi bi-x"></i>
                </button>
            </div>
            
            <?php if (!empty($success_message)): ?>
            <div class="alert alert-success" style="margin-bottom: 20px;">
                <i class="bi bi-check-circle-fill" style="margin-right: 8px;"></i>
                <?php echo htmlspecialchars($success_message); ?>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger" style="margin-bottom: 20px;">
                <i class="bi bi-exclamation-triangle-fill" style="margin-right: 8px;"></i>
                <?php echo htmlspecialchars($error_message); ?>
            </div>
            <?php endif; ?>
            
            <!-- Delete Individual Record -->
            <div class="delete-section-item">
                <h4>Delete Individual Record</h4>
                <p class="delete-warning">
                    <strong>Warning:</strong> This will permanently delete the selected record and make the plot available.
                </p>
                <form method="POST" action="" onsubmit="return confirmSingleDelete()">
                    <input type="hidden" name="action" value="delete_record">
                    <div class="form-group">
                        <label for="record_id">Select Record to Delete</label>
                        <div class="delete-filters-row">
                            <div class="delete-filter-group">
                                <select id="record_section_filter" class="form-select form-select-sm">
                                    <option value="all">All sections</option>
                                    <?php if ($all_sections_result && mysqli_num_rows($all_sections_result) > 0): ?>
                                        <?php mysqli_data_seek($all_sections_result, 0); ?>
                                        <?php while ($section = mysqli_fetch_assoc($all_sections_result)): ?>
                                            <option value="<?php echo $section['section_id']; ?>">
                                                <?php echo htmlspecialchars($section['section_name']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="delete-filter-group">
                                <select id="record_row_filter" class="form-select form-select-sm">
                                    <option value="all">All rows</option>
                                    <option value="A">ROW A</option>
                                    <option value="B">ROW B</option>
                                    <option value="C">ROW C</option>
                                    <option value="D">ROW D</option>
                                    <option value="E">ROW E</option>
                                </select>
                            </div>
                            <div class="delete-filter-group">
                                <input type="text" id="record_search" class="form-control form-control-sm" placeholder="Search by name or plot...">
                            </div>
                        </div>
                        <select id="record_id" name="record_id" required style="margin-top: 12px; width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
                            <option value="">Choose a record...</option>
                            <?php if ($records_result && mysqli_num_rows($records_result) > 0): ?>
                                <?php mysqli_data_seek($records_result, 0); ?>
                                <?php while ($record = mysqli_fetch_assoc($records_result)): ?>
                                    <?php 
                                        $sectionId = isset($record['section_id']) ? (int)$record['section_id'] : '';
                                        $rowNumber = isset($record['row_number']) ? (int)$record['row_number'] : null;
                                        $rowLetter = rowNumberToLetter($rowNumber);
                                    ?>
                                    <option 
                                        value="<?php echo $record['record_id']; ?>" 
                                        data-name="<?php echo htmlspecialchars($record['full_name']); ?>"
                                        data-section="<?php echo htmlspecialchars($sectionId); ?>"
                                        data-row="<?php echo $rowNumber ?? ''; ?>"
                                        data-row-letter="<?php echo htmlspecialchars($rowLetter); ?>">
                                        <?php echo htmlspecialchars($record['full_name']); ?> - <?php echo htmlspecialchars(($record['section_name'] ?? '') . '-' . ($rowLetter ?? '') . ($record['plot_number'] ?? '')); ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn-delete-record">
                        <i class="bi bi-trash"></i>
                        Delete Selected Record
                    </button>
                </form>
            </div>
            
            <!-- Bulk Delete Records by Section -->
            <div class="delete-section-item" style="margin-top: 32px; padding-top: 32px; border-top: 1px solid #e0e0e0;">
                <h4>Bulk Delete Records by Section</h4>
                <p class="delete-warning">
                    <strong>Warning:</strong> This will permanently delete ALL deceased records in the selected section and make their plots available.
                </p>
                <form method="POST" action="" onsubmit="return confirmBulkDelete()">
                    <input type="hidden" name="action" value="bulk_delete_section">
                    <div class="form-group">
                        <label for="bulk_delete_section">Select Section to Delete</label>
                        <select id="bulk_delete_section" name="section_id" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
                            <option value="">Choose a section...</option>
                            <?php if ($sections_with_records_result && mysqli_num_rows($sections_with_records_result) > 0): ?>
                                <?php mysqli_data_seek($sections_with_records_result, 0); ?>
                                <?php while ($section = mysqli_fetch_assoc($sections_with_records_result)): ?>
                                    <option value="<?php echo $section['section_id']; ?>" data-name="<?php echo htmlspecialchars($section['section_name']); ?>">
                                        <?php echo htmlspecialchars($section['section_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <option value="" disabled>No sections with records</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn-delete-bulk">
                        <i class="bi bi-trash-fill"></i>
                        Delete All Records in Section
                    </button>
                </form>
            </div>

            <!-- Bulk Delete Records by Rows -->
            <div class="delete-section-item" style="margin-top: 32px; padding-top: 32px; border-top: 1px solid #e0e0e0;">
                <h4>Bulk Delete Records by Rows</h4>
                <p class="delete-warning">
                    <strong>Warning:</strong> This will permanently delete ALL deceased records in the selected row (ROW A - ROW E) for the chosen section and make those plots available.
                </p>
                <form method="POST" action="" onsubmit="return confirmBulkDeleteRows()">
                    <input type="hidden" name="action" value="bulk_delete_rows">
                    <div class="form-group">
                        <label for="row_delete_section">Select Section</label>
                        <select id="row_delete_section" name="section_id_rows" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
                            <option value="">Choose a section...</option>
                            <?php if ($sections_with_records_result && mysqli_num_rows($sections_with_records_result) > 0): ?>
                                <?php mysqli_data_seek($sections_with_records_result, 0); ?>
                                <?php while ($section = mysqli_fetch_assoc($sections_with_records_result)): ?>
                                    <option value="<?php echo $section['section_id']; ?>">
                                        <?php echo htmlspecialchars($section['section_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <option value="" disabled>No sections with records</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="row_delete_rows">Select Row to Delete</label>
                        <select 
                            id="row_delete_rows" 
                            name="row_value" 
                            required 
                            disabled
                            style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
                            <option value="">Select a section first</option>
                        </select>
                        <small style="display: block; margin-top: 6px; color: #666;">Only one row can be deleted at a time.</small>
                    </div>
                    <button type="submit" class="btn-delete-bulk" style="background: #b71c1c;">
                        <i class="bi bi-trash"></i>
                        Delete Records in Selected Rows
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Search Form -->
        <div class="search-form">
            <div class="table-title">
                Search & Filter Records
                <?php 
                $active_filters = array_filter([$search_name, $search_plot, $search_section, $search_row]);
                if (!empty($active_filters)): 
                ?>
                    <span style="font-size: 12px; background: #2b4c7e; color: white; padding: 2px 8px; border-radius: 12px; margin-left: 8px;">
                        <?php echo count($active_filters); ?> filter(s) active
                    </span>
                <?php endif; ?>
            </div>
            <form method="GET" action="">
                <div class="search-row">
                    <div class="search-group">
                        <label for="search_name">Search by Name</label>
                        <input type="text" id="search_name" name="search_name" value="<?php echo htmlspecialchars($search_name); ?>" placeholder="Enter deceased name...">
                    </div>
                    <div class="search-group">
                        <label for="search_plot">Search by Plot</label>
                        <input type="text" id="search_plot" name="search_plot" value="<?php echo htmlspecialchars($search_plot); ?>" placeholder="Enter plot number...">
                    </div>
                    <div class="search-group">
                        <label for="search_section">Filter by Section</label>
                        <select id="search_section" name="search_section">
                            <option value="">All Sections</option>
                            <?php 
                            if ($sections_result && mysqli_num_rows($sections_result) > 0): 
                                mysqli_data_seek($sections_result, 0); // Reset pointer
                                while ($section = mysqli_fetch_assoc($sections_result)): 
                            ?>
                                <option value="<?php echo $section['section_id']; ?>" <?php echo $search_section == $section['section_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($section['section_name']); ?>
                                </option>
                            <?php 
                                endwhile;
                            endif; 
                            ?>
                        </select>
                    </div>
                    <div class="search-group">
                        <label for="search_row">Filter by Row</label>
                        <select id="search_row" name="search_row" <?php echo empty($search_section) ? 'disabled' : ''; ?>>
                            <?php if (empty($search_section)): ?>
                                <option value="">Select a section first</option>
                            <?php else: ?>
                                <option value=""><?php echo $search_row === '' ? 'All Rows' : 'All Rows'; ?></option>
                                <?php if (!empty($rows_data)): ?>
                                    <?php foreach ($rows_data as $row): ?>
                                        <option 
                                            value="<?php echo htmlspecialchars($row['row_number']); ?>" 
                                            <?php echo (string)$search_row === (string)$row['row_number'] ? 'selected' : ''; ?>>
                                            <?php echo 'Row ' . htmlspecialchars($row['row_letter']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" disabled>No rows found</option>
                                <?php endif; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                <div class="search-buttons">
                    <div style="display: flex; gap: 12px; margin-left: auto;">
                    <button type="submit" class="btn-search">Search</button>
                    <button type="button" class="btn-clear" onclick="clearSearch()">Clear</button>
                    </div>
                </div>
            </form>
        </div>
        
        <div class="table-card">
            <div class="table-title">
                All Deceased Records
                <?php if (isset($total_records)): ?>
                    <span style="font-size: 14px; color: #666; font-weight: normal;">
                        (<?php echo $total_records; ?> records found)
                    </span>
                <?php endif; ?>
            </div>
            <div class="table-wrapper">
                <table class="deceased-records-table">
                    <thead>
                        <tr>
                            <th style="cursor: pointer; text-align:center;" onclick="toggleSort('plot_location')">
                                Plot Location 
                                <?php if ($sort_by === 'plot_location'): ?>
                                    <?php if ($sort_order === 'asc'): ?>
                                        ↑
                                    <?php else: ?>
                                        ↓
                                    <?php endif; ?>
                                <?php else: ?>
                                    ↕
                                <?php endif; ?>
                            </th>
                            <th>Deceased Name</th>
                            <th>Date of Death</th>
                            <th>Date of Birth</th>
                            <th>Address</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && mysqli_num_rows($result) > 0): ?>
                            <?php 
                                $lastPlotLocation = null;
                                while ($record = mysqli_fetch_assoc($result)): 
                                    $rowLetter = rowNumberToLetter($record['row_number'] ?? 1);
                                    $sectionName = $record['section_name'] ?? 'Unknown Section';
                                    $plotNumber = $record['plot_number'] ?? '';
                                    $plotLocation = $sectionName . '-' . $rowLetter . $plotNumber;
                                    $displayPlotLocation = ($plotLocation !== $lastPlotLocation) ? $plotLocation : '';
                                    $lastPlotLocation = $plotLocation;
                            ?>
                            <tr>
                                <td style="text-align:center;"><?php echo htmlspecialchars($displayPlotLocation); ?></td>
                                <td><?php echo htmlspecialchars(trim($record['full_name']) !== '' ? trim($record['full_name']) : '—'); ?></td>
                                <td><?php echo formatDisplayDate($record['date_of_death'] ?? null); ?></td>
                                <td><?php echo formatDisplayDate($record['date_of_birth'] ?? null); ?></td>
                                <td><?php echo htmlspecialchars($record['address'] ?? ''); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="action-btn view" onclick="showViewRecordModal(<?php echo $record['record_id']; ?>)">
                                            <i class="bi bi-eye"></i> View
                                        </button>
                                        <button class="action-btn contract" onclick="showContractModal(<?php echo $record['record_id']; ?>, <?php echo $record['plot_id']; ?>)" title="Manage Contract">
                                            <i class="bi bi-file-text"></i> Contract
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center">No records found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if (isset($total_pages) && $total_pages > 1): ?>
            <div style="display: flex; justify-content: center; align-items: center; margin-top: 2rem; gap: 0.5rem;">
                <?php if ($page > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="btn-search" style="text-decoration: none;">Previous</a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="btn-search" style="pointer-events: none; opacity: 0.7;"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="btn-clear" style="text-decoration: none;"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="btn-search" style="text-decoration: none;">Next</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        </div>
    </div>
</div>

<!-- Contract Management Modal -->
<div class="modal-bg" id="contractModal">
    <div class="modal-content">
        <button class="modal-close" onclick="closeContractModal()">&times;</button>
        <div class="modal-title">Contract Details</div>
        
        <div class="record-info" id="recordInfo">
            <!-- Record information will be loaded here -->
        </div>
        
        <div id="alertContainer"></div>
        
        <form id="contractForm">
            <input type="hidden" id="recordId" name="record_id">
            <input type="hidden" id="plotId" name="plot_id">
            <input type="hidden" id="contract_status" name="contract_status">
            
            <div class="form-row">
                <div class="form-group">
                    <label>Contract Status</label>
                    <div>
                        <span id="contract_status_display" class="status-badge status-active">ACTIVE</span>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="contract_start_date">Contract Start Date</label>
                    <input type="text" id="contract_start_date" name="contract_start_date" readonly>
                </div>
                <div class="form-group">
                    <label for="contract_end_date">Contract End Date</label>
                    <input type="text" id="contract_end_date" name="contract_end_date" readonly>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="renewal_reminder_date">Renewal Reminder Date</label>
                    <input type="text" id="renewal_reminder_date" name="renewal_reminder_date" readonly>
                </div>
            </div>
            
            <div class="form-group">
                <label for="contract_notes">Contract Notes</label>
                <textarea id="contract_notes" name="contract_notes" class="readonly-display" readonly></textarea>
            </div>
        </form>
    </div>
</div>

<script>
    // Make functions globally available immediately
    window.showViewRecordModal = function(recordId) {
        console.log('showViewRecordModal called with ID:', recordId);
        
        // Check if modal elements exist
        const modal = document.getElementById('viewRecordModal');
        const modalTitle = document.getElementById('viewRecordModalTitle');
        const modalBody = document.getElementById('viewRecordModalBody');
        
        if (!modal || !modalTitle || !modalBody) {
            console.error('Modal elements not found:', {
                modal: !!modal,
                modalTitle: !!modalTitle,
                modalBody: !!modalBody
            });
            alert('Error: Modal elements not found. Please refresh the page.');
            return;
        }
        
        // Store record ID for edit functionality
        modal.dataset.recordId = recordId;
        
        // Fetch record details via AJAX
        fetch(`get_record_details.php?id=${recordId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const record = data.record;
                    modalTitle.textContent = `Record: ${record.full_name}`;
                    modalBody.innerHTML = `
                        <div class="record-info">
                            <h3>Deceased Information</h3>
                            <p><strong>Name:</strong> ${record.full_name}</p>
                            <p><strong>Date of Birth:</strong> ${formatDateDisplay(record.date_of_birth)}</p>
                            <p><strong>Date of Death:</strong> ${formatDateDisplay(record.date_of_death)}</p>
                            <p><strong>Date of Burial:</strong> ${formatDateDisplay(record.burial_date)}</p>
                        </div>
                        <div class="record-info">
                            <h3>Plot Information</h3>
                            ${(() => {
                                const rowNumber = record.row_number || 1;
                                let rowLetter = '';
                                let n = parseInt(rowNumber);
                                if (n > 0) {
                                    while (n > 0) {
                                        const remainder = (n - 1) % 26;
                                        rowLetter = String.fromCharCode(65 + remainder) + rowLetter;
                                        n = Math.floor((n - 1) / 26);
                                    }
                                }
                                const toOrdinal = (num) => {
                                    const nInt = parseInt(num, 10);
                                    if (!nInt || nInt <= 0) return '';
                                    const rem10 = nInt % 10;
                                    const rem100 = nInt % 100;
                                    let suffix = 'th';
                                    if (rem100 < 11 || rem100 > 13) {
                                        if (rem10 === 1) suffix = '1st';
                                        else if (rem10 === 2) suffix = '2nd';
                                        else if (rem10 === 3) suffix = '3rd';
                                    } else {
                                        suffix = nInt + 'th';
                                    }
                                    if (suffix === 'th') {
                                        suffix = nInt + 'th';
                                    }
                                    return suffix;
                                };
                                const ordinal = toOrdinal(rowNumber);
                                const rowDisplay = rowLetter && ordinal ? `${rowLetter} (${ordinal})` : (rowLetter || '—');
                                return `<p><strong>Section:</strong> ${record.section_name || '—'}</p>
                                        <p><strong>Row:</strong> ${rowDisplay}</p>
                                        <p><strong>Plot Number:</strong> ${record.plot_number || '—'}</p>`;
                            })()}
                        </div>
                    `;
                    // Show custom modal
                    modal.classList.add('active');
                } else {
                    alert('Error loading record details: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error loading record details');
            });
    }

    window.showEditRecordModal = function(recordId) {
        console.log('showEditRecordModal called with ID:', recordId);
        // Build return URL with current filter parameters
        const urlParams = new URLSearchParams(window.location.search);
        const returnParams = new URLSearchParams();
        returnParams.set('id', recordId);
        
        // Preserve all filter and pagination parameters
        ['search_name', 'search_plot', 'search_section', 'search_row', 'sort_by', 'sort_order', 'page'].forEach(param => {
            const value = urlParams.get(param);
            if (value) returnParams.set(param, value);
        });
        
        window.location.href = `edit_record.php?${returnParams.toString()}`;
    };

    window.showContractModal = function(recordId, plotId) {
        console.log('showContractModal called with ID:', recordId, 'Plot ID:', plotId);
        
        // Check if contract modal elements exist
        const contractModal = document.getElementById('contractModal');
        const recordInfo = document.getElementById('recordInfo');
        
        if (!contractModal || !recordInfo) {
            console.error('Contract modal elements not found:', {
                contractModal: !!contractModal,
                recordInfo: !!recordInfo
            });
            alert('Error: Contract modal elements not found. Please refresh the page.');
            return;
        }
        
        // Show loading state
        recordInfo.innerHTML = '<p>Loading...</p>';
        contractModal.classList.add('active');
        
        // Fetch record data
        fetch(`get_contract_data.php?record_id=${recordId}&plot_id=${plotId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Populate record info
                    document.getElementById('recordInfo').innerHTML = `
                        <h3>Deceased Information</h3>
                        <p><strong>Name:</strong> ${data.record.full_name}</p>
                        <p><strong>Date of Birth:</strong> ${formatDateDisplay(data.record.date_of_birth)}</p>
                        <p><strong>Date of Death:</strong> ${formatDateDisplay(data.record.date_of_death)}</p>
                        <p><strong>Date of Burial:</strong> ${formatDateDisplay(data.record.burial_date)}</p>
                        <p><strong>Plot:</strong> ${(() => {
                            const rowNumber = data.record.row_number || 1;
                            let rowLetter = '';
                            let n = parseInt(rowNumber);
                            if (n > 0) {
                                while (n > 0) {
                                    const remainder = (n - 1) % 26;
                                    rowLetter = String.fromCharCode(65 + remainder) + rowLetter;
                                    n = Math.floor((n - 1) / 26);
                                }
                            }
                            return (data.record.section_name || '') + '-' + rowLetter + (data.record.plot_number || '');
                        })()}</p>
                        <p><strong>Address:</strong> ${data.record.address || '—'}</p>
                        <p><strong>Name of Lessee:</strong> ${data.record.next_of_kin || '—'}</p>
                        <p><strong>Contact:</strong> ${data.record.contact_number || '—'}</p>
                    `;
                    
                    // Populate form fields
                    document.getElementById('recordId').value = recordId;
                    document.getElementById('plotId').value = plotId;
                    document.getElementById('contract_status').value = data.record.contract_status || 'active';
                    
                    // Handle dates: show formatted date text (MM/DD/YYYY) and no picker
                    const formatDateForDisplay = (dateStr) => {
                        const src = (dateStr && dateStr !== '0000-00-00') ? new Date(dateStr) : new Date();
                        if (isNaN(src.getTime())) return '';
                        const mm = String(src.getMonth() + 1).padStart(2, '0');
                        const dd = String(src.getDate()).padStart(2, '0');
                        const yyyy = src.getFullYear();
                        return `${mm}/${dd}/${yyyy}`;
                    };
                    
                    document.getElementById('contract_start_date').value = formatDateForDisplay(data.record.contract_start_date);
                    document.getElementById('contract_end_date').value = formatDateForDisplay(data.record.contract_end_date);
                    document.getElementById('renewal_reminder_date').value = formatDateForDisplay(data.record.renewal_reminder_date);
                    document.getElementById('contract_notes').value = data.record.contract_notes || '';

                    // Auto-set status based on end date
                    const endRaw = data.record.contract_end_date;
                    if (endRaw && endRaw !== '0000-00-00') {
                        const endDate = new Date(endRaw);
                        const today = new Date();
                        const statusSelect = document.getElementById('contract_status');
                        if (!isNaN(endDate.getTime())) {
                            if (endDate < today) {
                                statusSelect.value = 'expired';
                            } else if (endDate.getTime() - today.getTime() <= 30 * 24 * 60 * 60 * 1000) {
                                statusSelect.value = 'renewal_needed';
                            } else {
                                statusSelect.value = 'active';
                            }
                        }
                    }
                } else {
                    showAlert('Error loading contract data: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Error loading contract data', 'error');
            });
    }
    
    window.closeContractModal = function() {
        document.getElementById('contractModal').classList.remove('active');
        document.getElementById('alertContainer').innerHTML = '';
        document.getElementById('contractForm').reset();
    }
    
    window.showAlert = function(message, type) {
        const alertContainer = document.getElementById('alertContainer');
        const alertClass = type === 'success' ? 'alert-success' : 'alert-error';
        alertContainer.innerHTML = `<div class="alert ${alertClass}">${message}</div>`;
    }

    window.clearSearch = function() {
        document.getElementById('search_name').value = '';
        document.getElementById('search_plot').value = '';
        document.getElementById('search_section').value = '';
        const rowSelect = document.getElementById('search_row');
        if (rowSelect) {
            rowSelect.value = '';
            rowSelect.disabled = true;
        }
        window.location.href = window.location.pathname;
    }
    
    window.toggleSort = function(column) {
        const urlParams = new URLSearchParams(window.location.search);
        const currentSort = urlParams.get('sort_by');
        const currentOrder = urlParams.get('sort_order') || 'asc';
        
        if (currentSort === column) {
            // Toggle order
            urlParams.set('sort_order', currentOrder === 'asc' ? 'desc' : 'asc');
        } else {
            // New column, start with asc
            urlParams.set('sort_by', column);
            urlParams.set('sort_order', 'asc');
        }
        
        window.location.search = urlParams.toString();
    }
    
    // Handle form submission
    document.getElementById('contractForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        
        fetch('update_contract.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlert('Contract information updated successfully!', 'success');
                // Refresh the page after a short delay to show updated data
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                showAlert('Error updating contract information: ' + data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlert('Error updating contract information', 'error');
        });
    });
    
    // Auto-update contract status based on end date
    document.getElementById('contract_end_date').addEventListener('change', function() {
        const endDate = new Date(this.value);
        const today = new Date();
        const statusSelect = document.getElementById('contract_status');
        
        if (endDate < today) {
            statusSelect.value = 'expired';
        } else if (endDate.getTime() - today.getTime() <= 30 * 24 * 60 * 60 * 1000) { // 30 days
            statusSelect.value = 'renewal_needed';
        } else {
            statusSelect.value = 'active';
        }
    });
    
    // Close modal when clicking outside
    document.getElementById('contractModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeContractModal();
        }
    });
    
    // Close view record modal when clicking outside
    document.getElementById('viewRecordModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeViewRecordModal();
        }
    });
    
    window.closeViewRecordModal = function() {
        document.getElementById('viewRecordModal').classList.remove('active');
        document.getElementById('viewRecordModalBody').innerHTML = '';
    }
    
    window.editFromViewRecord = function() {
        // Get the record ID from the current view
        const recordId = document.getElementById('viewRecordModal').dataset.recordId;
        if (recordId) {
            // Build return URL with current filter parameters
            const urlParams = new URLSearchParams(window.location.search);
            const returnParams = new URLSearchParams();
            returnParams.set('id', recordId);
            
            // Preserve all filter and pagination parameters
            ['search_name', 'search_plot', 'search_section', 'search_row', 'sort_by', 'sort_order', 'page'].forEach(param => {
                const value = urlParams.get(param);
                if (value) returnParams.set(param, value);
            });
            
            window.location.href = `edit_record.php?${returnParams.toString()}`;
        }
    }
</script>

<!-- View Record Modal -->
<div class="modal-bg" id="viewRecordModal">
    <div class="modal-content">
        <button class="modal-close" onclick="closeViewRecordModal()">&times;</button>
        <div class="modal-title" id="viewRecordModalTitle">Record Details</div>
        
        <div id="viewRecordModalBody">
            <!-- Content will be loaded dynamically -->
        </div>
        
        <div class="modal-footer" style="display: flex; justify-content: flex-end; align-items: center;">
            <button type="button" class="modal-edit-btn" onclick="editFromViewRecord()">
                <i class="bi bi-pencil"></i>
                <span>Edit</span>
            </button>
        </div>
    </div>
</div>
<script>
    // DOM ready event listeners
    document.addEventListener('DOMContentLoaded', function() {
        // Handle form submission
        const contractForm = document.getElementById('contractForm');
        if (contractForm) {
            contractForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                
                fetch('update_contract.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAlert('Contract information updated successfully!', 'success');
                        // Refresh the page after a short delay to show updated data
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        showAlert('Error updating contract information: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('Error updating contract information', 'error');
                });
            });
        }
        
        // Auto-update contract status based on end date
        const contractEndDate = document.getElementById('contract_end_date');
        if (contractEndDate) {
            contractEndDate.addEventListener('change', function() {
                const endDate = new Date(this.value);
                const today = new Date();
                const statusSelect = document.getElementById('contract_status');
                
                if (endDate < today) {
                    statusSelect.value = 'expired';
                } else if (endDate.getTime() - today.getTime() <= 30 * 24 * 60 * 60 * 1000) { // 30 days
                    statusSelect.value = 'renewal_needed';
                } else {
                    statusSelect.value = 'active';
                }
            });
        }
        
        // Close modal when clicking outside
        const contractModal = document.getElementById('contractModal');
        if (contractModal) {
            contractModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeContractModal();
                }
            });
        }
        
        // Close view record modal when clicking outside
        const viewRecordModal = document.getElementById('viewRecordModal');
        if (viewRecordModal) {
            viewRecordModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeViewRecordModal();
                }
            });
        }

        // If a specific record ID is provided in the URL (e.g., from plot_details.php),
        // automatically open its view modal after the page is ready.
        try {
            const urlParams = new URLSearchParams(window.location.search);
            const recordIdFromUrl = urlParams.get('id');
            if (recordIdFromUrl && typeof window.showViewRecordModal === 'function') {
                // Slight delay to ensure the table and modal elements are fully rendered
                setTimeout(function () {
                    window.showViewRecordModal(recordIdFromUrl);
                }, 300);
            }
        } catch (e) {
            console.error('Error while trying to auto-open record modal from URL:', e);
        }

        // Dynamic row filter based on selected section
        const sectionSelect = document.getElementById('search_section');
        const rowSelect = document.getElementById('search_row');
        const selectedRowFromServer = "<?php echo htmlspecialchars($search_row); ?>";

        // Helper to reset the row dropdown with a single option + disabled state
        function resetRowSelect(message, disabled = true) {
            if (!rowSelect) return;
            rowSelect.disabled = disabled;
            rowSelect.innerHTML = '';
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = message;
            rowSelect.appendChild(opt);
        }

        function loadRowsForSection(sectionId, selectedRow) {
            if (!rowSelect) return;

            if (!sectionId) {
                resetRowSelect('Select a section first', true);
                return;
            }

            // Show loading state
            resetRowSelect('Loading rows...', true);

            fetch('get_section_rows.php?section_id=' + encodeURIComponent(sectionId))
                .then(response => response.json())
                .then(data => {
                    rowSelect.innerHTML = '';

                    // Default "All Rows" option
                    const defaultOption = document.createElement('option');
                    defaultOption.value = '';
                    defaultOption.textContent = 'All Rows';
                    rowSelect.appendChild(defaultOption);

                    if (data.success && Array.isArray(data.rows)) {
                        data.rows.forEach(function(row) {
                            const opt = document.createElement('option');
                            opt.value = row.row_number;
                            opt.textContent = row.display_name;
                            if (selectedRow && String(selectedRow) === String(row.row_number)) {
                                opt.selected = true;
                            }
                            rowSelect.appendChild(opt);
                        });
                    } else {
                        const opt = document.createElement('option');
                        opt.value = '';
                        opt.textContent = 'No rows found';
                        rowSelect.appendChild(opt);
                    }

                    rowSelect.disabled = false;
                })
                .catch(error => {
                    console.error('Error loading rows:', error);
                    resetRowSelect('Error loading rows', true);
                });
        }

        if (sectionSelect && rowSelect) {
            // Initial load if a section is already selected (e.g., after search)
            if (sectionSelect.value) {
                loadRowsForSection(sectionSelect.value, selectedRowFromServer);
            } else {
                resetRowSelect('Select a section first', true);
            }

            // Reload rows whenever section changes
            sectionSelect.addEventListener('change', function() {
                loadRowsForSection(this.value, '');
            });
        }

        // Bulk delete rows dropdown population
        const rowDeleteSection = document.getElementById('row_delete_section');
        const rowDeleteSelect = document.getElementById('row_delete_rows');

        const rowNumberToLetterJS = (value) => {
            const num = parseInt(value, 10);
            if (Number.isNaN(num) || num < 1) {
                return '';
            }
            let letter = '';
            let n = num;
            while (n > 0) {
                const remainder = (n - 1) % 26;
                letter = String.fromCharCode(65 + remainder) + letter;
                n = Math.floor((n - 1) / 26);
            }
            return letter;
        };

        const resetRowDeleteSelect = (message, disabled = true) => {
            if (!rowDeleteSelect) return;
            rowDeleteSelect.innerHTML = '';
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = message || 'Select a section first';
            rowDeleteSelect.appendChild(opt);
            rowDeleteSelect.disabled = disabled;
        };

        function loadRowsForBulkDelete(sectionId) {
            if (!rowDeleteSelect) return;

            if (!sectionId) {
                resetRowDeleteSelect('Select a section first', true);
                return;
            }

            rowDeleteSelect.disabled = true;
            rowDeleteSelect.innerHTML = '<option value=\"\">Loading rows...</option>';

            fetch('get_section_rows.php?section_id=' + encodeURIComponent(sectionId))
                .then(response => response.json())
                .then(data => {
                    rowDeleteSelect.innerHTML = '';

                    if (data.success && Array.isArray(data.rows) && data.rows.length > 0) {
                        data.rows.forEach(row => {
                            const value = rowNumberToLetterJS(row.row_number) || row.row_number;
                            const labelLetter = rowNumberToLetterJS(row.row_number);
                            const option = document.createElement('option');
                            option.value = value;
                            option.textContent = labelLetter ? `ROW ${labelLetter}` : `Row ${row.row_number}`;
                            rowDeleteSelect.appendChild(option);
                        });
                        rowDeleteSelect.disabled = false;
                    } else {
                        resetRowDeleteSelect('No rows found for this section', true);
                    }
                })
                .catch(error => {
                    console.error('Error loading rows for bulk delete:', error);
                    resetRowDeleteSelect('Error loading rows', true);
                });
        }

        if (rowDeleteSection && rowDeleteSelect) {
            resetRowDeleteSelect('Select a section first', true);
            rowDeleteSection.addEventListener('change', function() {
                loadRowsForBulkDelete(this.value);
            });
        }
    });
    
    /* Actions Dropdown Functions: Handle dropdown menu and action selection */
    
    // Toggle Actions Dropdown Menu
    window.toggleActionsDropdown = function() {
        const dropdownBtn = document.getElementById('actionsDropdownBtn');
        const dropdownMenu = document.getElementById('actionsDropdownMenu');
        
        if (dropdownBtn && dropdownMenu) {
            const isActive = dropdownBtn.classList.contains('active');
            
            if (isActive) {
                dropdownBtn.classList.remove('active');
                dropdownMenu.classList.remove('show');
            } else {
                dropdownBtn.classList.add('active');
                dropdownMenu.classList.add('show');
            }
        }
    };
    
    // Handle Action Selection from Dropdown
    window.selectAction = function(action) {
        const dropdownBtn = document.getElementById('actionsDropdownBtn');
        const dropdownMenu = document.getElementById('actionsDropdownMenu');
        
        // Close dropdown after selection
        if (dropdownBtn) dropdownBtn.classList.remove('active');
        if (dropdownMenu) dropdownMenu.classList.remove('show');
        
        // Show the selected section
        if (action === 'import') {
            toggleImportSection();
        } else if (action === 'backup') {
            toggleBackupSection();
        } else if (action === 'delete') {
            toggleDeleteSection();
        }
    };
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const dropdownWrapper = document.querySelector('.actions-dropdown-wrapper');
        const dropdownBtn = document.getElementById('actionsDropdownBtn');
        const dropdownMenu = document.getElementById('actionsDropdownMenu');
        
        if (dropdownWrapper && dropdownBtn && dropdownMenu) {
            // Check if click is outside the dropdown
            if (!dropdownWrapper.contains(event.target)) {
                dropdownBtn.classList.remove('active');
                dropdownMenu.classList.remove('show');
            }
        }
    });
    
    // Show Backup & Restore Modal
    window.toggleBackupSection = function() {
        const backupModal = document.getElementById('backupRestoreModal');
        const importSection = document.getElementById('bulkImportSection');
        const deleteSection = document.getElementById('deleteRecordsSection');
        
        if (backupModal) {
            // Close other sections if open
            if (importSection && importSection.style.display !== 'none') {
                importSection.style.display = 'none';
            }
            if (deleteSection && deleteSection.style.display !== 'none') {
                deleteSection.style.display = 'none';
            }
            
            // Show the modal
            backupModal.classList.add('show');
        }
    };
    
    // Close Backup & Restore Modal
    window.closeBackupRestoreModal = function() {
        const backupModal = document.getElementById('backupRestoreModal');
        if (backupModal) {
            backupModal.classList.remove('show');
        }
    };
    
    // CSV Import Modal Functions
    window.showCsvImportModal = function() {
        const importModal = document.getElementById('csvImportModal');
        if (importModal) {
            importModal.classList.add('show');
            // Reset to single tab by default
            switchImportTab('single');
        }
    };
    
    window.closeCsvImportModal = function() {
        const importModal = document.getElementById('csvImportModal');
        if (importModal) {
            importModal.classList.remove('show');
        }
    };
    
    window.switchImportTab = function(tab) {
        const singleTab = document.getElementById('single-import-tab');
        const bulkTab = document.getElementById('bulk-import-tab');
        const singleBtn = document.getElementById('tab-single-btn');
        const bulkBtn = document.getElementById('tab-bulk-btn');
        
        if (tab === 'single') {
            if (singleTab) singleTab.style.display = 'block';
            if (bulkTab) bulkTab.style.display = 'none';
            if (singleBtn) singleBtn.classList.add('active');
            if (bulkBtn) bulkBtn.classList.remove('active');
        } else if (tab === 'bulk') {
            if (singleTab) singleTab.style.display = 'none';
            if (bulkTab) bulkTab.style.display = 'block';
            if (singleBtn) singleBtn.classList.remove('active');
            if (bulkBtn) bulkBtn.classList.add('active');
        }
    };
    
    // Toggle Delete Records Section
    window.toggleDeleteSection = function() {
        const deleteSection = document.getElementById('deleteRecordsSection');
        const importSection = document.getElementById('bulkImportSection');
        const backupModal = document.getElementById('backupRestoreModal');
        
        if (deleteSection) {
            // Close other sections if open
            if (importSection && importSection.style.display !== 'none') {
                importSection.style.display = 'none';
            }
            if (backupModal && backupModal.classList.contains('show')) {
                backupModal.classList.remove('show');
            }
            
            if (deleteSection.style.display === 'none' || !deleteSection.style.display) {
                deleteSection.style.display = 'block';
                // Scroll to delete section
                deleteSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } else {
                deleteSection.style.display = 'none';
            }
        }
    };
    
    // Delete Confirmation Functions
    window.confirmSingleDelete = function() {
        const recordSelect = document.getElementById('record_id');
        const recordName = recordSelect.options[recordSelect.selectedIndex]?.getAttribute('data-name');
        
        if (!recordSelect || recordSelect.value === '') {
            alert('Please select a record to delete.');
            return false;
        }
        
        return confirm(`Are you sure you want to delete the record for "${recordName}"?\n\nThis action cannot be undone and will make the plot available.`);
    };
    
    window.confirmBulkDelete = function() {
        const sectionSelect = document.getElementById('bulk_delete_section');
        const sectionName = sectionSelect.options[sectionSelect.selectedIndex]?.getAttribute('data-name');
        
        if (!sectionSelect || sectionSelect.value === '') {
            alert('Please select a section to delete.');
            return false;
        }
        
        return confirm(`WARNING: This will permanently delete ALL deceased records in "${sectionName}"!\n\nThis action cannot be undone and will make all plots in this section available.\n\nAre you absolutely sure you want to continue?`);
    };
    
    window.confirmBulkDeleteRows = function() {
        const sectionSelect = document.getElementById('row_delete_section');
        const rowsSelect = document.getElementById('row_delete_rows');
        
        if (!sectionSelect || !rowsSelect) {
            alert('Form elements for row deletion are missing.');
            return false;
        }
        
        if (!sectionSelect.value) {
            alert('Please select a section to delete records from.');
            return false;
        }
        
        if (!rowsSelect.value) {
            alert('Please select a row (A-E) to delete.');
            return false;
        }
        
        const sectionName = sectionSelect.options[sectionSelect.selectedIndex]?.textContent.trim();
        const rowLabel = rowsSelect.options[rowsSelect.selectedIndex]?.textContent.trim();
        
        return confirm(
            `WARNING: This will permanently delete ALL deceased records in ${sectionName} for ${rowLabel}.\n\n` +
            `This action cannot be undone and will make the corresponding plots available.\n\n` +
            `Do you want to continue?`
        );
    };
    
    // Archive Modal Functions
    window.showArchiveModal = function() {
        const modal = document.getElementById('archiveListModal');
        if (modal) {
            modal.classList.add('show');
        }
    };
    
    window.closeArchiveListModal = function() {
        const modal = document.getElementById('archiveListModal');
        if (modal) {
            modal.classList.remove('show');
        }
    };
    
    window.viewArchiveDetails = function(record) {
        // Format dates using unified format
        const formatDate = (dateStr) => {
            return formatDateDisplay(dateStr);
        };

        // Update modal content
        document.getElementById('modal-name').textContent = (record.first_name || '') + ' ' + (record.last_name || '');
        document.getElementById('modal-plot').textContent = record.plot_location || '—';
        document.getElementById('modal-birth').textContent = record.date_of_birth ? formatDate(record.date_of_birth) : '—';
        document.getElementById('modal-death').textContent = formatDate(record.date_of_death);
        document.getElementById('modal-burial').textContent = formatDate(record.date_of_burial);
        document.getElementById('modal-age').textContent = record.age || '—';
        document.getElementById('modal-gender').textContent = record.gender || '—';
        document.getElementById('modal-cause').textContent = record.cause_of_death || '—';
        document.getElementById('modal-contact-person').textContent = record.contact_person || '—';
        document.getElementById('modal-contact-number').textContent = record.contact_number || '—';
        document.getElementById('modal-address').textContent = record.address || '—';
        document.getElementById('modal-archived-date').textContent = formatDate(record.archived_at);
        document.getElementById('modal-reason').textContent = record.reason || '—';
        document.getElementById('modal-archived-by').textContent = record.archived_by_user || '—';

        // Show modal
        const detailModal = document.getElementById('archiveModal');
        if (detailModal) {
            detailModal.classList.add('show');
        }
    };
    
    window.closeArchiveModal = function() {
        const modal = document.getElementById('archiveModal');
        if (modal) {
            modal.classList.remove('show');
        }
    };
    
    // Close modals when clicking outside
    document.addEventListener('DOMContentLoaded', function() {
        const archiveListModal = document.getElementById('archiveListModal');
        const archiveModal = document.getElementById('archiveModal');
        const backupRestoreModal = document.getElementById('backupRestoreModal');
        
        if (archiveListModal) {
            archiveListModal.addEventListener('click', function(e) {
                if (e.target === archiveListModal) {
                    closeArchiveListModal();
                }
            });
        }
        
        if (archiveModal) {
            archiveModal.addEventListener('click', function(e) {
                if (e.target === archiveModal) {
                    closeArchiveModal();
                }
            });
        }
        
        if (backupRestoreModal) {
            backupRestoreModal.addEventListener('click', function(e) {
                if (e.target === backupRestoreModal) {
                    closeBackupRestoreModal();
                }
            });
        }
        
        const csvImportModal = document.getElementById('csvImportModal');
        if (csvImportModal) {
            csvImportModal.addEventListener('click', function(e) {
                if (e.target === csvImportModal) {
                    closeCsvImportModal();
                }
            });
        }
        
        // Handle backup section selection change to populate row dropdown
        const backupSections = document.getElementById('backup_sections');
        const backupRowFilter = document.getElementById('backup_row_filter');
        const backupSectionCount = document.getElementById('backup-section-count');
        const backupAllSections = document.getElementById('backup_all_sections');
        const backupForm = document.getElementById('backupForm');
        
        function updateBackupSectionCount() {
            if (backupSectionCount && backupSections) {
                const count = backupSections.selectedOptions.length;
                if (count > 0) {
                    backupSectionCount.textContent = `(${count} selected)`;
                } else {
                    backupSectionCount.textContent = '';
                }
            }
        }
        
        // Handle "All Sections" checkbox
        if (backupAllSections) {
            backupAllSections.addEventListener('change', function() {
                if (this.checked) {
                    // Disable sections dropdown and clear selection
                    if (backupSections) {
                        backupSections.disabled = true;
                        backupSections.selectedIndex = -1;
                        if (backupSectionCount) {
                            backupSectionCount.textContent = '';
                        }
                    }
                    // Enable row filter and set to "All rows"
                    if (backupRowFilter) {
                        backupRowFilter.innerHTML = '<option value="">All rows</option>';
                        backupRowFilter.disabled = false;
                    }
                } else {
                    // Enable sections dropdown
                    if (backupSections) {
                        backupSections.disabled = false;
                        updateBackupSectionCount();
                    }
                    // Reset row filter
                    if (backupRowFilter) {
                        backupRowFilter.innerHTML = '<option value="">All rows</option>';
                        backupRowFilter.disabled = true;
                    }
                }
            });
        }
        
        // Handle form submission validation
        if (backupForm) {
            backupForm.addEventListener('submit', function(e) {
                // If "All Sections" is checked, allow submission even without section selection
                if (backupAllSections && backupAllSections.checked) {
                    return true;
                }
                
                // Otherwise, require at least one section to be selected
                if (backupSections && backupSections.selectedOptions.length === 0) {
                    e.preventDefault();
                    alert('Please select at least one section or check "All Sections"');
                    return false;
                }
                
                return true;
            });
        }
        
        if (backupSections && backupRowFilter) {
            // Update count on initial load
            updateBackupSectionCount();
            
            backupSections.addEventListener('change', function() {
                // Skip if disabled (when "All Sections" is checked)
                if (this.disabled) {
                    return;
                }
                
                updateBackupSectionCount();
                const selectedSections = Array.from(this.selectedOptions).map(opt => opt.value);
                
                // Reset row dropdown
                backupRowFilter.innerHTML = '<option value="">Loading rows...</option>';
                backupRowFilter.disabled = true;
                
                if (selectedSections.length > 0) {
                    // Fetch available rows for all selected sections
                    const sectionIds = selectedSections.join(',');
                    fetch(`get_section_rows.php?section_ids=${sectionIds}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success && data.rows.length > 0) {
                                // Populate row dropdown with available rows
                                backupRowFilter.innerHTML = '<option value="">All rows</option>';
                                data.rows.forEach(row => {
                                    const option = document.createElement('option');
                                    option.value = row.row_number;
                                    option.textContent = row.display_name;
                                    backupRowFilter.appendChild(option);
                                });
                                backupRowFilter.disabled = false;
                            } else {
                                // No available rows found
                                backupRowFilter.innerHTML = '<option value="">All rows</option>';
                                backupRowFilter.disabled = false;
                            }
                        })
                        .catch(error => {
                            console.error('Error fetching rows:', error);
                            backupRowFilter.innerHTML = '<option value="">All rows</option>';
                            backupRowFilter.disabled = false;
                        });
                } else {
                    // No sections selected
                    backupRowFilter.innerHTML = '<option value="">All rows</option>';
                    backupRowFilter.disabled = true;
                }
            });
        }
        
        // Handle CSV Import Modal bulk import section selection change to populate row dropdown
        const bulkImportSectionSelect = document.getElementById('bulk_import_section_id');
        const bulkImportRowSelect = document.getElementById('bulk_import_row_number');
        
        if (bulkImportSectionSelect && bulkImportRowSelect) {
            bulkImportSectionSelect.addEventListener('change', function() {
                const sectionId = this.value;
                
                // Reset row dropdown
                bulkImportRowSelect.innerHTML = '<option value="">Loading rows...</option>';
                bulkImportRowSelect.disabled = true;
                
                if (sectionId) {
                    // Fetch available rows for the selected section
                    fetch(`get_section_rows.php?section_id=${sectionId}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success && data.rows.length > 0) {
                                // Function to convert row number to letter (1=A, 2=B, ..., 26=Z, 27=AA, etc.)
                                function rowNumberToLetterForImport(rowNum) {
                                    const num = parseInt(rowNum, 10);
                                    if (Number.isNaN(num) || num < 1) {
                                        return rowNum;
                                    }
                                    let letter = '';
                                    let n = num;
                                    while (n > 0) {
                                        const remainder = (n - 1) % 26;
                                        letter = String.fromCharCode(65 + remainder) + letter;
                                        n = Math.floor((n - 1) / 26);
                                    }
                                    return letter;
                                }
                                
                                // Populate row dropdown with available rows
                                bulkImportRowSelect.innerHTML = '<option value="">Choose a row...</option>';
                                data.rows.forEach(row => {
                                    const option = document.createElement('option');
                                    option.value = row.row_number;
                                    option.textContent = 'ROW ' + rowNumberToLetterForImport(row.row_number);
                                    bulkImportRowSelect.appendChild(option);
                                });
                                bulkImportRowSelect.disabled = false;
                            } else {
                                // No available rows found
                                bulkImportRowSelect.innerHTML = '<option value="">No rows found in this section</option>';
                                bulkImportRowSelect.disabled = true;
                            }
                        })
                        .catch(error => {
                            console.error('Error fetching rows:', error);
                            bulkImportRowSelect.innerHTML = '<option value="">Error loading rows</option>';
                            bulkImportRowSelect.disabled = true;
                        });
                } else {
                    // No section selected
                    bulkImportRowSelect.innerHTML = '<option value="">First select a section...</option>';
                    bulkImportRowSelect.disabled = true;
                }
            });
        }
        
        // Helper function to convert row number to letter (1=A, 2=B, etc.)
        const rowNumberToLetter = (rowNum) => {
            const num = parseInt(rowNum, 10);
            if (Number.isNaN(num) || num < 1) {
                return '';
            }
            let letter = '';
            let n = num;
            while (n > 0) {
                const remainder = (n - 1) % 26;
                letter = String.fromCharCode(65 + remainder) + letter;
                n = Math.floor((n - 1) / 26);
            }
            return letter;
        };
        
        // Make archive table rows clickable
        const archiveTableRows = document.querySelectorAll('.archive-table tbody tr[data-birth-date]');
        archiveTableRows.forEach(row => {
            row.style.cursor = 'pointer';
            row.addEventListener('click', function() {
                const sectionName = this.dataset.sectionName || '';
                const rowNumber = this.dataset.rowNumber || '';
                const plotNumber = this.dataset.plotNumber || '';
                const rowLetter = rowNumberToLetter(rowNumber);
                const plotLocation = sectionName && (rowLetter || plotNumber) 
                    ? sectionName + '-' + rowLetter + plotNumber 
                    : '';
                
                const record = {
                    first_name: this.dataset.firstName || '',
                    last_name: this.dataset.lastName || '',
                    section_name: sectionName,
                    row_number: rowNumber,
                    plot_number: plotNumber,
                    plot_location: plotLocation,
                    date_of_birth: this.dataset.birthDate || '',
                    date_of_death: this.dataset.deathDate || '',
                    date_of_burial: this.dataset.burialDate || '',
                    age: this.dataset.age || '',
                    gender: this.dataset.gender || '',
                    cause_of_death: this.dataset.cause || '',
                    contact_person: this.dataset.contactPerson || '',
                    contact_number: this.dataset.contactNumber || '',
                    address: this.dataset.address || '',
                    archived_at: this.dataset.archivedDate || '',
                    reason: this.dataset.reason || '',
                    archived_by_user: this.dataset.archivedBy || ''
                };
                viewArchiveDetails(record);
            });
        });
        
        // Filtering for "Delete Individual Record" dropdown
        let currentRecordSection = 'all';
        let currentRecordRow = 'all';
        let currentRecordSearch = '';
        
        function applyRecordFilters() {
            const select = document.getElementById('record_id');
            if (!select) return;

            const options = Array.from(select.options);
            options.forEach((opt, index) => {
                if (index === 0) {
                    opt.style.display = '';
                    return;
                }

                const sectionId = (opt.getAttribute('data-section') || '').toString();
                const rowLetter = (opt.getAttribute('data-row-letter') || '').toUpperCase();
                const text = opt.textContent.toLowerCase();

                const matchesSection = currentRecordSection === 'all' || sectionId === currentRecordSection;
                const matchesRow = currentRecordRow === 'all' || rowLetter === currentRecordRow;
                const matchesSearch = !currentRecordSearch || text.includes(currentRecordSearch);

                const visible = matchesSection && matchesRow && matchesSearch;
                opt.style.display = visible ? '' : 'none';
            });

            const selected = select.options[select.selectedIndex];
            if (selected && selected.style.display === 'none') {
                select.selectedIndex = 0;
            }
        }
        
        const recordSelect = document.getElementById('record_id');
        const sectionFilter = document.getElementById('record_section_filter');
        const rowFilter = document.getElementById('record_row_filter');
        const searchInput = document.getElementById('record_search');

        if (recordSelect && sectionFilter && rowFilter && searchInput) {
            sectionFilter.addEventListener('change', function () {
                currentRecordSection = this.value || 'all';
                applyRecordFilters();
            });

            rowFilter.addEventListener('change', function () {
                currentRecordRow = this.value === 'all' ? 'all' : this.value.toUpperCase();
                applyRecordFilters();
            });

            searchInput.addEventListener('input', function () {
                currentRecordSearch = this.value.toLowerCase().trim();
                applyRecordFilters();
            });

            applyRecordFilters();
        }
    });
</script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.10.2/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.min.js"></script>

<!-- Archive Records List Modal -->
<div class="archive-modal" id="archiveListModal">
    <div class="archive-modal-content">
        <div class="archive-modal-header">
            <h3>Archived Records</h3>
            <button class="archive-modal-close" onclick="closeArchiveListModal()">&times;</button>
        </div>
        <div class="archive-modal-body">
            <div style="overflow-x: auto;">
                <table class="archive-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Plot Location</th>
                            <th>Birth Date</th>
                            <th>Death Date</th>
                            <th>Burial Date</th>
                            <th>Archived Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($archive_result && mysqli_num_rows($archive_result) > 0): ?>
                            <?php 
                            mysqli_data_seek($archive_result, 0);
                            while ($record = mysqli_fetch_assoc($archive_result)): 
                            ?>
                                <tr data-first-name="<?php echo htmlspecialchars($record['first_name'] ?? ''); ?>"
                                    data-last-name="<?php echo htmlspecialchars($record['last_name'] ?? ''); ?>"
                                    data-section-name="<?php echo htmlspecialchars($record['section_name'] ?? ''); ?>"
                                    data-row-number="<?php echo htmlspecialchars($record['row_number'] ?? ''); ?>"
                                    data-plot-number="<?php echo htmlspecialchars($record['plot_number'] ?? ''); ?>"
                                    data-birth-date="<?php echo htmlspecialchars($record['date_of_birth'] ?? ''); ?>"
                                    data-death-date="<?php echo htmlspecialchars($record['date_of_death'] ?? ''); ?>"
                                    data-burial-date="<?php echo htmlspecialchars($record['date_of_burial'] ?? ''); ?>"
                                    data-age="<?php echo htmlspecialchars($record['age'] ?? ''); ?>"
                                    data-gender="<?php echo htmlspecialchars($record['gender'] ?? ''); ?>"
                                    data-cause="<?php echo htmlspecialchars($record['cause_of_death'] ?? ''); ?>"
                                    data-contact-person="<?php echo htmlspecialchars($record['contact_person'] ?? ''); ?>"
                                    data-contact-number="<?php echo htmlspecialchars($record['contact_number'] ?? ''); ?>"
                                    data-address="<?php echo htmlspecialchars($record['address'] ?? ''); ?>"
                                    data-archived-date="<?php echo htmlspecialchars($record['archived_at'] ?? ''); ?>"
                                    data-reason="<?php echo htmlspecialchars($record['reason'] ?? ''); ?>"
                                    data-archived-by="<?php echo htmlspecialchars($record['archived_by_user'] ?? ''); ?>">
                                    <td><?php echo htmlspecialchars(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')); ?></td>
                                    <td><?php 
                                        $rowLetter = rowNumberToLetter($record['row_number'] ?? 1);
                                        $sectionName = $record['section_name'] ?? '';
                                        $plotNumber = $record['plot_number'] ?? '';
                                        $plotLocation = $sectionName . '-' . $rowLetter . $plotNumber;
                                        echo htmlspecialchars($plotLocation);
                                    ?></td>
                                    <td><?php echo formatDisplayDate($record['date_of_birth'] ?? null); ?></td>
                                    <td><?php echo formatDisplayDate($record['date_of_death'] ?? null); ?></td>
                                    <td><?php echo formatDisplayDate($record['date_of_burial'] ?? null); ?></td>
                                    <td><?php echo formatDisplayDate($record['archived_at'] ?? null); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 40px;">No archived records found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Archive Details Modal -->
<div class="archive-modal" id="archiveModal">
    <div class="archive-modal-content" style="max-width: 800px;">
        <div class="archive-modal-header">
            <h3>Archived Record Details</h3>
            <button class="archive-modal-close" onclick="closeArchiveModal()">&times;</button>
        </div>
        <div class="archive-modal-body">
            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">Name</div>
                    <div class="detail-value" id="modal-name"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Plot Location</div>
                    <div class="detail-value" id="modal-plot"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Date of Birth</div>
                    <div class="detail-value" id="modal-birth"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Date of Death</div>
                    <div class="detail-value" id="modal-death"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Date of Burial</div>
                    <div class="detail-value" id="modal-burial"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Age</div>
                    <div class="detail-value" id="modal-age"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Gender</div>
                    <div class="detail-value" id="modal-gender"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Cause of Death</div>
                    <div class="detail-value" id="modal-cause"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Contact Person</div>
                    <div class="detail-value" id="modal-contact-person"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Contact Number</div>
                    <div class="detail-value" id="modal-contact-number"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Address</div>
                    <div class="detail-value" id="modal-address"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Archived Date</div>
                    <div class="detail-value" id="modal-archived-date"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Reason for Archival</div>
                    <div class="detail-value" id="modal-reason"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Archived By</div>
                    <div class="detail-value" id="modal-archived-by"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Prevent browser from restoring scroll position
    if ('scrollRestoration' in history) {
        history.scrollRestoration = 'manual';
    }
    
    // Immediate scroll reset (before DOM is ready)
    window.scrollTo(0, 0);
    
    // Reset scroll position on page load to prevent layout shift when navigating
    document.addEventListener('DOMContentLoaded', function() {
        window.scrollTo(0, 0);
    });
</script>

</body>
</html> 