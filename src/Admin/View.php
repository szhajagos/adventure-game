<?php

declare(strict_types=1);

namespace AdventureGame\Admin;

/**
 * Egyszerű HTML-segédek a szerkesztőfelülethez: keret, escape, linkek, űrlapelemek.
 */
final class View
{
    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** /admin/?p=…&… cím összeállítása */
    public static function url(array $params = []): string
    {
        $params = array_filter($params, fn ($v) => $v !== null && $v !== '');

        return '/admin/'.($params ? '?'.http_build_query($params) : '');
    }

    public static function csrf(): string
    {
        return '<input type="hidden" name="_csrf" value="'.self::e(Auth::csrfToken()).'">';
    }

    public static function field(string $label, string $name, mixed $value = '', string $type = 'text', array $attrs = []): string
    {
        $a = '';
        foreach ($attrs as $k => $v) {
            $a .= $v === true ? ' '.$k : ' '.$k.'="'.self::e($v).'"';
        }
        $id = 'f_'.$name;
        if ($type === 'textarea') {
            $input = '<textarea id="'.$id.'" name="'.self::e($name).'"'.$a.'>'.self::e($value).'</textarea>';
        } else {
            $input = '<input id="'.$id.'" type="'.$type.'" name="'.self::e($name).'" value="'.self::e($value).'"'.$a.'>';
        }

        return '<label class="field" for="'.$id.'"><span>'.self::e($label).'</span>'.$input.'</label>';
    }

    public static function select(string $label, string $name, array $options, mixed $selected = null, array $attrs = []): string
    {
        $a = '';
        foreach ($attrs as $k => $v) {
            $a .= $v === true ? ' '.$k : ' '.$k.'="'.self::e($v).'"';
        }
        $o = '';
        foreach ($options as $val => $text) {
            $o .= '<option value="'.self::e($val).'"'.((string) $val === (string) $selected ? ' selected' : '').'>'.self::e($text).'</option>';
        }

        return '<label class="field" for="f_'.$name.'"><span>'.self::e($label).'</span><select id="f_'.$name.'" name="'.self::e($name).'"'.$a.'>'.$o.'</select></label>';
    }

    /** Egygombos POST-űrlap (pl. törlés), megerősítéssel */
    public static function postButton(string $label, array $hidden, string $confirm = '', string $class = 'btn'): string
    {
        $h = self::csrf();
        foreach ($hidden as $k => $v) {
            $h .= '<input type="hidden" name="'.self::e($k).'" value="'.self::e($v).'">';
        }
        $c = $confirm !== '' ? ' onsubmit="return confirm(\''.self::e(addslashes($confirm)).'\')"' : '';

        return '<form method="post" class="inline"'.$c.'>'.$h.'<button class="'.self::e($class).'">'.self::e($label).'</button></form>';
    }

    public static function layout(string $title, string $body, ?array $user = null, ?array $flash = null, array $crumbs = []): string
    {
        $nav = '';
        if ($user) {
            $nav = '<nav><span class="who">'.self::e($user['display_name']).'</span>'
                .self::postButton('Kilépés', ['a' => 'logout'], '', 'btn ghost').'</nav>';
        }
        $bc = '';
        if ($crumbs) {
            $parts = [];
            foreach ($crumbs as $text => $href) {
                $parts[] = $href ? '<a href="'.self::e($href).'">'.self::e($text).'</a>' : '<span>'.self::e($text).'</span>';
            }
            $bc = '<div class="crumbs">'.implode(' › ', $parts).'</div>';
        }
        $fl = $flash ? '<div class="flash '.self::e($flash['type']).'">'.self::e($flash['text']).'</div>' : '';

        return '<!DOCTYPE html><html lang="hu"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta name="robots" content="noindex, nofollow">'
            .'<title>'.self::e($title).' · MCP Adventure szerkesztő</title><style>'.self::css().'</style></head><body>'
            .'<header><a class="logo" href="/admin/">MCP ADVENTURE <small>szerkesztő</small></a>'.$nav.'</header>'
            .'<main>'.$bc.$fl.'<h1>'.self::e($title).'</h1>'.$body.'</main></body></html>';
    }

