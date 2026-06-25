<?php
require_once '../php/auth.php';

// セッションを完全に破壊
del_sess();
?>
<!DOCTYPE html>
<html lang="ja">
<head><title>検証4：ログアウト</title></head>
<body>
    <h1>ログアウト（セッション破棄）しました</h1>
    <p>ブラウザのデベロッパーツール等でCookie（PHPSESSID）が消えているか確認してください。</p>
    <p><a href="auth_test_secure.php">もう一度秘密のページにアクセスしてみる（サインイン画面に飛ばされるかテスト）</a></p>
    <p><a href="auth_test_input.php">入力画面に戻る</a></p>
</body>
</html>