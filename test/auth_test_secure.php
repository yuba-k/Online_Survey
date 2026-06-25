<?php
require_once '../php/auth.php';

// ログインチェックを実行
login_check();

// ※テスト用にタイムアウトを強制発動させたい場合は、以下のコメントアウトを解除してください
 $_SESSION['last_acc'] = time() - 4000; login_check(); 
?>
<!DOCTYPE html>
<html lang="ja">
<head><title>検証3：ログイン限定ページ</title></head>
<body>
    <h1>ログインチェック成功！</h1>
    <p>この画面が見えているということは、正常にログイン状態が維持されています。</p>
    <p>ユーザーID: <?php echo $_SESSION['user_id']; ?></p>
    <p><a href="auth_test_input.php">入力画面に戻る</a></p>
</body>
</html>