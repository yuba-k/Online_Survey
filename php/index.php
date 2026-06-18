<?php
// =========================================================================
// 整理番号: 4 | ファイル名: index.php
// 担当者：酒匂 莉乃
// プログラム名：ホームページ制御プログラム
// =========================================================================

// セッション管理モジュールを読み込み
require_once 'auth.php';

// 共通DB接続・操作モジュールを読み込み
require_once 'db.php';

// セキュリティモジュールを読み込み
require_once 'security.php';

start_sess(); // セッション開始（auth.phpの関数を使用）

// 安全にHTMLエスケープを行うための共通関数
if (!function_exists('h')) {
    function h($string) {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }
}

// ユーザー状態の検知
$is_logged_in = isset($_SESSION['user_id']); 
$current_user_id = $is_logged_in ? (int)$_SESSION['user_id'] : null;

// サインアウト処理のハンドリング
if (isset($_GET['action']) && $_GET['action'] === 'signout') {
    del_sess();
    header("Location: index.php");
    exit;
}

// 退会処理のハンドリング
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_account') {
    if ($is_logged_in) {
        $delete_success = delete_user($current_user_id);
        if ($delete_success) {
            del_sess();
            header("Location: index.php");
            exit;
        } else {
            $withdraw_error = "退会処理に失敗しました。システム管理者に連絡してください。";
        }
    }
}

// 並べ替えパラメータ検知（URLから受け取る元のパラメータ）
$sort_type = isset($_GET['sort']) ? $_GET['sort'] : 'start';

// index.phpのパラメータを get_homepage_survey_list の $sortOrder 用に変換
$sort_order = '新着'; // 初期値（開始日時順）
if ($sort_type === 'deadline') {
    $sort_order = '開始期限'; // 回答期限が近い順
} elseif ($sort_type === 'responses') {
    $sort_order = '回答数'; // 回答数が多い順
}


// =========================================================================
// JavaScriptからの延長リクエストを処理するAPIロジック
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['api']) && $_GET['api'] === 'extend') {
    // 応答をJSON形式にする
    header('Content-Type: application/json; charset=utf-8');
    
    // 生のPOSTデータ（JSON）を取得してデコード
    $raw_input = file_get_contents('php://input');
    $input_data = json_decode($raw_input, true);
    
    if (!$is_logged_in || !isset($input_data['survey_id'])) {
        echo json_encode(['success' => false, 'message' => '認証エラーまたはパラメータ不足です。']);
        exit;
    }
    
    $target_survey_id = (int)$input_data['survey_id'];
    
    try {
        $pdo = isset($GLOBALS['db']) ? $GLOBALS['db'] : (function_exists('db_connect') ? db_connect() : null);
        
        if ($pdo) {
            // データベースの時間を10分進めるSQL（現在時刻と比較し、切れていれば今から10分後、期限内なら+10分）
            $sql = "UPDATE surveys 
                    SET end_at = DATE_ADD(IF(end_at > NOW(), end_at, NOW()), INTERVAL 10 MINUTE) 
                    WHERE survey_id = :survey_id AND creator_id = :creator_id";
            
            $stmt = $pdo->prepare($sql);
            $success = $stmt->execute([
                ':survey_id' => $target_survey_id,
                ':creator_id' => $current_user_id
            ]);
            
            if ($success && $stmt->rowCount() > 0) {
                // 更新後の最新の終了時刻を取得してJavaScript側に返却する
                $time_sql = "SELECT end_at FROM surveys WHERE survey_id = :survey_id";
                $time_stmt = $pdo->prepare($time_sql);
                $time_stmt->execute([':survey_id' => $target_survey_id]);
                $updated_survey = $time_stmt->fetch(PDO::FETCH_ASSOC);
                
                // クライアント側で表示しやすいようにフォーマット
                $new_time_formatted = date('Y.m.d H:i', strtotime($updated_survey['end_at']));
                
                echo json_encode([
                    'success' => true, 
                    'new_time' => $new_time_formatted,
                    'message' => '回答期限を10分間延長しました。'
                ]);
                exit;
            } else {
                echo json_encode(['success' => false, 'message' => 'データの更新に対象が見つかりません。']);
                exit;
            }
        }
        echo json_encode(['success' => false, 'message' => 'データベース接続エラー。']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'システムエラー: ' . $e->getMessage()]);
        exit;
    }
}


