<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';
require_once 'notifications_helper.php';
require_once 'final_paper_helpers.php';
require_once 'final_concept_helpers.php';
require_once 'final_defense_endorsement_helpers.php';

enforce_role_access(['adviser']);

$advisorId = (int)($_SESSION['user_id'] ?? 0);
$advisorName = trim(
    ($_SESSION['first_name'] ?? $_SESSION['firstname'] ?? '') . ' ' .
    ($_SESSION['last_name'] ?? $_SESSION['lastname'] ?? '')
) ?: 'Adviser';

ensureFinalPaperTables($conn);
ensureFinalDefenseEndorsementTable($conn);

function adviserUsersColumnExists(mysqli $conn, string $column): bool
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

function buildAdvisorWhereClause(string $alias, array $columns): string
{
    $parts = array_map(fn($column) => "{$alias}.{$column} = ?", $columns);
    return '(' . implode(' OR ', $parts) . ')';
}

function adviserBindParams(mysqli_stmt $stmt, string $types, array &$params): bool
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
if (adviserUsersColumnExists($conn, 'adviser_id')) {
    $advisorColumns[] = 'adviser_id';
}
if (adviserUsersColumnExists($conn, 'advisor_id')) {
    $advisorColumns[] = 'advisor_id';
}
if (empty($advisorColumns)) {
    die('Advisor tracking columns are missing in the users table.');
}

$advisorParamTypes = str_repeat('i', count($advisorColumns));
$advisorParams = array_fill(0, count($advisorColumns), $advisorId);
$advisorWhereStudents = buildAdvisorWhereClause('u', $advisorColumns);

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
    adviserBindParams($studentStmt, $advisorParamTypes, $studentBindParams);
    $studentStmt->execute();
    $result = $studentStmt->get_result();
    if ($result) {
        $students = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }
    $studentStmt->close();
}

$studentFinalTitles = [];
foreach ($students as $student) {
    $studentId = (int)($student['id'] ?? 0);
    if ($studentId > 0) {
        $latest = fetchLatestFinalPaperSubmission($conn, $studentId);
        $studentFinalTitles[$studentId] = trim((string)($latest['final_title'] ?? ''));
    }
}

