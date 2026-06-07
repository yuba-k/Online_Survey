<?php
// ===============================================================
// survey_form.php
// アンケート作成・編集（入力 → 確認 → 登録）
// ===============================================================

session_start();
require_once 'auth.php';
require_login(); // creator_id を取得するため必須

require_once 'db.php';
require_once 'security.php';
require_once 'log.php';

// ---------------------------------------------------------------
// モード判定
// ---------------------------------------------------------------
$mode = $_POST['mode'] ?? 'input';
$errors = [];

// 編集モードの場合
$edit_survey_id = $_GET['survey_id'] ?? null;
$editing = false;
$loaded_survey = null;

if ($edit_survey_id) {
    $editing = true;
    $loaded_survey = executeQuery(
        "SELECT * FROM surveys WHERE survey_id = :id",
        [':id' => $edit_survey_id]
    )->fetch();

    if ($loaded_survey) {
        $loaded_survey['survey_spec'] = decodeJson($loaded_survey['survey_spec']);
    }
}

// ---------------------------------------------------------------
// 入力チェック（確認画面へ進むとき）
// ---------------------------------------------------------------
if ($mode === 'confirm') {

    // タイトル
    $title = trim($_POST['title'] ?? '');
    if (!checkWord($title, 100)) {
        $errors[] = "タイトルに禁止文字または文字数超過があります。";
    }

    // 説明文
    $description = trim($_POST['description'] ?? '');
    if (!checkWord($description, 300)) {
        $errors[] = "説明文に禁止文字または文字数超過があります。";
    }

    // 期間
    $start_at = $_POST['start_at'] ?? '';
    $end_at   = $_POST['end_at'] ?? '';
    if (!$start_at || !$end_at) {
        $errors[] = "開始日と終了日は必須です。";
    }

    // 集計設定
    $aggregate_gender = isset($_POST['aggregate_gender']);
    $aggregate_age = isset($_POST['aggregate_age']);
    $aggregate_gender_split = isset($_POST['aggregate_gender_split']);

    // 質問
    $questions = [];
    if (isset($_POST['questions']) && is_array($_POST['questions'])) {
        foreach ($_POST['questions'] as $q) {

            $q_text = trim($q['text'] ?? '');
            if (!checkWord($q_text, 200)) {
                $errors[] = "質問文に禁止文字または文字数超過があります。";
            }

            $q_type = $q['type'] ?? 'single_choice';
            $q_display = $q['result_display'] ?? 'bar';

            // 選択肢
            $opts = [];
            if (isset($q['options']) && is_array($q['options'])) {
                foreach ($q['options'] as $opt) {
                    $opt = trim($opt);
                    if ($opt !== '') {
                        if (!checkWord($opt, 100)) {
                            $errors[] = "選択肢に禁止文字または文字数超過があります。";
                        }
                        $opts[] = $opt;
                    }
                }
            }

            $questions[] = [
                'id' => generateUuid(),
                'text' => $q_text,
                'type' => $q_type,
                'options' => $opts,
                'result_display' => $q_display
            ];
        }
    }

    if (empty($questions)) {
        $errors[] = "質問は1つ以上必要です。";
    }

    // エラーがあれば入力画面へ戻す
    if (!empty($errors)) {
        writeLog('survey_form', 'WARN', '入力エラー: ' . implode(',', $errors));
        $mode = 'input';
    }

} elseif ($mode === 'register') {

    // -----------------------------------------------------------
    // 登録処理
    // -----------------------------------------------------------
    try {
        $creator_id = $_SESSION['user_id'];

        $title = $_POST['title'];
        $description = $_POST['description'];
        $start_at = $_POST['start_at'];
        $end_at = $_POST['end_at'];

        $spec = [
            'description' => $description,
            'aggregate' => [
                'gender' => isset($_POST['aggregate_gender']),
                'age' => isset($_POST['aggregate_age']),
                'gender_split' => isset($_POST['aggregate_gender_split'])
            ],
            'questions' => json_decode($_POST['questions_json'], true)
        ];

        if ($editing) {
            update_survey((int)$edit_survey_id, [
                'title' => $title,
                'survey_spec' => $spec,
                'start_at' => $start_at,
                'end_at' => $end_at
            ]);
            writeLog('survey_form', 'INFO', "アンケート更新成功 ID={$edit_survey_id}");
            header("Location: index.php");
            exit;
        } else {
            $question_key = insert_survey($creator_id, $title, $spec, $start_at, $end_at);
            writeLog('survey_form', 'INFO', "アンケート作成成功 key={$question_key}");
            header("Location: index.php");
            exit;
        }

    } catch (Throwable $e) {
        writeLog('survey_form', 'ERROR', $e->getMessage());
        $errors[] = "登録中にエラーが発生しました。";
        $mode = 'input';
    }
}

