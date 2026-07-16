<?php
session_start();

// POST のアンケートデータを受け取る
$data = $_POST;
$edit_mode = isset($data['edit_mode']) && $data['edit_mode'] === '1';
$survey_id = isset($data['survey_id']) && $data['survey_id'] !== '' ? (int)$data['survey_id'] : 0;
$survey_key = $data['survey_key'] ?? '';

// セッションに一時保存（完了画面で使う）
$_SESSION['survey_input'] = $data;
$_SESSION['survey_edit_mode'] = $edit_mode;
$_SESSION['survey_edit_id'] = $survey_id;
$_SESSION['survey_edit_key'] = $survey_key;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>アンケート作成 - 確認</title>
    <link rel="stylesheet" href="../css/reset.css">
    <link rel="stylesheet" href="../css/footer.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
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
            justify-content: center;
            align-items: flex-start;
            padding: 2rem;
            margin-top: 64px;
        }
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
    </style>
</head>

<body>
    <?php include 'header.php'; ?>

    <main>
        <div class="bg-[#24376F] p-8 rounded-2xl shadow-2xl border border-white/10 w-full max-w-2xl mx-4 my-8">
            
            <h1 class="text-3xl font-bold mb-8 text-center text-white">アンケート内容の確認</h1>

           <div class="space-y-8 mb-8 text-white">
                <?php foreach (($data['q_label'] ?? []) as $i => $label): ?>
                    <div class="border-b border-white/10 pb-8 last:border-none">
                        <div class="inline-flex items-center px-3 py-1 mb-4 rounded-full bg-blue-500/20 text-blue-300 font-semibold text-lg">
                            質問 <?= $i?>
                        </div>
                        <div class="border-l-4 border-blue-400 pl-4 py-2 bg-white/5 rounded-r-lg mt-2">
                            <p class="text-2xl font-semibold text-white leading-relaxed">
                                <?= htmlspecialchars($label) ?>
                            </p>
                        </div>
                        <?php if (!empty($data['q_option'][$i])): ?>
                            <div class="mt-5 space-y-3">
                                <?php foreach ($data['q_option'][$i] as $opt): ?>
                                    <div class="flex items-center bg-white/5 rounded-lg px-4 py-3 hover:bg-white/10 transition">
                                        <div class="w-2.5 h-2.5 rounded-full bg-emerald-400 mr-4"></div>
                                        <span class="text-lg text-slate-200">
                                            <?= htmlspecialchars($opt) ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <form action="survey_complete.php" method="POST">
                <?php
                function renderHiddenFields($array, $prefix = '') {
                    foreach ($array as $key => $value) {
                        $name = $prefix === '' ? $key : $prefix . '[' . $key . ']';
                        if (is_array($value)) {
                            renderHiddenFields($value, $name);
                        } else {
                            echo '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">' . "\n";
                        }
                    }
                }
                renderHiddenFields($data);
                ?>

                <div class="flex flex-col sm:flex-row justify-between items-center gap-4 mt-8">
                    <button type="submit" 
                            name="is_revision"
                            value="1"
                            formaction="survey_form.php" 
                            class="w-full sm:w-auto px-8 py-3 bg-slate-600 hover:bg-slate-500 text-white font-bold rounded-xl transition-all duration-200 shadow-md text-center cursor-pointer focus:outline-none focus:ring-2 focus:ring-slate-400">
                        修正する
                    </button>
                    <button type="submit" 
                            class="w-full sm:w-auto px-8 py-3 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl transition-all duration-200 shadow-md text-center cursor-pointer focus:outline-none focus:ring-2 focus:ring-emerald-400">
                        この内容で作成する
                    </button>
                </div>
            </form>

        </div>
    </main>

    <?php include 'footer.php'; ?>
</body>
</html>