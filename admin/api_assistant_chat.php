<?php
/* HCC Assistant — dashboard help chatbot endpoint.
   POST JSON {message} with X-CSRF-Token header. Returns structured JSON
   (rendered client-side with textContent only — no HTML from the server). */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/includes/assistant_knowledge.php';

header('Content-Type: application/json; charset=utf-8');

function hccAssistantRespond(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($GLOBALS['WA_AUTH_VIA_BRIDGE'])) {
    hccAssistantRespond(403, ['ok' => false, 'error' => 'Forbidden']);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    hccAssistantRespond(405, ['ok' => false, 'error' => 'Method not allowed']);
}
if (!verifyCSRFToken()) {
    hccAssistantRespond(403, ['ok' => false, 'error' => 'Your session expired. Please refresh the page.']);
}

$raw = PHP_SAPI === 'cli' ? (string)getenv('HCC_ASSISTANT_INPUT') : file_get_contents('php://input'); // CLI path = test harness only
$body = json_decode((string)$raw, true);
$message = is_array($body) ? trim((string)($body['message'] ?? '')) : '';
if ($message === '') {
    hccAssistantRespond(400, ['ok' => false, 'error' => 'Please type a question.']);
}
if (mb_strlen($message) > 500) {
    $message = mb_substr($message, 0, 500);
}

$q = mb_strtolower($message);
$q = preg_replace('/[^\p{L}\p{N}\s\-\']+/u', ' ', $q);
$q = ' ' . preg_replace('/\s+/', ' ', $q) . ' ';

$defaultSuggestions = ['Explain the system workflow', 'How do I enter marks?', 'How does WhatsApp sharing work?', 'Show me today\'s stats'];

/* Small talk */
if (str_word_count($q) <= 4 && preg_match('/^\s*(hi|hello|hey|hii+|good (morning|afternoon|evening)|namaste|greetings)\b/u', $q)) {
    $name = trim((string)($_SESSION['admin_name'] ?? ''));
    hccAssistantRespond(200, ['ok' => true, 'reply' => [
        'title' => 'Hello' . ($name !== '' ? ', ' . $name : '') . '!',
        'text' => 'I\'m the HCC Assistant. Ask me how any part of the system works — marks, results, timetables, WhatsApp, admissions and more.',
        'steps' => [], 'links' => [], 'followups' => $defaultSuggestions,
    ]]);
}
if (str_word_count($q) <= 5 && preg_match('/\b(thanks|thank you|thx|ty|great|awesome|perfect)\b/u', $q)) {
    hccAssistantRespond(200, ['ok' => true, 'reply' => [
        'title' => '', 'text' => 'You\'re welcome! Anything else I can help you with?',
        'steps' => [], 'links' => [], 'followups' => $defaultSuggestions,
    ]]);
}
if (preg_match('/\b(who are you|what are you|your name|help|what can you do)\b/u', $q) && !preg_match('/\b(marks|whatsapp|timetable|admission|import)\b/u', $q)) {
    hccAssistantRespond(200, ['ok' => true, 'reply' => [
        'title' => 'I\'m the HCC Assistant',
        'text' => 'I explain how the school automation system works and point you to the right page. I can help with:',
        'steps' => ['Marks entry & Excel import', 'Results, report cards & pass criteria', 'Unit tests, timetable, seating & substitutes', 'At-Risk Radar & Parent-Sign Chase', 'WhatsApp Hub & admissions', 'Promotion, certificates, archive & Drive', 'Live dashboard statistics'],
        'links' => [], 'followups' => $defaultSuggestions,
    ]]);
}

/* Live stats */
if (preg_match('/\b(stats|statistics|how many|total students|figures|numbers)\b/u', $q)) {
    $steps = [];
    try {
        $s = rcDashboardStats();
        $steps[] = 'Students on record: ' . (int)$s['students'];
        $steps[] = 'Subjects: ' . (int)$s['subjects'];
        $steps[] = 'Pass rate: ' . $s['passPct'] . '% (' . (int)$s['passed'] . ' of ' . (int)$s['total'] . ' results)';
        $steps[] = 'Average score: ' . $s['avgPct'] . '%';
    } catch (Throwable $e) {}
    try {
        $db = getDB();
        $steps[] = 'Open admission enquiries: ' . (int)$db->query("SELECT COUNT(*) FROM admission_enquiries WHERE status NOT IN ('CLOSED','ENROLLED')")->fetchColumn();
    } catch (Throwable $e) {}
    try {
        $db = $db ?? getDB();
        $steps[] = 'Substitutions today: ' . (int)$db->query("SELECT COUNT(*) FROM daily_substitutions WHERE sub_date = CURDATE()")->fetchColumn();
    } catch (Throwable $e) {}
    hccAssistantRespond(200, ['ok' => true, 'reply' => [
        'title' => 'Live summary',
        'text' => $steps ? 'Here are the current figures from the system:' : 'I couldn\'t read the statistics right now. Please check the database connection.',
        'steps' => $steps,
        'links' => [['label' => 'Results Matrix', 'href' => 'results.php']],
        'followups' => ['What is the At-Risk Radar?', 'What is the passing percentage?'],
    ]]);
}

/* Topic scoring */
$topics = hccAssistantTopics();
$best = null;
$bestScore = 0;
foreach ($topics as $key => $t) {
    $score = 0;
    foreach ($t['keywords'] as $kw) {
        $kw = mb_strtolower($kw);
        if (mb_strpos($q, ' ' . $kw . ' ') !== false) {
            $score += 2 + substr_count($kw, ' ') * 2; // multi-word phrases weigh more
        } elseif (mb_strlen($kw) >= 4 && mb_strpos($q, $kw) !== false) {
            $score += 1; // partial / plural match
        }
    }
    if ($score > $bestScore) {
        $bestScore = $score;
        $best = $key;
    }
}

if ($best === null) {
    hccAssistantRespond(200, ['ok' => true, 'reply' => [
        'title' => 'I\'m not sure about that one',
        'text' => 'I can explain how the HCC system works. Try asking about one of these topics:',
        'steps' => [], 'links' => [],
        'followups' => ['Explain the system workflow', 'How do I import Excel?', 'What is the At-Risk Radar?', 'How do admission enquiries work?'],
    ]]);
}

$t = $topics[$best];
$links = [];
foreach ($t['links'] as $l) {
    if (preg_match('/^[a-z0-9_]+\.php$/', $l['href']) && is_file(__DIR__ . '/' . $l['href'])) {
        $links[] = $l;
    }
}
hccAssistantRespond(200, ['ok' => true, 'reply' => [
    'title' => $t['title'], 'text' => $t['text'], 'steps' => $t['steps'],
    'links' => $links, 'followups' => $t['followups'],
]]);