// ---------------------------------------------------------------
// HTML 表示
// ---------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>アンケート作成</title>
<style>
.question-block { border:1px solid #ccc; padding:10px; margin:10px 0; }
.option-block { margin-left:20px; }
</style>
</head>
<body>

<?php include 'header.php'; ?>

<div style="margin-top:80px; max-width:900px; margin-left:auto; margin-right:auto;">

<h1>アンケート作成</h1>

<?php if (!empty($errors)): ?>
<div style="color:red;">
    <?php foreach ($errors as $e): ?>
        <p><?= htmlspecialchars($e) ?></p>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($mode === 'input'): ?>

<form method="post">

    <input type="hidden" name="mode" value="confirm">

    <label>タイトル：</label><br>
    <input type="text" name="title" value="<?= htmlspecialchars($loaded_survey['title'] ?? '') ?>" style="width:100%;"><br><br>

    <label>説明文：</label><br>
    <textarea name="description" style="width:100%; height:80px;"><?= htmlspecialchars($loaded_survey['survey_spec']['description'] ?? '') ?></textarea><br><br>

    <label>開始日：</label>
    <input type="date" name="start_at" value="<?= htmlspecialchars($loaded_survey['start_at'] ?? '') ?>">
    <label>終了日：</label>
    <input type="date" name="end_at" value="<?= htmlspecialchars($loaded_survey['end_at'] ?? '') ?>"><br><br>

    <h3>集計設定</h3>
    <label><input type="checkbox" name="aggregate_gender" <?= !empty($loaded_survey['survey_spec']['aggregate']['gender']) ? 'checked' : '' ?>> 性別</label>
    <label><input type="checkbox" name="aggregate_age" <?= !empty($loaded_survey['survey_spec']['aggregate']['age']) ? 'checked' : '' ?>> 年齢</label>
    <label><input type="checkbox" name="aggregate_gender_split" <?= !empty($loaded_survey['survey_spec']['aggregate']['gender_split']) ? 'checked' : '' ?>> 男女別集計</label>

    <h3>質問一覧</h3>

    <div id="questions-area"></div>

    <button type="button" onclick="addQuestion()">＋ 質問を追加</button>

    <br><br>
    <button type="submit">確認画面へ</button>

</form>

<script>
let qIndex = 0;

function addQuestion(existing=null) {
    const area = document.getElementById('questions-area');

    const block = document.createElement('div');
    block.className = 'question-block';
    block.dataset.index = qIndex;

    block.innerHTML = `
        <label>質問文：</label><br>
        <input type="text" name="questions[${qIndex}][text]" style="width:100%;" value="${existing?.text ?? ''}"><br><br>

        <label>質問タイプ：</label>
        <select name="questions[${qIndex}][type]">
            <option value="single_choice">単一選択</option>
            <option value="multiple_choice">複数選択</option>
            <option value="text">自由記述</option>
        </select><br><br>

        <label>結果表示タイプ：</label>
        <select name="questions[${qIndex}][result_display]">
            <option value="bar">棒グラフ</option>
            <option value="pie">円グラフ</option>
            <option value="line">折れ線</option>
            <option value="table">表形式</option>
        </select><br><br>

        <div class="option-block">
            <label>選択肢：</label>
            <div class="options"></div>
            <button type="button" onclick="addOption(${qIndex})">＋ 選択肢追加</button>
        </div>

        <button type="button" onclick="this.parentNode.remove()">この質問を削除</button>
    `;

    area.appendChild(block);
    qIndex++;
}

function addOption(qi) {
    const opts = document.querySelector(`.question-block[data-index="${qi}"] .options`);
    const opt = document.createElement('div');
    opt.innerHTML = `<input type="text" name="questions[${qi}][options][]" style="width:80%;"> <button type="button" onclick="this.parentNode.remove()">削除</button>`;
    opts.appendChild(opt);
}
</script>

<?php endif; ?>

<?php if ($mode === 'confirm'): ?>

<h2>確認画面</h2>

<form method="post">
    <input type="hidden" name="mode" value="register">

    <p><strong>タイトル：</strong> <?= htmlspecialchars($title) ?></p>
    <input type="hidden" name="title" value="<?= htmlspecialchars($title) ?>">

    <p><strong>説明文：</strong><br><?= nl2br(htmlspecialchars($description)) ?></p>
    <input type="hidden" name="description" value="<?= htmlspecialchars($description) ?>">

    <p><strong>期間：</strong> <?= htmlspecialchars($start_at) ?> ～ <?= htmlspecialchars($end_at) ?></p>
    <input type="hidden" name="start_at" value="<?= htmlspecialchars($start_at) ?>">
    <input type="hidden" name="end_at" value="<?= htmlspecialchars($end_at) ?>">

    <h3>集計設定</h3>
    <ul>
        <li>性別：<?= $aggregate_gender ? 'ON' : 'OFF' ?></li>
        <li>年齢：<?= $aggregate_age ? 'ON' : 'OFF' ?></li>
        <li>男女別集計：<?= $aggregate_gender_split ? 'ON' : 'OFF' ?></li>
    </ul>

    <input type="hidden" name="aggregate_gender" value="<?= $aggregate_gender ? 1 : 0 ?>">
    <input type="hidden" name="aggregate_age" value="<?= $aggregate_age ? 1 : 0 ?>">
    <input type="hidden" name="aggregate_gender_split" value="<?= $aggregate_gender_split ? 1 : 0 ?>">

    <h3>質問一覧</h3>
    <ul>
        <?php foreach ($questions as $q): ?>
            <li>
                <strong><?= htmlspecialchars($q['text']) ?></strong><br>
                タイプ：<?= htmlspecialchars($q['type']) ?><br>
                表示：<?= htmlspecialchars($q['result_display']) ?><br>
                <?php if (!empty($q['options'])): ?>
                    選択肢：<?= implode(', ', array_map('htmlspecialchars', $q['options'])) ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <input type="hidden" name="questions_json" value='<?= json_encode($questions, JSON_UNESCAPED_UNICODE) ?>'>

    <button type="submit">登録する</button>
    <button type="button" onclick="history.back()">戻る</button>

</form>

<?php endif; ?>

</div>

</body>
</html>
