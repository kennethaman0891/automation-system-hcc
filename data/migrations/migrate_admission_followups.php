<?php
/**
 * Admission Follow-up Database Migration Runner
 * Alters admission_enquiries, creates followup tables, and seeds realistic data across all required categories.
 */

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/data/migrations/migrate_admission_followups.php';

require_once __DIR__ . '/../../config/database.php';

echo "====================================================================\n";
echo "HCC Admission Follow-up Database Migration\n";
echo "====================================================================\n";

try {
    $pdo = getDB();
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    echo "Connected to database: {$dbName}\n\n";

    // 1. Add follow-up management columns to admission_enquiries if missing
    echo "Inspecting and altering admission_enquiries columns...\n";
    $existingCols = [];
    $colStmt = $pdo->query("SHOW COLUMNS FROM admission_enquiries");
    while ($col = $colStmt->fetch(PDO::FETCH_ASSOC)) {
        $existingCols[] = strtolower($col['Field']);
    }

    $columnsToAdd = [
        'parent_email' => "VARCHAR(150) NULL AFTER phone_e164",
        'next_followup_date' => "DATE NULL AFTER academic_year",
        'next_followup_time' => "TIME NULL DEFAULT '10:00:00' AFTER next_followup_date",
        'followup_reason' => "VARCHAR(255) NULL AFTER next_followup_time",
        'priority' => "ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium' AFTER followup_reason",
        'reminder_status' => "ENUM('pending', 'reminded', 'overdue', 'completed', 'cancelled') NOT NULL DEFAULT 'pending' AFTER priority",
        'followup_category' => "ENUM(
            'First response pending',
            'Admission details shared',
            'Parent requested more information',
            'Parent interested',
            'Campus visit pending',
            'Documents pending',
            'Application pending',
            'Admission decision pending',
            'Completed admission',
            'Enquiry closed'
        ) NOT NULL DEFAULT 'First response pending' AFTER reminder_status",
        'suggested_message' => "TEXT NULL AFTER followup_category",
        'next_recommended_action' => "VARCHAR(255) NULL AFTER suggested_message",
        'internal_notes' => "TEXT NULL AFTER next_recommended_action",
        'is_completed' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER internal_notes",
        'completed_at' => "DATETIME NULL AFTER is_completed",
        'completed_by' => "VARCHAR(100) NULL AFTER completed_at",
        'completed_by_id' => "INT NULL AFTER completed_by",
        'completion_notes' => "TEXT NULL AFTER completed_by_id",
        'manual_sent_confirmed' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER completion_notes",
        'delivery_verified' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER manual_sent_confirmed",
        'is_cancelled' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER delivery_verified",
        'cancelled_at' => "DATETIME NULL AFTER is_cancelled",
        'cancelled_by' => "VARCHAR(100) NULL AFTER cancelled_at",
        'cancelled_by_id' => "INT NULL AFTER cancelled_by",
        'cancellation_reason' => "VARCHAR(255) NULL AFTER cancelled_by_id",
    ];

    foreach ($columnsToAdd as $colName => $colDef) {
        if (!in_array(strtolower($colName), $existingCols, true)) {
            $sql = "ALTER TABLE admission_enquiries ADD COLUMN `{$colName}` {$colDef}";
            echo " Adding column: {$colName}...\n";
            $pdo->exec($sql);
        } else {
            echo " Column already exists: {$colName}\n";
        }
    }

    // Add indexes if missing
    try {
        $pdo->exec("ALTER TABLE admission_enquiries ADD INDEX `idx_next_followup` (`next_followup_date`, `next_followup_time`)");
    } catch (\Throwable $e) {}
    try {
        $pdo->exec("ALTER TABLE admission_enquiries ADD INDEX `idx_followup_cat` (`followup_category`)");
    } catch (\Throwable $e) {}
    try {
        $pdo->exec("ALTER TABLE admission_enquiries ADD INDEX `idx_rem_status` (`reminder_status`)");
    } catch (\Throwable $e) {}

    // 2. Execute SQL file for history and queue tables
    $sqlFile = __DIR__ . '/create_admission_followup_tables.sql';
    echo "\nReading SQL file: {$sqlFile}...\n";
    $sqlContent = file_get_contents($sqlFile);
    $pdo->exec($sqlContent);
    echo "History and Automation tables created successfully.\n\n";

    // 3. Seed realistic sample enquiries across all required categories if empty
    $count = (int)$pdo->query("SELECT COUNT(*) FROM admission_enquiries")->fetchColumn();
    if ($count === 0) {
        echo "Seeding initial realistic admission enquiries across all categories...\n";

        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $twoDaysAgo = date('Y-m-d', strtotime('-2 days'));
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $inThreeDays = date('Y-m-d', strtotime('+3 days'));
        $inOneWeek = date('Y-m-d', strtotime('+7 days'));

        $seeds = [
            // 1. Overdue & High Priority: Documents Pending
            [
                'parent_name' => 'Rajesh Sharma',
                'phone_number' => '+91 98016 60001',
                'phone_e164' => '+919801660001',
                'parent_email' => 'rajesh.sharma@gmail.com',
                'student_name' => 'Aarav Sharma',
                'interested_class' => 'STD I',
                'academic_year' => '2026-2027',
                'original_message' => 'Hello, I want to know about Class 1 admission and document requirements.',
                'followup_category' => 'Documents pending',
                'status' => 'DOCUMENTS_PENDING',
                'next_followup_date' => $yesterday,
                'next_followup_time' => '11:00:00',
                'followup_reason' => 'Pending Birth Certificate & Previous School Transfer Certificate',
                'assigned_staff_name' => 'Mrs. Sunita Patil',
                'priority' => 'urgent',
                'reminder_status' => 'overdue',
                'next_recommended_action' => 'Call parent urgently and request scan of Transfer Certificate before seat deadline.',
                'internal_notes' => 'Parent visited campus last week and paid prospectus fee. Very interested in English medium curriculum.',
                'suggested_message' => "Namaste Rajesh ji, gentle reminder from HCC High School admissions desk regarding Aarav's pending Transfer Certificate & Birth Certificate for Grade STD I. Kindly submit them today to finalize his provisional seat.",
                'is_completed' => 0,
            ],
            // 2. Overdue: Campus Visit Pending
            [
                'parent_name' => 'Priya Deshmukh',
                'phone_number' => '+91 98016 60002',
                'phone_e164' => '+919801660002',
                'parent_email' => 'priya.deshmukh@yahoo.com',
                'student_name' => 'Ananya Deshmukh',
                'interested_class' => 'STD V',
                'academic_year' => '2026-2027',
                'original_message' => 'Can we visit the school campus to see the science labs and library?',
                'followup_category' => 'Campus visit pending',
                'status' => 'VISIT_PLANNED',
                'next_followup_date' => $twoDaysAgo,
                'next_followup_time' => '14:30:00',
                'followup_reason' => 'Rescheduled campus walk-through and meeting with Headmistress',
                'assigned_staff_name' => 'Mr. Anand Kulkarni',
                'priority' => 'high',
                'reminder_status' => 'overdue',
                'next_recommended_action' => 'Offer weekend morning slot (Saturday 10:00 AM) for principal interaction.',
                'internal_notes' => 'Mother works at civil hospital, prefers Saturday or afternoon slots.',
                'suggested_message' => "Namaste Priya ji, greetings from Hindustani Covenant Church High School. We would be delighted to host you and Ananya for a campus walk-through this Saturday at 10:00 AM. Please let us know if this time suits you.",
                'is_completed' => 0,
            ],
            // 3. Today: Parents Awaiting Responses (First response pending)
            [
                'parent_name' => 'Vikram Jadhav',
                'phone_number' => '+91 98016 60003',
                'phone_e164' => '+919801660003',
                'parent_email' => 'vikram.jadhav@outlook.com',
                'student_name' => 'Rohan Jadhav',
                'interested_class' => 'STD II',
                'academic_year' => '2026-2027',
                'original_message' => 'Please provide information regarding fees, syllabus and bus facility to Hotgi Road.',
                'followup_category' => 'First response pending',
                'status' => 'NEW',
                'next_followup_date' => $today,
                'next_followup_time' => '10:00:00',
                'followup_reason' => 'Inbound WhatsApp enquiry received regarding admission syllabus, fees & school bus route',
                'assigned_staff_name' => 'Admissions Desk',
                'priority' => 'high',
                'reminder_status' => 'pending',
                'next_recommended_action' => 'Send welcoming greeting, prospectus PDF link and bus route 4 schedule.',
                'internal_notes' => 'Enquired via website WhatsApp widget yesterday evening.',
                'suggested_message' => "Namaste Vikram ji, thank you for contacting Hindustani Covenant Church High School regarding admission for Rohan in STD II. We have active school bus transport along Hotgi Road and modern smart classrooms. Would you like us to share our school prospectus and fee breakdown?",
                'is_completed' => 0,
            ],
            // 4. Today: Parent Interested
            [
                'parent_name' => 'Fatima Sheikh',
                'phone_number' => '+91 98016 60004',
                'phone_e164' => '+919801660004',
                'parent_email' => 'fatima.sheikh@gmail.com',
                'student_name' => 'Zayan Sheikh',
                'interested_class' => 'Nursery',
                'academic_year' => '2026-2027',
                'original_message' => 'We are very keen on enrolling our son Zayan in Nursery. We love your school activities.',
                'followup_category' => 'Parent interested',
                'status' => 'INTERESTED',
                'next_followup_date' => $today,
                'next_followup_time' => '12:00:00',
                'followup_reason' => 'Parent expressed high interest in early childhood Montessori wing and activity-based learning',
                'assigned_staff_name' => 'Mrs. Sunita Patil',
                'priority' => 'high',
                'reminder_status' => 'pending',
                'next_recommended_action' => 'Share registration form link and invite for classroom interaction.',
                'internal_notes' => 'Elder sibling already an alumnus of HCC school. Strong referral.',
                'suggested_message' => "Namaste Fatima ji, we are thrilled by your keen interest in HCC High School for Zayan in Nursery. We would love to reserve an interaction slot for Zayan this week. Would 11:30 AM tomorrow suit your family?",
                'is_completed' => 0,
            ],
            // 5. Today: Admission Details Shared
            [
                'parent_name' => 'Amitabh Shinde',
                'phone_number' => '+91 98016 60005',
                'phone_e164' => '+919801660005',
                'parent_email' => 'amitabh.s@rediffmail.com',
                'student_name' => 'Sneha Shinde',
                'interested_class' => 'STD VIII',
                'academic_year' => '2026-2027',
                'original_message' => 'Please email fee chart and 8th standard subjects.',
                'followup_category' => 'Admission details shared',
                'status' => 'INFORMATION_SHARED',
                'next_followup_date' => $today,
                'next_followup_time' => '15:00:00',
                'followup_reason' => 'Fee structure and elective subject options (Sanskrit/Hindi) sent yesterday',
                'assigned_staff_name' => 'Mr. Anand Kulkarni',
                'priority' => 'medium',
                'reminder_status' => 'pending',
                'next_recommended_action' => 'Follow up on elective preferences and check if any questions remain.',
                'internal_notes' => 'Student is transferring due to father bank transfer to Solapur.',
                'suggested_message' => "Namaste Amitabh ji, hope you reviewed the fee schedule and subject choices sent yesterday for Sneha (STD VIII). Please feel free to let us know if you need any clarification regarding lab facilities or academic electives.",
                'is_completed' => 0,
            ],
            // 6. Upcoming: Application Pending
            [
                'parent_name' => 'Deepak More',
                'phone_number' => '+91 98016 60006',
                'phone_e164' => '+919801660006',
                'parent_email' => 'deepak.more@gmail.com',
                'student_name' => 'Tanvi More',
                'interested_class' => 'STD IV',
                'academic_year' => '2026-2027',
                'original_message' => 'Collected application form from school counter.',
                'followup_category' => 'Application pending',
                'status' => 'APPLICATION_SUBMITTED',
                'next_followup_date' => $tomorrow,
                'next_followup_time' => '11:00:00',
                'followup_reason' => 'Application form kit collected from front desk; awaiting completed submission',
                'assigned_staff_name' => 'Mrs. Sunita Patil',
                'priority' => 'medium',
                'reminder_status' => 'pending',
                'next_recommended_action' => 'Remind parent about application submission deadline of Friday 3 PM.',
                'internal_notes' => 'Parent took physical form copy on Monday.',
                'suggested_message' => "Namaste Deepak ji, gentle reminder regarding Tanvi's admission form for STD IV at HCC High School. The current intake cycle closes this Friday. Kindly submit the completed form along with photographs at the school office.",
                'is_completed' => 0,
            ],
            // 7. Upcoming: Parent Requested More Information
            [
                'parent_name' => 'Meera Joshi',
                'phone_number' => '+91 98016 60007',
                'phone_e164' => '+919801660007',
                'parent_email' => 'meera.joshi@gmail.com',
                'student_name' => 'Advait Joshi',
                'interested_class' => 'STD VI',
                'academic_year' => '2026-2027',
                'original_message' => 'Do you offer robotics club and basketball coaching for class 6?',
                'followup_category' => 'Parent requested more information',
                'status' => 'CONTACTED',
                'next_followup_date' => $inThreeDays,
                'next_followup_time' => '10:30:00',
                'followup_reason' => 'Parent requested robotics club, sports coaching and scholarship criteria details',
                'assigned_staff_name' => 'Mr. Anand Kulkarni',
                'priority' => 'medium',
                'reminder_status' => 'pending',
                'next_recommended_action' => 'Send sports brochure and merit scholarship criteria sheet.',
                'internal_notes' => 'Student is a state-level chess player.',
                'suggested_message' => "Namaste Meera ji, following up from HCC High School. We have compiled our extracurricular sports, robotics club and scholarship details for Advait (STD VI). Our sports director is also happy to meet Advait.",
                'is_completed' => 0,
            ],
            // 8. Upcoming: Admission Decision Pending
            [
                'parent_name' => 'Ganesh Chavan',
                'phone_number' => '+91 98016 60008',
                'phone_e164' => '+919801660008',
                'parent_email' => 'ganesh.chavan@gmail.com',
                'student_name' => 'Pranav Chavan',
                'interested_class' => 'STD IX',
                'academic_year' => '2026-2027',
                'original_message' => 'When will the result of the entrance interaction be announced?',
                'followup_category' => 'Admission decision pending',
                'status' => 'APPLICATION_SUBMITTED',
                'next_followup_date' => $inOneWeek,
                'next_followup_time' => '16:00:00',
                'followup_reason' => 'Principal assessment completed; awaiting committee final grant letter',
                'assigned_staff_name' => 'Admissions Desk',
                'priority' => 'high',
                'reminder_status' => 'pending',
                'next_recommended_action' => 'Inform parent once principal signs the admission confirmation certificate.',
                'internal_notes' => 'Good interaction score. Entrance aptitude test cleared with 88%.',
                'suggested_message' => "Namaste Ganesh ji, we are pleased to inform you that Pranav's admission evaluation for STD IX at HCC High School is positive. The official confirmation letter is prepared. Kindly visit the administrative office to finalize enrollment.",
                'is_completed' => 0,
            ],
            // 9. Completed Admission
            [
                'parent_name' => 'Sanjay Rathod',
                'phone_number' => '+91 98016 60009',
                'phone_e164' => '+919801660009',
                'parent_email' => 'sanjay.rathod@gmail.com',
                'student_name' => 'Isha Rathod',
                'interested_class' => 'STD III',
                'academic_year' => '2026-2027',
                'original_message' => 'Fees paid online. Looking forward to class commencement.',
                'followup_category' => 'Completed admission',
                'status' => 'ENROLLED',
                'next_followup_date' => $yesterday,
                'next_followup_time' => '10:00:00',
                'followup_reason' => 'Admission finalized, fees paid, ID card issued',
                'assigned_staff_name' => 'Mrs. Sunita Patil',
                'priority' => 'low',
                'reminder_status' => 'completed',
                'next_recommended_action' => 'Admission completed successfully. Welcome kit delivered.',
                'internal_notes' => 'Fee receipt #HCC-2026-9081 issued. Student enrolled in Division A.',
                'suggested_message' => "Namaste Sanjay ji, welcome to the HCC High School family! Isha's admission to STD III is formally confirmed. Academic session commences shortly.",
                'is_completed' => 1,
                'completed_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
                'completed_by' => 'Administrator',
                'completion_notes' => 'Seat confirmed, document verification complete, manual sent confirmation logged.',
                'manual_sent_confirmed' => 1,
                'delivery_verified' => 0,
            ],
        ];

        $insSql = "INSERT INTO admission_enquiries (
            parent_name, phone_number, phone_e164, parent_email, student_name,
            interested_class, academic_year, original_message, followup_category, status,
            next_followup_date, next_followup_time, followup_reason, assigned_staff_name,
            priority, reminder_status, next_recommended_action, internal_notes, suggested_message,
            is_completed, completed_at, completed_by, completion_notes, manual_sent_confirmed,
            delivery_verified, created_by_name
        ) VALUES (
            :parent_name, :phone_number, :phone_e164, :parent_email, :student_name,
            :interested_class, :academic_year, :original_message, :followup_category, :status,
            :next_followup_date, :next_followup_time, :followup_reason, :assigned_staff_name,
            :priority, :reminder_status, :next_recommended_action, :internal_notes, :suggested_message,
            :is_completed, :completed_at, :completed_by, :completion_notes, :manual_sent_confirmed,
            :delivery_verified, 'Administrator'
        )";

        $stmt = $pdo->prepare($insSql);

        $histStmt = $pdo->prepare("INSERT INTO admission_followup_history (
            enquiry_id, action, title, details, performed_by, manual_sent_confirmed, delivery_verified
        ) VALUES (?, ?, ?, ?, ?, ?, ?)");

        foreach ($seeds as $s) {
            $stmt->execute([
                ':parent_name' => $s['parent_name'],
                ':phone_number' => $s['phone_number'],
                ':phone_e164' => $s['phone_e164'],
                ':parent_email' => $s['parent_email'],
                ':student_name' => $s['student_name'],
                ':interested_class' => $s['interested_class'],
                ':academic_year' => $s['academic_year'],
                ':original_message' => $s['original_message'],
                ':followup_category' => $s['followup_category'],
                ':status' => $s['status'],
                ':next_followup_date' => $s['next_followup_date'],
                ':next_followup_time' => $s['next_followup_time'],
                ':followup_reason' => $s['followup_reason'],
                ':assigned_staff_name' => $s['assigned_staff_name'],
                ':priority' => $s['priority'],
                ':reminder_status' => $s['reminder_status'],
                ':next_recommended_action' => $s['next_recommended_action'],
                ':internal_notes' => $s['internal_notes'],
                ':suggested_message' => $s['suggested_message'],
                ':is_completed' => $s['is_completed'],
                ':completed_at' => $s['completed_at'] ?? null,
                ':completed_by' => $s['completed_by'] ?? null,
                ':completion_notes' => $s['completion_notes'] ?? null,
                ':manual_sent_confirmed' => $s['manual_sent_confirmed'] ?? 0,
                ':delivery_verified' => 0, // ALWAYS 0
            ]);

            $enqId = (int)$pdo->lastInsertId();

            // Insert initial history
            $histStmt->execute([
                $enqId,
                'created',
                'Admission Enquiry Registered',
                "Enquiry created for student {$s['student_name']} applying for {$s['interested_class']}. Initial category: {$s['followup_category']}.",
                'Administrator',
                0,
                0,
            ]);

            if ($s['is_completed'] === 1) {
                $histStmt->execute([
                    $enqId,
                    'manual_sent_confirmed',
                    'Manual WhatsApp Confirmation Recorded',
                    'Administrator confirmed manually sending WhatsApp confirmation message to parent. Note: Manual confirmation is unverified delivery.',
                    'Administrator',
                    1,
                    0,
                ]);
                $histStmt->execute([
                    $enqId,
                    'completed',
                    'Admission Completed',
                    'Admission process marked completed. Seat assigned.',
                    'Administrator',
                    1,
                    0,
                ]);
            }
        }

        echo "Seeded " . count($seeds) . " admission enquiries with complete histories.\n";
    } else {
        echo "Table admission_enquiries already contains {$count} records. Skipping seed.\n";
    }

    echo "\nMigration finished successfully!\n";

} catch (\Throwable $e) {
    echo "ERROR during migration: " . $e->getMessage() . "\n";
    exit(1);
}
