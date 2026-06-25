<?php
// 作成された関数ファイルを読み込む
require_once '../php/auth.php';
start_sess();

// テスト用に強制的にログイン状態を作る（user_idを保存）
// ※未ログイン状態をテストしたい場合は、下の1行をコメントアウトしてください
//$_SESSION['user_id'] = 999;

// タイムアウト検証用に現在時刻を記録
$_SESSION['last_acc'] = time();

// CSRFトークンを発行
$csrf_token = generate_csrf();
?>
<!DOCTYPE html>
<html lang="ja">
<head><title>検証1：入力画面</title></head>
<body>
    <h1>アンケート入力画面（ログイン中）</h1>
    <p>現在のセッションID: <?php echo session_id(); ?></p>
    <p>発行されたCSRFトークン: <?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?></p>

    <!-- 通常の正常送信テスト -->
    <form action="auth_test_conf.php" method="POST" style="margin-bottom: 20px;">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <label>質問1：<input type="text" name="answers[q1]" value="テスト回答"></label>
        <button type="submit">正常に確認画面へ進む</button>
    </form>

    <!-- 不正なCSRFトークンを送信するテスト用フォーム -->
    <form action="auth_test_conf.php" method="POST">
        <input type="hidden" name="csrf_token" value="FAKE_TOKEN_12345">
        <input type="hidden" name="answers[q1]" value="不正送信テスト">
        <button type="submit" style="color: red;">【テスト】わざと不正なトークンで送信する</button>
    </form>

    <hr>
    <p><a href="auth_test_secure.php">ログインチェックが必要な秘密のページへ行く</a></p>
    <p><a href="auth_test_del.php" style="color: gray;">ログアウトする（セッション破棄のテスト）</a></p>
</body>
</html>