// ==========================================
// 100件ごとのページ分割制御ロジック
// ==========================================
$page_active = isset($_GET['p_act']) ? max(1, (int)$_GET['p_act']) : 1;
$page_result = isset($_GET['p_res']) ? max(1, (int)$_GET['p_res']) : 1;
$limit = 100; 

$offset_active = ($page_active - 1) * $limit;
$offset_result = ($page_result - 1) * $limit;

$created_surveys = [];
$answered_surveys = [];
$active_surveys = [];
$result_surveys = [];

try {
    // 【新関数への置き換え処理】
    if ($is_logged_in) {
        // ① 作成したアンケート（ユーザーIDが必要）
        $created_surveys  = get_homepage_survey_list('作成したアンケート', $sort_order, $current_user_id);
        // ② 回答したアンケート（ユーザーIDが必要）
        $answered_surveys = get_homepage_survey_list('回答したアンケート', $sort_order, $current_user_id);
    }
    
    // ③ アンケート (回答受付中、全ユーザー共通のためユーザーIDは null)
    $all_active_surveys = get_homepage_survey_list('アンケート', $sort_order, null);
    // ④ 調査結果 (期限終了分、全ユーザー共通のためユーザーIDは null)
    $all_result_surveys = get_homepage_survey_list('調査結果', $sort_order, null);

    // ページ分割用のarray_slice処理は既存のロジックをそのまま維持
    $total_active = count($all_active_surveys);
    $total_pages_active = ceil($total_active / $limit);
    $active_surveys = array_slice($all_active_surveys, $offset_active, $limit);

    $total_result = count($all_result_surveys);
    $total_pages_result = ceil($total_result / $limit);
    $result_surveys = array_slice($all_result_surveys, $offset_result, $limit);

} catch (Exception $e) {
    error_log("index.php データ抽出エラー: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ホームページ - 村上製作所 アンケートシステム</title>
    <style>
        body { font-family: 'Helvetica Neue', Arial, 'Hiragino Kaku Gothic ProN', 'Hiragino Sans', Meiryo, sans-serif; background-color: #f8fafc; color: #1e293b; margin: 0; padding: 0; line-height: 1.6; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; padding-top: 100px; } 
        
        /* ボタンベーススタイル */
        .btn { padding: 10px 20px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600; cursor: pointer; border: none; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .btn:active { transform: translateY(0); }
        
        /* カラーバリエーション */
        .btn-primary { background-color: #1e3a8a; color: white; }
        .btn-primary:hover { background-color: #1d4ed8; }
        .btn-secondary { background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .btn-secondary:hover { background-color: #e2e8f0; color: #334155; }
        .btn-danger { background-color: #dc2626; color: white; }
        .btn-danger:hover { background-color: #b91c1c; }
        .btn-withdraw { background-color: #ffffff; color: #dc2626; border: 1px solid #fca5a5; }
        .btn-withdraw:hover { background-color: #fef2f2; border-color: #ef4444; }
        
        /* カード内専用小ボタン */
        .btn-card { padding: 6px 12px; font-size: 12px; border-radius: 4px; font-weight: bold; flex: 1; text-align: center; }

        /* ガイドセクション */
        .guide-section { background-color: #ffffff; padding: 24px; border-radius: 8px; margin-bottom: 32px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; }
        .guide-steps { display: flex; justify-content: space-between; gap: 16px; margin-top: 20px; flex-wrap: wrap; }
        .guide-step { flex: 1; min-width: 180px; text-align: center; background-color: #f8fafc; padding: 20px 16px; border-radius: 6px; font-size: 13px; border: 1px solid #f1f5f9; }
        .guide-step-img { width: 44px; height: 44px; background-color: #e2e8f0; margin: 0 auto 12px auto; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 20px; color: #1e3a8a; }

        /* コントロールパネル */
        .auth-control-panel { background-color: #ffffff; padding: 20px 24px; border-radius: 8px; margin-bottom: 32px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; }
        .auth-buttons { display: flex; gap: 12px; flex-wrap: wrap; }

        /* セクションヘッダー・並べ替え */
        .survey-section { margin-bottom: 40px; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-top: 24px; margin-bottom: 16px; position: relative; padding-bottom: 8px; border-bottom: 2px solid #e2e8f0; }
        .section-header h3 { margin: 0; font-size: 18px; color: #1e3a8a; font-weight: 700; letter-spacing: 0.05em; }
        
        /* 横スクロールコンテナ */
        .scroll-container { display: flex; overflow-x: auto; overflow-y: hidden; white-space: nowrap; gap: 20px; padding: 4px 4px 16px 4px; scrollbar-width: thin; }
        
        /* カードUI */
        .card { flex: 0 0 290px; background-color: #ffffff; border-radius: 8px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; display: flex; flex-direction: column; justify-content: space-between; box-sizing: border-box; transition: transform 0.2s, box-shadow 0.2s; }
        .card:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05), 0 4px 6px -2px rgba(0,0,0,0.05); }
        .card-date { font-size: 12px; color: #64748b; margin-bottom: 8px; font-weight: 500; display: flex; align-items: center; flex-wrap: wrap; gap: 4px; }
        .card-title { font-size: 16px; font-weight: 700; color: #0f172a; margin: 0 0 12px 0; white-space: normal; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; height: 48px; line-height: 1.5; }
        .card-creator { font-size: 12px; color: #64748b; margin-bottom: 16px; overflow: hidden; text-overflow: ellipsis; }
        .card-actions { display: flex; gap: 8px; margin-top: auto; width: 100%; }

        /* カードアクション個別カラー指定 */
        .btn-extend { background-color: #e2e8f0; color: #1e293b; border: 1px solid #cbd5e1; }
        .btn-extend:hover { background-color: #cbd5e1; }
        .btn-result-view { background-color: #0284c7; color: white; }
        .btn-result-view:hover { background-color: #0369a1; }
        .btn-edit-view { background-color: #10b981; color: white; }
        .btn-edit-view:hover { background-color: #059669; }

        /* ページネーション */
        .pagination { display: flex; justify-content: center; align-items: center; gap: 6px; margin-top: 24px; padding: 0; list-style: none; }
        .page-item { display: inline; }
        .page-link { display: block; padding: 8px 14px; font-size: 13px; font-weight: 600; color: #4b5563; text-decoration: none; background-color: #fff; border: 1px solid #d1d5db; border-radius: 6px; transition: all 0.2s; }
        .page-link:hover { background-color: #f3f4f6; color: #1f2937; border-color: #cbd5e1; }
        .page-item.active .page-link { background-color: #1e3a8a; color: white; border-color: #1e3a8a; font-weight: bold; }

        /* 並べ替えポップアップ */
        .sort-container { position: relative; }
        .sort-popup { position: absolute; top: 100%; right: 0; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05); z-index: 120; padding: 6px 0; margin-top: 6px; display: none; }
        .sort-popup.show-popup { display: block; }
        .sort-option { display: block; width: 160px; padding: 10px 16px; font-size: 13px; color: #334155; text-align: left; background: none; border: none; cursor: pointer; font-weight: 500; }
        .sort-option:hover { background-color: #f1f5f9; color: #1e3a8a; }

        /* モーダル・ポップアップ表示 */
        .withdraw-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(15, 23, 42, 0.6); z-index: 400; display: <?php echo (isset($_GET['view']) && $_GET['view'] === 'withdraw') ? 'flex' : 'none'; ?>; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .withdraw-popup { background-color: #ffffff; padding: 32px; border-radius: 12px; width: 400px; max-width: 90%; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); text-align: center; border: 1px solid #e2e8f0; }
        .withdraw-message { font-size: 16px; font-weight: bold; color: #0f172a; margin-bottom: 24px; }
        .withdraw-buttons { display: flex; gap: 16px; justify-content: center; }

        /* トップに戻るボタン */
        .page-top-pink-btn { position: fixed; bottom: 32px; right: 32px; background-color: #ff4a8d; color: white; border: none; width: 48px; height: 48px; border-radius: 50%; font-size: 14px; font-weight: bold; cursor: pointer; box-shadow: 0 4px 14px rgba(255, 74, 141, 0.5); z-index: 150; transition: all 0.2s ease; display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1.1; }
        .page-top-pink-btn:hover { background-color: #ff2a75; transform: translateY(-4px); box-shadow: 0 6px 20px rgba(255, 74, 141, 0.6); }
        
        /* メンバーセクション */
        .member-section { background-color: #ffffff; padding: 24px; border-radius: 8px; margin-top: 48px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 32px; border: 1px solid #e2e8f0; }
        .member-leader { font-weight: 700; font-size: 15px; color: #1e3a8a; margin-bottom: 16px; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; letter-spacing: 0.05em; }
        .member-list { font-size: 14px; color: #475569; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .member-list span { font-weight: 500; color: #334155; }
    </style>
</head>
<body>

    <?php echo "<link rel='stylesheet' href='../css/footer.css'>"; ?>

    <?php include 'header.php'; ?>

    <div class="container">
        
        <div id="liveAlertBar" style="display: none; background-color: #ecfdf5; border: 1px solid #10b981; color: #065f46; padding: 14px 20px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; font-weight: bold; box-shadow: 0 2px 4px rgba(16, 185, 129, 0.1);">
            ✓ <span id="liveAlertText">ここにメッセージが入ります</span>
        </div>
        
        <section class="guide-section">
            <h2 style="margin-top: 0; font-size: 18px; color: #1e3a8a; font-weight: 700;">GUIDE ご利用方法</h2>
            <div class="guide-steps">
                <div class="guide-step">
                    <div class="guide-step-img">📋</div>
                    <strong>1. 調査要件の策定</strong>
                    <div style="margin-top: 8px; font-size: 11px; color: #64748b; line-height: 1.6; white-space: normal; text-align: left;">
                        アンケートの起案者は、事前に本システムへログインを完了した上で、調査目的の定義、設問数および選択肢の内部フォーマットの設計を厳密に行わなければなりません。収集すべきデータの属性を考慮し、対象ユーザーに不必要な負担を与えないよう、あらかじめ質問内容を精査・準備することが義務付けされています。
                    </div>
                </div>
                <div class="guide-step">
                    <div class="guide-step-img">📢</div>
                    <strong>2. 電磁的公示の実行</strong>
                    <div style="margin-top: 8px; font-size: 11px; color: #64748b; line-height: 1.6; white-space: normal; text-align: left;">
                        準備された調査要件に基づき、「アンケートフォーム作成」機能を使用してシステムへの登録処理を執り行います。タイトルや回答に要する想定所要時間を電算システムに入力し、確定操作を完了させた時点で、当システムを閲覧するすべての構成員に対して電磁的な公示および告知が自動的に執行されます。
                    </div>
                </div>
                <div class="guide-step">
                    <div class="guide-step-img">👥</div>
                    <strong>3. 回答権限の監視</strong>
                    <div style="margin-top: 8px; font-size: 11px; color: #64748b; line-height: 1.6; white-space: normal; text-align: left;">
                        公示されたアンケート案件は、システムによって設定された有効期限（end_at）に至るまで自動的にステータスが監視され、データ受付可能状態が維持されます。対象となる構成員は、それぞれの権限に基づいて該当レコードへアクセスし、付与された有効期限の枠内においてのみ電子的送信を行う権利を有します。
                    </div>
                </div>
                <div class="guide-step">
                    <div class="guide-step-img">💻</div>
                    <strong>4. 応答データの送信</strong>
                    <div style="margin-top: 8px; font-size: 11px; color: #64748b; line-height: 1.6; white-space: normal; text-align: left;">
                        各案件カードに配置された「回答する」リンクを押下すると、専用の応答データ入力フォームが展開されます。利用者は、展開されたフォームの所定記述欄に対し、客観的事実および自身の真実に基づいた適切なデータを遅滞なく入力した上で、送信シグナルをホストサーバーへ向けて実行してください。
                    </div>
                </div>
                <div class="guide-step">
                    <div class="guide-step-img">📊</div>
                    <strong>5. 統計情報の電算処理</strong>
                    <div style="margin-top: 8px; font-size: 11px; color: #64748b; line-height: 1.6; white-space: normal; text-align: left;">
                        データベースへ正常に格納され蓄積された応答レコードおよびデータログは、システム内部の集計モジュールによってリアルタイムに電算処理されます。処理された統計データは、「結果を見る」ボタンを経由することで、グラフおよび視覚的統計情報としていつでも安全に閲覧・検証を行うことが可能です。
                    </div>
                </div>
            </div>
        </section>

        <div class="auth-control-panel">
            <div>
                <?php if ($is_logged_in): ?>
                    <span style="font-size: 15px;">ログイン中: <strong style="color: #1e3a8a;"><?php echo h($_SESSION['account_name'] ?? '会員ユーザー'); ?></strong> 様</span>
                <?php else: ?>
                    <span style="font-size: 14px; color: #475569;">ゲストユーザー様 (ログインするとアンケート作成機能や回答履歴が解放されます)</span>
                <?php endif; ?>
            </div>
            <div class="auth-buttons">
                <?php if (!$is_logged_in): ?>
                    <a href="signup.php" class="btn btn-secondary">ユーザー登録</a>
                    <a href="signin.php" class="btn btn-primary">サインイン</a>
                <?php else: ?>
                    <a href="survey_form.php" class="btn btn-primary">アンケートフォーム作成</a>
                    <a href="profile.php" class="btn btn-secondary">ユーザー情報の変更</a>
                    <a href="index.php?view=withdraw" class="btn btn-withdraw">退会→</a>
                    <a href="index.php?action=signout" class="btn btn-danger">サインアウト</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($is_logged_in): ?>
            <section class="survey-section">
                <div class="section-header">
                    <h3>MY SURVEY 作成したアンケート</h3>
                    <div class="sort-container">
                        <button class="btn btn-secondary sort-trigger-btn" style="padding: 6px 14px; font-size: 13px;">並べ替え ▾</button>
                        <div class="sort-popup">
                            <button class="sort-option" data-sort-type="start">開始日時順</button>
                            <button class="sort-option" data-sort-type="deadline">回答期限が近い順</button>
                            <button class="sort-option" data-sort-type="responses">回答数が多い順</button>
                        </div>
                    </div>
                </div>
                <div class="scroll-container">
                    <?php if (empty($created_surveys)): ?>
                        <p style="font-size:13px; color:#64748b; padding:10px;">作成したアンケートはありません。</p>
                    <?php else: ?>
                        <?php foreach ($created_surveys as $survey): ?>
                            <div class="card" id="survey-card-<?php echo h($survey['survey_id']); ?>">
                                <div>
                                    <div class="card-date">
                                        <span class="live-date-text" id="date-box-<?php echo h($survey['survey_id']); ?>" style="color: #0f172a; font-weight: 600;">
                                            <?php echo h(date('Y.m.d H:i', strtotime($survey['end_at']))); ?>
                                        </span> まで <span style="color:#64748b; font-weight: normal;">(回答: <?php echo (int)($survey['response_count'] ?? 0); ?>件)</span>
                                    </div>
                                    <h4 class="card-title"><?php echo h($survey['title']); ?></h4>
                                </div>
                                <div class="card-actions">
                                    <button type="button" class="btn btn-secondary btn-card js-extend-btn btn-extend" 
                                            data-survey-id="<?php echo h($survey['survey_id']); ?>"
                                            data-survey-title="<?php echo h($survey['title']); ?>">
                                        +10分延長
                                    </button>
                                    <a href="result.php?id=<?php echo h($survey['survey_id']); ?>" class="btn btn-primary btn-card">結果</a>
                                    <a href="survey_form.php?id=<?php echo h($survey['survey_id']); ?>" class="btn btn-secondary btn-card btn-edit-view" style="border:none;">編集</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="survey-section">
                <div class="section-header">
                    <h3>MY SURVEY 回答したアンケート</h3>
                    <div class="sort-container">
                        <button class="btn btn-secondary sort-trigger-btn" style="padding: 6px 14px; font-size: 13px;">並べ替え ▾</button>
                        <div class="sort-popup">
                            <button class="sort-option" data-sort-type="start">開始日時順</button>
                            <button class="sort-option" data-sort-type="deadline">回答期限が近い順</button>
                        </div>
                    </div>
                </div>
                <div class="scroll-container">
                    <?php if (empty($answered_surveys)): ?>
                        <p style="font-size:13px; color:#64748b; padding:10px;">過去に回答したアンケートはありません。</p>
                    <?php else: ?>
                        <?php foreach ($answered_surveys as $survey): ?>
                            <div class="card">
                                <div>
                                    <div class="card-date" style="color: #0f172a; font-weight: 600;"><?php echo h(date('Y.m.d H:i', strtotime($survey['end_at']))); ?> まで</div>
                                    <h4 class="card-title"><?php echo h($survey['title']); ?></h4>
                                    <div class="card-creator">作成: <?php echo h($survey['creator_name'] ?? '不明'); ?></div>
                                </div>
                                <div class="card-actions">
                                    <a href="result.php?id=<?php echo h($survey['survey_id']); ?>" class="btn btn-primary btn-card" style="flex:1;">結果</a>
                                    <a href="question.php?id=<?php echo h($survey['survey_id']); ?>&mode=edit" class="btn btn-secondary btn-card" style="flex:1;">編集</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="survey-section">
            <div class="section-header">
                <h3>SURVEY アンケート (回答受付中)</h3>
                <div class="sort-container">
                    <button class="btn btn-secondary sort-trigger-btn" style="padding: 6px 14px; font-size: 13px;">並べ替え ▾</button>
                    <div class="sort-popup">
                        <button class="sort-option" data-sort-type="start">開始日時順</button>
                        <button class="sort-option" data-sort-type="deadline">回答期限が近い順</button>
                        <button class="sort-option" data-sort-type="responses">回答数が多い順</button>
                    </div>
                </div>
            </div>
            <div class="scroll-container">
                <?php if (empty($active_surveys)): ?>
                    <p style="font-size:13px; color:#64748b; padding:10px;">現在、受付中のアンケートはありません。</p>
                <?php else: ?>
                    <?php foreach ($active_surveys as $survey): ?>
                        <?php 
                            $spec = !empty($survey['survey_spec']) ? json_decode($survey['survey_spec'], true) : [];
                            $required_time = isset($spec['Estimated_time']) ? (int)$spec['Estimated_time'] : 0; 
                        ?>
                        <div class="card">
                            <div>
                                <div class="card-date">
                                    <span id="public-date-box-<?php echo h($survey['survey_id']); ?>" style="color: #0f172a; font-weight: 600;">
                                        <?php echo h(date('Y.m.d H:i', strtotime($survey['end_at']))); ?>
                                    </span> まで 
                                    <?php if ($required_time > 0): ?>
                                        <span style="background-color:#fee2e2; color:#dc2626; font-weight:bold; padding: 2px 6px; border-radius: 4px; margin-left:6px; font-size:11px;">所要: <?php echo $required_time; ?>分</span>
                                    <?php endif; ?>
                                </div>
                                <h4 class="card-title"><?php echo h($survey['title']); ?></h4>
                                <div class="card-creator">作成: <?php echo h($survey['creator_name'] ?? '不明'); ?></div>
                            </div>
                            <div class="card-actions">
                                <a href="question.php?id=<?php echo h($survey['survey_id']); ?>" class="btn btn-primary btn-card" style="flex:1;">回答する</a>
                                <a href="result.php?id=<?php echo h($survey['survey_id']); ?>" class="btn btn-secondary btn-card" style="flex:1;">結果を見る</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <?php if ($total_pages_active > 1): ?>
                <ul class="pagination">
                    <?php for ($i = 1; $i <= $total_pages_active; $i++): ?>
                        <li class="page-item <?php echo $page_active === $i ? 'active' : ''; ?>">
                            <a class="page-link" href="index.php?p_act=<?php echo $i; ?>&p_res=<?php echo $page_result; ?>&sort=<?php echo h($sort_type); ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="survey-section">
            <div class="section-header">
                <h3>Results 調査結果</h3>
                <div class="sort-container">
                    <button class="btn btn-secondary sort-trigger-btn" style="padding: 6px 14px; font-size: 13px;">並べ替え ▾</button>
                    <div class="sort-popup">
                        <button class="sort-option" data-sort-type="start">開始日時順</button>
                        <button class="sort-option" data-sort-type="deadline">回答期限が近い順</button>
                        <button class="sort-option" data-sort-type="responses">回答数が多い順</button>
                    </div>
                </div>
            </div>
            <div class="scroll-container">
                <?php if (empty($result_surveys)): ?>
                    <p style="font-size:13px; color:#64748b; padding:10px;">過去ログデータはありません。</p>
                <?php else: ?>
                    <?php foreach ($result_surveys as $survey): ?>
                        <div class="card">
                            <div>
                                <div class="card-date" style="color: #64748b;">期限終了: <?php echo h(date('Y.m.d H:i', strtotime($survey['end_at']))); ?></div>
                                <h4 class="card-title"><?php echo h($survey['title']); ?></h4>
                                <div class="card-creator">作成: <?php echo h($survey['creator_name'] ?? '不明'); ?></div>
                            </div>
                            <div class="card-actions">
                                <a href="question.php?id=<?php echo h($survey['survey_id']); ?>" class="btn btn-secondary btn-card" style="flex:1;">回答する</a>
                                <a href="result.php?id=<?php echo h($survey['survey_id']); ?>" class="btn btn-primary btn-card btn-result-view" style="flex:1; border: none;">結果を見る</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($total_pages_result > 1): ?>
                <ul class="pagination">
                    <?php for ($i = 1; $i <= $total_pages_result; $i++): ?>
                        <li class="page-item <?php echo $page_result === $i ? 'active' : ''; ?>">
                            <a class="page-link" href="index.php?p_act=<?php echo $page_active; ?>&p_res=<?php echo $i; ?>&sort=<?php echo h($sort_type); ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="member-section">
            <div class="member-leader">MEMBER メンバー ［役職: 社長 村上悠］</div>
            <div class="member-list">
                <span>吉守祥</span> | <span>中城大志</span> | <span>野元悠惺</span> |
                <span>湯場崎啓心</span> | <span>前田凱南</span> | <span>折本敢太</span> | 
                <span>酒匂莉乃</span> | <span>丸山夕渚</span> | <span>用貝有基</span>
            </div>
        </section>
    </div>

    <div class="withdraw-overlay" id="withdrawOverlay">
        <div class="withdraw-popup">
            <p class="withdraw-message">本当に退会しますか？</p>
            <form action="index.php" method="POST" class="withdraw-buttons">
                <input type="hidden" name="action" value="delete_account">
                <a href="index.php" class="btn btn-secondary" style="flex: 1;">戻る</a>
                <button type="submit" class="btn btn-danger" style="flex: 1; box-shadow: none;">退会</button>
            </form>
        </div>
    </div>

    <button class="page-top-pink-btn">▲<br><span style="font-size: 10px; font-weight: bold;">TOP</span></button>

    <footer>
        &copy; 2026 村上製作所 アンケート管理システム 
        <a href="terms.php">利用規約</a> | <a href="privacy.php">プライバシーポリシー</a>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // ==========================================
            // 非同期通信の延長ロジック
            // ==========================================
            const extendButtons = document.querySelectorAll('.js-extend-btn');
            const alertBar = document.getElementById('liveAlertBar');
            const alertText = document.getElementById('liveAlertText');

            extendButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    const surveyId = this.dataset.surveyId;
                    const surveyTitle = this.dataset.surveyTitle;
                    
                    // 連打した際に裏側で確実に処理するため、FetchAPIで自画面のAPIモードへ送信
                    fetch('index.php?api=extend&sort=<?php echo urlencode($sort_type); ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ survey_id: surveyId })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // 画面をリロードせず、そのカードの終了時刻テキストを即座に書き換え
                            const dateBox = document.getElementById(`date-box-${surveyId}`);
                            if (dateBox) dateBox.textContent = data.new_time;
                            
                            // 層3側にも同じアンケートがあれば同期
                            const publicDateBox = document.getElementById(`public-date-box-${surveyId}`);
                            if (publicDateBox) publicDateBox.textContent = data.new_time;

                            // 画面最上部に「延長完了」のUIバーを表示
                            alertText.textContent = `「${surveyTitle}」の回答期限を10分間延長しました。（最新期限: ${data.new_time}）`;
                            alertBar.style.display = 'block';
                            
                            // 少し経ったら自動で消える
                            setTimeout(() => {
                                // 別のボタンを連打している可能性も考慮して徐々にフェードアウトなど
                            }, 4000);
                        } else {
                            alert('延長処理に失敗しました: ' + data.message);
                        }
                    })
                    .catch(err => {
                        console.error('通信エラー:', err);
                        alert('システムエラーが発生しました。');
                    });
                });
            });

            // 以下、既存の並べ替え等のスクリプト仕様
            const sortTriggerButtons = document.querySelectorAll('.sort-trigger-btn');
            sortTriggerButtons.forEach(button => {
                button.addEventListener('click', (event) => {
                    document.querySelectorAll('.sort-popup').forEach(p => {
                        if (p !== button.nextElementSibling) p.classList.remove('show-popup');
                    });
                    const activeSortPopup = button.nextElementSibling;
                    activeSortPopup.classList.toggle('show-popup');
                    event.stopPropagation();
                });
            });

            const sortOptions = document.querySelectorAll('.sort-option');
            sortOptions.forEach(option => {
                option.addEventListener('click', function() {
                    const sortType = this.dataset.sortType;
                    const urlParams = new URLSearchParams(window.location.search);
                    urlParams.set('sort', sortType);
                    window.location.href = 'index.php?' + urlParams.toString();
                });
            });

            document.addEventListener('click', () => {
                document.querySelectorAll('.sort-popup').forEach(p => p.classList.remove('show-popup'));
            });

            const scrollTopButton = document.querySelector('.page-top-pink-btn');
            if (scrollTopButton) {
                scrollTopButton.addEventListener('click', () => {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                });
            }
        });
    </script>
    <?php require_once "footer.php"; ?>
</body>
</html>