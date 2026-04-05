<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $content */
/** @var string|null $notice */
/** @var string|null $error */
/** @var string $activePath */
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <style>
        :root {
            --bg: #f3f4f6;
            --panel: #ffffff;
            --panel-soft: #f8fafc;
            --text: #0f172a;
            --muted: #64748b;
            --line: #e2e8f0;
            --brand: #0f62fe;
            --brand-dark: #0b4fd3;
            --accent: #047857;
            --danger: #b91c1c;
            --shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Manrope", "Avenir Next", "Segoe UI", sans-serif;
            background: var(--bg);
            color: var(--text);
        }
        .container {
            width: min(1320px, 95vw);
            margin: 18px auto 36px;
        }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 14px 16px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--panel);
            box-shadow: var(--shadow);
        }
        .brand h1 {
            margin: 0;
            font-size: 20px;
            letter-spacing: -0.02em;
        }
        .brand p {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 12px;
        }
        .tabs {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px;
            border-radius: 10px;
            background: #f1f5f9;
            border: 1px solid var(--line);
        }
        .tab {
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            color: var(--muted);
            padding: 8px 12px;
            border-radius: 8px;
        }
        .tab.active {
            background: var(--brand);
            color: #fff;
        }
        .muted { color: var(--muted); }
        .alert {
            padding: 12px 14px;
            border-radius: 10px;
            margin: 12px 0;
            border: 1px solid var(--line);
            background: var(--panel);
        }
        .alert.notice { border-color: #bbf7d0; background: #f0fdf4; color: #14532d; }
        .alert.error { border-color: #fecaca; background: #fef2f2; color: #7f1d1d; }
        .grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 12px;
            margin-top: 12px;
        }
        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px;
            box-shadow: var(--shadow);
        }
        .card h2 { margin: 0 0 12px; font-size: 16px; letter-spacing: -0.01em; }
        .full { grid-column: 1 / -1; }
        .half { grid-column: span 6; }
        .third { grid-column: span 4; }
        .page-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
        }
        .page-title {
            margin: 0;
            font-size: 20px;
            letter-spacing: -0.02em;
        }
        .page-subtitle {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 13px;
        }
        .page-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        label { display: block; margin-bottom: 10px; font-weight: 600; font-size: 14px; }
        input {
            width: 100%;
            margin-top: 6px;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 14px;
            background: #fff;
            color: var(--text);
        }
        input:focus {
            outline: none;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(0, 90, 213, 0.15);
        }
        .button-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        button {
            border: 0;
            border-radius: 10px;
            background: var(--brand);
            color: #fff;
            font-weight: 600;
            padding: 10px 14px;
            cursor: pointer;
        }
        button.secondary { background: var(--accent); }
        button.warning { background: #b45309; }
        button.ghost {
            background: #fff;
            color: var(--muted);
            border: 1px solid var(--line);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th, td {
            text-align: left;
            border-bottom: 1px solid var(--line);
            padding: 9px 8px;
            vertical-align: top;
        }
        th { color: var(--muted); font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        .status-idle { color: #0f766e; font-weight: 700; }
        .status-running { color: #1d4ed8; font-weight: 700; }
        .status-failed { color: var(--danger); font-weight: 700; }
        .status-paused, .status-cancel_requested { color: #b45309; font-weight: 700; }
        .status-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .02em;
            text-transform: uppercase;
            background: #eef2ff;
        }
        .progress-live {
            display: grid;
            gap: 4px;
            font-size: 12px;
            line-height: 1.35;
            min-width: 260px;
        }
        .progress-text {
            color: #1f2937;
            word-break: break-word;
        }
        .dot-live {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: #16a34a;
            display: inline-block;
            box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.14);
        }
        .autorefresh-note {
            margin-top: 8px;
            font-size: 12px;
            color: var(--muted);
        }
        .pagination {
            margin-top: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .page-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            height: 34px;
            padding: 0 10px;
            border-radius: 10px;
            border: 1px solid var(--line);
            color: var(--brand);
            text-decoration: none;
            font-weight: 700;
            background: #fff;
        }
        .page-link.active {
            background: linear-gradient(135deg, var(--brand), var(--brand-dark));
            color: #fff;
            border-color: transparent;
        }
        .stack { display: grid; gap: 14px; }
        .subtle-link {
            color: var(--brand);
            text-decoration: none;
            font-weight: 600;
        }
        .danger-zone {
            border: 1px solid #fecaca;
            background: #fff7f7;
        }
        .danger-zone h2 {
            color: #9f1239;
        }
        .metric {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .metric .label {
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .04em;
            font-weight: 700;
        }
        .metric .value {
            font-size: 30px;
            font-weight: 800;
            letter-spacing: -0.02em;
            line-height: 1;
        }
        .mono {
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
            font-size: 12px;
            line-height: 1.4;
        }
        .log-console {
            border: 1px solid #1f2937;
            background: #0b1220;
            color: #e5e7eb;
            border-radius: 10px;
            max-height: 340px;
            overflow: auto;
            padding: 10px;
        }
        .log-line {
            display: flex;
            gap: 8px;
            align-items: flex-start;
            padding: 3px 0;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .log-line-time {
            color: #94a3b8;
            flex: 0 0 auto;
        }
        .log-line-level {
            font-weight: 700;
            flex: 0 0 auto;
        }
        .log-line-msg {
            flex: 1 1 auto;
        }
        .log-level-info .log-line-level { color: #93c5fd; }
        .log-level-warn .log-line-level { color: #fcd34d; }
        .log-level-error .log-line-level { color: #fca5a5; }
        .log-level-debug .log-line-level { color: #c4b5fd; }
        @media (max-width: 900px) {
            .half { grid-column: 1 / -1; }
            .third { grid-column: 1 / -1; }
            .topbar {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <header class="topbar">
        <div class="brand">
            <h1>Parser Inomarker</h1>
            <p>Управление сайтами, обходом и аналитикой совпадений.</p>
        </div>
        <nav class="tabs" aria-label="Основная навигация">
            <a class="tab <?= $activePath === '/' ? 'active' : '' ?>" href="/">Dashboard</a>
            <a class="tab <?= $activePath === '/sites' ? 'active' : '' ?>" href="/sites">Sites</a>
            <a class="tab <?= $activePath === '/settings' ? 'active' : '' ?>" href="/settings">Settings</a>
        </nav>
    </header>

    <?php if (!empty($notice)): ?>
        <div class="alert notice"><?= htmlspecialchars($notice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <?= $content ?>
</div>
</body>
</html>
