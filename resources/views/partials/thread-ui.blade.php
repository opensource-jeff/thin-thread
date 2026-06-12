<style>
    :root {
        color-scheme: dark;
        --thread-bg: #080a0c;
        --thread-panel: rgba(16, 20, 24, 0.9);
        --thread-panel-strong: rgba(11, 14, 17, 0.96);
        --thread-line: rgba(148, 163, 184, 0.16);
        --thread-line-strong: rgba(45, 212, 191, 0.34);
        --thread-text: #d6dde7;
        --thread-muted: #8a97a8;
        --thread-faint: #536071;
        --thread-teal: #2dd4bf;
        --thread-blue: #60a5fa;
        --thread-amber: #f2b84b;
        --thread-red: #f87171;
    }

    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        background: var(--thread-bg);
        color: var(--thread-text);
    }

    .thread-shell {
        min-height: 100vh;
        background-color: var(--thread-bg);
        background-image:
            linear-gradient(rgba(148, 163, 184, 0.08) 1px, transparent 1px),
            linear-gradient(90deg, rgba(148, 163, 184, 0.08) 1px, transparent 1px),
            linear-gradient(135deg, rgba(45, 212, 191, 0.08), transparent 34%),
            linear-gradient(315deg, rgba(242, 184, 75, 0.07), transparent 32%);
        background-size: 44px 44px, 44px 44px, 100% 100%, 100% 100%;
        overflow-x: hidden;
    }

    .thread-shell::before {
        content: "";
        position: fixed;
        inset: 0;
        pointer-events: none;
        background:
            linear-gradient(90deg, rgba(8, 10, 12, 0.98), rgba(8, 10, 12, 0.65), rgba(8, 10, 12, 0.98)),
            repeating-linear-gradient(120deg, transparent 0 17px, rgba(45, 212, 191, 0.035) 18px 19px);
    }

    .thread-page {
        position: relative;
        z-index: 1;
    }

    .thread-logo {
        position: relative;
        width: 48px;
        height: 48px;
        flex: 0 0 auto;
        border: 1px solid var(--thread-line-strong);
        border-radius: 8px;
        background:
            linear-gradient(180deg, rgba(45, 212, 191, 0.14), rgba(96, 165, 250, 0.04)),
            var(--thread-panel-strong);
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.03), 0 18px 40px rgba(0, 0, 0, 0.28);
    }

    .thread-logo::before,
    .thread-logo::after {
        content: "";
        position: absolute;
        left: 13px;
        width: 22px;
        border: 1px solid var(--thread-teal);
        border-radius: 50%;
    }

    .thread-logo::before {
        top: 12px;
        height: 9px;
        background: rgba(45, 212, 191, 0.18);
    }

    .thread-logo::after {
        top: 19px;
        height: 17px;
        border-top: 0;
        border-radius: 0 0 50% 50%;
        opacity: 0.72;
    }

    .thread-logo span {
        position: absolute;
        width: 6px;
        height: 6px;
        border-radius: 999px;
        background: var(--thread-amber);
        box-shadow: 0 0 18px rgba(242, 184, 75, 0.55);
    }

    .thread-logo span:nth-child(1) {
        left: 8px;
        top: 10px;
    }

    .thread-logo span:nth-child(2) {
        right: 8px;
        top: 27px;
        background: var(--thread-blue);
        box-shadow: 0 0 18px rgba(96, 165, 250, 0.5);
    }

    .thread-logo span:nth-child(3) {
        left: 19px;
        bottom: 7px;
        background: var(--thread-teal);
        box-shadow: 0 0 18px rgba(45, 212, 191, 0.45);
    }

    .thread-panel {
        border: 1px solid var(--thread-line);
        border-radius: 8px;
        background: var(--thread-panel);
        box-shadow: 0 24px 70px rgba(0, 0, 0, 0.3);
    }

    .thread-panel-strong {
        border: 1px solid var(--thread-line);
        border-radius: 8px;
        background: var(--thread-panel-strong);
        box-shadow: 0 24px 70px rgba(0, 0, 0, 0.32);
    }

    .thread-kicker {
        color: var(--thread-teal);
        font-size: 0.75rem;
        font-weight: 700;
    }

    .thread-muted {
        color: var(--thread-muted);
    }

    .thread-faint {
        color: var(--thread-faint);
    }

    .thread-link,
    .thread-danger,
    .thread-button {
        min-height: 40px;
        border-radius: 8px;
        transition: border-color 150ms ease, background-color 150ms ease, color 150ms ease, transform 150ms ease;
    }

    .thread-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid var(--thread-line);
        background: rgba(16, 20, 24, 0.68);
        color: var(--thread-text);
        padding: 0 14px;
        font-size: 0.8125rem;
        font-weight: 700;
    }

    .thread-link:hover {
        border-color: var(--thread-line-strong);
        color: white;
        background: rgba(45, 212, 191, 0.08);
    }

    .thread-danger {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid rgba(248, 113, 113, 0.25);
        background: rgba(127, 29, 29, 0.12);
        color: #fecaca;
        padding: 0 14px;
        font-size: 0.8125rem;
        font-weight: 700;
    }

    .thread-danger:hover {
        border-color: rgba(248, 113, 113, 0.55);
        color: white;
        background: rgba(127, 29, 29, 0.22);
    }

    .thread-button {
        display: inline-flex;
        width: 100%;
        align-items: center;
        justify-content: center;
        border: 1px solid rgba(45, 212, 191, 0.55);
        background: linear-gradient(180deg, rgba(45, 212, 191, 0.95), rgba(20, 184, 166, 0.84));
        color: #04100f;
        padding: 16px 20px;
        font-weight: 800;
    }

    .thread-button:hover {
        transform: translateY(-1px);
        background: linear-gradient(180deg, rgba(94, 234, 212, 1), rgba(45, 212, 191, 0.9));
    }

    .thread-input,
    .thread-select {
        width: 100%;
        border: 1px solid var(--thread-line);
        border-radius: 8px;
        background: rgba(5, 7, 10, 0.86);
        color: white;
        outline: none;
        transition: border-color 150ms ease, box-shadow 150ms ease, background-color 150ms ease;
    }

    .thread-input {
        padding: 16px;
    }

    .thread-select {
        padding: 16px;
    }

    .thread-input::placeholder {
        color: #566274;
    }

    .thread-input:focus,
    .thread-select:focus {
        border-color: var(--thread-teal);
        background: rgba(5, 7, 10, 0.98);
        box-shadow: 0 0 0 4px rgba(45, 212, 191, 0.1);
    }

    .thread-label {
        display: block;
        color: #b8c1cf;
        font-size: 0.8125rem;
        font-weight: 750;
    }

    .thread-metric {
        min-height: 72px;
        border: 1px solid var(--thread-line);
        border-radius: 8px;
        background: rgba(7, 10, 13, 0.68);
        padding: 14px;
    }

    .thread-metric span {
        display: block;
        color: var(--thread-faint);
        font-size: 0.75rem;
    }

    .thread-metric strong {
        display: block;
        margin-top: 5px;
        color: white;
        font-size: 0.95rem;
    }

    .search-surface {
        overflow: hidden;
        border: 1px solid var(--thread-line);
        border-radius: 8px;
        background:
            linear-gradient(180deg, rgba(255, 255, 255, 0.035), transparent 34%),
            linear-gradient(135deg, rgba(45, 212, 191, 0.08), transparent 45%),
            rgba(12, 16, 20, 0.94);
        box-shadow: 0 26px 80px rgba(0, 0, 0, 0.34);
    }

    .search-input-wrap {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 12px;
    }

    .search-input-wrap .thread-input {
        min-height: 64px;
        font-size: 1.125rem;
    }

    .search-submit {
        display: inline-flex;
        min-width: 124px;
        min-height: 64px;
        align-items: center;
        justify-content: center;
        border: 1px solid rgba(45, 212, 191, 0.5);
        border-radius: 8px;
        background: rgba(45, 212, 191, 0.12);
        color: #dffdf8;
        font-weight: 800;
        transition: background-color 150ms ease, border-color 150ms ease, transform 150ms ease;
    }

    .search-submit:hover {
        transform: translateY(-1px);
        border-color: rgba(94, 234, 212, 0.75);
        background: rgba(45, 212, 191, 0.2);
    }

    .status-pill {
        display: inline-flex;
        min-height: 32px;
        align-items: center;
        gap: 8px;
        border: 1px solid var(--thread-line);
        border-radius: 999px;
        background: rgba(7, 10, 13, 0.64);
        color: var(--thread-muted);
        padding: 0 12px;
        font-size: 0.8125rem;
        font-weight: 650;
    }

    .status-dot {
        width: 7px;
        height: 7px;
        border-radius: 999px;
        background: var(--thread-teal);
        box-shadow: 0 0 16px rgba(45, 212, 191, 0.7);
    }

    .result-toolbar {
        border: 1px solid var(--thread-line);
        border-radius: 8px;
        background: rgba(12, 16, 20, 0.78);
        padding: 14px 16px;
    }

    .thread-alert {
        border-radius: 8px;
        padding: 16px;
        font-size: 0.9rem;
    }

    .thread-alert-info {
        border: 1px solid rgba(45, 212, 191, 0.35);
        background: rgba(20, 184, 166, 0.1);
        color: #ccfbf1;
    }

    .thread-alert-error {
        border: 1px solid rgba(248, 113, 113, 0.35);
        background: rgba(127, 29, 29, 0.2);
        color: #fecaca;
    }

    .result-card {
        overflow: hidden;
    }

    .result-head {
        border-bottom: 1px solid var(--thread-line);
        background:
            linear-gradient(90deg, rgba(45, 212, 191, 0.08), transparent),
            rgba(7, 10, 13, 0.88);
    }

    .db-pill {
        display: inline-flex;
        align-items: center;
        max-width: 100%;
        min-height: 28px;
        border: 1px solid rgba(242, 184, 75, 0.3);
        border-radius: 999px;
        background: rgba(242, 184, 75, 0.08);
        color: #fde68a;
        padding: 0 10px;
        font-size: 0.75rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .hit-item {
        border-bottom: 1px solid rgba(148, 163, 184, 0.1);
        transition: background-color 150ms ease;
    }

    .hit-item:hover {
        background: rgba(45, 212, 191, 0.055);
    }

    .hit-index {
        color: var(--thread-faint);
        font-size: 0.75rem;
        font-weight: 750;
    }

    .hit-copy {
        color: #dbe4ef;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
        font-size: 0.8125rem;
        line-height: 1.7;
    }

    mark {
        border-radius: 4px;
        background: rgba(45, 212, 191, 0.22);
        color: #99f6e4;
        padding: 0 2px;
    }

    ::selection {
        background: rgba(45, 212, 191, 0.3);
        color: white;
    }

    @media (max-width: 640px) {
        .thread-logo {
            width: 42px;
            height: 42px;
        }

        .thread-input,
        .thread-select {
            padding: 14px;
        }

        .search-input-wrap {
            grid-template-columns: 1fr;
        }

        .search-input-wrap .thread-input,
        .search-submit {
            min-height: 56px;
        }
    }
</style>
