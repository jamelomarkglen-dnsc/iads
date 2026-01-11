<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';
require_once 'notifications_helper.php';
require_once 'final_concept_helpers.php';
require_once 'endorsement_helpers.php';

enforce_role_access(['adviser']);

$advisorId = (int)$_SESSION['user_id'];
$advisorName = trim(
    ($_SESSION['first_name'] ?? $_SESSION['firstname'] ?? '') . ' ' .
    ($_SESSION['last_name'] ?? $_SESSION['lastname'] ?? '')
) ?: 'Adviser';

ensureEndorsementRequestsTable($conn);

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
        $studentFinalTitles[$studentId] = fetch_final_pick_title_for_endorsement($conn, $studentId);
    }
}

$alert = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_endorsement'])) {
    $studentId = (int)($_POST['student_id'] ?? 0);
    $endorsementBody = trim((string)($_POST['endorsement_body'] ?? ''));
    $endorsementBody = strip_tags($endorsementBody, '<u><br>');

    $errors = [];
    if ($studentId <= 0) {
        $errors[] = 'Please select a student.';
    }
    if ($endorsementBody === '') {
        $errors[] = 'Please provide the endorsement letter body.';
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
        $finalTitle = fetch_final_pick_title_for_endorsement($conn, $studentId);
        if ($finalTitle === '') {
            $errors[] = 'Final pick title is not available yet for this student.';
        }
    }

    if ($errors) {
        $alert = ['type' => 'danger', 'message' => implode(' ', $errors)];
    } else {
        $insertStmt = $conn->prepare("
            INSERT INTO endorsement_requests (student_id, adviser_id, title, body)
            VALUES (?, ?, ?, ?)
        ");
        if ($insertStmt) {
            $insertStmt->bind_param('iiss', $studentId, $advisorId, $finalTitle, $endorsementBody);
            if ($insertStmt->execute()) {
                $studentName = trim(($studentInfo['firstname'] ?? '') . ' ' . ($studentInfo['lastname'] ?? '')) ?: 'the student';
                $message = "{$advisorName} endorsed {$studentName} for outline defense. Please review the endorsement letter.";
                $link = 'program_chairperson.php#endorsement-inbox';
                $chairs = getProgramChairsForStudent($conn, $studentId);
                if (!empty($chairs)) {
                    foreach ($chairs as $chairId) {
                        notify_user($conn, $chairId, 'Outline defense endorsement', $message, $link, false);
                    }
                } else {
                    notify_role($conn, 'program_chairperson', 'Outline defense endorsement', $message, $link, false);
                }
                $alert = ['type' => 'success', 'message' => 'Endorsement sent to the Program Chairperson.'];
            } else {
                $alert = ['type' => 'danger', 'message' => 'Unable to submit endorsement. Please try again.'];
            }
            $insertStmt->close();
        } else {
            $alert = ['type' => 'danger', 'message' => 'Unable to prepare the endorsement statement.'];
        }
    }
}

$endorsements = [];
$endorsementStmt = $conn->prepare("
    SELECT er.id, er.title, er.status, er.created_at, er.verified_at,
           CONCAT(stu.firstname, ' ', stu.lastname) AS student_name,
           CONCAT(pc.firstname, ' ', pc.lastname) AS verified_by_name
    FROM endorsement_requests er
    JOIN users stu ON stu.id = er.student_id
    LEFT JOIN users pc ON pc.id = er.verified_by
    WHERE er.adviser_id = ?
    ORDER BY er.created_at DESC
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
    <title>Adviser Endorsement</title>
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
                <h1 class="h4 fw-semibold text-success mb-1">Adviser Endorsement</h1>
                <p class="text-muted mb-0">Send an endorsement letter to confirm a student is ready for outline defense.</p>
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
                        <h5 class="mb-2">Send Endorsement</h5>
                        <p class="text-muted small mb-3">Select an advisee and confirm the auto-generated endorsement letter.</p>
                        <form method="POST" id="endorsementForm">
                            <input type="hidden" name="submit_endorsement" value="1">
                            <div class="mb-3">
                                <label class="form-label text-muted small">Student</label>
                                <select name="student_id" id="endorsementStudentSelect" class="form-select" required>
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
                                <label class="form-label text-muted small">Final Pick Title</label>
                                <input type="text" class="form-control" id="endorsementTitleDisplay" readonly>
                                <div class="form-text">Auto-filled from the final pick recommendation.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-muted small">Program</label>
                                <input type="text" class="form-control" id="endorsementProgramDisplay" readonly>
                            </div>
                            <div class="alert alert-warning d-none" id="endorsementWarning">
                                Final pick title or program is not available yet for this student.
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Endorsement Letter</label>
                                <div
                                    class="form-control"
                                    id="endorsementBodyEditor"
                                    contenteditable="true"
                                    style="min-height: 180px; white-space: pre-wrap;"
                                ></div>
                                <input type="hidden" name="endorsement_body" id="endorsementBodyInput">
                                <div class="form-text">Text with underline will appear exactly as shown here.</div>
                            </div>
                            <button type="submit" class="btn btn-success" id="endorsementSubmitBtn">
                                <i class="bi bi-send me-1"></i> Send Endorsement
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="mb-3">Recent Endorsements</h5>
                        <?php if (empty($endorsements)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-2 mb-2"></i>
                                <p class="mb-0">No endorsements submitted yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-3">
                                <?php foreach ($endorsements as $endorsement): ?>
                                    <?php
                                        $status = $endorsement['status'] ?? 'Pending';
                                        $statusClass = $status === 'Verified'
                                            ? 'bg-success-subtle text-success'
                                            : 'bg-warning-subtle text-warning';
                                        $createdAt = $endorsement['created_at'] ? date('M d, Y g:i A', strtotime($endorsement['created_at'])) : 'Not recorded';
                                        $verifiedAt = $endorsement['verified_at'] ? date('M d, Y g:i A', strtotime($endorsement['verified_at'])) : '';
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
                                        <?php if ($status === 'Verified'): ?>
                                            <div class="text-muted small mt-1">
                                                Verified <?php echo htmlspecialchars($verifiedAt); ?>
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
        const select = document.getElementById('endorsementStudentSelect');
        const titleDisplay = document.getElementById('endorsementTitleDisplay');
        const programDisplay = document.getElementById('endorsementProgramDisplay');
        const editor = document.getElementById('endorsementBodyEditor');
        const bodyInput = document.getElementById('endorsementBodyInput');
        const warning = document.getElementById('endorsementWarning');
        const submitBtn = document.getElementById('endorsementSubmitBtn');
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
                `This thesis "Entitled" <u>${title}</u> prepared and submitted by <u>${studentName}</u> in partial fulfillment of the requirements for the degree <u>${program}</u> has been examined and is recommended for routing, acceptance and approval of the Panel of Examiners.`,
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
