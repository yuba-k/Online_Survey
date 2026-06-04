<?php
// ============================================================
// survey_form.php  アンケート作成・編集画面処理（db.php対応版）
// ============================================================

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';

session_start();

// ------------------------------------------------------------
// 1. ログインチェック
// ------------------------------------------------------------
$user = require_login(); // 未ログインならリダイレクト

// ------------------------------------------------------------
// 2. 編集モード判定
// ------------------------------------------------------------
$editing    = false;
$survey     = null;
$surveySpec = null;

if (isset($_GET['id']) && ctype_digit($_GET['id'])) {
    $editing   = true;
    $survey_id = (int)$_GET['id'];

    // db.php の関数を使って取得
    $survey = get_survey_by_id($survey_id);

    if (!$survey || $survey['creator_id'] !== $user['user_id']) {
        header('Location: index.php');
        exit;
    }

    $surveySpec = json_decode($survey['survey_spec'], true);
} else {
    if (isset($_SESSION['saved_survey'])) {
        $surveySpec = $_SESSION['saved_survey'];
    }
}

// ------------------------------------------------------------
// 3. POST 送信時（確認画面へ）
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF チェック
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        die('不正なリクエストです。');
    }

    // -----------------------------
    // 3-1. 入力値取得 & サニタイズ
    // -----------------------------
    $title       = sanitize($_POST['title'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $is_public   = isset($_POST['is_public']);

    // datetime-local → ISO8601(+09:00)
    $start_raw = $_POST['start_at'] ?? '';
    $end_raw   = $_POST['end_at'] ?? '';

    $start_at = $start_raw ? $start_raw . ':00+09:00' : '';
    $end_at   = $end_raw ? $end_raw . ':00+09:00' : '';

    // 集計設定
    $agg_gender       = isset($_POST['agg_gender']);
    $agg_age          = isset($_POST['agg_age']);
    $agg_gender_split = isset($_POST['agg_gender_split']);

    // 質問
    $questions = [];
    if (!empty($_POST['questions'])) {
        foreach ($_POST['questions'] as $q) {
            $qid   = $q['id'] ?? bin2hex(random_bytes(16));
            $qtype = $q['type'] ?? '';
            $qtext = sanitize($q['text'] ?? '');

            $entry = [
                'id'   => $qid,
                'type' => $qtype,
                'text' => $qtext,
            ];

            if ($qtype === 'single_choice' || $qtype === 'multiple_choice') {
                $opts = $q['options'] ?? [];
                $opts = array_map('sanitize', $opts);
                $opts = array_values(array_filter($opts, fn($v) => $v !== ''));
                $entry['options'] = $opts;
            }

            $questions[] = $entry;
        }
    }

    // -----------------------------
    // 3-2. survey_spec JSON 構築
    // -----------------------------
    $survey_spec = [
        'title'       => $title,
        'description' => $description,
        'is_public'   => $is_public,
        'period'      => [
            'start' => $start_at,
            'end'   => $end_at,
        ],
        'aggregate'   => [
            'gender'       => $agg_gender,
            'age'          => $agg_age,
            'gender_split' => $agg_gender_split,
        ],
        'questions'   => $questions,
    ];

    // -----------------------------
    // 3-3. バリデーション
    // -----------------------------
    $errors = [];

    if ($title === '') {
        $errors[] = 'タイトルは必須です。';
    } elseif (mb_strlen($title) > 255) {
        $errors[] = 'タイトルは255文字以内で入力してください。';
    }

    if (!$start_at || !$end_at) {
        $errors[] = '公開期間は必須です。';
    } elseif (strtotime($start_at) >= strtotime($end_at)) {
        $errors[] = '公開期間の開始日時は終了日時より前である必要があります。';
    }

    if (count($questions) === 0) {
        $errors[] = '質問は1つ以上必要です。';
    }

    foreach ($questions as $i => $q) {
        if ($q['text'] === '') {
            $errors[] = '質問' . ($i + 1) . 'の質問文は必須です。';
        }
        if ($q['type'] === '') {
            $errors[] = '質問' . ($i + 1) . 'のタイプは必須です。';
        }
        if (($q['type'] === 'single_choice' || $q['type'] === 'multiple_choice')
            && empty($q['options'])) {
            $errors[] = '質問' . ($i + 1) . 'の選択肢は1つ以上必要です。';
        }
    }

    if (!empty($errors)) {
        $_SESSION['form_errors']  = $errors;
        $_SESSION['saved_survey'] = $survey_spec;

        $redirect = $editing ? "survey_form.php?id={$survey_id}" : "survey_form.php";
        header("Location: $redirect");
        exit;
    }

    // -----------------------------
    // 3-4. 確認画面へ渡す
    // -----------------------------
    $_SESSION['confirm_survey']    = $survey_spec;
    $_SESSION['editing_survey_id'] = $editing ? $survey_id : null;

    header('Location: confirm_survey.php');
    exit;
}

// ------------------------------------------------------------
// 4. 画面表示用の初期値セット
// ------------------------------------------------------------
$view = [
    'title'       => $surveySpec['title']       ?? '',
    'description' => $surveySpec['description'] ?? '',
    'is_public'   => $surveySpec['is_public']   ?? false,
    'start_at'    => '',
    'end_at'      => '',
    'aggregate'   => [
        'gender'       => $surveySpec['aggregate']['gender']       ?? false,
        'age'          => $surveySpec['aggregate']['age']          ?? false,
        'gender_split' => $surveySpec['aggregate']['gender_split'] ?? false,
    ],
    'questions'   => $surveySpec['questions']   ?? [],
];

// datetime-local 形式に変換
if (!empty($surveySpec['period']['start'])) {
    $view['start_at'] = date('Y-m-d\TH:i', strtotime($surveySpec['period']['start']));
}
if (!empty($surveySpec['period']['end'])) {
    $view['end_at'] = date('Y-m-d\TH:i', strtotime($surveySpec['period']['end']));
}

$errors = $_SESSION['form_errors'] ?? [];
unset($_SESSION['form_errors']);

$csrf_token = generate_csrf_token();

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title><?= $editing ? 'アンケート編集' : 'アンケート作成' ?></title>
</head>
<body>

<?php include __DIR__ . '/header.php'; ?>

<h1><?= $editing ? 'アンケート編集' : 'アンケート作成' ?></h1>

<?php if (!empty($errors)): ?>
    <div style="color:red;">
        <ul>
            <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

    <label>タイトル</label><br>
    <input type="text" name="title" size="60"
           value="<?= htmlspecialchars($view['title']) ?>"><br><br>

    <label>説明文</label><br>
    <textarea name="description" rows="4" cols="60"><?= htmlspecialchars($view['description']) ?></textarea><br><br>

    <label>
        <input type="checkbox" name="is_public" <?= $view['is_public'] ? 'checked' : '' ?>>
        公開する
    </label><br><br>

    <label>公開期間</label><br>
    <input type="datetime-local" name="start_at" value="<?= $view['start_at'] ?>">
    〜
    <input type="datetime-local" name="end_at" value="<?= $view['end_at'] ?>"><br><br>

    <h2>集計設定</h2>
    <label><input type="checkbox" name="agg_gender" <?= $view['aggregate']['gender'] ? 'checked' : '' ?>> 性別で集計</label><br>
    <label><input type="checkbox" name="agg_age" <?= $view['aggregate']['age'] ? 'checked' : '' ?>> 年齢で集計</label><br>
    <label><input type="checkbox" name="agg_gender_split" <?= $view['aggregate']['gender_split'] ? 'checked' : '' ?>> 男女別で集計</label><br><br>

    <h2>質問</h2>
    <div id="question-area">
        <?php foreach ($view['questions'] as $q): ?>
            <?php
            $qid   = htmlspecialchars($q['id']);
            $qtext = htmlspecialchars($q['text']);
            $qtype = $q['type'];
            ?>
            <div class="question-block">
                <hr>
                <input type="hidden" name="questions[<?= $qid ?>][id]" value="<?= $qid ?>">

                <label>質問文</label><br>
                <input type="text" name="questions[<?= $qid ?>][text]" size="60" value="<?= $qtext ?>"><br>

                <label>タイプ</label><br>
                <select name="questions[<?= $qid ?>][type]">
                    <option value="single_choice"   <?= $qtype === 'single_choice' ? 'selected' : '' ?>>単一選択</option>
                    <option value="multiple_choice" <?= $qtype === 'multiple_choice' ? 'selected' : '' ?>>複数選択</option>
                    <option value="text"            <?= $qtype === 'text' ? 'selected' : '' ?>>自由記述</option>
                </select><br>

                <?php if ($qtype !== 'text'): ?>
                    <label>選択肢</label><br>
                    <?php foreach ($q['options'] as $opt): ?>
                        <input type="text" name="questions[<?= $qid ?>][options][]" value="<?= htmlspecialchars($opt) ?>"><br>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <button type="button" onclick="addQuestion()">質問を追加</button><br><br>

    <button type="submit">確認画面へ</button>
</form>

<script>
function addQuestion() {
    const area = document.getElementById('question-area');
    const id   = crypto.randomUUID();

    const div = document.createElement('div');
    div.className = 'question-block';
    div.innerHTML = `
        <hr>
        <input type="hidden" name="questions[${id}][id]" value="${id}">
        <label>質問文</label><br>
        <input type="text" name="questions[${id}][text]" size="60"><br>
        <label>タイプ</label><br>
        <select name="questions[${id}][type]">
            <option value="single_choice">単一選択</option>
            <option value="multiple_choice">複数選択</option>
            <option value="text">自由記述</option>
        </select><br>
        <label>選択肢（選択式の場合）</label><br>
        <input type="text" name="questions[${id}][options][]" placeholder="選択肢1"><br>
        <input type="text" name="questions[${id}][options][]" placeholder="選択肢2"><br>
    `;
    area.appendChild(div);
}
</script>

</body>
</html>
