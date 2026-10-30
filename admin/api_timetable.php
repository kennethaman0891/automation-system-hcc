<?php
/**
 * HCC Exam Timetable API Handler
 * High-performance backend bridge supporting matrix engine state, relational sync,
 * presets management, and fail-safe JSON file storage.
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

function tt_json($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$action = trim($_GET['action'] ?? $_POST['action'] ?? 'list');
$method = $_SERVER['REQUEST_METHOD'];

$storageDir = __DIR__ . '/../data';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0755, true);
}
$storeFile = $storageDir . '/timetables.json';
$presetFile = $storageDir . '/timetable_presets.json';

function getFileTimetables($storeFile) {
    if (!file_exists($storeFile)) return [];
    $raw = @file_get_contents($storeFile);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function saveFileTimetables($storeFile, $data) {
    return @file_put_contents($storeFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Connect to DB if available
$db = null;
try {
    $db = getDB();
} catch (\Throwable $e) {
    $db = null;
}

/**
 * Normalizes timetable state for client-side TimetableMatrixController
 */
function normalizeTimetableForClient($raw, $db = null) {
    $meta = [
        'id'            => $raw['id'] ?? ($raw['metadata']['id'] ?? 1),
        'title'         => $raw['title'] ?? ($raw['metadata']['title'] ?? 'Examination Schedule'),
        'school_name'   => $raw['school_name'] ?? ($raw['metadata']['school_name'] ?? 'Hindustani Covenant Church High School'),
        'academic_year' => $raw['academic_year'] ?? ($raw['metadata']['academic_year'] ?? '2026-2027'),
        'exam_type'     => $raw['exam_type'] ?? ($raw['metadata']['exam_type'] ?? 'Semester Exam'),
        'instructions'  => $raw['notes'] ?? ($raw['metadata']['instructions'] ?? ''),
        'status'        => $raw['status'] ?? ($raw['metadata']['status'] ?? 'published')
    ];

    // Columns normalization
    $columns = [];
    $rawCols = $raw['columns'] ?? ($raw['metadata']['columns'] ?? []);
    if (!empty($rawCols)) {
        foreach ($rawCols as $idx => $c) {
            $colId = $c['id'] ?? $c['key'] ?? ('col_' . ($idx + 1));
            $colLabel = $c['label'] ?? $c['name'] ?? $c['column_name'] ?? ('Column ' . ($idx + 1));
            $columns[] = [
                'id' => (string)$colId,
                'label' => (string)$colLabel
            ];
        }
    } else {
        $columns = [
            ['id' => 'std_v', 'label' => 'STD V'],
            ['id' => 'std_vi_viii', 'label' => 'STD VI-VIII'],
            ['id' => 'std_ix_x', 'label' => 'STD IX & X']
        ];
    }

    // Rows normalization (grouped by date)
    $rows = [];
    $rawRows = $raw['rows'] ?? ($raw['metadata']['rows'] ?? []);

    // Check if rows are already grouped by date
    if (!empty($rawRows) && isset($rawRows[0]['slots'])) {
        $rows = $rawRows;
    } elseif (!empty($rawRows)) {
        // Group flat rows by date
        $grouped = [];
        foreach ($rawRows as $idx => $r) {
            $d = $r['exam_date'] ?? $r['date'] ?? date('Y-m-d');
            $dayName = $r['day_name'] ?? $r['day'] ?? (new DateTime($d))->format('l');
            $timeSlot = $r['time_slot'] ?? $r['time'] ?? '8.00 AM - 10.15 AM';
            $cells = $r['cells'] ?? [];

            if (!isset($grouped[$d])) {
                $grouped[$d] = [
                    'dateId' => 'd_' . md5($d),
                    'date'   => $d,
                    'day'    => $dayName,
                    'slots'  => []
                ];
            }

            $grouped[$d]['slots'][] = [
                'slotId' => 's_' . md5($d . '_' . $timeSlot . '_' . $idx),
                'time'   => $timeSlot,
                'cells'  => $cells
            ];
        }
        $rows = array_values($grouped);
    }

    // Presets
    $presets = [
        'subjects' => [
            'ALGEBRA(M-1)', 'GEOMETRY', 'MATHS', 'ENGLISH', 'MARATHI', 'HINDI',
            'HINDI / SANSKRIT', 'SCIENCE', 'SCIENCE-1', 'SCIENCE-2', 'GENERAL SCIENCE',
            'EVS - I', 'EVS-II', 'HISTORY', 'GEOGRAPHY', 'COMP. SCI.', 'DRAWING',
            'PHYSICAL EDUCATION', '--------'
        ],
        'timeSlots' => [
            '8.00 AM - 10.15 AM',
            '8.00 AM - 11.15 AM',
            '8.00 AM - 10.00 AM',
            '11.30 AM - 1.45 PM',
            '1.30 PM - 3.45 PM'
        ]
    ];

    if ($db instanceof PDO) {
        try {
            $pStmt = $db->query("SELECT category, preset_value FROM timetable_presets ORDER BY sort_order ASC, id ASC");
            $dbPresets = $pStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($dbPresets)) {
                $subList = [];
                $timeList = [];
                foreach ($dbPresets as $pr) {
                    if ($pr['category'] === 'subject') $subList[] = $pr['preset_value'];
                    if ($pr['category'] === 'time_slot') $timeList[] = $pr['preset_value'];
                }
                if (!empty($subList)) $presets['subjects'] = array_values(array_unique($subList));
                if (!empty($timeList)) $presets['timeSlots'] = array_values(array_unique($timeList));
            }
        } catch (\Throwable $e) {}
    }

    return [
        'metadata' => $meta,
        'columns'  => $columns,
        'rows'     => $rows,
        'presets'  => $presets
    ];
}

