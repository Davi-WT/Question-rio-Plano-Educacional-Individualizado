<?php
// questionario.php
declare(strict_types=1);

/**
 * Requisitos:
 * - questoes.json na mesma pasta deste arquivo
 * - (opcional) pasta "respostas" com permissão de escrita para salvar respostas
 */

mb_internal_encoding("UTF-8");

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

function safe_slug(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s);
    $s = trim($s ?? '', '-');
    return $s ?: 'questionario';
}

$baseDir = __DIR__;
$jsonFile = $baseDir . DIRECTORY_SEPARATOR . "questoes.json";

if (!file_exists($jsonFile)) {
    http_response_code(500);
    echo "Arquivo questoes.json não encontrado em: " . h($jsonFile);
    exit;
}

$raw = file_get_contents($jsonFile);
$data = json_decode($raw, true);

if (!is_array($data) || !isset($data["questoes"]) || !is_array($data["questoes"])) {
    http_response_code(500);
    echo "JSON inválido. Verifique o formato do questoes.json.";
    exit;
}

$questoes = $data["questoes"];
$escalaDefault = $data["escala"] ?? [
    ["value" => 0, "label" => "Nunca ou Raramente"],
    ["value" => 1, "label" => "Às vezes"],
    ["value" => 2, "label" => "Frequentemente"],
    ["value" => 3, "label" => "Sempre"],
];

// Lista de instrumentos disponíveis
$instrumentos = [];
foreach ($questoes as $q) {
    $inst = $q["instrumento"] ?? "Sem instrumento";
    $instrumentos[$inst] = true;
}
$instrumentos = array_keys($instrumentos);
sort($instrumentos, SORT_LOCALE_STRING);

// Instrumento selecionado (GET)
$instrumentoSelecionado = $_GET["instrumento"] ?? ($instrumentos[0] ?? "");
$instrumentoSelecionado = is_string($instrumentoSelecionado) ? $instrumentoSelecionado : "";

// Filtra questões do instrumento
$questoesFiltradas = array_values(array_filter($questoes, function ($q) use ($instrumentoSelecionado) {
    return ($q["instrumento"] ?? "") === $instrumentoSelecionado;
}));

// POST: processa respostas
$salvo = false;
$arquivoSalvo = null;
$resumo = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $instrumentoPost = $_POST["instrumento"] ?? "";
    $instrumentoPost = is_string($instrumentoPost) ? $instrumentoPost : "";

    // Re-filtra conforme o instrumento do POST (segurança)
    $questoesDoPost = array_values(array_filter($questoes, function ($q) use ($instrumentoPost) {
        return ($q["instrumento"] ?? "") === $instrumentoPost;
    }));

    $respostas = [];
    $faltando = [];

    foreach ($questoesDoPost as $q) {
        $id = (string)($q["id"] ?? "");
        if ($id === "") continue;

        $campo = "resp_" . $id;
        if (!isset($_POST[$campo]) || $_POST[$campo] === "") {
            $faltando[] = $id;
            continue;
        }
        $val = $_POST[$campo];
        // Força inteiro válido
        if (!is_numeric($val)) {
            $faltando[] = $id;
            continue;
        }
        $respostas[$id] = (int)$val;
    }

    $resumo = [
        "instrumento" => $instrumentoPost,
        "respondido_em" => date("c"),
        "total_questoes" => count($questoesDoPost),
        "respondidas" => count($respostas),
        "faltando" => $faltando,
        "respostas" => $respostas,
    ];

    // Salvar em arquivo (opcional)
    $dirResp = $baseDir . DIRECTORY_SEPARATOR . "respostas";
    if (is_dir($dirResp) && is_writable($dirResp)) {
        $slug = safe_slug($instrumentoPost);
        $nome = $slug . "_" . date("Ymd_His") . ".json";
        $path = $dirResp . DIRECTORY_SEPARATOR . $nome;

        $payload = json_encode($resumo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($payload !== false && file_put_contents($path, $payload) !== false) {
            $salvo = true;
            $arquivoSalvo = $nome;
        }
    }

    // Mantém selecionado
    $instrumentoSelecionado = $instrumentoPost;
    $questoesFiltradas = $questoesDoPost;
}

// Agrupa por faixa etária e dimensão (para ficar organizado)
$grupos = [];
foreach ($questoesFiltradas as $q) {
    $faixa = $q["faixa_etaria"] ?? "Sem faixa etária";
    $dim = $q["dimensao"] ?? "Sem dimensão";
    if (!isset($grupos[$faixa])) $grupos[$faixa] = [];
    if (!isset($grupos[$faixa][$dim])) $grupos[$faixa][$dim] = [];
    $grupos[$faixa][$dim][] = $q;
}

