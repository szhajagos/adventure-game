<?php

declare(strict_types=1);

namespace AdventureGame\Web;

/**
 * A játékosoknak szóló oldalak (fiók, bejelentkezés, jóváhagyás) közös kerete és űrlapelemei.
 */
final class Page
{
    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function csrf(): string
    {
        return '<input type="hidden" name="_csrf" value="'.self::e(Session::csrfToken()).'">';
    }

    public static function hidden(string $name, string $value): string
    {
        return '<input type="hidden" name="'.self::e($name).'" value="'.self::e($value).'">';
    }

    public static function field(string $label, string $name, string $value = '', string $type = 'text', array $attrs = []): string
    {
        $a = '';
        foreach ($attrs as $k => $v) {
            $a .= $v === true ? ' '.$k : ' '.$k.'="'.self::e($v).'"';
        }

        return '<label class="field"><span>'.self::e($label).'</span><input type="'.self::e($type).'" name="'.self::e($name).'" value="'.self::e($value).'"'.$a.'></label>';
    }

    public static function render(string $title, string $body, ?array $flash = null, ?string $userName = null): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');

        $nav = $userName !== null
            ? '<nav><a href="/fiok/">'.self::e($userName).'</a><form method="post" action="/fiok/?p=kilepes" class="inline">'.self::csrf().'<button class="link">Kilépés</button></form></nav>'
            : '<nav><a href="/fiok/?p=belepes">Belépés</a><a href="/fiok/?p=regisztracio">Regisztráció</a></nav>';
        $fl = $flash ? '<div class="flash '.self::e($flash['type']).'">'.self::e($flash['text']).'</div>' : '';

        echo '<!DOCTYPE html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.self::e($title).' · MCP Adventure</title><style>'.self::css().'</style></head><body>'
            .'<header><a class="logo" href="/">MCP ADVENTURE</a>'.$nav.'</header>'
            .'<main><div class="card"><h1>'.self::e($title).'</h1>'.$fl.$body.'</div></main></body></html>';
    }

    private static function css(): string
    {
        return <<<'CSS'
        :root{--bg:#1b1934;--card:#24214a;--frame:#a5a5ff;--text:#e8e6ff;--muted:#a9a5dc;--good:#7bd389;--bad:#ef6f6c;--acc:#f2c14e}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:16px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}
        header{display:flex;justify-content:space-between;align-items:center;padding:12px 20px;border-bottom:3px solid var(--frame)}
        .logo{color:var(--frame);text-decoration:none;font-family:ui-monospace,Consolas,monospace;font-weight:700;letter-spacing:.1em}
        nav{display:flex;gap:16px;align-items:center}nav a{color:var(--text)}
        main{max-width:560px;margin:32px auto;padding:0 16px}
        .card{background:var(--card);border:3px solid var(--frame);padding:22px 24px}
        h1{font-family:ui-monospace,Consolas,monospace;font-size:20px;text-transform:uppercase;letter-spacing:.06em;color:var(--frame);margin:0 0 16px}
        h2{font-size:17px;margin:24px 0 8px}
        p{margin:0 0 12px}a{color:var(--acc)}
        .field{display:flex;flex-direction:column;gap:4px;margin-bottom:14px}.field span{font-size:14px;color:var(--muted)}
        input[type=text],input[type=email],input[type=password]{font:inherit;padding:9px 11px;border:1px solid #4a4690;border-radius:6px;background:#15132b;color:var(--text);width:100%}
        input:focus{outline:2px solid var(--frame)}
        .btn{font:inherit;font-weight:600;padding:9px 18px;border:2px solid var(--frame);background:var(--frame);color:#1b1934;cursor:pointer;border-radius:6px}
        .btn.ghost{background:transparent;color:var(--text)}.btn.danger{background:transparent;color:var(--bad);border-color:var(--bad)}
        .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
        form.inline{display:inline}button.link{background:none;border:0;color:var(--text);font:inherit;text-decoration:underline;cursor:pointer;padding:0}
        .flash{padding:10px 12px;border-radius:6px;margin-bottom:14px}.flash.ok{background:#1f3b2a;color:var(--good)}.flash.err{background:#3d1f2a;color:var(--bad)}
        .muted{color:var(--muted);font-size:14px}
        code,.code{font-family:ui-monospace,Consolas,monospace;background:#15132b;padding:2px 6px;border-radius:4px;word-break:break-all}
        .box{background:#15132b;border:1px solid #4a4690;padding:12px;border-radius:6px;margin:10px 0}
        ol{padding-left:20px}li{margin:4px 0}
        table{width:100%;border-collapse:collapse;font-size:14px}td,th{padding:6px 4px;border-bottom:1px solid #3a3670;text-align:left}
        CSS;
    }
}
