<?php
ini_set('display_errors', 0);
error_reporting(0);

// =========================================================================
// 1. 独立した認証チェック
// =========================================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// =========================================================================
// 2. パラメータの取得とバリデーション
// =========================================================================
if (!isset($_GET['key']) || empty($_GET['key'])) {
    http_response_code(400);
    echo "400 Bad Request: 不正なアンケートキーです。";
    exit;
}

if (!isset($_GET['format']) || !in_array($_GET['format'], ['csv', 'pdf'], true)) {
    http_response_code(400);
    echo "400 Bad Request: 不正なフォーマット指定です。";
    exit;
}

$key = trim($_GET['key']); // 前後の余計な空白を削除
$format = $_GET['format'];
$current_user_id = (int)$_SESSION['user_id'];

// =========================================================================
// 3. 独立したデータベース接続
// =========================================================================
$dsn = getenv('DB_DSN') ?: 'pgsql:dbname=group1db;options=--client_encoding=UTF8';
$db_user = getenv('DB_USER') ?: 'group1';
$db_pass = getenv('DB_PASS') ?: 'Group1';

try {
    $db = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo "500 Internal Server Error: データベース接続に失敗しました。";
    exit;
}

// =========================================================================
// 4. アンケートIDの取得（判定をより柔軟に強化）
// =========================================================================
try {
    // ⭕️ 修正：文字列の完全一致(LIKE含む)や、大文字小文字の違い、UUIDキャストに幅広く対応
    $sql = "SELECT survey_id FROM surveys 
            WHERE question_key::text = :key 
               OR result_key::text = :key 
               OR question_key::text LIKE :key_like
               OR result_key::text LIKE :key_like 
            LIMIT 1";
            
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':key' => $key,
        ':key_like' => '%' . $key . '%'
    ]);
    $survey = $stmt->fetch();

    if (!$survey) {
        http_response_code(400);
        // デバッグしやすくするため、受け取ったキーを画面に出します
        echo "400 Bad Request: 該当するアンケートが見つかりません。(送信されたキー: " . htmlspecialchars($key) . ")";
        exit;
    }

    $survey_id = (int)$survey['survey_id'];
} catch (Exception $e) {
    http_response_code(500);
    echo "500 Internal Server Error: アンケート情報の取得中にエラーが発生しました。";
    exit;
}

// =========================================================================
// 5. データ集計（LEFT JOINでアカウント名を取得）
// =========================================================================
try {
    $sql = 'SELECT r.response_id, r.user_id, r.answer_data, r.answered_at, u.account_name 
            FROM responses r 
            LEFT JOIN users u ON r.user_id = u.user_id 
            WHERE r.survey_id = :survey_id 
            ORDER BY r.answered_at ASC';
    
    $stmt = $db->prepare($sql);
    $stmt->execute([':survey_id' => $survey_id]);
    $results = $stmt->fetchAll();

    if (empty($results)) {
        // 回答データが1件もない場合の受け皿
        header('Content-Description: File Transfer'); 
        header('Content-Type: text/csv; charset=utf-8'); 
        header('Content-Disposition: attachment; filename="survey_result_' . $survey_id . '_empty.csv"'); 
        echo "\xEF\xBB\xBF回答データがまだありません。";
        exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo "500 Internal Server Error: データ取得中にエラーが発生しました。";
    exit;
}

// =========================================================================
// 6. フォーマット別の出力制御（CSV）
// =========================================================================
if ($format === 'csv') {
    header('Content-Description: File Transfer'); 
    header('Content-Transfer-Encoding: binary'); 
    header('Content-Type: text/csv; charset=utf-8'); 
    header('Content-Disposition: attachment; filename="survey_result_' . $survey_id . '.csv"'); 

    echo "\xEF\xBB\xBF"; 
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['回答番号', 'ユーザーID', '回答内容', '回答日時'], ",", '"', "");

    $count = 1;
    foreach ($results as $row) {
        $answer_array = [];
        if (!empty($row['answer_data'])) {
            if (is_string($row['answer_data'])) {
                $answer_array = json_decode($row['answer_data'], true) ?? [];
            } else {
                $answer_array = $row['answer_data'];
            }
        }

        $readable_answers = [];
        if (is_array($answer_array)) {
            foreach ($answer_array as $q_key => $a_val) {
                if (is_array($a_val)) {
                    $a_val = implode(', ', $a_val);
                }
                $a_val = str_replace(["\r\n", "\r", "\n"], " ", (string)$a_val);
                $readable_answers[] = "{$q_key}: {$a_val}";
            }
        }
        
        $answer_text = !empty($readable_answers) ? implode(' | ', $readable_answers) : '回答なし';
        $formatted_date = !empty($row['answered_at']) ? date('Y/m/d H:i', strtotime($row['answered_at'])) : '未回答';
        $user_display_id = !empty($row['account_name']) ? $row['account_name'] : '匿名(未ログイン)';
            
        fputcsv($output, [
            $count,
            $user_display_id,
            $answer_text, 
            $formatted_date
        ], ",", '"', "");

        $count++;
    }
    fclose($output);
    exit;
}

