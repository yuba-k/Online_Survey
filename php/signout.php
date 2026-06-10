<?php
// signout.php
// サインアウト処理：セッションを破棄しトップページへリダイレクトします。

require_once 'auth.php';

// auth.php の del_sess() を呼んでセッションを完全に破棄する
del_sess();

// トップページへリダイレクト（php/ からの相対移動）
header('Location: ../index.php');
exit;
