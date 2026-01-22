<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';
require_once 'notifications_helper.php';
require_once 'final_concept_helpers.php';
require_once 'route_slip_helpers.php';

enforce_role_access(['adviser']);

$advisorId = (int)($_SESSION['user_id'] ?? 0);
$advisorName = trim(
    ($_SESSION['first_name'] ?? $_SESSION['firstname'] ?? '') . ' ' .
    ($_SESSION['last_name'] ?? $_SESSION['lastname'] ?? '')
);
$advisorName = $advisorName !== '' ? $advisorName : 'Adviser';

ensureRouteSlipTable($conn);

function adviser_route_slip_column_exists(mysqli $conn, string $column): bool
{
    $column = $conn->real_escape_string($column);
    $sql = "
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = '{$column}'
        LIMIT 1
    ";
    $result = $conn->query($sql);
    $exists = $result && $result->num_rows > 0;
    if ($result) {
        $result->free();
    }
    return $exists;
}

function build_adviser_route_slip_where(string $alias, array $columns): string
{
    $parts = array_map(fn($column) => "{$alias}.{$column} = ?", $columns);
    return '(' . implode(' OR ', $parts) . ')';
}

function adviser_route_slip_bind_params(mysqli_stmt $stmt, string $types, array &$params): bool
{
    if ($types === '' || empty($params)) {
        return true;
    }
    $bindParams = [$types];
    foreach ($params as $key => $value) {
        $bindParams[] = &$params[$key];
    }
    return call_user_func_array([$stmt, 'bind_param'], $bindParams);
}

$advisorColumns = [];
if (adviser_route_slip_column_exists($conn, 'adviser_id')) {
    $advisorColumns[] = 'adviser_id';
}
if (adviser_route_slip_column_exists($conn, 'advisor_id')) {
    $advisorColumns[] = 'advisor_id';
}
if (empty($advisorColumns)) {
    die('Advisor tracking columns are missing in the users table.');
}

$advisorParamTypes = str_repeat('i', count($advisorColumns));
$advisorParams = array_fill(0, count($advisorColumns), $advisorId);
$advisorWhereStudents = build_adviser_route_slip_where('u', $advisorColumns);

$students = [];
$studentSql = "
    SELECT u.id, u.firstname, u.lastname, u.email, u.program
    FROM users u
    WHERE u.role = 'student' AND {$advisorWhereStudents}
    ORDER BY u.lastname, u.firstname
";
$studentStmt = $conn->prepare($studentSql);
if ($studentStmt) {
    $studentBindParams = $advisorParams;
    adviser_route_slip_bind_params($studentStmt, $advisorParamTypes, $studentBindParams);
    $studentStmt->execute();
    $result = $studentStmt->get_result();
    if ($result) {
        $students = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }
    $studentStmt->close();
}

if (empty($students)) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'concept_reviewer_assignments'");
    $hasAssignments = $tableCheck && $tableCheck->num_rows > 0;
    if ($tableCheck) {
        $tableCheck->free();
    }
    if ($hasAssignments) {
        $fallbackSql = "
            SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.program
            FROM concept_reviewer_assignments cra
            JOIN users u ON u.id = cra.student_id
            WHERE cra.reviewer_id = ? AND cra.reviewer_role = 'adviser'
            ORDER BY u.lastname, u.firstname
        ";
        $fallbackStmt = $conn->prepare($fallbackSql);
        if ($fallbackStmt) {
            $fallbackStmt->bind_param('i', $advisorId);
            $fallbackStmt->execute();
            $fallbackResult = $fallbackStmt->get_result();
            if ($fallbackResult) {
                $students = $fallbackResult->fetch_all(MYSQLI_ASSOC);
                $fallbackResult->free();
            }
            $fallbackStmt->close();
        }
    }
}

$studentFinalTitles = [];
foreach ($students as $student) {
    $studentId = (int)($student['id'] ?? 0);
    if ($studentId > 0) {
        $finalPick = getEligibleConceptForFinalSubmission($conn, $studentId);
        $studentFinalTitles[$studentId] = trim((string)($finalPick['title'] ?? ''));
    }
}