// PDF出力
if ($format === 'pdf') {
    $text = "Survey Report (Survey ID: " . $survey_id . ")\n";
    $text .= "---------------------------------------------------------------------------------\n";
    $format_string = " %-12s | %-16s | %s\n";
    $text .= sprintf($format_string, "USER ID", "ANSWER DATE", "ANSWERS");
    $text .= "---------------------------------------------------------------------------------\n";
    
    foreach ($results as $row) {
        $user_display_id = !empty($row['account_name']) ? (string)$row['account_name'] : 'Guest';
        $formatted_date = !empty($row['answered_at']) ? date('Y/m/d H:i', strtotime($row['answered_at'])) : 'Unknown';

        $answer_array = [];
        if (!empty($row['answer_data'])) {
            if (is_string($row['answer_data'])) {
                $answer_array = json_decode($row['answer_data'], true) ?? [];
            } else {
                $answer_array = $row['answer_data'];
            }
        }

        $readable_answers = [];
        if (is_array($answer_array)) {
            foreach ($answer_array as $q_key => $a_val) {
                if (is_array($a_val)) { $a_val = implode(', ', $a_val); }
                $a_val = str_replace(["\r\n", "\r", "\n", "(", ")"], " ", (string)$a_val);
                if (preg_match('/[ぁ-んァ-ヶー一-龠]/u', $a_val)) { $a_val = '[JP Answer]'; }
                $readable_answers[] = "{$q_key}:{$a_val}";
            }
        }
        $answer_text = !empty($readable_answers) ? implode('  ', $readable_answers) : 'None';

        $text .= sprintf($format_string, $user_display_id, $formatted_date, $answer_text);
    }
    $text .= "---------------------------------------------------------------------------------\n";
    
    $stream = "BT\n/F1 10 Tf\n40 800 Td\n14 TL\n";
    foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $line) {
        $stream .= "(" . addcslashes($line, '()\\') . ") Tj T*\n";
    }
    $stream .= "ET";
    
    $chunks = [];
    $chunks[0] = "%PDF-1.4\n";
    $chunks[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    $chunks[2] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
    $chunks[3] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Courier >> >> >> >>\nendobj\n";
    $chunks[4] = "4 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream\nendobj\n";

    $offsets = [];
    $pdf_data = $chunks[0];
    for ($i = 1; $i <= 4; $i++) {
        $offsets[$i] = strlen($pdf_data);
        $pdf_data .= $chunks[$i];
    }
    $xref_pos = strlen($pdf_data);
    $pdf_data .= "xref\n0 5\n0000000000 65535 f \n";
    for ($i = 1; $i <= 4; $i++) { $pdf_data .= sprintf("%010d 00000 n \n", $offsets[$i]); }
    $pdf_data .= "trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n" . $xref_pos . "\n%%EOF";

    header('Content-Description: File Transfer');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="survey_report_' . $survey_id . '.pdf"');
    echo $pdf_data;
    exit;
}