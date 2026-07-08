<?php
    require_once "db.php";
    require_once "auth.php";
    require_once "security.php";
    start_sess();
?>

<!DOCTYPE html>
<html lang="jp">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ユーザ情報変更</title>
    <link rel='stylesheet' href='../css/footer.css'>
    <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'>
    <script src='https://cdn.tailwindcss.com'></script>
</head>
<body>
    <main>
        <h1>ユーザ情報変更</h1>
        <?php if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["change"])):?>
            <!-- 変更ボタン押下後：セッションのユーザ名で照合するため現在のパスワード確認フォーム -->
            <form method="post" action="" id="verify">
                <label for="">現在のパスワード：
                    <input type="password" name="current_password" required>
                </label>
                <button type="submit" name="verify">確認</button>
            </form>
        <?php elseif($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["verify"])):?>
            <!-- セッションに保存されたユーザ名からDB検索してパスワード検証 -->
            <?php
                $username = $_SESSION['username'] ?? null;
                $pw_ok = false;
                if($username){
                    $user = get_user_by_name($username);
                    if($user && isset($user['password_hash'])){
                        $pw_ok = password_verify($_POST['current_password'] ?? '', $user['password_hash']);
                    }
                }
            ?>
            <?php if(!$pw_ok): ?>
                <p>現在のパスワードが違います。</p>
                <form method="post" action="" id="verify">
                    <label for="">現在のパスワード：
                        <input type="password" name="current_password" required>
                    </label>
                    <button type="submit" name="verify">確認</button>
                </form>
            <?php else: ?>
                <!-- 検証成功：ユーザ情報を変更するための入力フォーム  -->
                <form method="post" action="" id="confirm">
                    <label for="">ユーザ名：
                        <input type="text" name="newusername" value="<?php echo htmlspecialchars($_SESSION['username'], ENT_QUOTES); ?>">
                    </label>
                    <label for="">新しいパスワード：
                        <input type="password" name="newpassword">
                    </label>
                    <label for="">もう一度入力：
                        <input type="password" name="newpassword_cheack">
                    </label>
                    <button type="submit" name="confirm">確定</button>
                </form>
            <?php endif; ?>
        <?php elseif($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["confirm"])):?>
            <!-- 入力データのチェック  -->
            <?php if(($_POST["newpassword"] ?? '') !== ($_POST["newpassword_cheack"] ?? '')):?>
                <!-- パスワードの不一致  -->
                <form method="post" action="" id="confirm">
                    <label for="">ユーザ名：
                        <input type="text" name="newusername" value="<?php echo htmlspecialchars($_POST['newusername'] ?? '', ENT_QUOTES); ?>">
                    </label>
                    <label for="">新しいパスワード：
                        <input type="password" name="newpassword">
                        <p>パスワードが不一致です</p>
                    </label>
                    <label for="">もう一度入力：
                        <input type="password" name="newpassword_cheack">
                    </label>
                    <button type="submit" name="confirm">確定</button>
                </form>
            <?php else:?>
                <!--  パスワードが一致入力  -->
                <?php
                    $newusername = trim($_POST['newusername'] ?? '');
                    // ユーザ名の検証
                    if(!checkWord($newusername)){
                        // 不正なユーザ名が含まれている場合はエラーメッセージを出して再表示
                        ?>
                        <form method="post" action="" id="confirm">
                            <label for="">ユーザ名：
                                <input type="text" name="newusername" value="<?php echo htmlspecialchars($newusername, ENT_QUOTES); ?>">
                            </label>
                            <label for="">新しいパスワード：
                                <input type="password" name="newpassword">
                                <p>ユーザ名に不正な文字が含まれています</p>
                            </label>
                            <label for="">もう一度入力：
                                <input type="password" name="newpassword_cheack">
                            </label>
                            <button type="submit" name="confirm">確定</button>
                        </form>
                        <?php
                    } else {// ユーザ名が正常な場合はDB更新処理
                        $r = update_user($_SESSION["user_id"], $newusername, password_hash($_POST["newpassword"],PASSWORD_DEFAULT));
                        if($r){
                            $user_id = $_SESSION['user_id'];
                            del_sess();
                            start_sess();
                            $_SESSION['user_id'] = $user_id;
                            $_SESSION['username'] = $newusername;
                            $_SESSION['last_acc'] = time();
                            echo "<script>setTimeout(() => {
                                location.href = '/php/index.php';
                                }, 0); </script>";
                        }else{
                            echo "失敗";
                        }
                    }
                ?>
            <?php endif?>
        <?php else:?>
            <p>ユーザ名：<?php echo $_SESSION["username"] ?></p>
            <p>パスワード：******</p>
            <form method="post" action="">
                <button type="submit" name="change">変更する</button>
            </form>
        <?php endif?>
    </main>
    <?php include "footer.php"?>
</body>
</html>