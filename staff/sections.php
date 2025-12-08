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
    if ($_GET['success'] === 'section_added') {
        $success_message = "Section added successfully";
    } elseif ($_GET['success'] === 'section_updated') {
        $success_message = "Section updated successfully";
    }
}

// Handle section creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_section') {
    $section_code = mysqli_real_escape_string($conn, $_POST['section_code']);
    $section_name = mysqli_real_escape_string($conn, $_POST['section_name']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    
    $query = "INSERT INTO sections (section_code, section_name, description) VALUES (?, ?, ?)";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "sss", $section_code, $section_name, $description);
    
    if (mysqli_stmt_execute($stmt)) {
        header('Location: sections.php?success=section_added');
        exit();
    } else {
        $error_message = "Error adding section: " . mysqli_error($conn);
    }
}

// Handle section update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_section') {
    $section_id = intval($_POST['section_id']);
    $section_name = mysqli_real_escape_string($conn, $_POST['section_name']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    
    $query = "UPDATE sections SET section_name = ?, description = ? WHERE section_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ssi", $section_name, $description, $section_id);
    
    if (mysqli_stmt_execute($stmt)) {
        header('Location: sections.php?success=section_updated');
        exit();
    } else {
        $error_message = "Error updating section: " . mysqli_error($conn);
    }
}

// Fetch all sections
$query = "SELECT s.*, COUNT(p.plot_id) as plot_count 
          FROM sections s 
          LEFT JOIN plots p ON s.section_id = p.section_id 
          GROUP BY s.section_id 
          ORDER BY s.section_code";
$result = mysqli_query($conn, $query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Trece Martires Memorial Park</title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="../assets/images/tmmp-logo.png">
    <link rel="shortcut icon" type="image/png" href="../assets/images/tmmp-logo.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <style>
        body { margin: 0; padding: 0; font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; }
        
        /* Page-specific styles */
        
        .page-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 24px;
            color: #222;
        }
        .table-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 24px;
            margin-bottom: 24px;
        }
        .table-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
            color: #222;
            border-bottom: 1px solid #eee;
            padding-bottom: 8px;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 16px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
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
    <script src="../assets/js/flash_clean_query.js"></script>
</head>
<body>
<div class="layout">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main">
        <div class="page-title">Section Management</div>
        
        <?php if (isset($success_message)): ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($success_message); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>

        <div class="table-card">
            <div class="table-title">All Sections</div>
            <div style="overflow-x:auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Section Code</th>
                            <th>Section Name</th>
                            <th>Description</th>
                            <th>Total Plots</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($section = mysqli_fetch_assoc($result)): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($section['section_code']); ?></td>
                            <td><?php echo htmlspecialchars($section['section_name']); ?></td>
                            <td><?php echo htmlspecialchars($section['description']); ?></td>
                            <td><?php echo $section['plot_count']; ?></td>
                            <td>
                                <button class="btn btn-sm btn-primary" onclick="showEditSectionModal(<?php echo $section['section_id']; ?>)">
                                    <i class="bi bi-pencil"></i> Edit
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="table-card">
            <div class="table-title">Add New Section</div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_section">
                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="section_code" class="form-label">Section Code</label>
                            <input type="text" class="form-control" id="section_code" name="section_code" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="section_name" class="form-label">Section Name</label>
                            <input type="text" class="form-control" id="section_name" name="section_name" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <input type="text" class="form-control" id="description" name="description">
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Add Section</button>
            </form>
        </div>
    </div>
</div>

<script>
function showEditSectionModal(sectionId) {
    // Fetch section details via AJAX
    fetch(`get_section_details.php?id=${sectionId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const section = data.section;
                document.getElementById('editSectionModalTitle').textContent = `Edit Section: ${section.section_name}`;
                document.getElementById('editSectionCode').value = section.section_code;
                document.getElementById('editSectionName').value = section.section_name;
                document.getElementById('editSectionDescription').value = section.description;
                document.getElementById('editSectionId').value = sectionId;
                new bootstrap.Modal(document.getElementById('editSectionModal')).show();
            } else {
                alert('Error loading section details: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading section details');
        });
}
</script>

<!-- Edit Section Modal -->
<div class="modal fade" id="editSectionModal" tabindex="-1" aria-labelledby="editSectionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editSectionModalTitle">Edit Section</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editSectionForm">
                <div class="modal-body">
                    <input type="hidden" id="editSectionId" name="section_id">
                    <div class="mb-3">
                        <label for="editSectionCode" class="form-label">Section Code</label>
                        <input type="text" class="form-control" id="editSectionCode" name="section_code" required>
                    </div>
                    <div class="mb-3">
                        <label for="editSectionName" class="form-label">Section Name</label>
                        <input type="text" class="form-control" id="editSectionName" name="section_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="editSectionDescription" class="form-label">Description</label>
                        <textarea class="form-control" id="editSectionDescription" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html> 