/* ── 1. Action: LIST ── */
if ($action === 'list') {
    $list = [];
    if ($db instanceof PDO) {
        try {
            $stmt = $db->query("SELECT id, title, academic_year, exam_type, status, updated_at FROM timetables ORDER BY id DESC");
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {}
    }

    if (empty($list)) {
        $all = getFileTimetables($storeFile);
        foreach ($all as $item) {
            $meta = $item['metadata'] ?? [];
            $list[] = [
                'id'            => $meta['id'] ?? 1,
                'title'         => $meta['title'] ?? 'First Semester Examination Time-Table',
                'academic_year' => $meta['academic_year'] ?? '2026-2027',
                'exam_type'     => $meta['exam_type'] ?? 'Semester Exam',
                'status'        => $meta['status'] ?? 'published',
                'updated_at'    => $item['updated_at'] ?? date('Y-m-d H:i:s')
            ];
        }
    }

    tt_json(['ok' => true, 'timetables' => $list]);
}

/* ── 2. Action: LOAD ── */
if ($action === 'load') {
    $id = trim($_GET['id'] ?? '');

    if ($db instanceof PDO) {
        try {
            if ($id !== '') {
                $stmt = $db->prepare("SELECT * FROM timetables WHERE id = ?");
                $stmt->execute([$id]);
            } else {
                $stmt = $db->query("SELECT * FROM timetables ORDER BY id DESC LIMIT 1");
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if (!empty($row['grid_data_json'])) {
                    $decoded = json_decode($row['grid_data_json'], true);
                    if (is_array($decoded)) {
                        $decoded['id'] = $row['id'];
                        $decoded['title'] = $row['title'];
                        $decoded['academic_year'] = $row['academic_year'];
                        $decoded['exam_type'] = $row['exam_type'];
                        $decoded['notes'] = $row['notes'];
                        $decoded['status'] = $row['status'];
                        tt_json(['ok' => true, 'timetable' => normalizeTimetableForClient($decoded, $db)]);
                    }
                }

                // If grid_data_json was empty, load from relational tables
                $colsStmt = $db->prepare("SELECT column_key AS id, column_name AS label FROM timetable_columns WHERE timetable_id = ? ORDER BY sort_order ASC");
                $colsStmt->execute([$row['id']]);
                $dbCols = $colsStmt->fetchAll(PDO::FETCH_ASSOC);

                $rowsStmt = $db->prepare("SELECT * FROM timetable_rows WHERE timetable_id = ? ORDER BY sort_order ASC, exam_date ASC");
                $rowsStmt->execute([$row['id']]);
                $dbRows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

                $cellsStmt = $db->prepare("SELECT r.id as row_id, c.column_key, cl.subject_name FROM timetable_cells cl JOIN timetable_rows r ON cl.row_id = r.id JOIN timetable_columns c ON cl.column_id = c.id WHERE cl.timetable_id = ?");
                $cellsStmt->execute([$row['id']]);
                $dbCells = $cellsStmt->fetchAll(PDO::FETCH_ASSOC);

                $cellLookup = [];
                foreach ($dbCells as $dc) {
                    $cellLookup[$dc['row_id']][$dc['column_key']] = $dc['subject_name'];
                }

                $groupedRows = [];
                foreach ($dbRows as $r) {
                    $d = $r['exam_date'];
                    if (!isset($groupedRows[$d])) {
                        $groupedRows[$d] = [
                            'dateId' => 'd_' . $r['id'],
                            'date'   => $d,
                            'day'    => $r['day_name'],
                            'slots'  => []
                        ];
                    }
                    $slotCells = $cellLookup[$r['id']] ?? [];
                    $groupedRows[$d]['slots'][] = [
                        'slotId' => 's_' . $r['id'],
                        'time'   => $r['time_slot'],
                        'cells'  => $slotCells
                    ];
                }

                $ttPayload = [
                    'id'            => $row['id'],
                    'title'         => $row['title'],
                    'school_name'   => $row['school_name'],
                    'academic_year' => $row['academic_year'],
                    'exam_type'     => $row['exam_type'],
                    'notes'         => $row['notes'],
                    'status'        => $row['status'],
                    'columns'       => $dbCols,
                    'rows'          => array_values($groupedRows)
                ];
                tt_json(['ok' => true, 'timetable' => normalizeTimetableForClient($ttPayload, $db)]);
            }
        } catch (\Throwable $e) {}
    }

    // File fallback
    $all = getFileTimetables($storeFile);
    foreach ($all as $item) {
        if ($id === '' || (isset($item['metadata']['id']) && (string)$item['metadata']['id'] === (string)$id)) {
            tt_json(['ok' => true, 'timetable' => normalizeTimetableForClient($item, $db)]);
        }
    }

    // Default template fallback
    tt_json(['ok' => true, 'timetable' => normalizeTimetableForClient([], $db)]);
}

/* ── 3. Action: SAVE ── */
if ($action === 'save') {
    if ($method !== 'POST') {
        tt_json(['ok' => false, 'error' => 'Method not allowed'], 405);
    }

    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true) ?: $_POST;

    // Verify CSRF
    $clientCsrf = $payload['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!empty($_SESSION['csrf_token']) && $clientCsrf !== $_SESSION['csrf_token']) {
        if (function_exists('verifyCSRFToken') && !verifyCSRFToken()) {
            tt_json(['ok' => false, 'error' => 'Invalid CSRF token'], 403);
        }
    }

    $meta = $payload['metadata'] ?? [];
    $title = trim($meta['title'] ?? 'Examination Schedule');
    $year = trim($meta['academic_year'] ?? '2026-2027');
    $examType = trim($meta['exam_type'] ?? 'Semester Exam');
    $instructions = trim($meta['instructions'] ?? '');
    $status = trim($meta['status'] ?? 'published');
    $id = !empty($meta['id']) ? (int)$meta['id'] : null;

    $jsonFull = json_encode($payload, JSON_UNESCAPED_UNICODE);

    if ($db instanceof PDO) {
        try {
            $db->beginTransaction();

            if ($id) {
                $stmt = $db->prepare("UPDATE timetables SET title = ?, academic_year = ?, exam_type = ?, notes = ?, status = ?, grid_data_json = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$title, $year, $examType, $instructions, $status, $jsonFull, $id]);
            } else {
                $stmt = $db->prepare("INSERT INTO timetables (title, academic_year, exam_type, notes, status, grid_data_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $stmt->execute([$title, $year, $examType, $instructions, $status, $jsonFull]);
                $id = (int)$db->lastInsertId();
                $payload['metadata']['id'] = $id;
                $jsonFull = json_encode($payload, JSON_UNESCAPED_UNICODE);
                $db->prepare("UPDATE timetables SET grid_data_json = ? WHERE id = ?")->execute([$jsonFull, $id]);
            }

            // Sync relational tables: timetable_columns, timetable_rows, timetable_cells
            $db->prepare("DELETE FROM timetable_cells WHERE timetable_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM timetable_rows WHERE timetable_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM timetable_columns WHERE timetable_id = ?")->execute([$id]);

            $colMap = [];
            $cols = $payload['columns'] ?? [];
            foreach ($cols as $idx => $c) {
                $cKey = $c['id'] ?? ('col_' . ($idx + 1));
                $cName = $c['label'] ?? ('Column ' . ($idx + 1));
                $cStmt = $db->prepare("INSERT INTO timetable_columns (timetable_id, column_key, column_name, sort_order) VALUES (?, ?, ?, ?)");
                $cStmt->execute([$id, $cKey, $cName, $idx + 1]);
                $colMap[$cKey] = (int)$db->lastInsertId();
            }

            $rows = $payload['rows'] ?? [];
            $rowOrder = 1;
            foreach ($rows as $dateObj) {
                $d = $dateObj['date'];
                $day = $dateObj['day'];
                $slots = $dateObj['slots'] ?? [];
                foreach ($slots as $s) {
                    $time = $s['time'];
                    $rStmt = $db->prepare("INSERT INTO timetable_rows (timetable_id, exam_date, day_name, time_slot, sort_order) VALUES (?, ?, ?, ?, ?)");
                    $rStmt->execute([$id, $d, $day, $time, $rowOrder++]);
                    $rowDbId = (int)$db->lastInsertId();

                    $cells = $s['cells'] ?? [];
                    foreach ($cells as $cKey => $subjectVal) {
                        if (isset($colMap[$cKey])) {
                            $cellStmt = $db->prepare("INSERT INTO timetable_cells (timetable_id, row_id, column_id, subject_name) VALUES (?, ?, ?, ?)");
                            $cellStmt->execute([$id, $rowDbId, $colMap[$cKey], $subjectVal ?: '--------']);
                        }
                    }
                }
            }

            $db->commit();
            tt_json(['ok' => true, 'id' => $id, 'message' => 'Timetable saved and synced']);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            // Continue to file fallback
        }
    }

    // Save to file storage
    $all = getFileTimetables($storeFile);
    if (!$id) {
        $id = time();
        $payload['metadata']['id'] = $id;
    }
    $payload['updated_at'] = date('Y-m-d H:i:s');

    $found = false;
    foreach ($all as &$existing) {
        if (isset($existing['metadata']['id']) && (int)$existing['metadata']['id'] === (int)$id) {
            $existing = $payload;
            $found = true;
            break;
        }
    }
    unset($existing);

    if (!$found) {
        $all[] = $payload;
    }

    saveFileTimetables($storeFile, $all);
    tt_json(['ok' => true, 'id' => $id, 'message' => 'Saved to file storage']);
}

/* ── 4. Action: ADD PRESET ── */
if ($action === 'add_preset') {
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true) ?: $_POST;
    $type = trim($payload['type'] ?? '');
    $val = trim($payload['value'] ?? '');

    if ($val === '') {
        tt_json(['ok' => false, 'error' => 'Value required'], 400);
    }

    if ($db instanceof PDO) {
        try {
            $category = $type === 'time' ? 'time_slot' : 'subject';
            $stmt = $db->prepare("INSERT INTO timetable_presets (category, preset_value, sort_order) VALUES (?, ?, 99) ON DUPLICATE KEY UPDATE sort_order = sort_order");
            $stmt->execute([$category, $val]);
        } catch (\Throwable $e) {}
    }

    tt_json(['ok' => true]);
}

/* ── 5. Action: GENERATE TEMPLATE ── */
if ($action === 'generate_template') {
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true) ?: $_POST;

    $group = $payload['standards_group'] ?? 'std_v_x';
    $year = $payload['academic_year'] ?? '2026-27';
    $examType = $payload['exam_type'] ?? 'FIRST SEMESTER EXAMINATION TIME-TABLE (WRITTEN EXAM)';
    $startDate = $payload['start_date'] ?? '2026-10-23';
    $primarySlot = $payload['primary_slot'] ?? '8.00 AM - 10.15 AM';
    $extendedSlot = $payload['extended_slot'] ?? '8.00 AM - 11.15 AM';
    $hasExtended = !empty($payload['has_extended_slot']);
    $skipSundays = isset($payload['skip_sundays']) ? (bool)$payload['skip_sundays'] : true;
    $days = $payload['days'] ?? [];

    if (empty($days)) {
        $days = [
            ['dayNum' => 1, 'subject' => 'ENGLISH', 'hsSubject' => 'ALGEBRA(M-1)'],
            ['dayNum' => 2, 'subject' => 'MARATHI', 'hsSubject' => 'ENGLISH'],
            ['dayNum' => 3, 'subject' => 'HINDI / SANSKRIT', 'hsSubject' => 'MARATHI'],
            ['dayNum' => 4, 'subject' => 'MATHS', 'hsSubject' => 'HINDI / SANSKRIT'],
            ['dayNum' => 5, 'subject' => 'EVS - I', 'hsSubject' => 'SCIENCE-1'],
            ['dayNum' => 6, 'subject' => 'SCIENCE', 'hsSubject' => 'GEOMETRY'],
            ['dayNum' => 7, 'subject' => 'COMP. SCI.', 'hsSubject' => 'SCIENCE-2'],
            ['dayNum' => 8, 'subject' => 'EVS-II', 'hsSubject' => 'HISTORY'],
            ['dayNum' => 9, 'subject' => 'DRAWING', 'hsSubject' => 'GEOGRAPHY']
        ];
    }

    $columns = [];
    if ($group === 'std_v_x') {
        $columns = [
            ['id' => 'std_v', 'label' => 'STD V'],
            ['id' => 'std_vi_viii', 'label' => 'STD VI-VIII'],
            ['id' => 'std_ix_x', 'label' => 'STD IX & X']
        ];
    } elseif ($group === 'std_i_iv') {
        $columns = [
            ['id' => 'col_1', 'label' => 'STD I'],
            ['id' => 'col_2', 'label' => 'STD II'],
            ['id' => 'col_3', 'label' => 'STD III'],
            ['id' => 'col_4', 'label' => 'STD IV']
        ];
    } else {
        $columns = [
            ['id' => 'col_1', 'label' => 'STD I'],
            ['id' => 'col_2', 'label' => 'STD II'],
            ['id' => 'col_3', 'label' => 'STD III'],
            ['id' => 'col_4', 'label' => 'STD IV'],
            ['id' => 'col_5', 'label' => 'STD V'],
            ['id' => 'col_6', 'label' => 'STD VI'],
            ['id' => 'col_7', 'label' => 'STD VII'],
            ['id' => 'col_8', 'label' => 'STD VIII'],
            ['id' => 'col_9', 'label' => 'STD IX'],
            ['id' => 'col_10', 'label' => 'STD X']
        ];
    }

    $rows = [];
    $currDate = new DateTime($startDate);

    foreach ($days as $idx => $d) {
        if ($skipSundays) {
            while ((int)$currDate->format('w') === 0) {
                $currDate->modify('+1 day');
            }
        }
        $dateStr = $currDate->format('Y-m-d');
        $dayName = $currDate->format('l');
        $dateId = 'd_' . ($idx + 1);

        $sub = $d['subject'] ?? 'ENGLISH';
        $hsSub = $d['hsSubject'] ?? $sub;

        $slots = [];
        if ($hasExtended && $group === 'std_v_x' && ($idx >= 1 && $idx <= 3)) {
            $slots[] = [
                'slotId' => $dateId . '_s1',
                'time' => $primarySlot,
                'cells' => [
                    'std_v' => $sub,
                    'std_vi_viii' => $sub,
                    'std_ix_x' => '--------'
                ]
            ];
            $slots[] = [
                'slotId' => $dateId . '_s2',
                'time' => $extendedSlot,
                'cells' => [
                    'std_v' => '--------',
                    'std_vi_viii' => '--------',
                    'std_ix_x' => $hsSub
                ]
            ];
        } elseif ($group === 'std_v_x' && $idx === 0) {
            $slots[] = [
                'slotId' => $dateId . '_s1',
                'time' => $primarySlot,
                'cells' => [
                    'std_v' => '--------',
                    'std_vi_viii' => '--------',
                    'std_ix_x' => $hsSub
                ]
            ];
        } else {
            $cells = [];
            foreach ($columns as $c) {
                if ($c['id'] === 'std_ix_x' || $c['id'] === 'col_9' || $c['id'] === 'col_10') {
                    $cells[$c['id']] = $hsSub;
                } else {
                    $cells[$c['id']] = $sub;
                }
            }
            $slots[] = [
                'slotId' => $dateId . '_s1',
                'time' => $primarySlot,
                'cells' => $cells
            ];
        }

        $rows[] = [
            'dateId' => $dateId,
            'date' => $dateStr,
            'day' => $dayName,
            'slots' => $slots
        ];

        $currDate->modify('+1 day');
    }

    $groupLabel = $group === 'std_v_x' ? 'STD V-X' : ($group === 'std_i_iv' ? 'STD I-IV' : 'STD I-X');
    $result = [
        'metadata' => [
            'id' => null,
            'title' => $examType . ' - ' . $groupLabel,
            'school_name' => 'Hindustani Covenant Church High School',
            'academic_year' => $year,
            'exam_type' => $examType,
            'instructions' => "1. Students must report 15 minutes before exam time in full school uniform.\n2. Bring your own exam stationery and clipboard.\n3. Hall ticket is mandatory for all examination sessions.",
            'status' => 'draft'
        ],
        'columns' => $columns,
        'rows' => $rows
    ];

    tt_json(['ok' => true, 'timetable' => $result]);
}

tt_json(['ok' => false, 'error' => 'Invalid action'], 400);
