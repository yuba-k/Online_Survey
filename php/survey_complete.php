<?php
require_once 'db.php';
require_once 'security.php';
require_once __DIR__ . '/error.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ------------------------------
   CSRF チェック
------------------------------ */
$posted_token  = $_POST['csrf_token'] ?? '';
$session_token = $_SESSION['csrf_token'] ?? '';

if (empty($posted_token) || $posted_token !== $session_token) {
    renderError('403 Forbidden: 不正なリクエストです。', 403, 'app', 'WARNING');
}

/* ------------------------------
   セッションから入力データ取得
------------------------------ */
$input = $_SESSION['survey_input'] ?? null;
if (!$input) {
    renderError('アンケート情報が見つかりません。最初からやり直してください。', 400, 'app', 'WARNING');
}

$edit_mode = !empty($_POST['edit_mode']) || !empty($_SESSION['survey_edit_mode']);
$survey_id = isset($_POST['survey_id']) && $_POST['survey_id'] !== ''
    ? (int)$_POST['survey_id']
    : (!empty($_SESSION['survey_edit_id']) ? (int)$_SESSION['survey_edit_id'] : 0);
$survey_key = $_POST['survey_key'] ?? ($_SESSION['survey_edit_key'] ?? '');

try {

    /* ------------------------------
       作成者ID（ログインユーザー）
    ------------------------------ */
    $creator_id = (int)($_SESSION['user_id'] ?? 0);
    if ($creator_id <= 0) {
        throw new RuntimeException('ログインユーザーが不明です。');
    }

    /* ------------------------------
       タイトル
    ------------------------------ */
    $title = (string)($input['title'] ?? '');

    /* ------------------------------
       開始・終了日時（空なら NULL）
    ------------------------------ */
    $start = !empty($input['start_at']) ? $input['start_at'] : null;
    $end   = !empty($input['end_at'])   ? $input['end_at']   : null;

    /* ------------------------------
       survey_spec を組み立てる
    ------------------------------ */
    $spec = [
        'description' => (string)($input['description'] ?? ''),
        'Survey_tag'  => !empty($input['tags'])
            ? array_map('trim', explode(',', $input['tags']))
            : [],
        'questions'   => [],
    ];

    /* ------------------------------
       質問を詰める
    ------------------------------ */
    if (!empty($input['q_label']) && is_array($input['q_label'])) {
        foreach ($input['q_label'] as $idx => $label) {

            $spec['questions'][] = [
                'label'          => (string)$label,
                'type'           => (string)($input['q_type'][$idx] ?? 'single'),
                'options'        => array_values($input['q_option'][$idx] ?? []),
                'result_display' => (string)($input['q_result_display'][$idx] ?? 'bar'),
            ];
        }
    }

    /* ------------------------------
       DB 登録
    ------------------------------ */
    if ($edit_mode && $survey_id > 0) {
        update_survey($survey_id, [
            'title' => $title,
            'survey_spec' => $spec,
            'start_at' => $start,
            'end_at' => $end,
        ]);
        $question_key = $survey_key;
    } else {
        $question_key = insert_survey(
            $creator_id,
            $title,
            $spec,
            $start,
            $end
        );
    }

    /* ------------------------------
       一時データ削除
    ------------------------------ */
    unset($_SESSION['survey_input']);
    unset($_SESSION['survey_edit_mode']);
    unset($_SESSION['survey_edit_id']);
    unset($_SESSION['survey_edit_key']);

} catch (Throwable $e) {

    renderError(
        '500 Internal Server Error: アンケート登録中にエラーが発生しました。',
        500,
        'db',
        'ERROR',
        $e,
        'Survey Complete Error'
    );
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>アンケート作成 - 完了</title>
    <!-- ヘッダー・フッター用のCSS読み込み -->
    <link rel="stylesheet" href="../css/reset.css">
    <link rel="stylesheet" href="../css/footer.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* 背景と全体のレイアウト */
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            background-color: #1e2d5a;
            color: #ffffff;
            display: flex;
            flex-direction: column;
        }
        main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            margin-top: 64px; /* ヘッダーの高さ分下げる */
        }
        /* header用の強制CSS（配置修正済み） */
        header.w-full.bg-\[\#1e3a8a\] {
            background-color: #1E3A8A !important;
            height: 64px !important;
            padding-left: 24px !important;
            padding-right: 24px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            width: 100% !important;
        }
        header .fa-house, header .fa-bell { color: #ffffff !important; font-size: 26px !important; }
        header .font-bold { color: #ffffff !important; font-size: 22px !important; font-weight: bold !important; }
        header input#survey-search { background: #ffffff !important; border-radius: 8px !important; padding: 8px 12px !important; color: #333 !important; width: 220px !important; border: none !important; outline: none !important; }
        
        /* 完了画面特有のポップアップアニメーション */
        @keyframes popIn {
            0% { transform: scale(0.5); opacity: 0; }
            70% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }
        @keyframes buttonFadeUp {
            0% { opacity: 0; transform: translateY(12px) scale(0.98); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }
        .animate-pop-in {
            animation: popIn 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
        }
        .animate-button-pop {
            animation: buttonFadeUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) both;
            animation-delay: 0.08s;
        }
        .survey-complete-button {
            transition: transform 0.18s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.18s ease, filter 0.18s ease;
            transform: translateY(0);
            will-change: transform;
        }
        .survey-complete-button:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.22);
            filter: brightness(1.05);
        }
        .survey-complete-button:active {
            transform: translateY(-1px) scale(0.99);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.16);
        }
    </style>
</head>

<body>
    <?php include 'header.php'; ?>

    <main>
        <!-- 中央の完了メッセージカード -->
        <div class="bg-[#24376F] p-10 rounded-2xl shadow-2xl border border-white/10 w-full max-w-lg mx-4 my-8 text-center animate-pop-in">
            
            <!-- 成功アイコン（緑色の丸にチェックマーク） -->
            <div class="w-24 h-24 bg-emerald-500 rounded-full flex items-center justify-center mx-auto mb-6 shadow-[0_0_20px_rgba(16,185,129,0.4)]">
                <i class="fa-solid fa-check text-5xl text-white"></i>
            </div>
            
            <h1 class="text-3xl font-bold mb-4 text-white tracking-wide">作成が完了しました！</h1>
            
            <p class="text-slate-300 mb-10 leading-relaxed text-lg">
                新しいアンケートが正常に登録されました。<br>
                さっそく回答の収集を開始できます。
            </p>

            <!-- ボタンエリア -->
            <div class="flex flex-col sm:flex-row justify-center items-center gap-4">
                
                <!-- ホームへ戻るボタン -->
                <div class="animate-button-pop w-full sm:w-auto">
                    <a href="index.php" 
                       class="w-full px-8 py-3 bg-slate-600 hover:bg-slate-500 text-white font-bold rounded-xl shadow-md focus:outline-none focus:ring-2 focus:ring-slate-400 flex items-center justify-center survey-complete-button">
                        <i class="fa-solid fa-house mr-2"></i> ホームへ戻る
                    </a>
                </div>

            </div>
        </div>
    </main>

    <?php include 'footer.php'; ?>
</body>
</html>
