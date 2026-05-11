<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit();
}

if (($_SESSION['user_role'] ?? '') !== 'student') {
    header('Location: ../index.php');
    exit();
}

$db = Database::getInstance();

$student_id = (int) ($_SESSION['user_id'] ?? 0);
$clearance_id = (int) ($_GET['clearance_id'] ?? 0);

if ($clearance_id <= 0 || $student_id <= 0) {
    http_response_code(400);
    echo 'Invalid clearance ID or user session.';
    exit();
}

// Load the clearance record and verify it belongs to the current student.
$db->query("SELECT c.clearance_id, c.users_id, c.semester, c.school_year, c.status, c.processed_date,
                   u.fname, u.lname, u.ismis_id,
                   cr.course_name,
                   col.college_name,
                   ct.clearance_name
            FROM clearance c
            JOIN users u ON c.users_id = u.users_id
            LEFT JOIN course cr ON u.course_id = cr.course_id
            LEFT JOIN college col ON u.college_id = col.college_id
            LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
            WHERE c.clearance_id = :clearance_id
              AND c.users_id = :student_id
            LIMIT 1");
$db->bind(':clearance_id', $clearance_id);
$db->bind(':student_id', $student_id);
$record = $db->single();

if (!$record) {
    http_response_code(404);
    echo 'Clearance record not found or does not belong to your account.';
    exit();
}

// Verify the entire clearance workflow is fully approved.
$db->query("SELECT COUNT(*) as total,
                   SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved
            FROM clearance
            WHERE users_id = :user_id
              AND semester = :semester
              AND school_year = :school_year");
$db->bind(':user_id', $record['users_id']);
$db->bind(':semester', $record['semester']);
$db->bind(':school_year', $record['school_year']);
$workflow = $db->single();

$total_steps = (int) ($workflow['total'] ?? 0);
$approved_steps = (int) ($workflow['approved'] ?? 0);

if ($total_steps === 0 || $approved_steps !== $total_steps) {
    http_response_code(400);
    echo 'This clearance workflow is not fully completed yet. All offices must approve before printing.';
    exit();
}

// Check if organization clearances are also involved and if they're all approved.
$db->query("SELECT COUNT(*) as total,
                   SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved
            FROM organization_clearance
            WHERE clearance_id = :clearance_id");
$db->bind(':clearance_id', $clearance_id);
$org_workflow = $db->single();

$org_total = (int) ($org_workflow['total'] ?? 0);
$org_approved = (int) ($org_workflow['approved'] ?? 0);

if ($org_total > 0 && $org_approved !== $org_total) {
    http_response_code(400);
    echo 'This clearance workflow is not fully completed yet. All organization clearances must be approved.';
    exit();
}

$student_name = trim(($record['fname'] ?? '') . ' ' . ($record['lname'] ?? ''));
$processed_date = !empty($record['processed_date']) ? date('F d, Y', strtotime($record['processed_date'])) : date('F d, Y');
$issued_on = date('F d, Y');
$period_label = trim(($record['semester'] ?? '') . ' ' . ($record['school_year'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Completed Clearance Document</title>
    <style>
        :root {
            --ink: #0f172a;
            --muted: #475569;
            --line: #cbd5e1;
            --accent: #0f4c81;
            --paper: #ffffff;
            --bg: #f1f5f9;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Times New Roman", Georgia, serif;
            color: var(--ink);
            background: var(--bg);
            padding: 24px;
        }

        .print-toolbar {
            max-width: 900px;
            margin: 0 auto 16px;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .btn {
            border: 1px solid #1e3a8a;
            background: #1e3a8a;
            color: #fff;
            padding: 10px 14px;
            border-radius: 6px;
            font: 600 14px/1 Arial, sans-serif;
            cursor: pointer;
        }

        .btn.secondary {
            background: #fff;
            color: #1e3a8a;
        }

        .document {
            width: 100%;
            max-width: 900px;
            margin: 0 auto;
            background: var(--paper);
            border: 1px solid var(--line);
            padding: 42px 48px;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
        }

        .header {
            text-align: center;
            border-bottom: 2px solid var(--ink);
            padding-bottom: 14px;
            margin-bottom: 26px;
        }

        .header h1 {
            margin: 0;
            font-size: 30px;
            letter-spacing: 1px;
        }

        .header p {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 15px;
        }

        .title {
            text-align: center;
            font-size: 23px;
            font-weight: 700;
            margin: 20px 0 26px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--accent);
        }

        .content {
            font-size: 18px;
            line-height: 1.7;
            text-align: justify;
        }

        .content strong {
            text-decoration: underline;
            text-underline-offset: 4px;
        }

        .meta {
            margin-top: 28px;
            border-top: 1px solid var(--line);
            padding-top: 18px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px 20px;
            font-size: 15px;
        }

        .signature {
            margin-top: 54px;
            display: flex;
            justify-content: flex-end;
        }

        .signature-box {
            width: 290px;
            text-align: center;
        }

        .signature-line {
            border-bottom: 1px solid var(--ink);
            margin-bottom: 8px;
            height: 34px;
        }

        .signature-box small {
            color: var(--muted);
        }

        @media print {
            body {
                background: #fff;
                padding: 0;
            }

            .print-toolbar {
                display: none;
            }

            .document {
                border: none;
                box-shadow: none;
                max-width: 100%;
                min-height: 100vh;
                padding: 36px 42px;
            }
        }
    </style>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars(versionedUrl('assets/img/favicon.png'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="manifest" href="<?php echo htmlspecialchars(versionedUrl('manifest.webmanifest'), ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="theme-color" content="#412886">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="BISU Clearance">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars(versionedUrl('assets/img/pwa-icon-192.png'), ENT_QUOTES, 'UTF-8'); ?>">
    <script defer src="<?php echo htmlspecialchars(versionedUrl('assets/js/pwa-register.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
</head>
<body>
    <div class="print-toolbar">
        <button class="btn secondary" type="button" onclick="window.close()">Close</button>
        <button class="btn" type="button" onclick="window.print()">Print Document</button>
    </div>

    <article class="document">
        <header class="header">
            <h1>BISU Online Clearance System</h1>
            <p>Bohol Island State University - Candijay Campus</p>
        </header>

        <h2 class="title">Certificate of Completed Clearance</h2>

        <section class="content">
            This is to certify that <strong><?php echo htmlspecialchars($student_name); ?></strong>
            with ISMIS ID <strong><?php echo htmlspecialchars($record['ismis_id'] ?? 'N/A'); ?></strong>,
            enrolled in <strong><?php echo htmlspecialchars($record['course_name'] ?? 'N/A'); ?></strong>
            under <strong><?php echo htmlspecialchars($record['college_name'] ?? 'N/A'); ?></strong>,
            has successfully completed all required office clearances for
            <strong><?php echo htmlspecialchars($period_label ?: 'Current Academic Period'); ?></strong>.
            
            <br><br>
            All offices in the clearance workflow have recorded an approved status,
            confirming that all clearance requirements have been fulfilled.
        </section>

        <section class="meta">
            <div><strong>Clearance ID:</strong> <?php echo (int) $record['clearance_id']; ?></div>
            <div><strong>Clearance Type:</strong> <?php echo htmlspecialchars(ucfirst((string) ($record['clearance_name'] ?? 'N/A'))); ?></div>
            <div><strong>Finalized On:</strong> <?php echo htmlspecialchars($processed_date); ?></div>
            <div><strong>Document Issued On:</strong> <?php echo htmlspecialchars($issued_on); ?></div>
        </section>

        <section class="signature">
            <div class="signature-box">
                <div class="signature-line"></div>
                <div>BISU Registrar</div>
                <small>Official Seal</small>
            </div>
        </section>
    </article>
</body>
</html>