    private static function css(): string
    {
        return <<<'CSS'
        :root{--ink:#1d1b33;--muted:#6b6890;--line:#dcdaf0;--bg:#f6f5fb;--card:#fff;--acc:#4b44c4;--acc2:#a5a5ff;--ok:#2f8a4c;--warn:#b7791f;--bad:#c0392b}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
        header{display:flex;justify-content:space-between;align-items:center;background:#1b1934;color:#e8e6ff;padding:10px 20px;border-bottom:3px solid var(--acc2)}
        .logo{color:var(--acc2);text-decoration:none;font-family:ui-monospace,Consolas,monospace;font-weight:700;letter-spacing:.08em}
        .logo small{color:#9c98d6;font-weight:400;letter-spacing:0;margin-left:6px}
        nav{display:flex;gap:10px;align-items:center}.who{color:#c9c6f5;font-size:14px}
        main{max-width:1100px;margin:0 auto;padding:18px 20px 60px}
        h1{font-size:24px;margin:6px 0 16px}h2{font-size:18px;margin:26px 0 10px}
        .crumbs{color:var(--muted);font-size:14px}.crumbs a{color:var(--acc)}
        .card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:16px;margin-bottom:14px}
        .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}
        .field{display:flex;flex-direction:column;gap:4px;margin-bottom:12px}.field span{font-size:13px;color:var(--muted);font-weight:600}
        input[type=text],input[type=email],input[type=password],input[type=number],textarea,select{font:inherit;padding:8px 10px;border:1px solid var(--line);border-radius:7px;background:#fff;width:100%}
        textarea{min-height:90px;resize:vertical}
        input:focus,textarea:focus,select:focus{outline:2px solid var(--acc2);border-color:var(--acc)}
        .row{display:flex;gap:12px;flex-wrap:wrap}.row>*{flex:1;min-width:140px}
        .btn{font:inherit;font-size:14px;padding:7px 14px;border-radius:7px;border:1px solid var(--acc);background:var(--acc);color:#fff;cursor:pointer;text-decoration:none;display:inline-block}
        .btn:hover{filter:brightness(1.1)}.btn.ghost{background:transparent;color:inherit;border-color:currentColor}
        .btn.light{background:#fff;color:var(--acc)}.btn.danger{background:#fff;color:var(--bad);border-color:var(--bad)}
        .btn.small{padding:3px 9px;font-size:13px}
        form.inline{display:inline}
        table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--line);border-radius:10px;overflow:hidden}
        th,td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--line);vertical-align:middle;font-size:14px}
        th{background:#efeefa;color:var(--muted);font-weight:600;font-size:13px}
        tr:last-child td{border-bottom:0}
        .muted{color:var(--muted)}.mono{font-family:ui-monospace,Consolas,monospace;font-size:13px}
        .thumb{width:96px;height:54px;object-fit:cover;border-radius:4px;border:1px solid var(--line);display:block;background:#eee}
        .thumb.none{display:flex;align-items:center;justify-content:center;font-size:11px;color:var(--muted)}
        .big{width:100%;max-width:560px;aspect-ratio:16/9;object-fit:cover;border-radius:8px;border:1px solid var(--line)}
        .flash{padding:10px 14px;border-radius:8px;margin:10px 0}.flash.ok{background:#e6f4ea;color:var(--ok)}.flash.err{background:#fdecea;color:var(--bad)}
        .badge{display:inline-block;padding:1px 8px;border-radius:99px;font-size:12px;background:#efeefa;color:var(--muted)}
        .badge.ok{background:#e6f4ea;color:var(--ok)}.badge.warn{background:#fdf3e1;color:var(--warn)}.badge.bad{background:#fdecea;color:var(--bad)}
        .checks li{margin:4px 0}.actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
        .progress{height:10px;background:#efeefa;border-radius:99px;overflow:hidden;margin:6px 0}.progress i{display:block;height:100%;background:var(--acc)}
        .two{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:800px){.two{grid-template-columns:1fr}}
        .center{max-width:420px;margin:40px auto}
        CSS;
    }
}
