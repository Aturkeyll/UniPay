<?php
require 'db.php';
require 'lib_student_auth.php';
header('Content-Type: application/json');

/**
 * AI Payment Agent
 * ------------------
 * Students ask in plain language ("what do I owe?", "when is my club fee due?"),
 * we fetch their real outstanding items from the DB, then hand that data + the
 * question to Claude to produce a friendly, accurate natural-language answer.
 *
 * Important: the model is only ever given the student's OWN data, already
 * filtered server-side by student_number; it never has free rein over the DB,
 * so it can't leak another student's payment info even if asked to.
 *
 * That scoping is only as good as the identity check in front of it. This
 * endpoint returns pay_link URLs containing payment tokens, so accepting a bare
 * 7-digit number meant the ID space could be walked for working payment links.
 * A matching surname is now required, rate-limited per IP.
 */

$input = json_decode(file_get_contents('php://input'), true);
$studentNumber = trim($input['student_number'] ?? '');
$surname       = trim($input['surname'] ?? '');
$question      = trim($input['question'] ?? '');

$pdo = getDb();

try {
    $student = verifyStudent($studentNumber, $surname);
} catch (StudentAuthRateLimited $e) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

if (!$student) {
    // Deliberately the same message whether the number is unknown or the
    // surname is wrong, distinguishing them confirms valid student numbers.
    echo json_encode(['success' => false, 'error' => STUDENT_AUTH_GENERIC_ERROR]);
    exit;
}

// Cap question length: this string reaches a paid API, so an unbounded field
// is both a cost and an abuse vector.
if (mb_strlen($question) > 500) {
    $question = mb_substr($question, 0, 500);
}

// Pull only this student's outstanding items
$stmt = $pdo->prepare(
    "SELECT pl.id, i.name AS item_name, pl.amount, pl.due_date, pl.status, pl.token
     FROM payment_links pl
     JOIN items i ON i.id = pl.item_id
     WHERE pl.student_id = ? AND pl.status IN ('pending', 'overdue')
     ORDER BY pl.due_date ASC"
);
$stmt->execute([$student['id']]);
$outstanding = $stmt->fetchAll();

$contextData = array_map(function ($o) {
    return [
        'item' => $o['item_name'],
        'amount_aud' => (float)$o['amount'],
        'due_date' => $o['due_date'],
        'overdue' => $o['due_date'] && strtotime($o['due_date']) < time(),
        'pay_link' => "https://yourdomain.com/pay.php?token=" . $o['token'],
    ];
}, $outstanding);

$systemPrompt = "You are a helpful payments assistant for {$student['first_name']}'s university student union. "
    . "You are given ONLY this student's own outstanding payment data as JSON. "
    . "Answer their question using only this data. Never invent amounts or items. "
    . "If they owe nothing, say so cheerfully. Keep answers short (2-4 sentences). "
    . "Include specific dollar amounts and due dates when relevant, and mention "
    . "the payment link when suggesting they pay something.\n\n"
    . "Student's outstanding items: " . json_encode($contextData);

if (empty($question)) {
    $question = "What do I currently owe?";
}

// --- Call the Claude API. Requires ANTHROPIC_API_KEY set in your environment. ---
$apiKey = getenv('ANTHROPIC_API_KEY');
if (!$apiKey) {
    // Fallback so the demo still works without a key configured
    if (empty($contextData)) {
        $answer = "You have no outstanding payments right now. 🎉";
    } else {
        $lines = array_map(function ($o) {
            $due = $o['due_date'] ? " (due {$o['due_date']}" . ($o['overdue'] ? ", overdue" : "") . ")" : "";
            return "- {$o['item']}: \${$o['amount_aud']} AUD{$due}";
        }, $contextData);
        $answer = "Here's what you currently owe:\n" . implode("\n", $lines);
    }
    echo json_encode(['success' => true, 'answer' => $answer, 'outstanding' => $contextData, 'mode' => 'fallback']);
    exit;
}

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 300,
        'system' => $systemPrompt,
        'messages' => [
            ['role' => 'user', 'content' => $question],
        ],
    ]),
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo json_encode(['success' => false, 'error' => 'AI agent unavailable, please try again.']);
    exit;
}

$data = json_decode($response, true);
$answer = $data['content'][0]['text'] ?? "Sorry, I couldn't generate a response.";

logAction('student', $student['id'], 'agent_query', null, null, $question);

echo json_encode(['success' => true, 'answer' => $answer, 'outstanding' => $contextData, 'mode' => 'live']);
