<?php
require_once '../php/auth.php';

// フォームから送られてきたトークン
$post_token = $_POST['csrf_token'] ?? '';

// 作成されたCSRFチェック関数を実行
// ※ここで不正なトークンなら die() して処理が止まります
check_csrf($post_token);

// 正常な場合のみセッションに保存して画面表示
start_sess();
$_SESSION['answers'] = $_POST['answers'] ?? [];
?>
<!DOCTYPE html>
<html lang="ja">
<head><title>検証2：確認画面</title></head>
<body>
    <h1>CSRFチェック成功！確認画面</h1>
    <p>送信された回答：<?php echo htmlspecialchars($_SESSION['answers']['q1'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
    <p><a href="auth_test_input.php">入力画面に戻る</a></p>
</body>
</html>