$alert = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_final_endorsement'])) {
    $studentId = (int)($_POST['student_id'] ?? 0);
    $endorsementBody = trim((string)($_POST['endorsement_body'] ?? ''));
    $endorsementBody = strip_tags($endorsementBody, '<u><br>');

    $errors = [];
    if ($studentId <= 0) {
        $errors[] = 'Please select a student.';
    }
    if ($endorsementBody === '') {
        $errors[] = 'Please provide the final endorsement letter body.';
    }

    $studentInfo = null;
    if (!$errors) {
        $studentLookupSql = "
            SELECT u.id, u.firstname, u.lastname
            FROM users u
            WHERE u.role = 'student' AND u.id = ? AND {$advisorWhereStudents}
            LIMIT 1
        ";
        $lookupStmt = $conn->prepare($studentLookupSql);
        if ($lookupStmt) {
            $params = array_merge([$studentId], $advisorParams);
            $types = 'i' . $advisorParamTypes;
            adviserBindParams($lookupStmt, $types, $params);
            $lookupStmt->execute();
            $lookupResult = $lookupStmt->get_result();
            $studentInfo = $lookupResult ? $lookupResult->fetch_assoc() : null;
            $lookupStmt->close();
        }
        if (!$studentInfo) {
            $errors[] = 'You can only endorse your advisees.';
        }
    }

    $finalTitle = '';
    if (!$errors) {
        $latest = fetchLatestFinalPaperSubmission($conn, $studentId);
        $finalTitle = trim((string)($latest['final_title'] ?? ''));
        if ($finalTitle === '') {
            $errors[] = 'Final manuscript title is not available yet for this student.';
        }
    }

    $signatureError = '';
    $signaturePath = '';
    if (isset($_FILES['adviser_signature'])) {
        $signaturePath = save_final_defense_signature_upload($_FILES['adviser_signature'], $advisorId, $signatureError);
        if ($signatureError !== '') {
            $errors[] = $signatureError;
        }
    }

    if ($errors) {
        $alert = ['type' => 'danger', 'message' => implode(' ', $errors)];
    } else {
        $insertStmt = $conn->prepare("
            INSERT INTO final_defense_endorsements (student_id, adviser_id, title, body, signature_path)
            VALUES (?, ?, ?, ?, ?)
        ");
        if ($insertStmt) {
            $insertStmt->bind_param('iisss', $studentId, $advisorId, $finalTitle, $endorsementBody, $signaturePath);
            if ($insertStmt->execute()) {
                $studentName = trim(($studentInfo['firstname'] ?? '') . ' ' . ($studentInfo['lastname'] ?? '')) ?: 'the student';
                $message = "{$advisorName} submitted a final defense endorsement for {$studentName}.";
                $link = 'program_chair_final_endorsement.php';
                $chairs = getProgramChairsForStudent($conn, $studentId);
                if (!empty($chairs)) {
                    foreach ($chairs as $chairId) {
                        notify_user($conn, $chairId, 'Final defense endorsement', $message, $link, false);
                    }
                } else {
                    notify_role($conn, 'program_chairperson', 'Final defense endorsement', $message, $link, false);
                }
                $alert = ['type' => 'success', 'message' => 'Final endorsement sent to the Program Chairperson.'];
            } else {
                $alert = ['type' => 'danger', 'message' => 'Unable to submit the final endorsement. Please try again.'];
            }
            $insertStmt->close();
        } else {
            $alert = ['type' => 'danger', 'message' => 'Unable to prepare the final endorsement statement.'];
        }
    }
}

