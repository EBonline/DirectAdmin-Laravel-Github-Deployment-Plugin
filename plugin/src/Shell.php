<?php

declare(strict_types=1);

namespace GitDeploy;

/**
 * The HTML every page returns: a mount point for the Vue app (images/app.js, built from ui/),
 * the boot data it needs, and a loading state that turns into a clear message if the app
 * cannot start. DirectAdmin wraps this fragment in its own page.
 */
final class Shell
{
    public const BOOT_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Picks up Evolution's dark mode before the first paint, so a dark panel does not flash a
     * white plugin while app.js loads. A copy of applyTheme(isDark()) in ui/src/theme.ts, which
     * then keeps following the mode.
     */
    private const THEME_SCRIPT = '<script>(function(){try{var w=window,h=document.documentElement,a=document.getElementById("gd-app"),'
        . 'm=function(e){return e.classList.contains("mode:dark")||e.classList.contains("mode:light")},s=m(h)?h:null,f=w.parent!==w;'
        . 'if(!s&&f){try{var p=w.parent.document.documentElement;if(m(p))s=p}catch(e){}}'
        . 'var d=!!s&&s.classList.contains("mode:dark");a.classList.toggle("gd-dark",d);'
        . 'if(f){h.classList.add("gd-framed");h.classList.toggle("gd-dark",d)}}catch(e){}})();</script>';

    /** @param array<string,mixed> $boot */
    public static function render(Env $env, string $base, array $boot): string
    {
        $js = $env->pluginDir . '/images/app.js';
        $css = $env->pluginDir . '/images/app.css';
        $assets = $base . '/images';
        $json = json_encode($boot + ['server_time' => time()], self::BOOT_FLAGS);
        $bootTag = '<script type="application/json" id="gd-boot">' . $json . '</script>' . "\n";

        if (!is_file($js) || !is_file($css)) {
            return '<div class="gd" id="gd-app"><div style="padding:16px;border:1px solid #b42318;border-radius:8px;color:#b42318">'
                . '<strong>Laravel Github deploy cannot start:</strong> its interface files (<code>images/app.js</code>, '
                . '<code>images/app.css</code>) are missing. Build the plugin with <code>./build.sh</code> and upload the new '
                . 'tarball in the Plugin Manager.</div></div>' . "\n" . $bootTag;
        }
        $version = substr((string) md5_file($js), 0, 12);

        return '<link rel="stylesheet" href="' . h($assets . '/app.css?v=' . $version) . '">' . "\n"
            . '<div class="gd" id="gd-app">'
            . '<div class="gd-boot" role="status"><span class="gd-spinner" aria-hidden="true"></span> Loading Laravel Github deploy…</div>'
            . '<noscript><div class="gd-alert gd-alert-err">Laravel Github deploy needs JavaScript. Enable it in your browser and reload this page.</div></noscript>'
            . '</div>' . "\n"
            . self::THEME_SCRIPT . "\n"
            . $bootTag
            // If the app does not start (blocked or failed download), replace the spinner with an explanation.
            . '<script>setTimeout(function(){var b=document.querySelector("#gd-app .gd-boot");'
            . 'if(b&&!window.__gdStarted){b.className="gd-alert gd-alert-err";b.textContent='
            . '"The interface did not load. Reload the page; if this keeps happening, ask your hosting administrator to check the Laravel Github deploy plugin.";}},15000);</script>' . "\n"
            . '<script type="module" src="' . h($assets . '/app.js?v=' . $version) . '"></script>';
    }
}