$alert = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_route_slip'])) {
    $studentId = (int)($_POST['student_id'] ?? 0);
    $course = trim((string)($_POST['course'] ?? ''));
    $major = trim((string)($_POST['major'] ?? ''));
    $panelMemberName = trim((string)($_POST['panel_member_name'] ?? ''));
    $actionTaken = trim((string)($_POST['action_taken'] ?? ''));
    $slipDateInput = trim((string)($_POST['slip_date'] ?? ''));
    $pdfData = trim((string)($_POST['route_slip_pdf'] ?? ''));

    $errors = [];
    if ($studentId <= 0) {
        $errors[] = 'Please select a student.';
    }

    $studentInfo = null;
    if (!$errors) {
        $studentLookupSql = "
            SELECT u.id, u.firstname, u.lastname, u.program
            FROM users u
            WHERE u.role = 'student' AND u.id = ? AND {$advisorWhereStudents}
            LIMIT 1
        ";
        $lookupStmt = $conn->prepare($studentLookupSql);
        if ($lookupStmt) {
            $params = array_merge([$studentId], $advisorParams);
            $types = 'i' . $advisorParamTypes;
            adviser_route_slip_bind_params($lookupStmt, $types, $params);
            $lookupStmt->execute();
            $lookupResult = $lookupStmt->get_result();
            $studentInfo = $lookupResult ? $lookupResult->fetch_assoc() : null;
            $lookupStmt->close();
        }
        if (!$studentInfo) {
            $errors[] = 'You can only send route slips to your advisees.';
        }
    }

    $finalTitle = $studentFinalTitles[$studentId] ?? '';

    $allowedActions = ['approved', 'minor_revision', 'major_revision'];
    if (!in_array($actionTaken, $allowedActions, true)) {
        $actionTaken = 'approved';
    }

    $slipDate = null;
    if ($slipDateInput !== '') {
        $dateObj = DateTime::createFromFormat('Y-m-d', $slipDateInput);
        if ($dateObj && $dateObj->format('Y-m-d') === $slipDateInput) {
            $slipDate = $slipDateInput;
        }
    }
    if ($slipDate === null) {
        $slipDate = date('Y-m-d');
    }

    if ($pdfData === '') {
        $errors[] = 'Unable to generate the route slip PDF.';
    }

    $signaturePath = '';
    if (isset($_FILES['signature_image']) && ($_FILES['signature_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $signatureDir = 'uploads/signatures/';
        if (!is_dir($signatureDir)) {
            mkdir($signatureDir, 0777, true);
        }
        $sigName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_FILES['signature_image']['name']));
        $sigFile = 'signature_' . $advisorId . '_' . date('Ymd_His') . '_' . $sigName;
        $sigPath = $signatureDir . $sigFile;
        if (move_uploaded_file($_FILES['signature_image']['tmp_name'], $sigPath)) {
            $signaturePath = $sigPath;
        }
    }

    if ($errors) {
        $alert = ['type' => 'danger', 'message' => implode(' ', $errors)];
    } else {
        if (preg_match('/base64,(.*)$/s', $pdfData, $matches)) {
            $pdfData = $matches[1];
        }
        $pdfData = str_replace(' ', '+', trim($pdfData));
        $binary = base64_decode($pdfData, true);
        if ($binary === false) {
            $alert = ['type' => 'danger', 'message' => 'Unable to decode the route slip PDF.'];
        } else {
            $uploadDir = 'uploads/route_slips/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $filename = 'route_slip_' . $studentId . '_' . date('Ymd_His') . '.pdf';
            $filePath = $uploadDir . $filename;
            if (file_put_contents($filePath, $binary) === false) {
                $alert = ['type' => 'danger', 'message' => 'Unable to save the route slip PDF.'];
            } else {
                $insertStmt = $conn->prepare("
                    INSERT INTO route_slips
                        (adviser_id, student_id, title, course, major, panel_member_name, action_taken, slip_date, file_path, file_name, signature_path)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                if ($insertStmt) {
                    $studentCourse = $course !== '' ? $course : ($studentInfo['program'] ?? '');
                    $insertStmt->bind_param(
                        'iisssssssss',
                        $advisorId,
                        $studentId,
                        $finalTitle,
                        $studentCourse,
                        $major,
                        $panelMemberName,
                        $actionTaken,
                        $slipDate,
                        $filePath,
                        $filename,
                        $signaturePath
                    );
                    if ($insertStmt->execute()) {
                        $studentName = trim(($studentInfo['firstname'] ?? '') . ' ' . ($studentInfo['lastname'] ?? '')) ?: 'the student';
                        $message = "{$advisorName} sent a Thesis/Dissertation Route Slip for {$studentName}. Please upload it with your outline defense submission.";
                        notify_user_for_role($conn, $studentId, 'student', 'Route slip issued', $message, 'submit_final_paper.php');
                        $alert = ['type' => 'success', 'message' => 'Route slip sent successfully.'];
                    } else {
                        $alert = ['type' => 'danger', 'message' => 'Unable to save the route slip.'];
                    }
                    $insertStmt->close();
                } else {
                    $alert = ['type' => 'danger', 'message' => 'Unable to prepare the route slip statement.'];
                }
            }
        }
    }
}