$endorsements = [];
$endorsementStmt = $conn->prepare("
    SELECT fe.id, fe.title, fe.status, fe.submitted_at, fe.reviewed_at,
           CONCAT(stu.firstname, ' ', stu.lastname) AS student_name,
           CONCAT(pc.firstname, ' ', pc.lastname) AS verified_by_name
    FROM final_defense_endorsements fe
    JOIN users stu ON stu.id = fe.student_id
    LEFT JOIN users pc ON pc.id = fe.reviewed_by
    WHERE fe.adviser_id = ?
    ORDER BY fe.submitted_at DESC
    LIMIT 10
");
if ($endorsementStmt) {
    $endorsementStmt->bind_param('i', $advisorId);
    $endorsementStmt->execute();
    $endorsementResult = $endorsementStmt->get_result();
    if ($endorsementResult) {
        $endorsements = $endorsementResult->fetch_all(MYSQLI_ASSOC);
        $endorsementResult->free();
    }
    $endorsementStmt->close();
}

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Final Defense Endorsement</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f4f8f4; color: #1d3522; }
        .content { margin-left: var(--sidebar-width-expanded, 240px); padding: 28px 24px; min-height: 100vh; }
        #sidebar.collapsed ~ .content { margin-left: var(--sidebar-width-collapsed, 70px); }
        @media (max-width: 992px) { .content { margin-left: 0; } #sidebar.collapsed ~ .content { margin-left: 0; } }
        .card { border-radius: 18px; border: 1px solid rgba(22, 86, 44, 0.12); box-shadow: 0 18px 40px rgba(15, 61, 31, 0.08); }
        .form-text { color: #5f6f63; }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h4 fw-semibold text-success mb-1">Final Defense Endorsement</h1>
                <p class="text-muted mb-0">Submit the final defense endorsement for your advisee.</p>
            </div>
            <a href="adviser.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Back to dashboard</a>
        </div>

        <?php if ($alert): ?>
            <div class="alert alert-<?php echo htmlspecialchars($alert['type']); ?> border-0 shadow-sm">
                <?php echo htmlspecialchars($alert['message']); ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="mb-2">Send Final Endorsement</h5>
                        <p class="text-muted small mb-3">Select an advisee and confirm the endorsement letter.</p>
                        <form method="POST" id="finalEndorsementForm" enctype="multipart/form-data">
                            <input type="hidden" name="submit_final_endorsement" value="1">
                            <div class="mb-3">
                                <label class="form-label text-muted small">Student</label>
                                <select name="student_id" id="finalEndorsementStudentSelect" class="form-select" required>
                                    <option value="">Select student</option>
                                    <?php foreach ($students as $student): ?>
                                        <?php
                                            $studentId = (int)($student['id'] ?? 0);
                                            $studentName = trim(($student['firstname'] ?? '') . ' ' . ($student['lastname'] ?? ''));
                                            $studentEmail = trim((string)($student['email'] ?? ''));
                                            $studentProgram = trim((string)($student['program'] ?? ''));
                                            $studentLabel = $studentName;
                                            if ($studentEmail !== '') {
                                                $studentLabel .= ' - ' . $studentEmail;
                                            }
                                            $finalTitle = $studentFinalTitles[$studentId] ?? '';
                                        ?>
                                        <option
                                            value="<?php echo $studentId; ?>"
                                            data-student="<?php echo htmlspecialchars($studentName); ?>"
                                            data-title="<?php echo htmlspecialchars($finalTitle); ?>"
                                            data-program="<?php echo htmlspecialchars($studentProgram); ?>"
                                        >
                                            <?php echo htmlspecialchars($studentLabel); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-muted small">Final Manuscript Title</label>
                                <input type="text" class="form-control" id="finalEndorsementTitleDisplay" readonly>
                                <div class="form-text">Auto-filled from the latest final manuscript submission.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-muted small">Program</label>
                                <input type="text" class="form-control" id="finalEndorsementProgramDisplay" readonly>
                            </div>
                            <div class="alert alert-warning d-none" id="finalEndorsementWarning">
                                Final manuscript title or program is not available yet for this student.
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Final Endorsement Letter</label>
                                <div
                                    class="form-control"
                                    id="finalEndorsementBodyEditor"
                                    contenteditable="true"
                                    style="min-height: 180px; white-space: pre-wrap;"
                                ></div>
                                <input type="hidden" name="endorsement_body" id="finalEndorsementBodyInput">
                                <div class="form-text">Text with underline will appear exactly as shown here.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-muted small">Adviser E-Signature (PNG or JPG)</label>
                                <input type="file" name="adviser_signature" class="form-control" accept="image/png,image/jpeg">
                                <div class="form-text">Upload to include your signature in this endorsement.</div>
                            </div>
                            <button type="submit" class="btn btn-success" id="finalEndorsementSubmitBtn">
                                <i class="bi bi-send me-1"></i> Send Final Endorsement
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="mb-3">Recent Final Endorsements</h5>
                        <?php if (empty($endorsements)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-2 mb-2"></i>
                                <p class="mb-0">No final endorsements submitted yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-3">
                                <?php foreach ($endorsements as $endorsement): ?>
                                    <?php
                                        $status = $endorsement['status'] ?? 'Submitted';
                                        $statusClass = $status === 'Verified'
                                            ? 'bg-success-subtle text-success'
                                            : ($status === 'Rejected' ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning');
                                        $createdAt = $endorsement['submitted_at'] ? date('M d, Y g:i A', strtotime($endorsement['submitted_at'])) : 'Not recorded';
                                        $verifiedAt = $endorsement['reviewed_at'] ? date('M d, Y g:i A', strtotime($endorsement['reviewed_at'])) : '';
                                    ?>
                                    <div class="border rounded-4 p-3">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <div class="fw-semibold text-success"><?php echo htmlspecialchars($endorsement['student_name'] ?? 'Student'); ?></div>
                                                <div class="text-muted small"><?php echo htmlspecialchars($endorsement['title'] ?? ''); ?></div>
                                            </div>
                                            <span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($status); ?></span>
                                        </div>
                                        <div class="text-muted small">Sent <?php echo htmlspecialchars($createdAt); ?></div>
                                        <?php if ($status === 'Verified' || $status === 'Rejected'): ?>
                                            <div class="text-muted small mt-1">
                                                Reviewed <?php echo htmlspecialchars($verifiedAt); ?>
                                                <?php if (!empty($endorsement['verified_by_name'])): ?>
                                                    by <?php echo htmlspecialchars($endorsement['verified_by_name']); ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    (() => {
        const select = document.getElementById('finalEndorsementStudentSelect');
        const titleDisplay = document.getElementById('finalEndorsementTitleDisplay');
        const programDisplay = document.getElementById('finalEndorsementProgramDisplay');
        const editor = document.getElementById('finalEndorsementBodyEditor');
        const bodyInput = document.getElementById('finalEndorsementBodyInput');
        const warning = document.getElementById('finalEndorsementWarning');
        const submitBtn = document.getElementById('finalEndorsementSubmitBtn');
        if (!select || !titleDisplay || !programDisplay || !editor || !bodyInput || !warning || !submitBtn) {
            return;
        }

        const advisorName = <?php echo json_encode($advisorName); ?>;

        const formatDate = () => {
            const date = new Date();
            return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
        };

        const buildTemplate = (studentName, title, program) => {
            return [
                `This is to endorse the thesis entitled <u>${title}</u> prepared and submitted by <u>${studentName}</u> in partial fulfillment of the requirements for the degree <u>${program}</u>. The manuscript has completed the required revisions and is recommended for final defense.`,
                '',
                `Adviser: ${advisorName}`,
                `Date of Endorsement: ${formatDate()}`
            ].join('<br>');
        };

        const sanitizeEditorHtml = (html) => {
            let normalized = html
                .replace(/<\/(div|p)>/gi, '<br>')
                .replace(/<(div|p)([^>]*)>/gi, '');
            const wrapper = document.createElement('div');
            wrapper.innerHTML = normalized;
            const allowed = new Set(['U', 'BR']);
            const walker = document.createTreeWalker(wrapper, NodeFilter.SHOW_ELEMENT);
            const nodes = [];
            while (walker.nextNode()) {
                nodes.push(walker.currentNode);
            }
            nodes.forEach((node) => {
                if (!allowed.has(node.tagName)) {
                    const text = document.createTextNode(node.textContent || '');
                    node.replaceWith(text);
                }
            });
            return wrapper.innerHTML;
        };

        const syncBodyInput = () => {
            bodyInput.value = sanitizeEditorHtml(editor.innerHTML);
        };

        const updatePreview = () => {
            const option = select.options[select.selectedIndex];
            const studentName = option ? option.getAttribute('data-student') || '' : '';
            const title = option ? option.getAttribute('data-title') || '' : '';
            const program = option ? option.getAttribute('data-program') || '' : '';
            if (!select.value) {
                warning.classList.add('d-none');
                submitBtn.disabled = true;
                titleDisplay.value = '';
                programDisplay.value = '';
                editor.innerHTML = '';
                syncBodyInput();
                return;
            }
            titleDisplay.value = title;
            programDisplay.value = program;
            if (!title || !program) {
                warning.classList.remove('d-none');
                submitBtn.disabled = true;
                editor.innerHTML = '';
                syncBodyInput();
                return;
            }
            warning.classList.add('d-none');
            submitBtn.disabled = false;
            editor.innerHTML = buildTemplate(studentName, title, program);
            syncBodyInput();
        };

        select.addEventListener('change', updatePreview);
        editor.addEventListener('input', syncBodyInput);
        editor.addEventListener('blur', syncBodyInput);
        updatePreview();
    })();
</script>
</body>
</html>