?><!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Questionários</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .q-card { border: 1px solid #e9ecef; border-radius: 12px; padding: 14px; }
    .q-meta { font-size: .9rem; color: #6c757d; }
    .sticky-top-2 { position: sticky; top: 0; z-index: 1030; background: #fff; }
  </style>
</head>
<body class="bg-light">

<div class="sticky-top-2 border-bottom">
  <div class="container py-3">
    <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
      <div>
        <h1 class="h4 mb-1">Questionários</h1>
        <div class="text-secondary">Selecione um instrumento e preencha as questões.</div>
      </div>

      <form method="get" class="d-flex align-items-center gap-2">
        <label for="instrumento" class="form-label mb-0 fw-semibold">Questionário:</label>
        <select class="form-select" id="instrumento" name="instrumento" onchange="this.form.submit()">
          <?php foreach ($instrumentos as $inst): ?>
            <option value="<?= h($inst) ?>" <?= ($inst === $instrumentoSelecionado ? "selected" : "") ?>>
              <?= h($inst) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <noscript><button class="btn btn-primary">Carregar</button></noscript>
      </form>
    </div>
  </div>
</div>

<div class="container py-4">

  <?php if ($resumo !== null): ?>
    <div class="alert alert-info">
      <div class="fw-semibold mb-1">Respostas recebidas ✅</div>
      <div>
        Instrumento: <b><?= h($resumo["instrumento"]) ?></b><br>
        Respondidas: <b><?= (int)$resumo["respondidas"] ?></b> / <?= (int)$resumo["total_questoes"] ?>
        <?php if (!empty($resumo["faltando"])): ?>
          <br><span class="text-danger">Faltando: <?= h(implode(", ", $resumo["faltando"])) ?></span>
        <?php endif; ?>
        <?php if ($salvo): ?>
          <br><span class="text-success">Arquivo salvo em <code>respostas/<?= h($arquivoSalvo ?? "") ?></code></span>
        <?php else: ?>
          <br><span class="text-secondary">Para salvar automaticamente, crie a pasta <code>respostas/</code> com permissão de escrita.</span>
        <?php endif; ?>
      </div>
      <details class="mt-2">
        <summary>Ver JSON gerado (resumo)</summary>
        <pre class="mt-2 mb-0"><?= h(json_encode($resumo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
      </details>
    </div>
  <?php endif; ?>

  <?php if ($instrumentoSelecionado === "" || empty($questoesFiltradas)): ?>
    <div class="alert alert-warning">Nenhuma questão encontrada para o questionário selecionado.</div>
  <?php else: ?>

    <form method="post" class="card shadow-sm">
      <div class="card-body">
        <h2 class="h5 mb-1"><?= h($instrumentoSelecionado) ?></h2>
        <div class="text-secondary mb-3">Marque uma opção em cada questão.</div>

        <input type="hidden" name="instrumento" value="<?= h($instrumentoSelecionado) ?>">

        <?php foreach ($grupos as $faixa => $dims): ?>
          <div class="mt-4">
            <h3 class="h6 text-uppercase text-secondary mb-2"><?= h($faixa) ?></h3>

            <?php foreach ($dims as $dim => $lista): ?>
              <div class="mb-3">
                <div class="fw-semibold mb-2"><?= h($dim) ?></div>

                <div class="d-grid gap-3">
                  <?php foreach ($lista as $q): 
                    $qid = (string)($q["id"] ?? "");
                    $pergunta = (string)($q["pergunta"] ?? "");
                    $campo = "resp_" . $qid;

                    // Usa opcoes da própria questão, se existir; senão usa default do arquivo
                    $opcoes = $q["opcoes"] ?? $escalaDefault;

                    // Valor previamente selecionado (após POST com erro ou para reexibir)
                    $prev = $_POST[$campo] ?? null;
                  ?>
                    <div class="q-card bg-white">
                      <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                        <div class="fw-semibold">
                          <span class="badge text-bg-secondary me-2"><?= h($qid) ?></span>
                          <?= h($pergunta) ?>
                        </div>
                      </div>

                      <div class="mt-3">
                        <div class="row g-2">
                          <?php foreach ($opcoes as $idx => $opt):
                            $val = $opt["value"];
                            $label = $opt["label"];
                            $rid = $campo . "_" . $idx;
                            $checked = ((string)$prev !== "" && (string)$prev === (string)$val) ? "checked" : "";
                          ?>
                            <div class="col-12 col-sm-6 col-lg-3">
                              <input class="btn-check" type="radio"
                                     name="<?= h($campo) ?>" id="<?= h($rid) ?>"
                                     value="<?= h((string)$val) ?>" <?= $checked ?> required>
                              <label class="btn btn-outline-primary w-100" for="<?= h($rid) ?>">
                                <?= h((string)$label) ?>
                              </label>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      </div>

                      <?php if (!empty($q["faixa_etaria"]) || !empty($q["dimensao"])): ?>
                        <div class="q-meta mt-2">
                          <?= !empty($q["faixa_etaria"]) ? "Faixa: " . h((string)$q["faixa_etaria"]) : "" ?>
                          <?= (!empty($q["faixa_etaria"]) && !empty($q["dimensao"])) ? " • " : "" ?>
                          <?= !empty($q["dimensao"]) ? "Dimensão: " . h((string)$q["dimensao"]) : "" ?>
                        </div>
                      <?php endif; ?>

                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>

        <div class="d-flex gap-2 justify-content-end mt-4">
          <a class="btn btn-outline-secondary" href="?instrumento=<?= urlencode($instrumentoSelecionado) ?>">Limpar</a>
          <button class="btn btn-primary" type="submit">Enviar respostas</button>
        </div>
      </div>
    </form>

  <?php endif; ?>

  <div class="text-secondary small mt-4">
    Dica: se você quiser “autosalvar”, crie uma pasta <code>respostas/</code> com permissão de escrita no servidor.
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>