$routeSlips = fetchRouteSlipsForAdviser($conn, $advisorId, 10);

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Route Slip Issuance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f4f8f4; }
        .content { margin-left: 220px; padding: 28px 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .hero-card { border-radius: 20px; background: linear-gradient(130deg, #16562c, #0f3d1f); color: #fff; padding: 24px; }
        .card-shell { border-radius: 18px; border: none; box-shadow: 0 18px 36px rgba(22, 86, 44, 0.12); }
        .form-label { font-weight: 600; color: #16562c; }
        .small-muted { color: #6c757d; font-size: 0.9rem; }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="hero-card mb-4">
            <h3 class="fw-bold mb-1">Thesis/Dissertation Route Slip</h3>
            <p class="mb-0 text-white-50">Generate and send the signed route slip to your advisee.</p>
        </div>

        <?php if ($alert): ?>
            <div class="alert alert-<?= htmlspecialchars($alert['type']); ?>">
                <?= htmlspecialchars($alert['message']); ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card card-shell p-4">
                    <h5 class="fw-bold text-success mb-3">Create Route Slip</h5>
                    <form id="routeSlipForm" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="submit_route_slip" value="1">
                        <input type="hidden" name="route_slip_pdf" id="routeSlipPdf">
                        <div class="mb-3">
                            <label class="form-label">Select Student</label>
                        <select name="student_id" id="studentSelect" class="form-select">
                                <option value="">Choose student...</option>
                                <?php foreach ($students as $student): ?>
                                    <?php
                                    $studentId = (int)($student['id'] ?? 0);
                                    $finalTitle = $studentFinalTitles[$studentId] ?? '';
                                    ?>
                                    <option
                                        value="<?= $studentId; ?>"
                                        data-firstname="<?= htmlspecialchars($student['firstname'] ?? '', ENT_QUOTES); ?>"
                                        data-lastname="<?= htmlspecialchars($student['lastname'] ?? '', ENT_QUOTES); ?>"
                                        data-program="<?= htmlspecialchars($student['program'] ?? '', ENT_QUOTES); ?>"
                                        data-title="<?= htmlspecialchars($finalTitle, ENT_QUOTES); ?>"
                                    >
                                        <?= htmlspecialchars(($student['lastname'] ?? '') . ', ' . ($student['firstname'] ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="small-muted mt-1">Final pick title is auto-filled from the ranking board.</div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Course</label>
                                <input type="text" name="course" id="courseInput" class="form-control" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Major (optional)</label>
                                <input type="text" name="major" id="majorInput" class="form-control">
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label">Thesis/Dissertation Title</label>
                            <input type="text" name="title_display" id="titleInput" class="form-control" readonly>
                        </div>

                        <div class="row g-3 mt-2">
                            <div class="col-md-6">
                                <label class="form-label">Name of Panel Member</label>
                                <input type="text" name="panel_member_name" id="panelMemberInput" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Date</label>
                                <input type="date" name="slip_date" id="slipDate" class="form-control" value="<?= htmlspecialchars(date('Y-m-d')); ?>">
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label d-block">Action Taken</label>
                            <div class="small-muted mb-2">Based on my thorough review of the manuscript, I hereby recommend it as follows: (Please check only one)</div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="action_taken" id="actionApproved" value="approved">
                                <label class="form-check-label" for="actionApproved">Approval for the conduct of the study.</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="action_taken" id="actionMinor" value="minor_revision">
                                <label class="form-check-label" for="actionMinor">Approval for the conduct of the study but still subjected to minor revisions and improvement.</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="action_taken" id="actionMajor" value="major_revision">
                                <label class="form-check-label" for="actionMajor">Disapproval. The paper needs further major revisions and improvement.</label>
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label">Signature (PNG or JPG)</label>
                            <input type="file" name="signature_image" id="signatureImage" class="form-control" accept="image/png,image/jpeg">
                            <div class="small-muted mt-1">Signature will be embedded into the generated PDF.</div>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button type="submit" name="submit_route_slip" class="btn btn-success">
                                <i class="bi bi-send-check me-2"></i>Generate &amp; Send
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card card-shell p-4">
                    <h5 class="fw-bold text-success mb-3">Recent Route Slips</h5>
                    <?php if (empty($routeSlips)): ?>
                        <p class="text-muted mb-0">No route slips sent yet.</p>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-3">
                            <?php
                            $actionLabels = [
                                'approved' => 'Approved for conduct',
                                'minor_revision' => 'Minor revisions',
                                'major_revision' => 'Major revisions',
                            ];
                            ?>
                            <?php foreach ($routeSlips as $slip): ?>
                                <?php
                                $action = $actionLabels[$slip['action_taken'] ?? ''] ?? 'Action recorded';
                                $slipDate = $slip['slip_date'] ? date('M d, Y', strtotime($slip['slip_date'])) : 'Date not set';
                                ?>
                                <div class="border rounded-3 p-3">
                                    <div class="fw-semibold"><?= htmlspecialchars($slip['student_name'] ?? 'Student'); ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars($action); ?> - <?= htmlspecialchars($slipDate); ?></div>
                                    <div class="small mt-2">
                                        <a href="<?= htmlspecialchars($slip['file_path']); ?>" target="_blank" class="text-success text-decoration-none">
                                            <i class="bi bi-file-earmark-pdf me-1"></i>View PDF
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<img src="memopic.jpg" id="routeSlipLetterheadSource" alt="" style="display:none;">

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script>
const studentSelect = document.getElementById('studentSelect');
const courseInput = document.getElementById('courseInput');
const titleInput = document.getElementById('titleInput');
const slipDateInput = document.getElementById('slipDate');
const signatureInput = document.getElementById('signatureImage');
const routeSlipPdf = document.getElementById('routeSlipPdf');
const routeSlipForm = document.getElementById('routeSlipForm');
const letterheadSource = document.getElementById('routeSlipLetterheadSource');
const adviserName = <?php echo json_encode($advisorName); ?>;

function fillStudentDetails() {
    const option = studentSelect.options[studentSelect.selectedIndex];
    if (!option || !option.value) {
        courseInput.value = '';
        titleInput.value = '';
        return;
    }
    courseInput.value = option.dataset.program || '';
    titleInput.value = option.dataset.title || '';
}

function formatDateLabel(dateValue) {
    if (!dateValue) return '';
    const date = new Date(dateValue + 'T00:00:00');
    if (Number.isNaN(date.getTime())) return dateValue;
    return date.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
}

function sliceLetterheadImage(image, startY, sliceHeight) {
    if (!image || !image.naturalWidth || !image.naturalHeight) {
        return '';
    }
    const canvas = document.createElement('canvas');
    canvas.width = image.naturalWidth;
    canvas.height = sliceHeight;
    const ctx = canvas.getContext('2d');
    if (!ctx) {
        return '';
    }
    ctx.drawImage(image, 0, startY, image.naturalWidth, sliceHeight, 0, 0, image.naturalWidth, sliceHeight);
    return canvas.toDataURL('image/png');
}

function getRouteSlipLetterheadImages(headerHeight, footerHeight, pageWidth) {
    if (!letterheadSource || !letterheadSource.naturalHeight || !letterheadSource.naturalWidth || !pageWidth) {
        return { header: '', footer: '' };
    }
    const headerSlice = Math.min(
        letterheadSource.naturalHeight,
        Math.round((headerHeight / pageWidth) * letterheadSource.naturalWidth)
    );
    const footerSlice = Math.min(
        letterheadSource.naturalHeight,
        Math.round((footerHeight / pageWidth) * letterheadSource.naturalWidth)
    );
    const header = sliceLetterheadImage(letterheadSource, 0, headerSlice);
    const footerStart = Math.max(0, letterheadSource.naturalHeight - footerSlice);
    const footer = sliceLetterheadImage(letterheadSource, footerStart, footerSlice);
    return { header, footer };
}

function buildRouteSlipPdf(signatureDataUrl) {
    const option = studentSelect.options[studentSelect.selectedIndex];
    const firstName = option ? (option.dataset.firstname || '') : '';
    const lastName = option ? (option.dataset.lastname || '') : '';
    const course = courseInput.value || '';
    const major = document.getElementById('majorInput').value || '';
    const title = titleInput.value || '';
    const panelMember = document.getElementById('panelMemberInput').value || '';
    const slipDate = slipDateInput.value || '';
    const action = document.querySelector('input[name="action_taken"]:checked');
    const actionValue = action ? action.value : '';
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ unit: 'pt', format: 'letter' });

    const pageWidth = doc.internal.pageSize.getWidth();
    const pageHeight = doc.internal.pageSize.getHeight();
    const headerHeight = 230;
    const footerHeight = 170;
    const letterhead = getRouteSlipLetterheadImages(headerHeight, footerHeight, pageWidth);
    if (letterhead.header) {
        doc.addImage(letterhead.header, 'PNG', 0, 0, pageWidth, headerHeight);
    }
    if (letterhead.footer) {
        doc.addImage(letterhead.footer, 'PNG', 0, pageHeight - footerHeight, pageWidth, footerHeight);
    }

    const marginX = 48;
    let y = headerHeight + 12;
    doc.setFontSize(14);
    doc.text('THESIS/DISSERTATION ROUTE SLIP', 306, y, { align: 'center' });
    y += 30;

    doc.setFontSize(11);
    doc.text('Date:', marginX, y);
    const dateLabel = formatDateLabel(slipDate);
    doc.text(dateLabel, marginX + 40, y);
    doc.line(marginX + 40, y + 2, marginX + 260, y + 2);

    y += 30;
    doc.text('Name:', marginX, y);
    doc.text(lastName, marginX + 50, y);
    doc.line(marginX + 50, y + 2, marginX + 220, y + 2);
    doc.text(firstName, marginX + 230, y);
    doc.line(marginX + 230, y + 2, marginX + 400, y + 2);
    doc.text('', marginX + 420, y);
    doc.line(marginX + 420, y + 2, marginX + 460, y + 2);

    doc.setFontSize(8);
    doc.text('Last Name', marginX + 90, y + 12);
    doc.text('First Name', marginX + 275, y + 12);
    doc.text('M.I.', marginX + 430, y + 12);
    doc.setFontSize(11);
    y += 30;

    doc.text('Course:', marginX, y);
    doc.text(course, marginX + 55, y);
    doc.line(marginX + 55, y + 2, marginX + 280, y + 2);
    doc.text('Major (if applicable):', marginX + 300, y);
    doc.text(major, marginX + 430, y);
    doc.line(marginX + 430, y + 2, marginX + 560, y + 2);

    y += 30;
    doc.text('Thesis/Dissertation Title:', marginX, y);
    const titleLines = doc.splitTextToSize(title, 500);
    y += 16;
    titleLines.forEach((line, index) => {
        doc.text(line, marginX, y);
        doc.line(marginX, y + 2, marginX + 520, y + 2);
        y += 18;
        if (index === 1) {
            y += 2;
        }
    });

    y += 8;
    doc.text('Adviser: ' + adviserName, marginX, y);
    doc.line(marginX + 50, y + 2, marginX + 280, y + 2);
    doc.text('Name of Panel Member: ' + panelMember, marginX + 300, y);
    doc.line(marginX + 470, y + 2, marginX + 560, y + 2);

    y += 28;
    doc.text('Action Taken:', marginX, y);
    y += 18;
    doc.text('Based on my thorough review of the manuscript, I hereby recommend it as follows: (Please check only one)', marginX, y);
    y += 18;

    const actionItems = [
        { value: 'approved', label: 'Approval for the conduct of the study.' },
        { value: 'minor_revision', label: 'Approval for the conduct of the study but still subjected to minor revisions and improvement.' },
        { value: 'major_revision', label: 'Disapproval. The paper needs further major revisions and improvement.' }
    ];

    actionItems.forEach((item) => {
        doc.rect(marginX, y - 8, 10, 10);
        if (actionValue === item.value) {
            doc.text('X', marginX + 2, y);
        }
        doc.text(item.label, marginX + 20, y);
        y += 18;
    });

    y += 36;
    const lineWidth = 200;
    const columnGap = 40;
    const leftX = marginX + 20;
    const rightX = leftX + lineWidth + columnGap;
    const firstLineY = y;
    doc.setFontSize(10);
    doc.line(leftX, firstLineY, leftX + lineWidth, firstLineY);
    doc.line(rightX, firstLineY, rightX + lineWidth, firstLineY);
    doc.text('Panel Member 1', leftX, firstLineY + 12);
    doc.text('Panel Member 2', rightX, firstLineY + 12);

    const secondLineY = firstLineY + 50;
    doc.line(leftX, secondLineY, leftX + lineWidth, secondLineY);
    doc.line(rightX, secondLineY, rightX + lineWidth, secondLineY);
    doc.text('Committee Chairperson', leftX, secondLineY + 12);
    doc.text('Adviser', rightX, secondLineY + 12);

    if (signatureDataUrl) {
        const format = signatureDataUrl.startsWith('data:image/jpeg') ? 'JPEG' : 'PNG';
        doc.addImage(signatureDataUrl, format, rightX + 10, secondLineY - 42, 140, 36);
    }

    return doc.output('datauristring');
}

if (studentSelect) {
    studentSelect.addEventListener('change', fillStudentDetails);
    fillStudentDetails();
}

if (routeSlipForm) {
    routeSlipForm.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!studentSelect.value) {
            alert('Please select a student.');
            return;
        }
        if (!window.jspdf || !window.jspdf.jsPDF) {
            alert('Unable to load the PDF generator. Please refresh and try again.');
            return;
        }
        const proceed = () => {
            const file = signatureInput.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = () => {
                    const pdfData = buildRouteSlipPdf(reader.result);
                    routeSlipPdf.value = pdfData;
                    routeSlipForm.submit();
                };
                reader.readAsDataURL(file);
            } else {
                const pdfData = buildRouteSlipPdf('');
                routeSlipPdf.value = pdfData;
                routeSlipForm.submit();
            }
        };
        if (letterheadSource && !letterheadSource.complete) {
            letterheadSource.addEventListener('load', proceed, { once: true });
            letterheadSource.addEventListener('error', proceed, { once: true });
        } else {
            proceed();
        }
    });
}

</script>
</body>
</html>
